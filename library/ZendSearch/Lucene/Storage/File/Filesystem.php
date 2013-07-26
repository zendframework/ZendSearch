<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2012 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 * @package   Zend_Search
 */

namespace ZendSearch\Lucene\Storage\File;

use ZendSearch\Lucene;
use Zend\Stdlib\ErrorHandler;

/**
 * @category   Zend
 * @package    Zend_Search_Lucene
 * @subpackage Storage
 */
class Filesystem extends AbstractFile
{
    /**
     * Holding the currently active (opened) Filesystem instance. Needed to prevent the system
     * from opening multiple file handles in parallel.
     *
     * @var Filesystem
     */
    private static $openFile = null;

    /**
     * Resource of the open file
     *
     * @var resource
     */
    protected $_fileHandle;

    /**
     * Current position in the file
     *
     * @var int
     */
    private $_seek = 0;

    /**
     * Name of the opened file
     *
     * @var string
     */
    private $_filename;

    /**
     * Mode of the file
     *
     * @var string
     */
    private $_mode;


    /**
     * Class constructor.  Open the file.
     *
     * @param string $filename
     * @param string $mode
     * @throws \ZendSearch\Lucene\Exception\InvalidArgumentException
     * @throws \ZendSearch\Lucene\Exception\RuntimeException
     */
    public function __construct($filename, $mode='r+b')
    {
        if (strpos($mode, 'w') === false  &&  !is_readable($filename)) {
            // opening for reading non-readable file
            throw new Lucene\Exception\InvalidArgumentException('File \'' . $filename . '\' is not readable.');
        }

        $this->_filename = $filename;
        $this->_mode = $mode;

        // We always want the file to be present. Even if we don't use it for writing.
        if(!is_file($this->_filename))
        {
            touch($this->_filename);
        }
    }

    /**
     * Sets the file position indicator and advances the file pointer.
     * The new position, measured in bytes from the beginning of the file,
     * is obtained by adding offset to the position specified by whence,
     * whose values are defined as follows:
     * SEEK_SET - Set position equal to offset bytes.
     * SEEK_CUR - Set position to current location plus offset.
     * SEEK_END - Set position to end-of-file plus offset. (To move to
     * a position before the end-of-file, you need to pass a negative value
     * in offset.)
     * SEEK_CUR is the only supported offset type for compound files
     *
     * Upon success, returns 0; otherwise, returns -1
     *
     * @param integer $offset
     * @param integer $whence
     * @return integer
     */
    public function seek($offset, $whence=SEEK_SET)
    {
        $this->_beforeFileOperation();
        $result = fseek($this->_fileHandle, $offset, $whence);
        $this->_afterFileOperation();
        return $result;
    }

    /**
     * Needs to be called before a file operation is executed on the file
     * handle. Makes sure that the file handle is opened with the required
     * mode.
     *
     * @throws \ZendSearch\Lucene\Exception\RuntimeException
     */
    private function _beforeFileOperation()
    {
        global $php_errormsg;

        // If file handle is set it is also open
        if ($this->_fileHandle !== null) {
            return;
        }

        $trackErrors = ini_get('track_errors');
        ini_set('track_errors', '1');

        $this->_fileHandle = @fopen($this->_filename, $this->_mode);

        if ($this->_fileHandle === false) {
            ini_set('track_errors', $trackErrors);
            throw new Lucene\Exception\RuntimeException($php_errormsg);
        }

        // Seek to the position we stopped last time
        fseek($this->_fileHandle, $this->_seek);

        // Close other file handle if one is open
        if (self::$openFile !== null && self::$openFile !== $this) {
            self::$openFile->_afterFileOperation(true);
        }

        // Remember this file to be open
        self::$openFile = $this;

        // We don't want to truncate the file to zero length when opening it a second time
        // later. So we will change "w+" mode to "r+" and "w" to "a".
        $this->_mode = str_replace("w+", "r+", $this->_mode);
        $this->_mode = str_replace("w", "a", $this->_mode);

        ini_set('track_errors', $trackErrors);
    }

    /**
     * Called after a file operation has been completed. Stores the current position
     * of the file pointer for later use. If $forceClose is set, the file handle will
     * be closed.
     *
     * @param bool $forceClose
     */
    private function _afterFileOperation($forceClose=false)
    {
        // Remember current position for the next file operation
        if ($this->_fileHandle !== null) {
            $this->_seek = ftell($this->_fileHandle);
        }

        // Close file if requested
        if ($forceClose) {
            ErrorHandler::start(E_WARNING);
            fclose($this->_fileHandle);
            ErrorHandler::stop();
            $this->_fileHandle = null;

            if (self::$openFile === $this) {
                self::$openFile = null;
            }
        }
    }


    /**
     * Get file position.
     *
     * @return integer
     */
    public function tell()
    {
        return $this->_seek;
    }

    /**
     * Flush output.
     *
     * Returns true on success or false on failure.
     *
     * @return boolean
     */
    public function flush()
    {
        if ($this->_fileHandle !== null) {
            return fflush($this->_fileHandle);
        }
        else {
            return true;
        }
    }

    /**
     * Close File object
     */
    public function close()
    {
        if ($this->_fileHandle !== null) {
            $this->_afterFileOperation(true);
        }
    }

    /**
     * Get the size of the already opened file
     *
     * @return integer
     */
    public function size()
    {
        // Clears the cache for this file. Otherwise we will get wrong return values
        // for filesize().
        clearstatcache(false, $this->_filename);

        return is_file($this->_filename) ? filesize($this->_filename) : 0;
    }

    /**
     * Read a $length bytes from the file and advance the file pointer.
     *
     * @param integer $length
     * @return string
     */
    protected function _fread($length=1)
    {
        if ($length == 0) {
            return '';
        }

        $this->_beforeFileOperation();

        if ($length < 1024) {
            $res = fread($this->_fileHandle, $length);
            $this->_afterFileOperation();
            return $res;
        }

        $data = '';
        while ( $length > 0 && ($nextBlock = fread($this->_fileHandle, $length)) != false ) {
            $data .= $nextBlock;
            $length -= strlen($nextBlock);
        }

        $this->_afterFileOperation();

        return $data;
    }


    /**
     * Writes $length number of bytes (all, if $length===null) to the end
     * of the file.
     *
     * @param string $data
     * @param integer $length
     */
    protected function _fwrite($data, $length=null)
    {
        $this->_beforeFileOperation();
        if ($length === null ) {
            fwrite($this->_fileHandle, $data);
        } else {
            fwrite($this->_fileHandle, $data, $length);
        }
        $this->_afterFileOperation();
    }

    /**
     * Lock file
     *
     * Lock type may be a LOCK_SH (shared lock) or a LOCK_EX (exclusive lock)
     *
     * @param integer $lockType
     * @param boolean $nonBlockingLock
     * @return boolean
     */
    public function lock($lockType, $nonBlockingLock = false)
    {
        $this->_beforeFileOperation();
        if ($nonBlockingLock) {
            $res = flock($this->_fileHandle, $lockType | LOCK_NB);
        } else {
            $res = flock($this->_fileHandle, $lockType);
        }
        $this->_afterFileOperation();
        return $res;
    }

    /**
     * Unlock file
     *
     * Returns true on success
     *
     * @return boolean
     */
    public function unlock()
    {
        $this->_beforeFileOperation();
        if ($this->_fileHandle !== null ) {
            $res = flock($this->_fileHandle, LOCK_UN);
            $this->_afterFileOperation();
            return $res;
        } else {
            return true;
        }
    }
}

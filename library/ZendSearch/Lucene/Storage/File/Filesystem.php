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
     * Resource of the open file
     *
     * @var resource
     */
    protected $_fileHandle;

    private $isLocked = false;
    private $seek = 0;
    private $filename;
    private $mode;


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
        global $php_errormsg;

        if (strpos($mode, 'w') === false  &&  !is_readable($filename)) {
            // opening for reading non-readable file
            throw new Lucene\Exception\InvalidArgumentException('File \'' . $filename . '\' is not readable.');
        }

        $trackErrors = ini_get('track_errors');
        ini_set('track_errors', '1');

        $this->filename = $filename;
        $this->mode = $mode;

        if(!is_file($this->filename))
        {
            touch($this->filename);
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
        $this->doOpen();
        $res = fseek($this->_fileHandle, $offset, $whence);
        $this->doClose();
        return $res;
    }

    private function doOpen()
    {
        if($this->_fileHandle === null)
        {
            $this->_fileHandle = fopen($this->filename, $this->mode);
            fseek($this->_fileHandle, $this->seek);

            $this->mode = str_replace("w+", "r+", $this->mode);
        }
    }

    private function doClose()
    {
        if($this->_fileHandle !== null)
        {
            $this->seek = ftell($this->_fileHandle);
        }

        if($this->isLocked)
        {
            return;
        }

        fflush($this->_fileHandle);
        fclose($this->_fileHandle);
        $this->_fileHandle = null;
    }


    /**
     * Get file position.
     *
     * @return integer
     */
    public function tell()
    {
        return $this->seek;
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
        if($this->_fileHandle !== null)
        {
            return fflush($this->_fileHandle);
        }
        else
        {
            return true;
        }
    }

    /**
     * Close File object
     */
    public function close()
    {
        if($this->_fileHandle !== null)
        {
            /*
            if($this->isLocked)
            {
                $this->unlock();
            }
            */
            $this->doClose();
        }
    }

    /**
     * Get the size of the already opened file
     *
     * @return integer
     */
    public function size()
    {
        clearstatcache(false, $this->filename);
        return is_file($this->filename) ? filesize($this->filename) : 0;
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

        $this->doOpen();

        if ($length < 1024) {
            $res = fread($this->_fileHandle, $length);
            $this->doClose();
            return $res;
        }

        $data = '';
        while ( $length > 0 && ($nextBlock = fread($this->_fileHandle, $length)) != false ) {
            $data .= $nextBlock;
            $length -= strlen($nextBlock);
        }
        $this->doClose();
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
        $this->doOpen();
        if ($length === null ) {
            fwrite($this->_fileHandle, $data);
        } else {
            fwrite($this->_fileHandle, $data, $length);
        }
        $this->doClose();
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
        $this->doOpen();
        if ($nonBlockingLock) {
            $res = flock($this->_fileHandle, $lockType | LOCK_NB);
        } else {
            $res = flock($this->_fileHandle, $lockType);
        }
        $this->isLocked = true;
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
        if ($this->_fileHandle !== null ) {
            $res = flock($this->_fileHandle, LOCK_UN);
            $this->isLocked = false;
            $this->doClose();
            return $res;
        } else {
            return true;
        }
    }
}

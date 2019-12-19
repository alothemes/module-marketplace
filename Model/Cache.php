<?php

namespace Swissup\Marketplace\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\ValidatorException;

/**
 * This class is created to eliminate magento cache usage
 * to keep the channel data when caches are flushed.
 */
class Cache
{
    /**
     * @var \Magento\Framework\Filesystem
     */
    private $filesystem;

    /**
     * @var string
     */
    private $folder;

    /**
     * @var integer
     */
    private $lifetime;

    /**
     * @param \Magento\Framework\Filesystem $filesystem
     * @param string $folder
     * @param integer $lifetime
     */
    public function __construct(
        \Magento\Framework\Filesystem $filesystem,
        $folder = 'swissup/marketplace',
        $lifetime = 1800
    ) {
        $this->filesystem = $filesystem;
        $this->folder = $folder;
        $this->lifetime = $lifetime;
    }

    /**
     * @param string $data
     * @param string $id
     * @return void
     * @throws FileSystemException
     * @throws ValidatorException
     */
    public function save($data, $id)
    {
        $file = $this->getDirectory()->openFile($this->folder . '/' . $id);

        if (!$file->lock()) {
            return;
        }

        try {
            $file->flush();
            $file->write($data);
        } catch (FileSystemException $e) {
            throw $e;
        } finally {
            $file->unlock();
        }
    }

    /**
     * @param string $id
     * @return string|false
     * @throws ValidatorException
     */
    public function load($id)
    {
        $path = $this->folder . '/' . $id;
        $dir = $this->getDirectory(false);

        if (!$dir->isReadable($path)) {
            return false;
        }

        $file = $dir->openFile($path);

        try {
            return $file->readAll();
        } catch (FileSystemException $e) {
            return false;
        }
    }

    /**
     * @param string $id
     * @return bool
     * @throws FileSystemException
     * @throws ValidatorException
     */
    public function remove($id)
    {
        return $this->getDirectory()->delete($this->folder . '/' . $id);
    }

    /**
     * @param boolean $write
     * @return Magento\Framework\Filesystem\Directory\Write|Magento\Framework\Filesystem\Directory\Read
     */
    private function getDirectory($write = true)
    {
        return $write ?
            $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR) :
            $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
    }
}

<?php

namespace Swissup\Marketplace\Helper\Installer;

use Magento\Framework\Exception\FileSystemException;

class Renderer
{
    public function render($path)
    {
        if (!is_readable($path)) {
            throw new FileSystemException(__(
                'File %1 can\'t be read. Please check if it exists and has read permissions.',
                [
                    $path
                ]
            ));
        }

        return file_get_contents($path);
    }
}

<?php

namespace Swissup\Marketplace\Model\Handler;

class AbstractHandler
{
    public function getTitle()
    {
        return get_class($this);
    }

    public function beforeQueue()
    {
        return [];
    }

    public function afterQueue()
    {
        return [];
    }
}

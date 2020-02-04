<?php

namespace Swissup\Marketplace\Model\Handler;

use Swissup\Marketplace\Model\Traits\LoggerAware;

class AbstractHandler extends \Magento\Framework\DataObject
{
    use LoggerAware;

    public function handle()
    {
        $this->getLogger()->info($this->getTitle());

        return $this->execute();
    }

    public function execute()
    {
        throw new \Exception('Execute method is not implemented');
    }

    public function getTitle()
    {
        return get_class($this);
    }

    public function validate()
    {
        return true;
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

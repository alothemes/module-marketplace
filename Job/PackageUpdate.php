<?php

namespace Swissup\Marketplace\Job;

use Swissup\Marketplace\Api\JobInterface;

class PackageUpdate extends PackageAbstractJob implements JobInterface
{
    public function execute()
    {
        return $this->packageManager->update($this->packageName);
    }
}

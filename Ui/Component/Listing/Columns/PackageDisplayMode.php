<?php

namespace Swissup\Marketplace\Ui\Component\Listing\Columns;

use Magento\Framework\Escaper;
use Magento\Framework\Data\OptionSourceInterface;
use Swissup\Marketplace\Model\ChannelRepository;

class PackageDisplayMode implements OptionSourceInterface
{
    /**
     * @param Escaper $escaper
     */
    public function __construct(
        Escaper $escaper
    ) {
        $this->escaper = $escaper;
    }

    /**
     * Get options
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            [
                'label' => $this->escaper->escapeHtml(__('Bundles')),
                'value' => '',
            ],
            [
                'label' => $this->escaper->escapeHtml(__('All Except Bundles')),
                'value' => '!metapackage',
            ],
        ];
    }
}

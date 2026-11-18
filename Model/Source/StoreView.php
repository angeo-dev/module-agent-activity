<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Store views as flat "Website / Store / View" options for the event grid.
 *
 * The core store options are a nested tree padded with spaces; in a grid
 * CSV export that padding ends up inside the cell. A flat list exports
 * clean values.
 *
 * @since 1.2.0
 */
class StoreView implements OptionSourceInterface
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        $options = [['value' => '0', 'label' => __('All Store Views')]];
        foreach ($this->storeManager->getStores() as $store) {
            $website = $store->getWebsite();
            $group = $store->getGroup();
            $options[] = [
                'value' => (string) $store->getId(),
                'label' => implode(' / ', array_filter([
                    $website ? trim((string) $website->getName()) : '',
                    $group ? trim((string) $group->getName()) : '',
                    trim((string) $store->getName()),
                ])),
            ];
        }
        return $options;
    }
}

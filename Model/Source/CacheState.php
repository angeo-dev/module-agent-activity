<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Full page cache state of a crawler page fetch (event log grid).
 */
class CacheState implements OptionSourceInterface
{
    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => '1', 'label' => __('Hit')],
            ['value' => '0', 'label' => __('Miss')],
        ];
    }
}

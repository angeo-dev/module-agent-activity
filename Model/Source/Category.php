<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model\Source;

use Angeo\AgentActivity\Model\Agent\Catalog;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Agent categories for grid filters and labels.
 */
class Category implements OptionSourceInterface
{
    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach (Catalog::categories() as $value => $label) {
            $options[] = ['value' => $value, 'label' => __($label)];
        }
        return $options;
    }
}

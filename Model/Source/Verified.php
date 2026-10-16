<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model\Source;

use Angeo\AgentActivity\Model\Verify\IpVerifier;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * IP check results for grid filters and labels.
 */
class Verified implements OptionSourceInterface
{
    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => (string) IpVerifier::STATE_VERIFIED, 'label' => __('Verified')],
            ['value' => (string) IpVerifier::STATE_FAILED, 'label' => __('Spoofed')],
        ];
    }
}

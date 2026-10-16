<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model\Source;

use Angeo\AgentActivity\Model\RequestClassifier;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Channels for grid filters and labels.
 */
class Channel implements OptionSourceInterface
{
    /**
     * Channel code => label.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            RequestClassifier::CHANNEL_UCP     => 'UCP',
            RequestClassifier::CHANNEL_MCP     => 'MCP',
            RequestClassifier::CHANNEL_WEBMCP  => 'WebMCP',
            RequestClassifier::CHANNEL_CRAWLER => 'Web pages',
        ];
    }

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach (self::labels() as $value => $label) {
            $options[] = ['value' => $value, 'label' => __($label)];
        }
        return $options;
    }
}

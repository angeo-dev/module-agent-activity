<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Cron;

use Angeo\AgentActivity\Model\Aggregator;
use Angeo\AgentActivity\Model\Config;

/**
 * Hourly: rebuilds today's and yesterday's daily totals for the report.
 */
class Aggregate
{
    public function __construct(
        private readonly Aggregator $aggregator,
        private readonly Config $config
    ) {
    }

    /**
     * Rebuild recent days.
     *
     * @return void
     */
    public function execute(): void
    {
        if ($this->config->isEnabled()) {
            $this->aggregator->refreshRecent();
        }
    }
}

<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Cron;

use Angeo\AgentActivity\Model\Aggregator;
use Angeo\AgentActivity\Model\Config;
use Angeo\AgentActivity\Model\EventRepository;

/**
 * Daily: removes events and daily totals older than their retention settings.
 */
class Cleanup
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly Aggregator $aggregator,
        private readonly Config $config
    ) {
    }

    /**
     * Delete expired rows.
     *
     * @return void
     */
    public function execute(): void
    {
        $this->events->deleteOlderThan(time() - 86400 * $this->config->getRawRetentionDays());
        $this->aggregator->deleteOlderThan(time() - 86400 * $this->config->getDailyRetentionDays());
    }
}

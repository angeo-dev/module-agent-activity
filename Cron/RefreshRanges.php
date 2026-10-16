<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Cron;

use Angeo\AgentActivity\Model\Config;
use Angeo\AgentActivity\Model\Verify\RangeRefresher;

/**
 * Daily: downloads the vendors' published bot IP ranges.
 */
class RefreshRanges
{
    public function __construct(
        private readonly RangeRefresher $refresher,
        private readonly Config $config
    ) {
    }

    /**
     * Refresh the lists when IP verification is on.
     *
     * @return void
     */
    public function execute(): void
    {
        if ($this->config->isEnabled() && $this->config->verifyIp()) {
            $this->refresher->refresh();
        }
    }
}

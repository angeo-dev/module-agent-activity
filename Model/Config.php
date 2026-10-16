<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Settings under angeo_agent_activity/* (global scope).
 */
class Config
{
    public const MODULE_VERSION = '1.0.0';

    private const PATH_ENABLED = 'angeo_agent_activity/general/enabled';
    private const PATH_LOG_CRAWLERS = 'angeo_agent_activity/general/log_crawlers';
    private const PATH_LOG_SEARCH_ENGINES = 'angeo_agent_activity/general/log_search_engines';
    private const PATH_VERIFY_IP = 'angeo_agent_activity/general/verify_ip';
    private const PATH_RAW_DAYS = 'angeo_agent_activity/retention/raw_days';
    private const PATH_DAILY_DAYS = 'angeo_agent_activity/retention/daily_days';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH_ENABLED);
    }

    public function logCrawlers(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH_LOG_CRAWLERS);
    }

    public function logSearchEngines(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH_LOG_SEARCH_ENGINES);
    }

    public function verifyIp(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH_VERIFY_IP);
    }

    /**
     * Days to keep single events (the event log).
     */
    public function getRawRetentionDays(): int
    {
        return $this->days(self::PATH_RAW_DAYS, 90, 365);
    }

    /**
     * Days to keep daily totals (the report).
     */
    public function getDailyRetentionDays(): int
    {
        return $this->days(self::PATH_DAILY_DAYS, 730, 3650);
    }

    private function days(string $path, int $default, int $max): int
    {
        $days = (int) $this->scopeConfig->getValue($path);
        return $days >= 1 ? min($days, $max) : $default;
    }
}

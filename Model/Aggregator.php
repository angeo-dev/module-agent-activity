<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\FlagManager;

/**
 * Rebuilds the daily totals (angeo_agent_event_daily, angeo_agent_path_daily)
 * from the event log for a range of UTC days.
 *
 * Idempotent: each run deletes the days it covers and inserts them again in
 * one transaction, so it can run every hour for "today" and as often as an
 * admin presses Refresh.
 */
class Aggregator
{
    public const DAILY_TABLE = 'angeo_agent_event_daily';
    public const PATH_TABLE = 'angeo_agent_path_daily';
    public const FLAG_LAST_RUN = 'angeo_agent_activity_aggregated_at';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly FlagManager $flagManager,
        private readonly Config $config
    ) {
    }

    /**
     * Rebuild today and yesterday (UTC): the hourly job.
     */
    public function refreshRecent(): void
    {
        $today = gmdate('Y-m-d');
        $this->rebuild(gmdate('Y-m-d', strtotime($today . ' -1 day')), $today);
    }

    /**
     * Rebuild every day from $from to $to (Y-m-d, UTC, inclusive).
     *
     * Days older than the event log keeps are skipped: their events are
     * gone, so rebuilding them would erase their totals.
     */
    public function rebuild(string $from, string $to): void
    {
        $oldest = gmdate('Y-m-d', time() - 86400 * ($this->config->getRawRetentionDays() - 1));
        $from = max($from, $oldest);
        if ($from > $to) {
            return;
        }
        $connection = $this->resource->getConnection();
        $events = $this->resource->getTableName(EventRepository::TABLE);
        $daily = $this->resource->getTableName(self::DAILY_TABLE);
        $paths = $this->resource->getTableName(self::PATH_TABLE);
        $start = $from . ' 00:00:00';
        $end = gmdate('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';

        $connection->beginTransaction();
        try {
            $connection->delete($daily, ['day >= ?' => $from, 'day <= ?' => $to]);
            $connection->delete($paths, ['day >= ?' => $from, 'day <= ?' => $to]);

            $select = $connection->select()
                ->from($events, [
                    'day'            => new Expression('DATE(created_at)'),
                    'store_id'       => 'store_id',
                    'channel'        => 'channel',
                    'category'       => new Expression('MAX(category)'),
                    'agent'          => 'agent',
                    'operator'       => new Expression('MAX(operator)'),
                    'operation'      => 'operation',
                    'verified_state' => new Expression('IFNULL(verified, 2)'),
                    'calls'          => new Expression('COUNT(*)'),
                    'status_2xx'     => new Expression('SUM(status >= 200 AND status < 300)'),
                    'status_3xx'     => new Expression('SUM(status >= 300 AND status < 400)'),
                    'status_4xx'     => new Expression('SUM(status >= 400 AND status < 500)'),
                    'status_5xx'     => new Expression('SUM(status >= 500)'),
                    'signed'         => new Expression('SUM(signed)'),
                    'rpc_errors'     => new Expression('SUM(rpc_error)'),
                    'cache_hits'     => new Expression('IFNULL(SUM(cache = 1), 0)'),
                    'cache_misses'   => new Expression('IFNULL(SUM(cache = 0), 0)'),
                    'query_calls'    => new Expression('SUM(has_query)'),
                    'duration_sum'   => new Expression('IFNULL(SUM(duration_ms), 0)'),
                    'duration_count' => new Expression('COUNT(duration_ms)'),
                    'orders'         => new Expression('SUM(order_id IS NOT NULL)'),
                    'revenue'        => new Expression('IFNULL(SUM(value), 0)'),
                    'currency'       => new Expression('MAX(currency)'),
                ])
                ->where('created_at >= ?', $start)
                ->where('created_at < ?', $end)
                ->group(['day', 'store_id', 'channel', 'agent', 'operation', 'verified_state']);
            $connection->query($connection->insertFromSelect($select, $daily, [
                'day', 'store_id', 'channel', 'category', 'agent', 'operator', 'operation', 'verified_state',
                'calls', 'status_2xx', 'status_3xx', 'status_4xx', 'status_5xx', 'signed',
                'rpc_errors', 'cache_hits', 'cache_misses', 'query_calls',
                'duration_sum', 'duration_count', 'orders', 'revenue', 'currency',
            ]));

            $pathSelect = $connection->select()
                ->from($events, [
                    'day'      => new Expression('DATE(created_at)'),
                    'store_id' => 'store_id',
                    'category' => 'category',
                    'path'     => 'path',
                    'calls'    => new Expression('COUNT(*)'),
                    'agents'   => new Expression('COUNT(DISTINCT agent)'),
                ])
                ->where('created_at >= ?', $start)
                ->where('created_at < ?', $end)
                ->where('channel = ?', RequestClassifier::CHANNEL_CRAWLER)
                ->where('path IS NOT NULL')
                ->group(['day', 'store_id', 'category', 'path']);
            $connection->query($connection->insertFromSelect(
                $pathSelect,
                $paths,
                ['day', 'store_id', 'category', 'path', 'calls', 'agents']
            ));

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
        $this->flagManager->saveFlag(self::FLAG_LAST_RUN, time());
    }

    /**
     * Unix time of the last successful run, or null.
     */
    public function lastRun(): ?int
    {
        $value = $this->flagManager->getFlagData(self::FLAG_LAST_RUN);
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Delete daily totals older than the cutoff (unix time).
     */
    public function deleteOlderThan(int $cutoff): void
    {
        $connection = $this->resource->getConnection();
        $day = gmdate('Y-m-d', $cutoff);
        $connection->delete($this->resource->getTableName(self::DAILY_TABLE), ['day < ?' => $day]);
        $connection->delete($this->resource->getTableName(self::PATH_TABLE), ['day < ?' => $day]);
    }
}

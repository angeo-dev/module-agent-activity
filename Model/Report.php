<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model;

use Angeo\AgentActivity\Model\Report\Filter;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;

/**
 * Report queries. Everything reads the daily totals (Aggregator) except the
 * agent order list, which reads the event log, and the UCP funnel, which
 * reads the UCP checkout sessions.
 *
 * "Calls" never include order rows; orders are counted on their own.
 */
class Report
{
    private const UCP_TABLE = 'angeo_ucp_checkout_session';
    private const VERIFIED = 1;
    private const SPOOFED = 0;

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Headline numbers.
     *
     * @param Filter $filter
     * @return array{calls: int, agents: int, errors: int, verified: int, spoofed: int, signed: int,
     *     orders: int, revenue: array<string, float>}
     */
    public function totals(Filter $filter): array
    {
        $connection = $this->connection();
        $row = $connection->fetchRow(
            $this->callsSelect($filter)->columns([
                'calls'    => new Expression('IFNULL(SUM(calls), 0)'),
                'agents'   => new Expression('COUNT(DISTINCT agent)'),
                'errors'   => new Expression('IFNULL(SUM(status_4xx + status_5xx), 0)'),
                'verified' => new Expression('IFNULL(SUM(IF(verified_state = ' . self::VERIFIED . ', calls, 0)), 0)'),
                'spoofed'  => new Expression('IFNULL(SUM(IF(verified_state = ' . self::SPOOFED . ', calls, 0)), 0)'),
                'signed'   => new Expression('IFNULL(SUM(signed), 0)'),
            ])
        ) ?: [];

        $orders = 0;
        $revenue = [];
        foreach ($this->ordersByChannel($filter) as $r) {
            $orders += $r['orders'];
            $revenue[$r['currency']] = ($revenue[$r['currency']] ?? 0.0) + $r['revenue'];
        }

        return [
            'calls'    => (int) ($row['calls'] ?? 0),
            'agents'   => (int) ($row['agents'] ?? 0),
            'errors'   => (int) ($row['errors'] ?? 0),
            'verified' => (int) ($row['verified'] ?? 0),
            'spoofed'  => (int) ($row['spoofed'] ?? 0),
            'signed'   => (int) ($row['signed'] ?? 0),
            'orders'   => $orders,
            'revenue'  => $revenue,
        ];
    }

    /**
     * Calls per day and channel, every day of the range (zeros included).
     *
     * @param Filter $filter
     * @return array{days: array<int, string>, channels: array<string, array<int, int>>}
     */
    public function dailyByChannel(Filter $filter): array
    {
        $rows = $this->connection()->fetchAll(
            $this->callsSelect($filter)
                ->columns(['day', 'channel', 'calls' => new Expression('SUM(calls)')])
                ->group(['day', 'channel'])
        );
        $days = $filter->dayList();
        $index = array_flip($days);
        $channels = [];
        foreach ($rows as $r) {
            $channel = (string) $r['channel'];
            $channels[$channel] ??= array_fill(0, count($days), 0);
            $i = $index[(string) $r['day']] ?? null;
            if ($i !== null) {
                $channels[$channel][$i] = (int) $r['calls'];
            }
        }
        return ['days' => $days, 'channels' => $this->orderChannels($channels)];
    }

    /**
     * Calls per agent category.
     *
     * @param Filter $filter
     * @return array<int, array{category: string, calls: int, agents: int}>
     */
    public function byCategory(Filter $filter): array
    {
        $rows = $this->connection()->fetchAll(
            $this->callsSelect($filter)
                ->columns(['category', 'calls' => new Expression('SUM(calls)'), 'agents' => new Expression('COUNT(DISTINCT agent)')])
                ->group('category')
                ->order('calls DESC')
        );
        return array_map(static fn (array $r): array => [
            'category' => (string) $r['category'],
            'calls'    => (int) $r['calls'],
            'agents'   => (int) $r['agents'],
        ], $rows);
    }

    /**
     * Agents, busiest first.
     *
     * @param Filter $filter
     * @param int $limit
     * @return array<int, array{agent: string, operator: string, category: string, calls: int, errors: int,
     *     verified: int, spoofed: int, signed: int, last_day: string}>
     */
    public function byAgent(Filter $filter, int $limit = 25): array
    {
        $rows = $this->connection()->fetchAll(
            $this->callsSelect($filter)
                ->columns([
                    'agent',
                    'operator' => new Expression('MAX(operator)'),
                    'category' => new Expression('MAX(category)'),
                    'calls'    => new Expression('SUM(calls)'),
                    'errors'   => new Expression('SUM(status_4xx + status_5xx)'),
                    'verified' => new Expression('SUM(IF(verified_state = ' . self::VERIFIED . ', calls, 0))'),
                    'spoofed'  => new Expression('SUM(IF(verified_state = ' . self::SPOOFED . ', calls, 0))'),
                    'signed'   => new Expression('SUM(signed)'),
                    'last_day' => new Expression('MAX(day)'),
                ])
                ->group('agent')
                ->order('calls DESC')
                ->limit($limit)
        );
        return array_map(static fn (array $r): array => [
            'agent'    => (string) $r['agent'],
            'operator' => (string) $r['operator'],
            'category' => (string) $r['category'],
            'calls'    => (int) $r['calls'],
            'errors'   => (int) $r['errors'],
            'verified' => (int) $r['verified'],
            'spoofed'  => (int) $r['spoofed'],
            'signed'   => (int) $r['signed'],
            'last_day' => (string) $r['last_day'],
        ], $rows);
    }

    /**
     * Operations, busiest first.
     *
     * @param Filter $filter
     * @param int $limit
     * @return array<int, array{channel: string, operation: string, calls: int, errors: int, avg_ms: int}>
     */
    public function byOperation(Filter $filter, int $limit = 25): array
    {
        $rows = $this->connection()->fetchAll(
            $this->callsSelect($filter)
                ->columns([
                    'channel',
                    'operation',
                    'calls'  => new Expression('SUM(calls)'),
                    'errors' => new Expression('SUM(status_4xx + status_5xx)'),
                    'avg_ms' => new Expression('SUM(duration_sum) / NULLIF(SUM(duration_count), 0)'),
                ])
                ->group(['channel', 'operation'])
                ->order('calls DESC')
                ->limit($limit)
        );
        return array_map(static fn (array $r): array => [
            'channel'   => (string) $r['channel'],
            'operation' => (string) $r['operation'],
            'calls'     => (int) $r['calls'],
            'errors'    => (int) $r['errors'],
            'avg_ms'    => (int) round((float) $r['avg_ms']),
        ], $rows);
    }

    /**
     * Responses by status class.
     *
     * @param Filter $filter
     * @return array{'2xx': int, '3xx': int, '4xx': int, '5xx': int}
     */
    public function statusMix(Filter $filter): array
    {
        $row = $this->connection()->fetchRow(
            $this->callsSelect($filter)->columns([
                's2' => new Expression('IFNULL(SUM(status_2xx), 0)'),
                's3' => new Expression('IFNULL(SUM(status_3xx), 0)'),
                's4' => new Expression('IFNULL(SUM(status_4xx), 0)'),
                's5' => new Expression('IFNULL(SUM(status_5xx), 0)'),
            ])
        ) ?: [];
        return [
            '2xx' => (int) ($row['s2'] ?? 0),
            '3xx' => (int) ($row['s3'] ?? 0),
            '4xx' => (int) ($row['s4'] ?? 0),
            '5xx' => (int) ($row['s5'] ?? 0),
        ];
    }

    /**
     * Storefront pages fetched most by AI crawlers and agents.
     *
     * @param Filter $filter
     * @param int $limit
     * @return array<int, array{path: string, calls: int, training: int, search: int, user_fetch: int, agent: int}>
     */
    public function topPaths(Filter $filter, int $limit = 20): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->resource->getTableName(Aggregator::PATH_TABLE), [
                'path',
                'calls'      => new Expression('SUM(calls)'),
                'training'   => new Expression("SUM(IF(category = 'training', calls, 0))"),
                'search'     => new Expression("SUM(IF(category = 'search', calls, 0))"),
                'user_fetch' => new Expression("SUM(IF(category = 'user_fetch', calls, 0))"),
                'agent'      => new Expression("SUM(IF(category = 'agent', calls, 0))"),
            ])
            ->where('day >= ?', $filter->from)
            ->where('day <= ?', $filter->to)
            ->group('path')
            ->order('calls DESC')
            ->limit($limit);
        $this->whereStore($select, $filter);
        return array_map(static fn (array $r): array => [
            'path'       => (string) $r['path'],
            'calls'      => (int) $r['calls'],
            'training'   => (int) $r['training'],
            'search'     => (int) $r['search'],
            'user_fetch' => (int) $r['user_fetch'],
            'agent'      => (int) $r['agent'],
        ], $connection->fetchAll($select));
    }

    /**
     * Agent-built orders and revenue per channel and currency.
     *
     * @param Filter $filter
     * @return array<int, array{channel: string, currency: string, orders: int, revenue: float}>
     */
    public function ordersByChannel(Filter $filter): array
    {
        $connection = $this->connection();
        $select = $this->dailySelect($filter)
            ->columns([
                'channel',
                'currency' => new Expression("IFNULL(currency, '')"),
                'orders'   => new Expression('SUM(orders)'),
                'revenue'  => new Expression('SUM(revenue)'),
            ])
            ->where('operation = ?', RequestClassifier::OPERATION_ORDER)
            ->group(['channel', 'currency'])
            ->order('orders DESC');
        return array_map(static fn (array $r): array => [
            'channel'  => (string) $r['channel'],
            'currency' => (string) $r['currency'],
            'orders'   => (int) $r['orders'],
            'revenue'  => (float) $r['revenue'],
        ], $connection->fetchAll($select));
    }

    /**
     * Latest agent-built orders (from the event log).
     *
     * @param Filter $filter
     * @param int $limit
     * @return array<int, array{created_at: string, channel: string, agent: string, order_id: int,
     *     increment_id: string, value: float, currency: string}>
     */
    public function recentOrders(Filter $filter, int $limit = 20): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->resource->getTableName(EventRepository::TABLE), [
                'created_at', 'channel', 'agent', 'order_id', 'ref', 'value', 'currency',
            ])
            ->where('operation = ?', RequestClassifier::OPERATION_ORDER)
            ->where('created_at >= ?', $filter->from . ' 00:00:00')
            ->where('created_at < ?', gmdate('Y-m-d', (int) strtotime($filter->to . ' +1 day')) . ' 00:00:00')
            ->order(['created_at DESC', 'event_id DESC'])
            ->limit($limit);
        $this->whereStore($select, $filter);
        return array_map(static fn (array $r): array => [
            'created_at'   => (string) $r['created_at'],
            'channel'      => (string) $r['channel'],
            'agent'        => (string) $r['agent'],
            'order_id'     => (int) $r['order_id'],
            'increment_id' => (string) $r['ref'],
            'value'        => (float) $r['value'],
            'currency'     => (string) $r['currency'],
        ], $connection->fetchAll($select));
    }

    /**
     * UCP checkout funnel, or null without angeo/module-ucp-checkout.
     *
     * @param Filter $filter
     * @return array{created: int, escalated: int, completed: int, canceled: int, orders: int}|null
     */
    public function ucpFunnel(Filter $filter): ?array
    {
        $connection = $this->resource->getConnection('checkout');
        $table = $this->resource->getTableName(self::UCP_TABLE, 'checkout');
        if (!$connection->isTableExists($table)) {
            return null;
        }
        $select = $connection->select()
            ->from($table, [
                'created'   => new Expression('COUNT(*)'),
                'escalated' => new Expression("IFNULL(SUM(status IN ('requires_escalation', 'completed')), 0)"),
                'completed' => new Expression("IFNULL(SUM(status = 'completed'), 0)"),
                'canceled'  => new Expression("IFNULL(SUM(status = 'canceled'), 0)"),
            ])
            ->where('created_at >= ?', $filter->from . ' 00:00:00')
            ->where('created_at < ?', gmdate('Y-m-d', (int) strtotime($filter->to . ' +1 day')) . ' 00:00:00');
        if ($filter->storeId !== null) {
            $select->where('store_id = ?', $filter->storeId);
        }
        $row = $connection->fetchRow($select) ?: [];

        $orders = 0;
        foreach ($this->ordersByChannel($filter) as $r) {
            if ($r['channel'] === RequestClassifier::CHANNEL_UCP) {
                $orders += $r['orders'];
            }
        }
        return [
            'created'   => (int) ($row['created'] ?? 0),
            'escalated' => (int) ($row['escalated'] ?? 0),
            'completed' => (int) ($row['completed'] ?? 0),
            'canceled'  => (int) ($row['canceled'] ?? 0),
            'orders'    => $orders,
        ];
    }

    /**
     * Daily totals in range, all rows (orders included).
     *
     * @param Filter $filter
     * @return Select
     */
    private function dailySelect(Filter $filter): Select
    {
        $select = $this->connection()->select()
            ->from($this->resource->getTableName(Aggregator::DAILY_TABLE), [])
            ->where('day >= ?', $filter->from)
            ->where('day <= ?', $filter->to);
        $this->whereStore($select, $filter);
        return $select;
    }

    /**
     * Daily totals in range, calls only (no order rows).
     *
     * @param Filter $filter
     * @return Select
     */
    private function callsSelect(Filter $filter): Select
    {
        return $this->dailySelect($filter)->where('operation <> ?', RequestClassifier::OPERATION_ORDER);
    }

    /**
     * @param Select $select
     * @param Filter $filter
     * @return void
     */
    private function whereStore(Select $select, Filter $filter): void
    {
        if ($filter->storeId !== null) {
            $select->where('store_id = ?', $filter->storeId);
        }
    }

    /**
     * Channels in a fixed order, so a chart color always means the same channel.
     *
     * @param array<string, array<int, int>> $channels
     * @return array<string, array<int, int>>
     */
    private function orderChannels(array $channels): array
    {
        $order = [
            RequestClassifier::CHANNEL_UCP,
            RequestClassifier::CHANNEL_MCP,
            RequestClassifier::CHANNEL_WEBMCP,
            RequestClassifier::CHANNEL_CRAWLER,
        ];
        $sorted = [];
        foreach ($order as $channel) {
            if (isset($channels[$channel])) {
                $sorted[$channel] = $channels[$channel];
            }
        }
        return $sorted + $channels;
    }

    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }
}

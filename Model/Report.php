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
    private const UNCHECKED = 2;
    private const BEHIND_PROXY = 3;

    /** Errors: HTTP 4xx/5xx plus JSON-RPC errors sent with HTTP 200. */
    private const ERRORS = 'status_4xx + status_5xx + rpc_errors';

    /** MCP tools that build a cart, and tools that move it to checkout. */
    public const MCP_CART_TOOLS = ['create_cart', 'add_to_cart', 'update_cart', 'reorder', 'quick_order'];
    public const MCP_CHECKOUT_TOOLS = [
        'create_checkout', 'update_checkout', 'set_shipping_information', 'complete_checkout', 'place_order',
    ];

    /** Page types that a crawler has no business fetching. */
    public const WASTE_PAGES = ['account', 'cart', 'checkout', 'search'];

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Headline numbers.
     *
     * @param Filter $filter
     * @return array{calls: int, agents: int, errors: int, rpc_errors: int, verified: int, spoofed: int,
     *     unchecked: int, behind_proxy: int, signed: int, cache_hits: int, cache_misses: int,
     *     query_calls: int, orders: int, revenue: array<string, float>}
     */
    public function totals(Filter $filter): array
    {
        $connection = $this->connection();
        $row = $connection->fetchRow(
            $this->callsSelect($filter)->columns([
                'calls'    => new Expression('IFNULL(SUM(calls), 0)'),
                'agents'   => new Expression('COUNT(DISTINCT agent)'),
                'errors'   => new Expression('IFNULL(SUM(' . self::ERRORS . '), 0)'),
                'rpc'      => new Expression('IFNULL(SUM(rpc_errors), 0)'),
                'verified' => new Expression('IFNULL(SUM(IF(verified_state = ' . self::VERIFIED . ', calls, 0)), 0)'),
                'spoofed'  => new Expression('IFNULL(SUM(IF(verified_state = ' . self::SPOOFED . ', calls, 0)), 0)'),
                'unchecked' => new Expression('IFNULL(SUM(IF(verified_state = ' . self::UNCHECKED . ', calls, 0)), 0)'),
                'proxy'    => new Expression('IFNULL(SUM(IF(verified_state = ' . self::BEHIND_PROXY . ', calls, 0)), 0)'),
                'signed'   => new Expression('IFNULL(SUM(signed), 0)'),
                'hits'     => new Expression('IFNULL(SUM(cache_hits), 0)'),
                'misses'   => new Expression('IFNULL(SUM(cache_misses), 0)'),
                'queries'  => new Expression('IFNULL(SUM(query_calls), 0)'),
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
            'rpc_errors' => (int) ($row['rpc'] ?? 0),
            'verified' => (int) ($row['verified'] ?? 0),
            'spoofed'  => (int) ($row['spoofed'] ?? 0),
            'unchecked' => (int) ($row['unchecked'] ?? 0),
            'behind_proxy' => (int) ($row['proxy'] ?? 0),
            'signed'   => (int) ($row['signed'] ?? 0),
            'cache_hits' => (int) ($row['hits'] ?? 0),
            'cache_misses' => (int) ($row['misses'] ?? 0),
            'query_calls' => (int) ($row['queries'] ?? 0),
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
     *     verified: int, spoofed: int, behind_proxy: int, signed: int, avg_ms: int, last_day: string}>
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
                    'errors'   => new Expression('SUM(' . self::ERRORS . ')'),
                    'verified' => new Expression('SUM(IF(verified_state = ' . self::VERIFIED . ', calls, 0))'),
                    'spoofed'  => new Expression('SUM(IF(verified_state = ' . self::SPOOFED . ', calls, 0))'),
                    'proxy'    => new Expression('SUM(IF(verified_state = ' . self::BEHIND_PROXY . ', calls, 0))'),
                    'signed'   => new Expression('SUM(signed)'),
                    'avg_ms'   => new Expression('SUM(duration_sum) / NULLIF(SUM(duration_count), 0)'),
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
            'behind_proxy' => (int) $r['proxy'],
            'signed'   => (int) $r['signed'],
            'avg_ms'   => (int) round((float) $r['avg_ms']),
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
                    'errors' => new Expression('SUM(' . self::ERRORS . ')'),
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
     * @return array<int, array{path: string, calls: int, training: int, search: int, user_fetch: int, agent: int,
     *     search_engine: int, other: int}>
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
                'search_engine' => new Expression("SUM(IF(category = 'search_engine', calls, 0))"),
                'other'      => new Expression("SUM(IF(category NOT IN "
                    . "('training', 'search', 'user_fetch', 'agent', 'search_engine'), calls, 0))"),
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
            'search_engine' => (int) $r['search_engine'],
            'other'      => (int) $r['other'],
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
     * MCP funnel: discovery, tool listing, tool calls, cart, checkout, orders.
     *
     * Stateless MCP has no session, so this counts calls per step, not
     * people. A healthy store shows tool calls; discovery alone means
     * clients connect and leave.
     *
     * @param Filter $filter
     * @return array{discover: int, list: int, tool_calls: int, cart: int, checkout: int, orders: int,
     *     clients: int, named_clients: int}
     */
    public function mcpFunnel(Filter $filter): array
    {
        $cart = $this->toolList(self::MCP_CART_TOOLS);
        $checkout = $this->toolList(self::MCP_CHECKOUT_TOOLS);
        $row = $this->connection()->fetchRow(
            $this->callsSelect($filter)
                ->where('channel = ?', RequestClassifier::CHANNEL_MCP)
                ->columns([
                    'discover'   => new Expression(
                        "IFNULL(SUM(IF(operation IN ('server/discover', 'initialize'), calls, 0)), 0)"
                    ),
                    'list'       => new Expression("IFNULL(SUM(IF(operation LIKE '%/list', calls, 0)), 0)"),
                    'tool_calls' => new Expression("IFNULL(SUM(IF(operation LIKE 'tools/call%', calls, 0)), 0)"),
                    'cart'       => new Expression('IFNULL(SUM(IF(operation IN (' . $cart . '), calls, 0)), 0)'),
                    'checkout'   => new Expression('IFNULL(SUM(IF(operation IN (' . $checkout . '), calls, 0)), 0)'),
                    'clients'    => new Expression('COUNT(DISTINCT agent)'),
                    'named'      => new Expression("COUNT(DISTINCT IF(agent <> 'unknown', agent, NULL))"),
                ])
        ) ?: [];
        $orders = 0;
        foreach ($this->ordersByChannel($filter) as $r) {
            if ($r['channel'] === RequestClassifier::CHANNEL_MCP) {
                $orders += $r['orders'];
            }
        }
        return [
            'discover'      => (int) ($row['discover'] ?? 0),
            'list'          => (int) ($row['list'] ?? 0),
            'tool_calls'    => (int) ($row['tool_calls'] ?? 0),
            'cart'          => (int) ($row['cart'] ?? 0),
            'checkout'      => (int) ($row['checkout'] ?? 0),
            'orders'        => $orders,
            'clients'       => (int) ($row['clients'] ?? 0),
            'named_clients' => (int) ($row['named'] ?? 0),
        ];
    }

    /**
     * MCP clients and how far each one gets.
     *
     * @param Filter $filter
     * @param int $limit
     * @return array<int, array{agent: string, operator: string, calls: int, discover: int, list: int,
     *     tool_calls: int, errors: int, avg_ms: int, last_day: string}>
     */
    public function mcpClients(Filter $filter, int $limit = 25): array
    {
        $rows = $this->connection()->fetchAll(
            $this->callsSelect($filter)
                ->where('channel = ?', RequestClassifier::CHANNEL_MCP)
                ->columns([
                    'agent',
                    'operator'   => new Expression('MAX(operator)'),
                    'calls'      => new Expression('SUM(calls)'),
                    'discover'   => new Expression("SUM(IF(operation IN ('server/discover', 'initialize'), calls, 0))"),
                    'list'       => new Expression("SUM(IF(operation LIKE '%/list', calls, 0))"),
                    'tool_calls' => new Expression("SUM(IF(operation LIKE 'tools/call%', calls, 0))"),
                    'errors'     => new Expression('SUM(' . self::ERRORS . ')'),
                    'avg_ms'     => new Expression('SUM(duration_sum) / NULLIF(SUM(duration_count), 0)'),
                    'last_day'   => new Expression('MAX(day)'),
                ])
                ->group('agent')
                ->order('calls DESC')
                ->limit($limit)
        );
        return array_map(static fn (array $r): array => [
            'agent'      => (string) $r['agent'],
            'operator'   => (string) $r['operator'],
            'calls'      => (int) $r['calls'],
            'discover'   => (int) $r['discover'],
            'list'       => (int) $r['list'],
            'tool_calls' => (int) $r['tool_calls'],
            'errors'     => (int) $r['errors'],
            'avg_ms'     => (int) round((float) $r['avg_ms']),
            'last_day'   => (string) $r['last_day'],
        ], $rows);
    }

    /**
     * Agent tool calls by tool name (MCP tools/call and WebMCP tools).
     *
     * @param Filter $filter
     * @param int $limit
     * @return array<int, array{channel: string, tool: string, calls: int, errors: int, clients: int, avg_ms: int}>
     */
    public function byTool(Filter $filter, int $limit = 50): array
    {
        $select = $this->callsSelect($filter)
            ->where(
                "(channel = 'mcp' AND operation LIKE 'tools/call:%') OR channel = ?",
                RequestClassifier::CHANNEL_WEBMCP
            )
            ->columns([
                'channel',
                'tool'    => new Expression("REPLACE(operation, 'tools/call:', '')"),
                'calls'   => new Expression('SUM(calls)'),
                'errors'  => new Expression('SUM(' . self::ERRORS . ')'),
                'clients' => new Expression('COUNT(DISTINCT agent)'),
                'avg_ms'  => new Expression('SUM(duration_sum) / NULLIF(SUM(duration_count), 0)'),
            ])
            ->group(['channel', 'operation'])
            ->order('calls DESC')
            ->limit($limit);
        return array_map(static fn (array $r): array => [
            'channel' => (string) $r['channel'],
            'tool'    => (string) $r['tool'],
            'calls'   => (int) $r['calls'],
            'errors'  => (int) $r['errors'],
            'clients' => (int) $r['clients'],
            'avg_ms'  => (int) round((float) $r['avg_ms']),
        ], $this->connection()->fetchAll($select));
    }

    /**
     * What each agent type reads: page type x agent category, with speed and cache.
     *
     * @param Filter $filter
     * @return array<int, array{page_type: string, calls: int, by_category: array<string, int>, avg_ms: int,
     *     cache_hits: int, cache_misses: int, errors: int, query_calls: int}>
     */
    public function contentMix(Filter $filter): array
    {
        $rows = $this->connection()->fetchAll(
            $this->callsSelect($filter)
                ->where('channel = ?', RequestClassifier::CHANNEL_CRAWLER)
                ->columns([
                    'operation',
                    'category',
                    'calls'   => new Expression('SUM(calls)'),
                    'dsum'    => new Expression('SUM(duration_sum)'),
                    'dcount'  => new Expression('SUM(duration_count)'),
                    'hits'    => new Expression('SUM(cache_hits)'),
                    'misses'  => new Expression('SUM(cache_misses)'),
                    'errors'  => new Expression('SUM(' . self::ERRORS . ')'),
                    'queries' => new Expression('SUM(query_calls)'),
                ])
                ->group(['operation', 'category'])
        );
        $mix = [];
        foreach ($rows as $r) {
            $type = (string) $r['operation'];
            $mix[$type] ??= ['page_type' => $type, 'calls' => 0, 'by_category' => [], 'dsum' => 0, 'dcount' => 0,
                'cache_hits' => 0, 'cache_misses' => 0, 'errors' => 0, 'query_calls' => 0];
            $mix[$type]['calls'] += (int) $r['calls'];
            $mix[$type]['by_category'][(string) $r['category']] = (int) $r['calls'];
            $mix[$type]['dsum'] += (int) $r['dsum'];
            $mix[$type]['dcount'] += (int) $r['dcount'];
            $mix[$type]['cache_hits'] += (int) $r['hits'];
            $mix[$type]['cache_misses'] += (int) $r['misses'];
            $mix[$type]['errors'] += (int) $r['errors'];
            $mix[$type]['query_calls'] += (int) $r['queries'];
        }
        $out = [];
        foreach ($mix as $row) {
            $row['avg_ms'] = $row['dcount'] > 0 ? (int) round($row['dsum'] / $row['dcount']) : 0;
            unset($row['dsum'], $row['dcount']);
            $out[] = $row;
        }
        usort($out, static fn (array $a, array $b): int => $b['calls'] <=> $a['calls']);
        return $out;
    }

    /**
     * Per crawler agent: robots.txt reads versus everything else (for "reads robots.txt only").
     *
     * @param Filter $filter
     * @return array<int, array{agent: string, operator: string, category: string, robots: int, llms: int,
     *     pages: int, unchecked: int}>
     */
    public function crawlerReach(Filter $filter): array
    {
        $rows = $this->connection()->fetchAll(
            $this->callsSelect($filter)
                ->where('channel = ?', RequestClassifier::CHANNEL_CRAWLER)
                ->columns([
                    'agent',
                    'operator'  => new Expression('MAX(operator)'),
                    'category'  => new Expression('MAX(category)'),
                    'robots'    => new Expression("SUM(IF(operation = 'robots.txt', calls, 0))"),
                    'llms'      => new Expression("SUM(IF(operation = 'llms.txt', calls, 0))"),
                    'pages'     => new Expression("SUM(IF(operation NOT IN ('robots.txt', 'llms.txt'), calls, 0))"),
                    'unchecked' => new Expression('SUM(IF(verified_state = ' . self::UNCHECKED . ', calls, 0))'),
                ])
                ->group('agent')
                ->order('robots DESC')
        );
        return array_map(static fn (array $r): array => [
            'agent'     => (string) $r['agent'],
            'operator'  => (string) $r['operator'],
            'category'  => (string) $r['category'],
            'robots'    => (int) $r['robots'],
            'llms'      => (int) $r['llms'],
            'pages'     => (int) $r['pages'],
            'unchecked' => (int) $r['unchecked'],
        ], $rows);
    }

    /**
     * Crawler fetches of URLs without a URL rewrite (/catalog/product/view/id/…).
     *
     * @param Filter $filter
     * @param int $limit
     * @return array{calls: int, paths: array<int, array{path: string, calls: int}>}
     */
    public function rawUrlFetches(Filter $filter, int $limit = 5): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->resource->getTableName(Aggregator::PATH_TABLE), [
                'path', 'calls' => new Expression('SUM(calls)'),
            ])
            ->where('day >= ?', $filter->from)
            ->where('day <= ?', $filter->to)
            ->where("path LIKE '/catalog/product/view/%' OR path LIKE '/catalog/category/view/%'")
            ->group('path')
            ->order('calls DESC');
        $this->whereStore($select, $filter);
        $rows = $connection->fetchAll($select);
        $total = 0;
        foreach ($rows as $r) {
            $total += (int) $r['calls'];
        }
        return [
            'calls' => $total,
            'paths' => array_map(static fn (array $r): array => [
                'path' => (string) $r['path'], 'calls' => (int) $r['calls'],
            ], array_slice($rows, 0, $limit)),
        ];
    }

    /**
     * Pages that answered agents and crawlers with 4xx or 5xx (from the event log).
     *
     * @param Filter $filter
     * @param int $limit
     * @return array<int, array{path: string, status: int, calls: int, agents: int}>
     */
    public function errorPaths(Filter $filter, int $limit = 10): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->resource->getTableName(EventRepository::TABLE), [
                'path'   => new Expression("IFNULL(path, operation)"),
                'status',
                'calls'  => new Expression('COUNT(*)'),
                'agents' => new Expression('COUNT(DISTINCT agent)'),
            ])
            ->where('status >= 400')
            ->where('operation <> ?', RequestClassifier::OPERATION_ORDER)
            ->where('created_at >= ?', $filter->from . ' 00:00:00')
            ->where('created_at < ?', gmdate('Y-m-d', (int) strtotime($filter->to . ' +1 day')) . ' 00:00:00')
            ->group([new Expression('IFNULL(path, operation)'), 'status'])
            ->order('calls DESC')
            ->limit($limit);
        $this->whereStore($select, $filter);
        return array_map(static fn (array $r): array => [
            'path'   => (string) $r['path'],
            'status' => (int) $r['status'],
            'calls'  => (int) $r['calls'],
            'agents' => (int) $r['agents'],
        ], $connection->fetchAll($select));
    }

    /**
     * MCP client versions and protocol versions seen (from the event log).
     *
     * @param Filter $filter
     * @return array<int, array{agent: string, client: string, client_version: string, protocol: string,
     *     calls: int}>
     */
    public function mcpVersions(Filter $filter): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from($this->resource->getTableName(EventRepository::TABLE), [
                'agent',
                'client'         => new Expression("IFNULL(client, '')"),
                'client_version' => new Expression("IFNULL(client_version, '')"),
                'protocol'       => new Expression("IFNULL(protocol, '')"),
                'calls'          => new Expression('COUNT(*)'),
            ])
            ->where('channel = ?', RequestClassifier::CHANNEL_MCP)
            ->where('created_at >= ?', $filter->from . ' 00:00:00')
            ->where('created_at < ?', gmdate('Y-m-d', (int) strtotime($filter->to . ' +1 day')) . ' 00:00:00')
            ->group(['agent', 'client', 'client_version', 'protocol'])
            ->order('calls DESC')
            ->limit(50);
        $this->whereStore($select, $filter);
        return array_map(static fn (array $r): array => [
            'agent'          => (string) $r['agent'],
            'client'         => (string) $r['client'],
            'client_version' => (string) $r['client_version'],
            'protocol'       => (string) $r['protocol'],
            'calls'          => (int) $r['calls'],
        ], $connection->fetchAll($select));
    }

    /**
     * Quoted SQL list of MCP operations for tool names.
     *
     * @param array<int, string> $tools
     */
    private function toolList(array $tools): string
    {
        $connection = $this->connection();
        return implode(', ', array_map(
            static fn (string $tool): string => (string) $connection->quote('tools/call:' . $tool),
            $tools
        ));
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

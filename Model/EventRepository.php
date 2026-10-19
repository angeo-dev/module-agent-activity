<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model;

use Angeo\AgentActivity\Model\Agent\Identity;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

/**
 * Writes and prunes angeo_agent_event rows, and the quote markers that
 * attribute a later order to the agent that built the cart.
 */
class EventRepository
{
    public const TABLE = 'angeo_agent_event';
    public const QUOTE_TABLE = 'angeo_agent_quote';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @param array{channel: string, operation: string, ref: ?string, path?: ?string,
     *     mcp?: ?array{client: ?string, client_version: ?string, protocol: ?string}} $event
     * @param Identity $agent who sent it
     * @param int $storeId store view id
     * @param int $status HTTP status of the response
     * @param int|null $verified IpVerifier::STATE_* or null when not checked
     * @param int|null $durationMs time from request start to response
     * @param array{order_id?: int, value?: float, currency?: string} $order for agent-built orders
     * @param array{rpc_error?: bool, cache?: ?bool, has_query?: bool} $facts response facts
     */
    public function add(
        array $event,
        Identity $agent,
        int $storeId,
        int $status,
        ?int $verified = null,
        ?int $durationMs = null,
        array $order = [],
        array $facts = []
    ): void {
        $mcp = $event['mcp'] ?? null;
        $cache = $facts['cache'] ?? null;
        $this->resource->getConnection()->insert($this->resource->getTableName(self::TABLE), [
            'store_id'    => $storeId,
            'channel'     => mb_substr($event['channel'], 0, 16),
            'category'    => mb_substr($agent->category, 0, 16),
            'agent'       => mb_substr($agent->name, 0, 64),
            'operator'    => mb_substr($agent->operator, 0, 64),
            'operation'   => mb_substr($event['operation'], 0, 64),
            'status'      => max(0, min($status, 999)),
            'verified'    => $verified,
            'signed'      => $agent->signed ? 1 : 0,
            'duration_ms' => $durationMs !== null ? max(0, $durationMs) : null,
            'path'        => isset($event['path']) ? mb_substr((string) $event['path'], 0, 255) : null,
            'ref'         => $event['ref'] !== null ? mb_substr($event['ref'], 0, 64) : null,
            'order_id'    => $order['order_id'] ?? null,
            'value'       => $order['value'] ?? null,
            'currency'    => isset($order['currency']) ? mb_substr($order['currency'], 0, 3) : null,
            'client'         => isset($mcp['client']) ? mb_substr((string) $mcp['client'], 0, 64) : null,
            'client_version' => isset($mcp['client_version']) ? mb_substr((string) $mcp['client_version'], 0, 32) : null,
            'protocol'       => isset($mcp['protocol']) ? mb_substr((string) $mcp['protocol'], 0, 16) : null,
            'rpc_error'      => !empty($facts['rpc_error']) ? 1 : 0,
            'cache'          => $cache === null ? null : ($cache ? 1 : 0),
            'has_query'      => !empty($facts['has_query']) ? 1 : 0,
        ]);
    }

    /**
     * Remember that an agent put items into this quote (WebMCP add_to_cart).
     */
    public function markQuote(int $quoteId, string $channel, string $agent): void
    {
        if ($quoteId <= 0) {
            return;
        }
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::QUOTE_TABLE),
            ['quote_id' => $quoteId, 'channel' => $channel, 'agent' => mb_substr($agent, 0, 64)],
            ['channel', 'agent']
        );
    }

    /**
     * @return array{channel: string, agent: string}|null
     */
    public function findQuoteMark(int $quoteId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(self::QUOTE_TABLE), ['channel', 'agent'])
                ->where('quote_id = ?', $quoteId)
        );
        return is_array($row) && $row !== [] ? ['channel' => (string) $row['channel'], 'agent' => (string) $row['agent']] : null;
    }

    /**
     * Day (Y-m-d, UTC) of the first stored event, or null when the log is empty.
     */
    public function firstDay(): ?string
    {
        $connection = $this->resource->getConnection();
        $value = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), [new Expression('MIN(created_at)')])
        );
        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    /**
     * Delete events, and quote markers, older than the cutoff (unix time).
     */
    public function deleteOlderThan(int $cutoff): int
    {
        $connection = $this->resource->getConnection();
        $date = gmdate('Y-m-d H:i:s', $cutoff);
        $connection->delete($this->resource->getTableName(self::QUOTE_TABLE), ['created_at < ?' => $date]);
        return $connection->delete($this->resource->getTableName(self::TABLE), ['created_at < ?' => $date]);
    }
}

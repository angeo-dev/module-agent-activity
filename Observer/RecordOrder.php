<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Observer;

use Angeo\AgentActivity\Model\Agent\Catalog;
use Angeo\AgentActivity\Model\Agent\Identity;
use Angeo\AgentActivity\Model\AgentDetector;
use Angeo\AgentActivity\Model\Config;
use Angeo\AgentActivity\Model\EventRepository;
use Angeo\AgentActivity\Model\RequestClassifier;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * checkout_submit_all_after: records an order as agent-built when its cart
 * was built by an agent. Evidence, first match wins:
 *
 *  1. UCP: the quote belongs to a UCP checkout session
 *     (angeo/module-ucp-checkout); the agent is the platform host.
 *  2. MCP: the quote was created by the MCP create_cart tool
 *     (angeo/module-mcp-checkout); the agent is named from the request that
 *     places the order.
 *  3. WebMCP: a browser agent added items with the add_to_cart tool
 *     (marked by RecordRequest).
 *
 * Tables of the other modules are read only when they exist, so this module
 * does not require them.
 */
class RecordOrder implements ObserverInterface
{
    private const UCP_TABLE = 'angeo_ucp_checkout_session';
    private const MCP_TABLE = 'angeo_mcp_agent_quote';

    public function __construct(
        private readonly Config $config,
        private readonly EventRepository $events,
        private readonly AgentDetector $agentDetector,
        private readonly ResourceConnection $resource,
        private readonly RequestInterface $request,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Record an agent-built order.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            if (!$this->config->isEnabled()) {
                return;
            }
            $quote = $observer->getEvent()->getData('quote');
            $order = $observer->getEvent()->getData('order');
            if (!$quote instanceof Quote || !$order instanceof Order) {
                return;
            }
            $attribution = $this->attribute((int) $quote->getId());
            if ($attribution === null) {
                return;
            }
            [$channel, $identity] = $attribution;
            $this->events->add(
                ['channel' => $channel, 'operation' => RequestClassifier::OPERATION_ORDER,
                    'ref' => (string) $order->getIncrementId()],
                $identity,
                (int) $order->getStoreId(),
                200,
                null,
                null,
                [
                    'order_id' => (int) $order->getId(),
                    'value'    => (float) $order->getBaseGrandTotal(),
                    'currency' => (string) $order->getBaseCurrencyCode(),
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[Angeo_AgentActivity] Could not record order: ' . $e->getMessage());
        }
    }

    /**
     * @param int $quoteId
     * @return array{0: string, 1: Identity}|null
     */
    private function attribute(int $quoteId): ?array
    {
        if ($quoteId <= 0) {
            return null;
        }
        $checkout = $this->resource->getConnection('checkout');
        $ucpTable = $this->resource->getTableName(self::UCP_TABLE, 'checkout');
        if ($checkout->isTableExists($ucpTable)) {
            $profile = $checkout->fetchOne(
                $checkout->select()
                    ->from($ucpTable, ['agent_profile'])
                    ->where('quote_id = ?', $quoteId)
                    ->order('entity_id DESC')
                    ->limit(1)
            );
            if ($profile !== false && $profile !== null) {
                $host = strtolower((string) parse_url((string) $profile, PHP_URL_HOST));
                $name = $host !== '' ? $host : 'unknown';
                return [
                    RequestClassifier::CHANNEL_UCP,
                    new Identity($name, $name, Catalog::CATEGORY_PLATFORM, Identity::SOURCE_UCP),
                ];
            }
        }

        $connection = $this->resource->getConnection();
        $mcpTable = $this->resource->getTableName(self::MCP_TABLE);
        if ($connection->isTableExists($mcpTable)
            && $connection->fetchOne(
                $connection->select()->from($mcpTable, ['quote_id'])->where('quote_id = ?', $quoteId)
            )
        ) {
            return [RequestClassifier::CHANNEL_MCP, $this->requestIdentity('mcp-client')];
        }

        $mark = $this->events->findQuoteMark($quoteId);
        if ($mark !== null) {
            return [
                $mark['channel'],
                new Identity($mark['agent'], '', Catalog::CATEGORY_PLATFORM, Identity::SOURCE_BROWSER),
            ];
        }
        return null;
    }

    /**
     * The agent of the current request, or a generic name.
     *
     * @param string $fallback
     * @return Identity
     */
    private function requestIdentity(string $fallback): Identity
    {
        $identity = $this->agentDetector->detect(
            (string) $this->request->getHeader('UCP-Agent'),
            (string) $this->request->getHeader('User-Agent'),
            (string) $this->request->getHeader('Signature-Agent'),
            (string) $this->request->getHeader('Signature-Input')
        );
        return $identity->source === Identity::SOURCE_UNKNOWN
            ? new Identity($fallback, '', Catalog::CATEGORY_PLATFORM, Identity::SOURCE_UNKNOWN)
            : $identity;
    }
}

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
use Angeo\AgentActivity\Model\PageCacheState;
use Angeo\AgentActivity\Model\RequestClassifier;
use Angeo\AgentActivity\Model\Verify\IpVerifier;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * controller_front_send_response_before (frontend): records agent requests.
 *
 * Runs after the result is rendered, so the status code is final. Costs a
 * few header reads for ordinary shoppers and one INSERT for agent calls.
 * Never throws: logging must not break the response.
 *
 * The client address is read only to check a claimed bot against its
 * vendor's IP ranges; it is not stored. The checkout session is injected as
 * a proxy (di.xml), so it is started only for a WebMCP add to cart.
 */
class RecordRequest implements ObserverInterface
{
    private const MCP_BODY_LIMIT = 65536;
    private const RESPONSE_PEEK = 65536;

    public function __construct(
        private readonly Config $config,
        private readonly RequestClassifier $classifier,
        private readonly AgentDetector $agentDetector,
        private readonly IpVerifier $ipVerifier,
        private readonly EventRepository $events,
        private readonly StoreManagerInterface $storeManager,
        private readonly RemoteAddress $remoteAddress,
        private readonly CheckoutSession $checkoutSession,
        private readonly Catalog $catalog,
        private readonly PageCacheState $pageCache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Record the request when it is agent activity.
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
            $request = $observer->getEvent()->getData('request');
            $response = $observer->getEvent()->getData('response');
            if (!$request instanceof HttpRequest) {
                return;
            }
            $this->record($request, $response instanceof HttpResponse ? $response : null);
        } catch (\Throwable $e) {
            $this->logger->warning('[Angeo_AgentActivity] Could not record request: ' . $e->getMessage());
        }
    }

    /**
     * @param HttpRequest $request
     * @param HttpResponse|null $response
     * @return void
     */
    private function record(HttpRequest $request, ?HttpResponse $response): void
    {
        $identity = $this->agentDetector->detect(
            (string) $request->getHeader('UCP-Agent'),
            (string) $request->getHeader('User-Agent'),
            (string) $request->getHeader('Signature-Agent'),
            (string) $request->getHeader('Signature-Input')
        );
        $isAiAgent = $identity->isKnownAiAgent()
            && $this->config->logCrawlers()
            && ($identity->category !== Catalog::CATEGORY_SEARCH_ENGINE || $this->config->logSearchEngines());

        $uriPath = (string) (parse_url((string) $request->getRequestUri(), PHP_URL_PATH) ?: '');
        $pathInfo = (string) $request->getPathInfo();
        $path = $pathInfo !== '' && $pathInfo !== '/' ? $pathInfo : $uriPath;
        $body = str_ends_with(rtrim($path, '/'), '/mcp')
            ? substr((string) $request->getContent(), 0, self::MCP_BODY_LIMIT)
            : '';

        $event = $this->classifier->classify(
            (string) $request->getMethod(),
            $path,
            (string) $request->getHeader('X-Angeo-Agent-Tool'),
            $isAiAgent,
            $identity->category === Catalog::CATEGORY_AGENT,
            $body,
            (string) $request->getFullActionName(),
            (string) $request->getHeader('Mcp-Method'),
            (string) $request->getHeader('Mcp-Name')
        );
        if ($event === null) {
            return;
        }
        $identity = $this->mcpIdentity($identity, $event);
        if ($identity->source === Identity::SOURCE_UNKNOWN && $event['channel'] === RequestClassifier::CHANNEL_WEBMCP) {
            $identity = new Identity('browser', '', Catalog::CATEGORY_PLATFORM, Identity::SOURCE_BROWSER);
        }

        $verified = $this->config->verifyIp()
            ? $this->ipVerifier->verify($identity, (string) $this->remoteAddress->getRemoteAddress())
            : null;

        $started = (float) ($request->getServer('REQUEST_TIME_FLOAT') ?: 0);
        $duration = $started > 0 ? (int) round((microtime(true) - $started) * 1000) : null;
        $status = $response !== null ? (int) $response->getHttpResponseCode() : 0;

        $isMcp = $event['channel'] === RequestClassifier::CHANNEL_MCP;
        $this->events->add(
            $event,
            $identity,
            (int) $this->storeManager->getStore()->getId(),
            $status,
            $verified,
            $duration,
            [],
            [
                'rpc_error' => $isMcp && $response !== null && $status < 400
                    && RequestClassifier::isRpcError(substr((string) $response->getContent(), 0, self::RESPONSE_PEEK)),
                'cache'     => $event['channel'] === RequestClassifier::CHANNEL_CRAWLER ? $this->pageCache->get() : null,
                'has_query' => (string) parse_url((string) $request->getRequestUri(), PHP_URL_QUERY) !== '',
            ]
        );

        if ($event['channel'] === RequestClassifier::CHANNEL_WEBMCP
            && $event['operation'] === 'add_to_cart'
            && $status > 0 && $status < 400
        ) {
            $this->events->markQuote(
                (int) $this->checkoutSession->getQuoteId(),
                RequestClassifier::CHANNEL_WEBMCP,
                $identity->name
            );
        }
    }

    /**
     * Name an unknown MCP caller by the clientInfo it sent (e.g. "claude-ai").
     *
     * @param Identity $identity
     * @param array<string, mixed> $event
     * @return Identity
     */
    private function mcpIdentity(Identity $identity, array $event): Identity
    {
        $client = $event['mcp']['client'] ?? null;
        if ($event['channel'] !== RequestClassifier::CHANNEL_MCP
            || $identity->source !== Identity::SOURCE_UNKNOWN
            || !is_string($client)
        ) {
            return $identity;
        }
        $known = $this->catalog->matchMcpClient($client);
        if ($known === null) {
            return $identity;
        }
        return new Identity(
            $known['name'],
            $known['operator'],
            Catalog::CATEGORY_PLATFORM,
            Identity::SOURCE_MCP_CLIENT,
            null,
            $identity->signed
        );
    }
}

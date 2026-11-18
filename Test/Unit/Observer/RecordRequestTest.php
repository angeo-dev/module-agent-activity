<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Test\Unit\Observer;

use Angeo\AgentActivity\Model\Agent\Catalog;
use Angeo\AgentActivity\Model\AgentDetector;
use Angeo\AgentActivity\Model\Config;
use Angeo\AgentActivity\Model\EventRepository;
use Angeo\AgentActivity\Model\PageCacheState;
use Angeo\AgentActivity\Model\PageTypeResolver;
use Angeo\AgentActivity\Model\RequestClassifier;
use Angeo\AgentActivity\Model\Verify\IpVerifier;
use Angeo\AgentActivity\Observer\RecordRequest;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RecordRequestTest extends TestCase
{
    /** @var array<int, array<int, mixed>> */
    private array $added = [];

    private PageTypeResolver&MockObject $pageTypes;

    private function observer(): RecordRequest
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('logCrawlers')->willReturn(true);
        $config->method('logSearchEngines')->willReturn(true);
        $config->method('verifyIp')->willReturn(false);

        $events = $this->createMock(EventRepository::class);
        $events->method('add')->willReturnCallback(function (...$args): void {
            $this->added[] = $args;
        });

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        $this->pageTypes = $this->createMock(PageTypeResolver::class);

        return new RecordRequest(
            $config,
            new RequestClassifier(),
            new AgentDetector(new Catalog()),
            $this->createMock(IpVerifier::class),
            $events,
            $stores,
            $this->createMock(RemoteAddress::class),
            $this->createMock(CheckoutSession::class),
            new Catalog(),
            new PageCacheState(),
            new NullLogger(),
            $this->pageTypes
        );
    }

    private function fire(RecordRequest $observer, string $uri, string $original, string $pathInfo, string $action): void
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getHeader')->willReturnCallback(
            static fn (string $name) => $name === 'User-Agent'
                ? 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' : false
        );
        $request->method('getRequestUri')->willReturn($uri);
        $request->method('getOriginalPathInfo')->willReturn($original);
        $request->method('getPathInfo')->willReturn($pathInfo);
        $request->method('getFullActionName')->willReturn($action);
        $request->method('getMethod')->willReturn('GET');

        $event = new Event(['request' => $request, 'response' => null]);
        $observer->execute(new Observer(['event' => $event]));
    }

    public function testRoutedPageKeepsTheRequestedUrl(): void
    {
        $observer = $this->observer();
        $this->fire(
            $observer,
            '/women/tops-women.html?climate=205&price=50-60',
            '/women/tops-women.html',
            '/catalog/category/view/id/21',
            'catalog_category_view'
        );

        self::assertCount(1, $this->added);
        [$event, , , , , , , $facts] = $this->added[0];
        self::assertSame('category', $event['operation']);
        self::assertSame('/women/tops-women.html', $event['path'], 'not the rewrite target');
        self::assertSame('climate,price', $facts['query_keys']);
        self::assertTrue($facts['has_query']);
    }

    public function testCacheHitIsTypedThroughUrlRewrites(): void
    {
        $observer = $this->observer();
        $this->pageTypes->method('actionFor')->with('/hero-hoodie.html', 1)->willReturn('catalog_product_view');
        $this->fire($observer, '/hero-hoodie.html', '/hero-hoodie.html', '/hero-hoodie.html', '__');

        [$event] = $this->added[0];
        self::assertSame('product', $event['operation']);
    }
}

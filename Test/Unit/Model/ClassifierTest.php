<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Test\Unit\Model;

use Angeo\AgentActivity\Model\RequestClassifier;
use PHPUnit\Framework\TestCase;

class ClassifierTest extends TestCase
{
    /**
     * Classify and flatten to "channel:operation#ref".
     */
    private function op(
        string $method,
        string $path,
        string $tool = '',
        bool $ai = false,
        bool $acting = false,
        string $body = ''
    ): ?string {
        $r = (new RequestClassifier())->classify($method, $path, $tool, $ai, $acting, $body);
        return $r === null ? null : $r['channel'] . ':' . $r['operation'] . ($r['ref'] !== null ? '#' . $r['ref'] : '');
    }

    public function testUcp(): void
    {
        $id = 'chk_' . str_repeat('a', 32);
        self::assertSame('ucp:profile', $this->op('GET', '/.well-known/ucp'));
        self::assertSame('ucp:catalog.search', $this->op('POST', '/ucp/v1/catalog/search'));
        self::assertSame('ucp:checkout.create', $this->op('POST', '/ucp/v1/checkout-sessions'));
        self::assertNull($this->op('GET', '/ucp/v1/checkout-sessions'));
        self::assertSame("ucp:checkout.get#$id", $this->op('GET', "/ucp/v1/checkout-sessions/$id"));
        self::assertSame("ucp:checkout.update#$id", $this->op('PUT', "/ucp/v1/checkout-sessions/$id"));
        self::assertSame("ucp:checkout.complete#$id", $this->op('POST', "/ucp/v1/checkout-sessions/$id/complete"));
        self::assertSame("ucp:checkout.cancel#$id", $this->op('POST', "/api/ucp/checkout-sessions/$id/cancel"));
        self::assertSame('ucp:checkout.continue', $this->op('GET', '/ucp/checkout/continue/' . str_repeat('0f', 24)));
        self::assertSame('ucp:order.get#000000123', $this->op('GET', '/ucp/v1/orders/000000123'));
    }

    public function testMcp(): void
    {
        $call = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'search_products']]);
        self::assertSame('mcp:tools/call:search_products', $this->op('POST', '/mcp', '', false, false, (string) $call));
        self::assertSame('mcp:initialize', $this->op('POST', '/mcp', '', false, false, '{"jsonrpc":"2.0","method":"initialize","id":1}'));
        self::assertSame('mcp:invalid', $this->op('POST', '/mcp', '', false, false, 'garbage'));
        self::assertSame('mcp:tools/list', $this->op('POST', '/mcp', '', false, false, '[{"method":"tools/list"}]'));
        self::assertNull($this->op('GET', '/mcp'));
    }

    public function testWebMcp(): void
    {
        self::assertSame('webmcp:add_to_cart', $this->op('POST', '/webmcp/cart/add'));
        self::assertSame('webmcp:get_cart', $this->op('GET', '/webmcp/cart/view'));
        self::assertSame('webmcp:search_products', $this->op('POST', '/graphql', 'search_products'));
        self::assertNull($this->op('POST', '/graphql', 'DROP TABLE'));
    }

    public function testCrawlerPages(): void
    {
        self::assertSame('crawler:llms.txt', $this->op('GET', '/llms.txt', '', true));
        self::assertSame('crawler:page', $this->op('GET', '/fusion-backpack.html', '', true));
        self::assertSame('crawler:markdown', $this->op('GET', '/fusion-backpack.html.md', '', true));
        self::assertSame('crawler:home', $this->op('HEAD', '/', '', true));
        self::assertSame('crawler:page', $this->op('GET', '/customer-service', '', true));
        self::assertSame('crawler:account', $this->op('GET', '/customer/account/login', '', true));
        self::assertNull($this->op('GET', '/fusion-backpack.html'), 'ordinary shoppers are not logged');
        self::assertNull($this->op('POST', '/customer/account/loginPost', '', true), 'crawler POSTs are not logged');
    }

    public function testActingAgentPostsAreLogged(): void
    {
        self::assertSame('crawler:cart.post', $this->op('POST', '/checkout/cart/add', '', true, true));
    }

    public function testCrawlerRowKeepsThePath(): void
    {
        $row = (new RequestClassifier())->classify('GET', '/women/tops.html', '', true);
        self::assertSame('/women/tops.html', $row['path'] ?? null);
        $api = (new RequestClassifier())->classify('POST', '/ucp/v1/catalog/search', '', true);
        self::assertNull($api['path'] ?? null, 'API calls do not store a path');
    }
}

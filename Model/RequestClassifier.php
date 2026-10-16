<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model;

/**
 * Decides whether a storefront request is agent activity, and which.
 *
 * Pure: takes the facts of a request, returns [channel, operation, ref,
 * path] or null. Channels:
 *  - ucp      UCP profile, catalog and checkout REST calls, continue_url
 *  - mcp      JSON-RPC calls to the store's MCP endpoint (/mcp)
 *  - webmcp   WebMCP tool calls from a shopper's browser
 *  - crawler  storefront pages fetched by a known AI crawler or agent
 */
class RequestClassifier
{
    public const CHANNEL_UCP     = 'ucp';
    public const CHANNEL_MCP     = 'mcp';
    public const CHANNEL_WEBMCP  = 'webmcp';
    public const CHANNEL_CRAWLER = 'crawler';

    public const OPERATION_ORDER = 'order.placed';

    private const MAX_OP = 64;
    private const MAX_PATH = 255;

    /**
     * @param string $method HTTP method
     * @param string $path URL path without query string or store code
     * @param string $toolHeader X-Angeo-Agent-Tool header (WebMCP)
     * @param bool $knownAiAgent the request comes from a known AI crawler or agent
     * @param bool $actingAgent that agent acts for a person (may POST forms)
     * @param string $body request body, read only for /mcp
     * @return array{channel: string, operation: string, ref: ?string, path: ?string}|null
     */
    public function classify(
        string $method,
        string $path,
        string $toolHeader,
        bool $knownAiAgent,
        bool $actingAgent = false,
        string $body = ''
    ): ?array {
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');

        if ($path === '/.well-known/ucp') {
            return self::row(self::CHANNEL_UCP, 'profile');
        }
        if (preg_match('#/catalog/(search|lookup|product)$#', $path, $m) && str_contains($path, '/ucp/')) {
            return self::row(self::CHANNEL_UCP, 'catalog.' . $m[1]);
        }
        if (preg_match('#/orders/([A-Za-z0-9_\-]{1,64})$#', $path, $m) && str_contains($path, '/ucp/')) {
            return self::row(self::CHANNEL_UCP, 'order.get', $m[1]);
        }
        if (preg_match('#/checkout-sessions(?:/([A-Za-z0-9_\-]{1,64}))?(?:/(complete|cancel))?$#', $path, $m)) {
            $id = $m[1] ?? '';
            if ($id === '') {
                return $method === 'POST' ? self::row(self::CHANNEL_UCP, 'checkout.create') : null;
            }
            $action = $m[2] ?? '';
            if ($action !== '') {
                return self::row(self::CHANNEL_UCP, 'checkout.' . $action, $id);
            }
            return self::row(self::CHANNEL_UCP, $method === 'PUT' ? 'checkout.update' : 'checkout.get', $id);
        }
        if (str_starts_with($path, '/ucp/checkout/continue/')) {
            return self::row(self::CHANNEL_UCP, 'checkout.continue');
        }
        if ($path === '/mcp' && $method === 'POST') {
            return self::row(self::CHANNEL_MCP, $this->mcpOperation($body));
        }
        if (preg_match('#^/webmcp/cart/(add|view)$#', $path, $m)) {
            return self::row(self::CHANNEL_WEBMCP, $m[1] === 'add' ? 'add_to_cart' : 'get_cart');
        }
        if ($toolHeader !== '' && preg_match('/^[a-z_]{1,48}$/', $toolHeader)) {
            return self::row(self::CHANNEL_WEBMCP, $toolHeader);
        }
        if ($knownAiAgent && ($method === 'GET' || $method === 'HEAD' || $actingAgent)) {
            $operation = self::pageKind($path);
            if ($method !== 'GET' && $method !== 'HEAD') {
                $operation .= '.' . strtolower($method);
            }
            return self::row(self::CHANNEL_CRAWLER, $operation, null, mb_substr($path, 0, self::MAX_PATH));
        }
        return null;
    }

    /**
     * "tools/call:search_products", "initialize", "tools/list" …
     */
    private function mcpOperation(string $body): string
    {
        $decoded = json_decode(substr($body, 0, 65536), true);
        if (!is_array($decoded)) {
            return 'invalid';
        }
        $calls = array_is_list($decoded) ? $decoded : [$decoded];
        $first = $calls[0] ?? [];
        $method = is_array($first) && is_string($first['method'] ?? null) ? $first['method'] : 'invalid';
        if ($method === 'tools/call' && is_string($first['params']['name'] ?? null)) {
            $method .= ':' . $first['params']['name'];
        }
        return mb_substr(preg_replace('/[^A-Za-z0-9_.:\/\-]/', '', $method) ?: 'invalid', 0, self::MAX_OP);
    }

    private static function pageKind(string $path): string
    {
        return match (true) {
            $path === '/'                              => 'home',
            $path === '/robots.txt'                    => 'robots.txt',
            str_starts_with($path, '/llms')            => 'llms.txt',
            $path === '/agents.md'                     => 'agents.md',
            str_ends_with($path, '.md')                => 'markdown',
            str_contains($path, 'sitemap')             => 'sitemap',
            str_starts_with($path, '/catalogsearch')   => 'search',
            str_starts_with($path, '/checkout/cart')   => 'cart',
            str_starts_with($path, '/checkout')        => 'checkout',
            str_starts_with($path, '/customer/')       => 'account',
            str_starts_with($path, '/media/')          => 'media',
            default                                    => 'page',
        };
    }

    /**
     * @return array{channel: string, operation: string, ref: ?string, path: ?string}
     */
    private static function row(string $channel, string $operation, ?string $ref = null, ?string $path = null): array
    {
        return ['channel' => $channel, 'operation' => $operation, 'ref' => $ref, 'path' => $path];
    }
}

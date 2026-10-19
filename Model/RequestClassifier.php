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
 * path, mcp] or null. For MCP calls "mcp" holds what the client said about
 * itself (clientInfo name and version, protocol version); otherwise null.
 * Channels:
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
     * @param string $fullActionName Magento route, e.g. "catalog_product_view" (crawler page type)
     * @param string $mcpMethodHeader Mcp-Method header (MCP 2026-07-28), used when the body has no method
     * @param string $mcpNameHeader Mcp-Name header (MCP 2026-07-28), used when the body has no tool name
     * @return array{channel: string, operation: string, ref: ?string, path: ?string,
     *     mcp: ?array{client: ?string, client_version: ?string, protocol: ?string}}|null
     */
    public function classify(
        string $method,
        string $path,
        string $toolHeader,
        bool $knownAiAgent,
        bool $actingAgent = false,
        string $body = '',
        string $fullActionName = '',
        string $mcpMethodHeader = '',
        string $mcpNameHeader = ''
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
            $mcp = $this->mcpRequest($body, $mcpMethodHeader, $mcpNameHeader);
            $row = self::row(self::CHANNEL_MCP, $mcp['operation']);
            $row['mcp'] = [
                'client'         => $mcp['client'],
                'client_version' => $mcp['client_version'],
                'protocol'       => $mcp['protocol'],
            ];
            return $row;
        }
        if (preg_match('#^/webmcp/cart/(add|view)$#', $path, $m)) {
            return self::row(self::CHANNEL_WEBMCP, $m[1] === 'add' ? 'add_to_cart' : 'get_cart');
        }
        if ($toolHeader !== '' && preg_match('/^[a-z_]{1,48}$/', $toolHeader)) {
            return self::row(self::CHANNEL_WEBMCP, $toolHeader);
        }
        if ($knownAiAgent && ($method === 'GET' || $method === 'HEAD' || $actingAgent)) {
            $operation = self::pageKind($path, $fullActionName);
            if ($method !== 'GET' && $method !== 'HEAD') {
                $operation .= '.' . strtolower($method);
            }
            return self::row(self::CHANNEL_CRAWLER, $operation, null, mb_substr($path, 0, self::MAX_PATH));
        }
        return null;
    }

    /**
     * Operation and client facts of an MCP JSON-RPC request.
     *
     * Operation: "tools/call:search_products", "server/discover", "tools/list" …
     * Client: clientInfo from "initialize" (MCP up to 2025-11-25) or from
     * params._meta["io.modelcontextprotocol/clientInfo"] (stateless MCP
     * 2026-07-28, sent on every request). Headers Mcp-Method / Mcp-Name fill
     * the gaps when the body is batched or empty.
     *
     * @return array{operation: string, client: ?string, client_version: ?string, protocol: ?string}
     */
    public function mcpRequest(string $body, string $methodHeader = '', string $nameHeader = ''): array
    {
        $decoded = json_decode(substr($body, 0, 65536), true);
        $calls = is_array($decoded) ? (array_is_list($decoded) ? $decoded : [$decoded]) : [];
        $first = is_array($calls[0] ?? null) ? $calls[0] : [];
        $params = is_array($first['params'] ?? null) ? $first['params'] : [];
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];

        $method = is_string($first['method'] ?? null) ? $first['method'] : trim($methodHeader);
        if ($method === '') {
            $method = 'invalid';
        }
        $tool = is_string($params['name'] ?? null) ? $params['name'] : trim($nameHeader);
        if ($method === 'tools/call' && $tool !== '') {
            $method .= ':' . $tool;
        }

        $info = $params['clientInfo'] ?? $meta['io.modelcontextprotocol/clientInfo'] ?? null;
        $info = is_array($info) ? $info : [];
        $protocol = $params['protocolVersion'] ?? $meta['io.modelcontextprotocol/protocolVersion'] ?? null;

        return [
            'operation'      => mb_substr(preg_replace('/[^A-Za-z0-9_.:\/\-]/', '', $method) ?: 'invalid', 0, self::MAX_OP),
            'client'         => self::clean($info['name'] ?? null, 64),
            'client_version' => self::clean($info['version'] ?? null, 32),
            'protocol'       => self::clean($protocol, 16),
        ];
    }

    /**
     * Whether a JSON-RPC response body (or SSE stream) reports an error.
     * MCP servers answer errors with HTTP 200, so the status alone misses them.
     */
    public static function isRpcError(string $responseBody): bool
    {
        if ($responseBody === ''
            || (!str_contains($responseBody, '"error"') && !preg_match('/"isError"\s*:\s*true/', $responseBody))
        ) {
            return false;
        }
        $text = ltrim($responseBody);
        if (str_starts_with($text, 'event:') || str_starts_with($text, 'data:')) {
            foreach (explode("\n", $text) as $line) {
                if (str_starts_with($line, 'data:') && self::jsonHasError(substr($line, 5))) {
                    return true;
                }
            }
            return false;
        }
        return self::jsonHasError($text);
    }

    private static function jsonHasError(string $json): bool
    {
        $decoded = json_decode(trim($json), true);
        if (!is_array($decoded)) {
            return false;
        }
        $rows = array_is_list($decoded) ? $decoded : [$decoded];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['error'])) {
                return true;
            }
            // tools/call result with isError: the tool ran and failed.
            if (is_array($row) && ($row['result']['isError'] ?? false) === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * A short, printable client fact, or null.
     */
    private static function clean(mixed $value, int $max): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }
        $text = trim((string) preg_replace('/[^\x20-\x7E]/', '', (string) $value));
        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /**
     * Page type: the Magento route first (reliable for rewritten URLs), then the path.
     */
    private static function pageKind(string $path, string $fullActionName = ''): string
    {
        $byAction = match (true) {
            $fullActionName === 'catalog_product_view'           => 'product',
            $fullActionName === 'catalog_category_view'          => 'category',
            $fullActionName === 'cms_index_index'                => 'home',
            $fullActionName === 'cms_page_view'                  => 'cms',
            $fullActionName === 'cms_noroute_index'              => 'not_found',
            str_starts_with($fullActionName, 'catalogsearch_')   => 'search',
            str_starts_with($fullActionName, 'checkout_cart_')   => 'cart',
            str_starts_with($fullActionName, 'checkout_')        => 'checkout',
            str_starts_with($fullActionName, 'customer_')        => 'account',
            str_starts_with($fullActionName, 'wishlist_')        => 'account',
            str_starts_with($fullActionName, 'review_product_')  => 'review',
            default                                              => null,
        };
        if ($byAction !== null && !in_array($path, ['/robots.txt', '/agents.md'], true)
            && !str_starts_with($path, '/llms')
        ) {
            return $byAction;
        }
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
            str_starts_with($path, '/catalog/product/view')  => 'product',
            str_starts_with($path, '/catalog/category/view') => 'category',
            default                                    => 'page',
        };
    }

    /**
     * @return array{channel: string, operation: string, ref: ?string, path: ?string, mcp: null}
     */
    private static function row(string $channel, string $operation, ?string $ref = null, ?string $path = null): array
    {
        return ['channel' => $channel, 'operation' => $operation, 'ref' => $ref, 'path' => $path, 'mcp' => null];
    }
}

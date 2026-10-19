<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model\Insights;

/**
 * Turns report facts into findings a store owner can act on.
 *
 * Pure: takes the facts gathered by Model\Insights, returns findings sorted
 * by level. Each finding has a stable code (for CLI, JSON and monitoring),
 * a level, a short title, one or two sentences of detail and the number it
 * is based on. Texts are plain English and go through __() in the block.
 *
 * Thresholds are deliberately low: the report is read by people, and a
 * small store with ten crawler visits still wants to know that half of them
 * went to the login page.
 */
class Rules
{
    public const CRITICAL = 'critical';
    public const WARNING = 'warning';
    public const INFO = 'info';
    public const GOOD = 'good';

    private const LEVEL_ORDER = [self::CRITICAL => 0, self::WARNING => 1, self::INFO => 2, self::GOOD => 3];

    /** Crawler page average above this (ms) is called slow. */
    public const SLOW_MS = 800;

    /** Fewer days of data than this gets a "young data" note. */
    public const YOUNG_DAYS = 7;

    /** AI categories: the ones an AEO store cares about. */
    private const AI_CATEGORIES = ['training', 'search', 'user_fetch', 'agent'];

    /**
     * @param array<string, mixed> $facts see Model\Insights::facts()
     * @return array<int, array{code: string, level: string, title: string, detail: string, count: int,
     *     args: array<int, string|int>}>
     */
    public function evaluate(array $facts): array
    {
        $out = [];
        $totals = $facts['totals'] ?? [];
        $mcp = $facts['mcp'] ?? [];

        if (($totals['spoofed'] ?? 0) > 0) {
            $out[] = self::finding(
                'spoofed_bots',
                self::CRITICAL,
                'Fake AI bots',
                '%1 calls used the name of a known bot but came from outside its published IP ranges. '
                . 'Treat them as unknown scrapers; block them at the CDN or WAF if they are heavy.',
                (int) $totals['spoofed'],
                [(int) $totals['spoofed']]
            );
        }

        if (($totals['behind_proxy'] ?? 0) > 0) {
            $out[] = self::finding(
                'ip_behind_proxy',
                self::WARNING,
                'IP check cannot run: Magento sees a proxy address',
                '%1 bot calls came from a private address, so they could not be checked against vendor IP ranges. '
                . 'Pass the real client IP from your load balancer, CDN or Varnish (X-Forwarded-For) and add it '
                . 'to the trusted proxies.',
                (int) $totals['behind_proxy'],
                [(int) $totals['behind_proxy']]
            );
        }

        $missingLists = array_values(array_filter(
            $facts['unchecked_with_ranges'] ?? [],
            static fn (array $r): bool => $r['calls'] > 0
        ));
        if ($missingLists !== []) {
            $calls = array_sum(array_column($missingLists, 'calls'));
            $out[] = self::finding(
                'ip_lists_missing',
                self::WARNING,
                'Vendor IP lists are not downloaded',
                '%1 calls from bots with a published IP list were not checked, because the list is not stored yet '
                . '(%2). '
                . 'Run bin/magento angeo:agents:refresh-ranges and check that cron runs.',
                $calls,
                [$calls, implode(', ', array_column($missingLists, 'agent'))]
            );
        }

        // MCP: clients that look but never use a tool.
        $mcpLook = (int) ($mcp['discover'] ?? 0) + (int) ($mcp['list'] ?? 0);
        $mcpCalls = (int) ($mcp['tool_calls'] ?? 0);
        if ($mcpLook > 0 && $mcpCalls === 0) {
            $out[] = self::finding(
                'mcp_no_tool_calls',
                self::WARNING,
                'MCP clients connect but never call a tool',
                '%1 discovery and list calls, 0 tool calls. Clients see the server but do not use it. Check tool '
                . 'names and descriptions (they decide when an assistant picks a tool), and test the server from '
                . 'a real client.',
                $mcpLook,
                [$mcpLook]
            );
        } elseif ($mcpCalls > 0 && (int) ($mcp['cart'] ?? 0) === 0) {
            $out[] = self::finding(
                'mcp_no_cart',
                self::INFO,
                'MCP tools are used, but no cart was built',
                '%1 tool calls, none of them built a cart. Agents search and read, but do not buy yet.',
                $mcpCalls,
                [$mcpCalls]
            );
        }
        if ((int) ($mcp['checkout'] ?? 0) > 0 && (int) ($mcp['orders'] ?? 0) === 0) {
            $out[] = self::finding(
                'mcp_checkout_no_orders',
                self::WARNING,
                'MCP checkouts do not end in orders',
                '%1 checkout tool calls and no agent-built order. Check the checkout handoff (payment link, '
                . 'shipping methods) from a real client.',
                (int) $mcp['checkout'],
                [(int) $mcp['checkout']]
            );
        }
        $unnamed = (int) ($facts['mcp_unnamed_calls'] ?? 0);
        if ($unnamed > 0) {
            $out[] = self::finding(
                'mcp_unnamed_clients',
                self::INFO,
                'Some MCP clients do not say who they are',
                '%1 MCP calls came without clientInfo or a known user agent, so they show as "unknown". '
                . 'This is common for test tools and proxies.',
                $unnamed,
                [$unnamed]
            );
        }
        if (($totals['rpc_errors'] ?? 0) > 0) {
            $out[] = self::finding(
                'mcp_rpc_errors',
                self::WARNING,
                'MCP calls fail with HTTP 200',
                '%1 MCP responses carried a JSON-RPC error or a failed tool result. They count as errors here, '
                . 'but a web server log shows them as success.',
                (int) $totals['rpc_errors'],
                [(int) $totals['rpc_errors']]
            );
        }

        // Crawlers.
        foreach ($facts['reach'] ?? [] as $agent) {
            if (!in_array($agent['category'], self::AI_CATEGORIES, true)) {
                continue;
            }
            if ($agent['robots'] > 0 && $agent['pages'] === 0 && $agent['llms'] === 0) {
                $out[] = self::finding(
                    'robots_only:' . $agent['agent'],
                    self::WARNING,
                    '%1 reads robots.txt but no pages',
                    '%2 read robots.txt %3 times and fetched no pages. Check that robots.txt allows it and lists '
                    . 'the sitemap; it may also still be deciding whether to crawl.',
                    $agent['robots'],
                    [$agent['agent'], $agent['operator'] !== '' ? $agent['operator'] . ' ' . $agent['agent']
                        : $agent['agent'], $agent['robots']]
                );
            }
        }

        $aiPages = (int) ($facts['ai_crawler_calls'] ?? 0);
        $llms = (int) ($facts['llms_calls'] ?? 0);
        if ($aiPages > 0 && $llms === 0) {
            $out[] = self::finding(
                'no_llms_txt',
                self::INFO,
                'No AI crawler read llms.txt',
                'AI crawlers made %1 calls, none to /llms.txt. Most crawlers do not read it yet; check that the file '
                . 'exists and that robots.txt or the home page links to it.',
                $aiPages,
                [$aiPages]
            );
        } elseif ($llms > 0) {
            $out[] = self::finding(
                'llms_txt_read',
                self::GOOD,
                'llms.txt is being read',
                'AI crawlers fetched llms.txt %1 times.',
                $llms,
                [$llms]
            );
        }

        $crawlerCalls = (int) ($facts['crawler_calls'] ?? 0);
        $waste = (int) ($facts['waste_calls'] ?? 0);
        if ($waste > 0 && $crawlerCalls > 0) {
            $share = (int) round($waste / $crawlerCalls * 100);
            $out[] = self::finding(
                'crawl_waste',
                $share >= 5 ? self::WARNING : self::INFO,
                'Crawlers fetch account, cart, checkout or search pages',
                '%1 crawler calls (%2% of all) went to pages with no content for them: %3. Add Disallow rules for '
                . '/customer/, /checkout/, /wishlist/ and /catalogsearch/ to robots.txt.',
                $waste,
                [$waste, $share, (string) ($facts['waste_detail'] ?? '')]
            );
        }

        $raw = (int) ($facts['raw_urls']['calls'] ?? 0);
        if ($raw > 0) {
            $examples = array_map(
                static fn (array $r): string => $r['path'],
                array_slice($facts['raw_urls']['paths'] ?? [], 0, 3)
            );
            $out[] = self::finding(
                'raw_urls',
                self::WARNING,
                'Crawlers use URLs without a URL rewrite',
                '%1 fetches of system URLs such as %2. These pages compete with the real product and category '
                . 'URLs. Check the canonical tag, the sitemap and internal links that point to /catalog/…/view/id/.',
                $raw,
                [$raw, implode(', ', $examples)]
            );
        }

        $queries = (int) ($totals['query_calls'] ?? 0);
        if ($queries > 0 && $crawlerCalls > 0 && $queries / $crawlerCalls >= 0.1) {
            $share = (int) round($queries / $crawlerCalls * 100);
            $out[] = self::finding(
                'query_urls',
                self::INFO,
                'Many crawler calls carry a query string',
                '%1 calls (%2%) had URL parameters: layered navigation filters, sorting or tracking. These create '
                . 'many copies of the same page.',
                $queries,
                [$queries, $share]
            );
        }

        $pageMs = (int) ($facts['page_avg_ms'] ?? 0);
        if ($pageMs >= self::SLOW_MS) {
            $hits = (int) ($totals['cache_hits'] ?? 0);
            $misses = (int) ($totals['cache_misses'] ?? 0);
            $cache = $hits + $misses > 0
                ? sprintf('Full page cache hit rate for crawlers: %d%%.', (int) round($hits / ($hits + $misses) * 100))
                : 'Cache hits are unknown (Varnish or the cache is off).';
            $out[] = self::finding(
                'slow_pages',
                self::WARNING,
                'Pages are slow for crawlers',
                'Crawler page requests take %1 ms on average. %2 Slow pages get crawled less.',
                $pageMs,
                [$pageMs, $cache]
            );
        }

        foreach (array_slice($facts['error_paths'] ?? [], 0, 1) as $error) {
            $count = array_sum(array_column($facts['error_paths'], 'calls'));
            $out[] = self::finding(
                'agent_errors',
                self::WARNING,
                'Agents and crawlers get error pages',
                '%1 calls ended with 4xx or 5xx. Most common: %2 (HTTP %3, %4 times).',
                $count,
                [$count, $error['path'], $error['status'], $error['calls']]
            );
        }

        if (($facts['user_fetch_calls'] ?? 0) === 0 && $aiPages > 0) {
            $out[] = self::finding(
                'no_user_fetch',
                self::INFO,
                'No assistant opened a page for a person yet',
                'No ChatGPT-User, Claude-User, Perplexity-User or similar fetches. These show that real people '
                . 'asked an assistant about your store.',
                0,
                []
            );
        }

        $days = (int) ($facts['days_of_data'] ?? 0);
        if ($days > 0 && $days < self::YOUNG_DAYS) {
            $out[] = self::finding(
                'young_data',
                self::INFO,
                'Only a few days of data',
                'Tracking started %1 (%2 days of data). Read the numbers as signals, not trends.',
                $days,
                [(string) ($facts['first_day'] ?? ''), $days]
            );
        }

        usort(
            $out,
            static fn (array $a, array $b): int
                => (self::LEVEL_ORDER[$a['level']] <=> self::LEVEL_ORDER[$b['level']]) ?: ($b['count'] <=> $a['count'])
        );
        return $out;
    }

    /**
     * @param array<int, string|int> $args placeholders %1, %2 … in title and detail
     * @return array{code: string, level: string, title: string, detail: string, count: int,
     *     args: array<int, string|int>}
     */
    private static function finding(
        string $code,
        string $level,
        string $title,
        string $detail,
        int $count,
        array $args
    ): array {
        return [
            'code'   => $code,
            'level'  => $level,
            'title'  => $title,
            'detail' => $detail,
            'count'  => $count,
            'args'   => $args,
        ];
    }

    /**
     * Fill %1, %2 … for CLI and JSON (the admin block uses __() instead).
     */
    public static function render(string $text, array $args): string
    {
        $replace = [];
        foreach (array_values($args) as $i => $value) {
            $replace['%' . ($i + 1)] = (string) $value;
        }
        return strtr($text, $replace);
    }
}

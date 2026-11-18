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
 * by level and then by impact. Each finding has a stable code (for CLI,
 * JSON and monitoring), a level, a short title, one or two sentences of
 * detail, the number it is based on ("count", which may be a time in ms)
 * and the number of calls it affects ("weight", used for sorting). Texts
 * are plain English and go through __() in the block.
 *
 * Thresholds are low: a small store with ten crawler visits still wants to
 * know that half of them went to the login page. But a single event is not
 * a pattern, so error and funnel findings need MIN_SAMPLE events.
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

    /** Fewer events than this are not reported as a pattern (errors, MCP funnel). */
    public const MIN_SAMPLE = 3;

    /** AI categories: the ones an AEO store cares about. */
    private const AI_CATEGORIES = ['training', 'search', 'user_fetch', 'agent'];

    /**
     * @param array<string, mixed> $facts see Model\Insights::facts()
     * @return array<int, array{code: string, level: string, title: string, detail: string, count: int,
     *     weight: int, args: array<int, string|int>}>
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
            $status = $facts['ranges_status'] ?? null;
            if (!is_array($status)) {
                $last = 'The download never ran: cron is probably not running.';
            } elseif ((int) $status['failed'] > 0) {
                $errors = is_array($status['errors'] ?? null) ? $status['errors'] : [];
                $last = sprintf(
                    'Last download %s: %d of %d lists failed (%s). Check outgoing HTTPS from the server.',
                    gmdate('Y-m-d H:i', (int) $status['ran_at']) . ' UTC',
                    (int) $status['failed'],
                    (int) $status['failed'] + (int) $status['ok'],
                    (string) (reset($errors) ?: 'unknown error')
                );
            } else {
                $last = sprintf(
                    'Last download %s was fine; these lists are new. Run the command again.',
                    gmdate('Y-m-d H:i', (int) $status['ran_at']) . ' UTC'
                );
            }
            $out[] = self::finding(
                'ip_lists_missing',
                self::WARNING,
                'Bot IP check is not working: vendor IP lists are missing',
                '%1 calls from bots with a published IP list were not checked (%2), so fake bots cannot be told '
                . 'apart. %3 Run bin/magento angeo:agents:refresh-ranges and check that cron runs.',
                $calls,
                [$calls, implode(', ', array_column($missingLists, 'agent')), $last],
                $calls
            );
        }

        // MCP: clients that look but never use a tool.
        $mcpLook = (int) ($mcp['discover'] ?? 0) + (int) ($mcp['list'] ?? 0);
        $mcpCalls = (int) ($mcp['tool_calls'] ?? 0);
        if ($mcpLook >= self::MIN_SAMPLE && $mcpCalls === 0) {
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
        } elseif ($mcpCalls >= self::MIN_SAMPLE && (int) ($mcp['cart'] ?? 0) === 0) {
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
                . '/customer/, /checkout/, /wishlist/ and /catalogsearch/ to robots.txt: in the User-agent: * group '
                . 'and in every bot group, because a bot with its own group ignores the * group. '
                . 'angeo/module-robots-txt-aeo 4.1+ does this (Crawl Hygiene).',
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
                . 'URLs. Check the canonical tag, the sitemap, product feeds and internal links that point to '
                . '/catalog/…/view/id/.',
                $raw,
                [$raw, implode(', ', $examples)]
            );
        }

        $queries = (int) ($totals['query_calls'] ?? 0);
        if ($queries > 0 && $crawlerCalls > 0 && $queries / $crawlerCalls >= 0.1) {
            $share = (int) round($queries / $crawlerCalls * 100);
            $keys = array_map(
                static fn (array $k): string => $k['key'] . ' (' . $k['calls'] . ')',
                array_slice($facts['query_keys'] ?? [], 0, 5)
            );
            $out[] = self::finding(
                'query_urls',
                $share >= 30 ? self::WARNING : self::INFO,
                'Many crawler calls carry a query string',
                '%1 calls (%2%) had URL parameters: layered navigation filters, sorting or tracking. These create '
                . 'many copies of the same page. %3',
                $queries,
                [$queries, $share, $keys !== []
                    ? 'Most used parameters: ' . implode(', ', $keys) . '. Block the filter ones in robots.txt '
                        . '(Disallow: /*?*name=) or make them nofollow.'
                    : 'Block filter parameters in robots.txt (Disallow: /*?*name=) or make them nofollow.'],
                $queries
            );
        }

        $redirects = (int) ($facts['robots_redirects']['calls'] ?? 0);
        if ($redirects > 0) {
            $out[] = self::finding(
                'robots_redirect',
                self::WARNING,
                'robots.txt answers with a redirect',
                '%1 robots.txt fetches got a 3xx answer (%2). Some crawlers do not follow it and then work '
                . 'without your rules. Serve /robots.txt with HTTP 200 on the final host (check http→https and '
                . 'www redirects).',
                $redirects,
                [$redirects, implode(', ', array_slice($facts['robots_redirects']['agents'] ?? [], 0, 5))]
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
                [$pageMs, $cache],
                (int) ($facts['page_calls'] ?? 0)
            );
        }

        foreach (array_slice($facts['error_paths'] ?? [], 0, 1) as $error) {
            $count = array_sum(array_column($facts['error_paths'], 'calls'));
            if ($count < self::MIN_SAMPLE) {
                break;
            }
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
                => (self::LEVEL_ORDER[$a['level']] <=> self::LEVEL_ORDER[$b['level']]) ?: ($b['weight'] <=> $a['weight'])
        );
        return $out;
    }

    /**
     * @param array<int, string|int> $args placeholders %1, %2 … in title and detail
     * @param int|null $weight calls affected, for sorting; defaults to $count
     * @return array{code: string, level: string, title: string, detail: string, count: int,
     *     weight: int, args: array<int, string|int>}
     */
    private static function finding(
        string $code,
        string $level,
        string $title,
        string $detail,
        int $count,
        array $args,
        ?int $weight = null
    ): array {
        return [
            'code'   => $code,
            'level'  => $level,
            'title'  => $title,
            'detail' => $detail,
            'count'  => $count,
            'weight' => $weight ?? $count,
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

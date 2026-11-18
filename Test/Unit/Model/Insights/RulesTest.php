<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Test\Unit\Model\Insights;

use Angeo\AgentActivity\Model\Insights\Rules;
use PHPUnit\Framework\TestCase;

/**
 * Facts are taken from the first three days on demo.angeo.dev (October 2026).
 */
class RulesTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function demoFacts(): array
    {
        return [
            'totals' => [
                'spoofed' => 0, 'behind_proxy' => 0, 'rpc_errors' => 0, 'cache_hits' => 0, 'cache_misses' => 0,
                'query_calls' => 0,
            ],
            'mcp' => ['discover' => 26, 'list' => 36, 'tool_calls' => 0, 'cart' => 0, 'checkout' => 0, 'orders' => 0],
            'mcp_unnamed_calls' => 62,
            'reach' => [
                ['agent' => 'googlebot', 'operator' => 'Google', 'category' => 'search_engine', 'robots' => 40,
                    'llms' => 0, 'pages' => 110, 'unchecked' => 150],
                ['agent' => 'openai-search', 'operator' => 'OpenAI', 'category' => 'search', 'robots' => 7,
                    'llms' => 0, 'pages' => 0, 'unchecked' => 7],
                ['agent' => 'bytedance', 'operator' => 'ByteDance', 'category' => 'training', 'robots' => 1,
                    'llms' => 0, 'pages' => 8, 'unchecked' => 9],
            ],
            'crawler_calls' => 211,
            'ai_crawler_calls' => 25,
            'user_fetch_calls' => 0,
            'llms_calls' => 0,
            'waste_calls' => 11,
            'waste_detail' => 'account 11',
            'raw_urls' => ['calls' => 124, 'paths' => [['path' => '/catalog/category/view/id/21', 'calls' => 42]]],
            'page_avg_ms' => 1048,
            'error_paths' => [],
            'unchecked_with_ranges' => [['agent' => 'googlebot', 'calls' => 150]],
            'first_day' => '2026-10-17',
            'days_of_data' => 3,
        ];
    }

    /**
     * @return array<string, string> code => level
     */
    private function codes(array $facts): array
    {
        $out = [];
        foreach ((new Rules())->evaluate($facts) as $f) {
            $out[$f['code']] = $f['level'];
        }
        return $out;
    }

    public function testDemoStoreFindings(): void
    {
        $codes = $this->codes($this->demoFacts());

        self::assertSame(Rules::WARNING, $codes['mcp_no_tool_calls'] ?? null);
        self::assertSame(Rules::WARNING, $codes['robots_only:openai-search'] ?? null);
        self::assertArrayNotHasKey('robots_only:googlebot', $codes, 'search engines are not AEO findings');
        self::assertArrayNotHasKey('robots_only:bytedance', $codes, 'bytedance fetched pages');
        self::assertSame(Rules::WARNING, $codes['crawl_waste'] ?? null, '11 of 211 is over 5%');
        self::assertSame(Rules::WARNING, $codes['raw_urls'] ?? null);
        self::assertSame(Rules::WARNING, $codes['slow_pages'] ?? null);
        self::assertSame(Rules::WARNING, $codes['ip_lists_missing'] ?? null);
        self::assertSame(Rules::INFO, $codes['no_llms_txt'] ?? null);
        self::assertSame(Rules::INFO, $codes['mcp_unnamed_clients'] ?? null);
        self::assertSame(Rules::INFO, $codes['no_user_fetch'] ?? null);
        self::assertSame(Rules::INFO, $codes['young_data'] ?? null);
        self::assertArrayNotHasKey('spoofed_bots', $codes);
    }

    public function testCriticalComesFirst(): void
    {
        $facts = $this->demoFacts();
        $facts['totals']['spoofed'] = 3;
        $findings = (new Rules())->evaluate($facts);
        self::assertSame('spoofed_bots', $findings[0]['code']);
    }

    public function testHealthyMcpHasNoMcpWarning(): void
    {
        $facts = $this->demoFacts();
        $facts['mcp'] = ['discover' => 5, 'list' => 5, 'tool_calls' => 40, 'cart' => 4, 'checkout' => 2, 'orders' => 1];
        $codes = $this->codes($facts);
        self::assertArrayNotHasKey('mcp_no_tool_calls', $codes);
        self::assertArrayNotHasKey('mcp_no_cart', $codes);
        self::assertArrayNotHasKey('mcp_checkout_no_orders', $codes);
    }

    public function testRenderFillsPlaceholders(): void
    {
        self::assertSame('7 of 70%', Rules::render('%1 of %2%', [7, 70]));
    }

    /**
     * The 30-day demo export (2026-10-17 … 10-24): findings sort by the calls
     * they affect, a single error is not a pattern, and a single tool call
     * does not raise the "no cart" note.
     */
    public function testImpactOrderAndMinimumSample(): void
    {
        $facts = $this->demoFacts();
        $facts['mcp'] = ['discover' => 189, 'list' => 201, 'tool_calls' => 1, 'cart' => 0, 'checkout' => 0, 'orders' => 0];
        $facts['raw_urls'] = ['calls' => 474, 'paths' => [['path' => '/catalog/category/view/id/21', 'calls' => 129]]];
        $facts['unchecked_with_ranges'] = [['agent' => 'googlebot', 'calls' => 710]];
        $facts['page_avg_ms'] = 1080;
        $facts['page_calls'] = 300;
        $facts['error_paths'] = [['path' => 'initialize', 'status' => 401, 'calls' => 1, 'agents' => 1]];

        $findings = (new Rules())->evaluate($facts);
        $codes = array_column($findings, 'code');

        self::assertSame(['ip_lists_missing', 'raw_urls', 'slow_pages'], array_slice($codes, 0, 3));
        self::assertNotContains('agent_errors', $codes);
        self::assertNotContains('mcp_no_cart', $codes);
        self::assertStringContainsString('never ran', Rules::render($findings[0]['detail'], $findings[0]['args']));
    }

    public function testIpListStatusExplainsFailedDownload(): void
    {
        $facts = $this->demoFacts();
        $facts['ranges_status'] = [
            'ran_at' => 1761000000, 'ok' => 8, 'failed' => 2,
            'errors' => ['https://openai.com/gptbot.json' => 'failed: HTTP 403'],
        ];
        $finding = array_values(array_filter(
            (new Rules())->evaluate($facts),
            static fn (array $f): bool => $f['code'] === 'ip_lists_missing'
        ))[0];

        self::assertStringContainsString('2 of 10 lists failed (failed: HTTP 403)', Rules::render($finding['detail'], $finding['args']));
    }

    public function testQueryKeysAndRobotsRedirect(): void
    {
        $facts = $this->demoFacts();
        $facts['totals']['query_calls'] = 100;
        $facts['query_keys'] = [['key' => 'climate', 'calls' => 60], ['key' => 'price', 'calls' => 40]];
        $facts['robots_redirects'] = ['calls' => 2, 'agents' => ['openai-search', 'anthropic-training']];

        $findings = [];
        foreach ((new Rules())->evaluate($facts) as $f) {
            $findings[$f['code']] = Rules::render($f['detail'], $f['args']);
        }

        self::assertStringContainsString('climate (60), price (40)', $findings['query_urls'] ?? '');
        self::assertStringContainsString('openai-search, anthropic-training', $findings['robots_redirect'] ?? '');
    }
}

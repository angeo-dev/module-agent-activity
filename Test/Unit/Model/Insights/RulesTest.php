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
}

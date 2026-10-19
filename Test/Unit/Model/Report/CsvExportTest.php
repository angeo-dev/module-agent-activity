<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Test\Unit\Model\Report;

use Angeo\AgentActivity\Model\Insights;
use Angeo\AgentActivity\Model\Report;
use Angeo\AgentActivity\Model\Report\CsvExport;
use Angeo\AgentActivity\Model\Report\Filter;
use PHPUnit\Framework\TestCase;

class CsvExportTest extends TestCase
{
    public function testSectionsAndFormulaInjection(): void
    {
        $report = $this->createMock(Report::class);
        $report->method('dailyByChannel')->willReturn([
            'days' => ['2026-10-15', '2026-10-16'],
            'channels' => ['ucp' => [3, 4], 'crawler' => [10, 0]],
        ]);
        $report->method('byAgent')->willReturn([[
            'agent' => '=HYPERLINK("x")', 'operator' => 'OpenAI', 'category' => 'training', 'calls' => 5,
            'errors' => 0, 'verified' => 5, 'spoofed' => 0, 'signed' => 0, 'last_day' => '2026-10-16',
        ]]);
        $report->method('byOperation')->willReturn([]);
        $report->method('byCategory')->willReturn([]);
        $report->method('topPaths')->willReturn([]);
        $report->method('ordersByChannel')->willReturn([]);
        $report->method('recentOrders')->willReturn([]);

        $insights = $this->createMock(Insights::class);
        $insights->method('build')->willReturn([[
            'code' => 'mcp_no_tool_calls', 'level' => 'warning', 'title' => 'MCP clients connect',
            'detail' => '%1 discovery calls', 'count' => 62, 'args' => [62],
        ]]);
        $export = new CsvExport($report, $insights);
        $filter = new Filter('2026-10-15', '2026-10-16');
        $csv = $export->build($filter);

        self::assertStringContainsString("\"day\",\"ucp\",\"crawler\"\r\n\"2026-10-15\",\"3\",\"10\"", $csv);
        self::assertStringContainsString('"\'=HYPERLINK(""x"")"', $csv, 'formula cells are neutralised');
        self::assertStringContainsString('"# Findings"', $csv);
        self::assertStringContainsString('"62 discovery calls"', $csv, 'placeholders are filled');

        $agents = $export->build($filter, 'agents');
        self::assertStringStartsWith('"agent","operator","category","calls"', $agents, 'one section is a plain table');
        self::assertStringNotContainsString('# ', $agents);

        $json = json_decode($export->buildJson($filter), true);
        self::assertSame('mcp_no_tool_calls', $json['findings'][0]['code'] ?? null);
    }

    public function testNegativeNumbersStayNumbers(): void
    {
        $method = new \ReflectionMethod(CsvExport::class, 'cell');
        $method->setAccessible(true);
        $export = (new \ReflectionClass(CsvExport::class))->newInstanceWithoutConstructor();
        self::assertSame('"-5"', $method->invoke($export, -5));
        self::assertSame('"\'-x"', $method->invoke($export, '-x'));
    }
}


<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Test\Unit\Model\Report;

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

        $csv = (new CsvExport($report))->build(new Filter('2026-10-15', '2026-10-16'));

        self::assertStringContainsString("\"day\",\"ucp\",\"crawler\"\r\n\"2026-10-15\",\"3\",\"10\"", $csv);
        self::assertStringContainsString('"\'=HYPERLINK(""x"")"', $csv, 'formula cells are neutralised');
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


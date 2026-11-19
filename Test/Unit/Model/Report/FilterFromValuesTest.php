<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Test\Unit\Model\Report;

use Angeo\AgentActivity\Model\Report\FilterResolver;
use Angeo\AgentActivity\Model\Report\Help;
use PHPUnit\Framework\TestCase;

class FilterFromValuesTest extends TestCase
{
    public function testPresetPeriodAndStore(): void
    {
        $filter = (new FilterResolver())->fromValues(['period' => '7', 'store' => '2']);

        self::assertSame(7, $filter->days());
        self::assertSame(2, $filter->storeId);
    }

    public function testUnknownPeriodFallsBackToDefault(): void
    {
        $filter = (new FilterResolver())->fromValues(['period' => '365', 'store' => 'x']);

        self::assertSame(FilterResolver::DEFAULT_PERIOD, $filter->days());
        self::assertNull($filter->storeId);
    }

    public function testCustomRangeWins(): void
    {
        $filter = (new FilterResolver())->fromValues(['period' => '7', 'from' => '2026-10-01', 'to' => '2026-10-10']);

        self::assertSame('2026-10-01', $filter->from);
        self::assertSame('2026-10-10', $filter->to);
    }

    public function testEverySectionHasHelp(): void
    {
        $help = new Help();
        foreach (['summary', 'findings', 'agents', 'mcp_clients', 'tools', 'content', 'orders', 'operations', 'paths'] as $section) {
            self::assertTrue($help->get($section) !== '', $section);
        }
        self::assertSame('', $help->get('nope'));
    }
}

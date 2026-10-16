<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Test\Unit\Model\Report;

use Angeo\AgentActivity\Model\Report\Filter;
use PHPUnit\Framework\TestCase;

class FilterTest extends TestCase
{
    public function testLastDaysIncludesToday(): void
    {
        $f = Filter::lastDays(7, null, '2026-10-16');
        self::assertSame('2026-10-10', $f->from);
        self::assertSame('2026-10-16', $f->to);
        self::assertSame(7, $f->days());
        self::assertCount(7, $f->dayList());
        self::assertSame('2026-10-10', $f->dayList()[0]);
    }

    public function testPreviousPeriodHasTheSameLength(): void
    {
        $p = Filter::lastDays(30, 2, '2026-10-16')->previous();
        self::assertSame('2026-08-18', $p->from);
        self::assertSame('2026-09-16', $p->to);
        self::assertSame(30, $p->days());
        self::assertSame(2, $p->storeId);
    }

    public function testRangeIsValidatedSwappedAndClamped(): void
    {
        self::assertNull(Filter::range('2026-02-30', '2026-03-01'));
        self::assertNull(Filter::range('yesterday', '2026-03-01'));
        $swapped = Filter::range('2026-03-10', '2026-03-01');
        self::assertSame(['2026-03-01', '2026-03-10'], [$swapped->from, $swapped->to]);
        $long = Filter::range('2020-01-01', '2026-10-16');
        self::assertSame(Filter::MAX_DAYS, $long->days());
    }

    public function testMonthAndLeapBoundaries(): void
    {
        $f = Filter::range('2028-02-27', '2028-03-01');
        self::assertSame(['2028-02-27', '2028-02-28', '2028-02-29', '2028-03-01'], $f->dayList());
    }
}

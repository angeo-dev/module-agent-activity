<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model\Report;

/**
 * Report scope: a range of UTC days and an optional store view.
 */
class Filter
{
    public const MAX_DAYS = 730;

    /**
     * @param string $from first day, Y-m-d (UTC)
     * @param string $to last day, Y-m-d (UTC), inclusive
     * @param int|null $storeId store view, or null for all
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?int $storeId = null
    ) {
    }

    /**
     * The last $days days up to and including $today.
     */
    public static function lastDays(int $days, ?int $storeId = null, ?string $today = null): self
    {
        $days = max(1, min(self::MAX_DAYS, $days));
        $end = $today ?? gmdate('Y-m-d');
        return new self(self::shift($end, -($days - 1)), $end, $storeId);
    }

    /**
     * A custom range; swapped when reversed, clamped to MAX_DAYS. Null when
     * either date is not a valid Y-m-d.
     */
    public static function range(string $from, string $to, ?int $storeId = null): ?self
    {
        if (!self::isDate($from) || !self::isDate($to)) {
            return null;
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if (self::count($from, $to) > self::MAX_DAYS) {
            $from = self::shift($to, -(self::MAX_DAYS - 1));
        }
        return new self($from, $to, $storeId);
    }

    /**
     * Number of days in the range.
     */
    public function days(): int
    {
        return self::count($this->from, $this->to);
    }

    /**
     * The range of the same length right before this one.
     */
    public function previous(): self
    {
        $to = self::shift($this->from, -1);
        return new self(self::shift($to, -($this->days() - 1)), $to, $this->storeId);
    }

    /**
     * Every day of the range, oldest first.
     *
     * @return array<int, string>
     */
    public function dayList(): array
    {
        $days = [];
        for ($i = 0, $n = $this->days(); $i < $n; $i++) {
            $days[] = self::shift($this->from, $i);
        }
        return $days;
    }

    private static function isDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function shift(string $day, int $by): string
    {
        return gmdate('Y-m-d', (int) strtotime($day . ' 00:00:00 UTC') + 86400 * $by);
    }

    private static function count(string $from, string $to): int
    {
        return intdiv((int) strtotime($to . ' 00:00:00 UTC') - (int) strtotime($from . ' 00:00:00 UTC'), 86400) + 1;
    }
}

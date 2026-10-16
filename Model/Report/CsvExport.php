<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model\Report;

use Angeo\AgentActivity\Model\Report;

/**
 * The report as one CSV file: one block per section, separated by a blank
 * line, each with its own header row.
 */
class CsvExport
{
    public function __construct(
        private readonly Report $report
    ) {
    }

    /**
     * CSV text for the filter.
     */
    public function build(Filter $filter): string
    {
        $out = [];
        $out[] = ['AI agent activity', $filter->from, $filter->to, $filter->storeId !== null ? 'store ' . $filter->storeId : 'all stores'];

        $daily = $this->report->dailyByChannel($filter);
        $out[] = [];
        $out[] = array_merge(['day'], array_keys($daily['channels']));
        foreach ($daily['days'] as $i => $day) {
            $row = [$day];
            foreach ($daily['channels'] as $values) {
                $row[] = $values[$i];
            }
            $out[] = $row;
        }

        $this->section($out, ['agent', 'operator', 'category', 'calls', 'errors', 'verified', 'spoofed', 'signed', 'last_day'], $this->report->byAgent($filter, 1000));
        $this->section($out, ['channel', 'operation', 'calls', 'errors', 'avg_ms'], $this->report->byOperation($filter, 1000));
        $this->section($out, ['category', 'calls', 'agents'], $this->report->byCategory($filter));
        $this->section($out, ['path', 'calls', 'training', 'search', 'user_fetch', 'agent'], $this->report->topPaths($filter, 1000));
        $this->section($out, ['channel', 'currency', 'orders', 'revenue'], $this->report->ordersByChannel($filter));
        $this->section($out, ['created_at', 'channel', 'agent', 'order_id', 'increment_id', 'value', 'currency'], $this->report->recentOrders($filter, 1000));

        return $this->toCsv($out);
    }

    /**
     * @param array<int, array<int, mixed>> $out
     * @param array<int, string> $headers
     * @param array<int, array<string, mixed>> $rows
     */
    private function section(array &$out, array $headers, array $rows): void
    {
        $out[] = [];
        $out[] = $headers;
        foreach ($rows as $row) {
            $out[] = array_map(static fn (string $h) => $row[$h] ?? '', $headers);
        }
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     */
    private function toCsv(array $rows): string
    {
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map([$this, 'cell'], $row));
        }
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * One CSV cell; values that a spreadsheet would run as a formula get a
     * leading apostrophe (CSV injection).
     */
    private function cell(mixed $value): string
    {
        $text = (string) $value;
        if ($text !== '' && !is_numeric($text) && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $text = "'" . $text;
        }
        return '"' . str_replace('"', '""', $text) . '"';
    }
}

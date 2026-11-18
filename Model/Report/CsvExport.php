<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model\Report;

use Angeo\AgentActivity\Model\EventRepository;
use Angeo\AgentActivity\Model\Insights;
use Angeo\AgentActivity\Model\Insights\Rules;
use Angeo\AgentActivity\Model\Report;

/**
 * The report as CSV or JSON.
 *
 * CSV comes in three shapes: the whole report in one file (a "Report"
 * block with the period and data start, then one block per section, each
 * with its own header row), a single section as a plain table, or a ZIP
 * with one plain table per section. Plain tables open cleanly in a
 * spreadsheet and load into pandas, BigQuery or similar. JSON holds every
 * section plus the findings and the facts behind them, for scripts and
 * monitoring.
 */
class CsvExport
{
    /** Exportable sections: code => label. */
    public const SECTIONS = [
        'findings'   => 'Findings',
        'daily'      => 'Calls per day',
        'agents'     => 'Agents',
        'operations' => 'Operations',
        'categories' => 'Agent types',
        'content'    => 'What agents read',
        'paths'      => 'Most fetched pages',
        'mcp'        => 'MCP clients',
        'tools'      => 'Tool calls',
        'query_keys' => 'Query parameters',
        'orders'     => 'Agent-built orders',
    ];

    private const PAGE_CATEGORIES = [
        'training', 'search', 'user_fetch', 'agent', 'search_engine', 'other', 'platform', 'unknown',
    ];

    public function __construct(
        private readonly Report $report,
        private readonly Insights $insights,
        private readonly EventRepository $events
    ) {
    }

    /**
     * ZIP archive with report.csv (period facts) and one plain CSV per
     * section, or null when ext-zip is missing.
     */
    public function buildZip(Filter $filter): ?string
    {
        if (!class_exists(\ZipArchive::class)) {
            return null;
        }
        $file = tempnam(sys_get_temp_dir(), 'angeo-agents');
        if ($file === false) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::OVERWRITE) !== true) {
            return null;
        }
        $zip->addFromString('report.csv', $this->toCsv($this->meta($filter)));
        foreach (array_keys(self::SECTIONS) as $code) {
            $zip->addFromString($code . '.csv', $this->toCsv($this->rows($code, $filter)));
        }
        $zip->close();
        $data = (string) file_get_contents($file);
        unlink($file);
        return $data;
    }

    /**
     * CSV text for the filter: the whole report, or one section.
     */
    public function build(Filter $filter, string $section = ''): string
    {
        if ($section !== '' && isset(self::SECTIONS[$section])) {
            return $this->toCsv($this->rows($section, $filter));
        }

        $out = [['# Report']];
        foreach ($this->meta($filter) as $row) {
            $out[] = $row;
        }
        foreach (array_keys(self::SECTIONS) as $code) {
            $out[] = [];
            $out[] = ['# ' . self::SECTIONS[$code]];
            foreach ($this->rows($code, $filter) as $row) {
                $out[] = $row;
            }
        }
        return $this->toCsv($out);
    }

    /**
     * Period facts as key/value rows. "data_from" is the first day with
     * events: days before it are empty because tracking had not started.
     *
     * @return array<int, array<int, mixed>>
     */
    private function meta(Filter $filter): array
    {
        $first = $this->events->firstDay();
        return [
            ['key', 'value'],
            ['report', 'AI agent activity'],
            ['from', $filter->from],
            ['to', $filter->to],
            ['store', $filter->storeId !== null ? (string) $filter->storeId : 'all'],
            ['data_from', $first !== null ? max($first, $filter->from) : ''],
            ['generated_at', gmdate('Y-m-d H:i:s') . ' UTC'],
        ];
    }

    /**
     * The whole report as one JSON document.
     */
    public function buildJson(Filter $filter): string
    {
        $findings = array_map(static fn (array $f): array => [
            'code'   => $f['code'],
            'level'  => $f['level'],
            'title'  => Rules::render($f['title'], $f['args']),
            'detail' => Rules::render($f['detail'], $f['args']),
            'count'  => $f['count'],
        ], $this->insights->build($filter));

        return (string) json_encode([
            'from'        => $filter->from,
            'to'          => $filter->to,
            'store_id'    => $filter->storeId,
            'data_from'   => $this->events->firstDay(),
            'findings'    => $findings,
            'facts'       => $this->insights->facts($filter),
            'totals'      => $this->report->totals($filter),
            'daily'       => $this->report->dailyByChannel($filter),
            'categories'  => $this->report->byCategory($filter),
            'agents'      => $this->report->byAgent($filter, 1000),
            'operations'  => $this->report->byOperation($filter, 1000),
            'content'     => $this->report->contentMix($filter),
            'paths'       => $this->report->topPaths($filter, 1000),
            'mcp'         => [
                'funnel'   => $this->report->mcpFunnel($filter),
                'clients'  => $this->report->mcpClients($filter, 1000),
                'versions' => $this->report->mcpVersions($filter),
            ],
            'tools'       => $this->report->byTool($filter, 1000),
            'query_keys'  => $this->report->queryKeys($filter, 100),
            'status'      => $this->report->statusMix($filter),
            'orders'      => [
                'by_channel' => $this->report->ordersByChannel($filter),
                'recent'     => $this->report->recentOrders($filter, 1000),
            ],
            'ucp_funnel'  => $this->report->ucpFunnel($filter),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Header row plus data rows for one section.
     *
     * @return array<int, array<int, mixed>>
     */
    private function rows(string $section, Filter $filter): array
    {
        return match ($section) {
            'findings'   => $this->table(
                ['level', 'code', 'title', 'detail', 'count'],
                array_map(static fn (array $f): array => [
                    'level'  => $f['level'],
                    'code'   => $f['code'],
                    'title'  => Rules::render($f['title'], $f['args']),
                    'detail' => Rules::render($f['detail'], $f['args']),
                    'count'  => $f['count'],
                ], $this->insights->build($filter))
            ),
            'daily'      => $this->daily($filter),
            'agents'     => $this->table(
                ['agent', 'operator', 'category', 'calls', 'errors', 'verified', 'spoofed', 'behind_proxy',
                    'unchecked', 'signed', 'avg_ms', 'last_day'],
                $this->report->byAgent($filter, 1000)
            ),
            'operations' => $this->table(
                ['channel', 'operation', 'calls', 'errors', 'avg_ms'],
                $this->report->byOperation($filter, 1000)
            ),
            'categories' => $this->table(['category', 'calls', 'agents'], $this->report->byCategory($filter)),
            'content'    => $this->content($filter),
            'paths'      => $this->table(
                ['path', 'calls', 'training', 'search', 'user_fetch', 'agent', 'search_engine', 'other'],
                $this->report->topPaths($filter, 1000)
            ),
            'mcp'        => $this->table(
                ['agent', 'operator', 'calls', 'discover', 'list', 'tool_calls', 'errors', 'avg_ms', 'last_day'],
                $this->report->mcpClients($filter, 1000)
            ),
            'tools'      => $this->table(
                ['channel', 'tool', 'calls', 'errors', 'clients', 'avg_ms'],
                $this->report->byTool($filter, 1000)
            ),
            'query_keys' => $this->table(['key', 'calls'], $this->report->queryKeys($filter, 100)),
            'orders'     => $this->table(
                ['created_at', 'channel', 'agent', 'order_id', 'increment_id', 'value', 'currency'],
                $this->report->recentOrders($filter, 1000)
            ),
            default      => [],
        };
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function daily(Filter $filter): array
    {
        $daily = $this->report->dailyByChannel($filter);
        $out = [array_merge(['day'], array_keys($daily['channels']))];
        foreach ($daily['days'] as $i => $day) {
            $row = [$day];
            foreach ($daily['channels'] as $values) {
                $row[] = $values[$i];
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Page type x agent category, flattened to one row per page type.
     *
     * @return array<int, array<int, mixed>>
     */
    private function content(Filter $filter): array
    {
        $out = [array_merge(
            ['page_type', 'calls'],
            self::PAGE_CATEGORIES,
            ['avg_ms', 'cache_hits', 'cache_misses', 'errors', 'query_calls']
        )];
        foreach ($this->report->contentMix($filter) as $row) {
            $line = [$row['page_type'], $row['calls']];
            foreach (self::PAGE_CATEGORIES as $category) {
                $line[] = $row['by_category'][$category] ?? 0;
            }
            $out[] = array_merge($line, [
                $row['avg_ms'], $row['cache_hits'], $row['cache_misses'], $row['errors'], $row['query_calls'],
            ]);
        }
        return $out;
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<int, mixed>>
     */
    private function table(array $headers, array $rows): array
    {
        $out = [$headers];
        foreach ($rows as $row) {
            $out[] = array_map(static fn (string $h) => $row[$h] ?? '', $headers);
        }
        return $out;
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

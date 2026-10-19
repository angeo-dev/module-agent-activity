<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model;

use Angeo\AgentActivity\Model\Agent\Catalog;
use Angeo\AgentActivity\Model\Insights\Rules;
use Angeo\AgentActivity\Model\Report\Filter;
use Angeo\AgentActivity\Model\Verify\RangeStore;

/**
 * Findings for a period: gathers the facts from the report and hands them
 * to Insights\Rules. Shared by the admin report, the CLI and the exports.
 */
class Insights
{
    public function __construct(
        private readonly Report $report,
        private readonly EventRepository $events,
        private readonly Catalog $catalog,
        private readonly RangeStore $rangeStore,
        private readonly Rules $rules
    ) {
    }

    /**
     * @param Filter $filter
     * @return array<int, array{code: string, level: string, title: string, detail: string, count: int,
     *     args: array<int, string|int>}>
     */
    public function build(Filter $filter): array
    {
        return $this->rules->evaluate($this->facts($filter));
    }

    /**
     * Everything the rules read, in one array (also part of the JSON export).
     *
     * @param Filter $filter
     * @return array<string, mixed>
     */
    public function facts(Filter $filter): array
    {
        $totals = $this->report->totals($filter);
        $reach = $this->report->crawlerReach($filter);
        $mix = $this->report->contentMix($filter);

        $crawlerCalls = 0;
        $aiCalls = 0;
        $userFetch = 0;
        $llms = 0;
        $unchecked = [];
        foreach ($reach as $agent) {
            $calls = $agent['robots'] + $agent['llms'] + $agent['pages'];
            $crawlerCalls += $calls;
            if (in_array($agent['category'], ['training', 'search', 'user_fetch', 'agent'], true)) {
                $aiCalls += $calls;
                $llms += $agent['llms'];
            }
            if ($agent['category'] === Catalog::CATEGORY_USER_FETCH) {
                $userFetch += $calls;
            }
            $list = $this->catalog->rangesFor($agent['agent']);
            if ($agent['unchecked'] > 0 && $list !== null && $this->rangeStore->prefixes($list) === null) {
                $unchecked[] = ['agent' => $agent['agent'], 'calls' => $agent['unchecked']];
            }
        }

        $waste = 0;
        $wasteParts = [];
        $pageSum = 0;
        $pageCount = 0;
        foreach ($mix as $row) {
            if (in_array($row['page_type'], Report::WASTE_PAGES, true)) {
                $waste += $row['calls'];
                $wasteParts[] = $row['page_type'] . ' ' . $row['calls'];
            }
            if (!in_array($row['page_type'], ['robots.txt', 'llms.txt', 'sitemap', 'media', 'markdown'], true)
                && $row['avg_ms'] > 0
            ) {
                $pageSum += $row['avg_ms'] * $row['calls'];
                $pageCount += $row['calls'];
            }
        }

        $unnamed = 0;
        foreach ($this->report->mcpClients($filter, 1000) as $client) {
            if ($client['agent'] === 'unknown') {
                $unnamed += $client['calls'];
            }
        }

        $first = $this->events->firstDay();
        $days = $first !== null
            ? (int) floor((strtotime(gmdate('Y-m-d')) - strtotime($first)) / 86400) + 1
            : 0;

        return [
            'totals'                => $totals,
            'mcp'                   => $this->report->mcpFunnel($filter),
            'mcp_unnamed_calls'     => $unnamed,
            'reach'                 => $reach,
            'crawler_calls'         => $crawlerCalls,
            'ai_crawler_calls'      => $aiCalls,
            'user_fetch_calls'      => $userFetch,
            'llms_calls'            => $llms,
            'waste_calls'           => $waste,
            'waste_detail'          => implode(', ', $wasteParts),
            'raw_urls'              => $this->report->rawUrlFetches($filter),
            'page_avg_ms'           => $pageCount > 0 ? (int) round($pageSum / $pageCount) : 0,
            'error_paths'           => $this->report->errorPaths($filter, 5),
            'unchecked_with_ranges' => $unchecked,
            'first_day'             => $first,
            'days_of_data'          => $days,
        ];
    }
}

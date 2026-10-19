<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Block\Adminhtml;

use Angeo\AgentActivity\Model\Agent\Catalog;
use Angeo\AgentActivity\Model\Aggregator;
use Angeo\AgentActivity\Model\EventRepository;
use Angeo\AgentActivity\Model\Insights;
use Angeo\AgentActivity\Model\Report as ReportModel;
use Angeo\AgentActivity\Model\Report\CsvExport;
use Angeo\AgentActivity\Model\Report\Filter;
use Angeo\AgentActivity\Model\Report\FilterResolver;
use Angeo\AgentActivity\Model\Source\Channel;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Data for the admin report page (Reports → Marketing → AI Agent Activity).
 *
 * Every query runs once per page view and is memoised here, so the template
 * only reads.
 */
class Report extends Template
{
    /** @var string */
    protected $_template = 'Angeo_AgentActivity::report.phtml';

    /** @var array<string, mixed> */
    private array $memo = [];

    /**
     * @param Context $context
     * @param ReportModel $report
     * @param FilterResolver $filterResolver
     * @param Aggregator $aggregator
     * @param TimezoneInterface $timezone
     * @param Json $json
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly ReportModel $report,
        private readonly FilterResolver $filterResolver,
        private readonly Aggregator $aggregator,
        private readonly TimezoneInterface $timezone,
        private readonly Json $json,
        private readonly Insights $insights,
        private readonly EventRepository $events,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * The scope of the page, from the request.
     */
    public function getFilter(): Filter
    {
        return $this->memo['filter'] ??= $this->filterResolver->fromRequest($this->getRequest());
    }

    /**
     * Preset period chosen (7, 30, 90) or 0 for a custom range.
     */
    public function getPeriod(): int
    {
        return $this->filterResolver->period($this->getRequest());
    }

    /**
     * @return array<int, int>
     */
    public function getPeriods(): array
    {
        return FilterResolver::PERIODS;
    }

    /**
     * Store views for the scope switcher: id => "Website / Store / View".
     *
     * @return array<int, string>
     */
    public function getStoreOptions(): array
    {
        $options = [];
        foreach ($this->_storeManager->getStores() as $store) {
            $website = $store->getWebsite();
            $group = $store->getGroup();
            $options[(int) $store->getId()] = implode(' / ', array_filter([
                $website ? (string) $website->getName() : '',
                $group ? (string) $group->getName() : '',
                (string) $store->getName(),
            ]));
        }
        return $options;
    }

    /**
     * URL of this page with other query parameters.
     *
     * @param array<string, mixed> $params
     */
    public function getPageUrl(array $params): string
    {
        return $this->getUrl('*/*/index', ['_query' => array_filter($params, static fn ($v) => $v !== null)]);
    }

    /**
     * Query parameters that describe the current scope.
     *
     * @return array<string, mixed>
     */
    public function getScopeParams(): array
    {
        $filter = $this->getFilter();
        $params = $this->getPeriod() > 0
            ? ['period' => $this->getPeriod()]
            : ['from' => $filter->from, 'to' => $filter->to];
        if ($filter->storeId !== null) {
            $params['store'] = $filter->storeId;
        }
        return $params;
    }

    /**
     * @param string $section one of CsvExport::SECTIONS, or '' for the whole report
     * @param string $format csv or json
     */
    public function getExportUrl(string $section = '', string $format = 'csv'): string
    {
        $params = $this->getScopeParams();
        if ($section !== '') {
            $params['section'] = $section;
        }
        if ($format !== 'csv') {
            $params['format'] = $format;
        }
        return $this->getUrl('*/*/export', ['_query' => $params]);
    }

    /**
     * Sections that can be exported on their own: code => label.
     *
     * @return array<string, string>
     */
    public function getExportSections(): array
    {
        return array_map(static fn (string $label): string => (string) __($label), CsvExport::SECTIONS);
    }

    public function getRefreshUrl(): string
    {
        return $this->getUrl('*/*/refresh', ['_query' => $this->getScopeParams()]);
    }

    public function getEventsUrl(): string
    {
        return $this->getUrl('*/events/index');
    }

    public function getOrderUrl(int $orderId): string
    {
        return $this->getUrl('sales/order/view', ['order_id' => $orderId]);
    }

    public function canRefresh(): bool
    {
        return $this->_authorization->isAllowed('Angeo_AgentActivity::refresh');
    }

    /**
     * When the daily totals were last rebuilt, in the admin's time zone.
     */
    public function getLastRunLabel(): string
    {
        $last = $this->aggregator->lastRun();
        if ($last === null) {
            return (string) __('never');
        }
        return $this->timezone->formatDateTime(
            (new \DateTime('@' . $last)),
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::SHORT
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getTotals(): array
    {
        return $this->memo['totals'] ??= $this->report->totals($this->getFilter());
    }

    /**
     * @return array<string, mixed>
     */
    public function getPreviousTotals(): array
    {
        return $this->memo['previous'] ??= $this->report->totals($this->getFilter()->previous());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAgents(): array
    {
        return $this->memo['agents'] ??= $this->report->byAgent($this->getFilter(), 25);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOperations(): array
    {
        return $this->memo['operations'] ??= $this->report->byOperation($this->getFilter(), 25);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCategories(): array
    {
        return $this->memo['categories'] ??= $this->report->byCategory($this->getFilter());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPaths(): array
    {
        return $this->memo['paths'] ??= $this->report->topPaths($this->getFilter(), 20);
    }

    /**
     * @return array<string, int>
     */
    public function getStatusMix(): array
    {
        return $this->memo['status'] ??= $this->report->statusMix($this->getFilter());
    }

    /**
     * @return array{days: array<int, string>, channels: array<string, array<int, int>>}
     */
    public function getDaily(): array
    {
        return $this->memo['daily'] ??= $this->report->dailyByChannel($this->getFilter());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOrdersByChannel(): array
    {
        return $this->memo['ordersByChannel'] ??= $this->report->ordersByChannel($this->getFilter());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentOrders(): array
    {
        return $this->memo['recentOrders'] ??= $this->report->recentOrders($this->getFilter(), 20);
    }

    /**
     * @return array<string, int>|null
     */
    public function getFunnel(): ?array
    {
        if (!array_key_exists('funnel', $this->memo)) {
            $this->memo['funnel'] = $this->report->ucpFunnel($this->getFilter());
        }
        return $this->memo['funnel'];
    }

    /**
     * Findings for the period, most serious first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFindings(): array
    {
        return $this->memo['findings'] ??= $this->insights->build($this->getFilter());
    }

    /**
     * Title and detail of a finding, translated, with placeholders filled.
     *
     * @param array<string, mixed> $finding
     * @return array{title: string, detail: string}
     */
    public function findingText(array $finding): array
    {
        $args = array_values($finding['args']);
        return [
            'title'  => (string) __($finding['title'], ...$args),
            'detail' => (string) __($finding['detail'], ...$args),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function getMcpFunnel(): array
    {
        return $this->memo['mcpFunnel'] ??= $this->report->mcpFunnel($this->getFilter());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMcpClients(): array
    {
        return $this->memo['mcpClients'] ??= $this->report->mcpClients($this->getFilter(), 25);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMcpVersions(): array
    {
        return $this->memo['mcpVersions'] ??= $this->report->mcpVersions($this->getFilter());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTools(): array
    {
        return $this->memo['tools'] ??= $this->report->byTool($this->getFilter(), 50);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getContentMix(): array
    {
        return $this->memo['mix'] ??= $this->report->contentMix($this->getFilter());
    }

    /**
     * Agent categories that appear in the content mix, in display order.
     *
     * @return array<string, string> code => label
     */
    public function getMixCategories(): array
    {
        $seen = [];
        foreach ($this->getContentMix() as $row) {
            $seen += $row['by_category'];
        }
        $out = [];
        foreach (Catalog::categories() as $code => $label) {
            if (isset($seen[$code])) {
                $out[$code] = (string) __($label);
            }
        }
        return $out;
    }

    /**
     * First day with data (UTC), or null.
     */
    public function getFirstDay(): ?string
    {
        if (!array_key_exists('firstDay', $this->memo)) {
            $this->memo['firstDay'] = $this->events->firstDay();
        }
        return $this->memo['firstDay'];
    }

    /**
     * A path made readable: the base64 "referer" of a login URL is decoded.
     *
     * "/customer/account/login/referer/aHR0cHM6…" → "/customer/account/login ← /women/tops-women.html?climate=205"
     */
    public function prettyPath(string $path): string
    {
        if (!preg_match('#^(.*?)/referer/([A-Za-z0-9_\-~,]+)/?$#', $path, $m)) {
            return $path;
        }
        $decoded = base64_decode(strtr($m[2], '-_~,', '+/=='), true);
        if ($decoded === false || !preg_match('#^https?://#', $decoded)) {
            return $path;
        }
        $parts = parse_url($decoded);
        $from = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        return $m[1] . ' ← ' . $from;
    }

    /**
     * Whether a path is a system URL without a rewrite.
     */
    public function isRawUrl(string $path): bool
    {
        return (bool) preg_match('#^/catalog/(product|category)/view/#', $path);
    }

    /**
     * Human label for a channel code.
     */
    public function channelLabel(string $channel): string
    {
        return (string) __(Channel::labels()[$channel] ?? $channel);
    }

    /**
     * Human label for a category code.
     */
    public function categoryLabel(string $category): string
    {
        return (string) __(Catalog::categories()[$category] ?? $category);
    }

    /**
     * Signed change versus the previous period, as a percentage, or null.
     */
    public function delta(int|float $current, int|float $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }
        return round(($current - $previous) / $previous * 100, 1);
    }

    /**
     * Compact number: 1,284 / 12.9K / 4.2M.
     */
    public function compact(int|float $value): string
    {
        $abs = abs($value);
        return match (true) {
            $abs >= 1000000 => round($value / 1000000, 1) . 'M',
            $abs >= 10000   => round($value / 1000, 1) . 'K',
            default         => number_format((float) $value, 0),
        };
    }

    /**
     * Revenue per currency, e.g. "1,240.00 EUR · 310.00 USD".
     *
     * @param array<string, float> $revenue
     */
    public function formatRevenue(array $revenue): string
    {
        if ($revenue === []) {
            return '0';
        }
        $parts = [];
        foreach ($revenue as $currency => $amount) {
            $parts[] = trim(number_format($amount, 2) . ' ' . $currency);
        }
        return implode(' · ', $parts);
    }

    /**
     * Data for the charts (x-magento-init), as JSON.
     */
    public function getChartJson(): string
    {
        $daily = $this->getDaily();
        $series = [];
        foreach ($daily['channels'] as $channel => $values) {
            $series[] = ['key' => $channel, 'label' => $this->channelLabel($channel), 'values' => $values];
        }
        $categories = array_map(fn (array $r): array => [
            'label' => $this->categoryLabel($r['category']),
            'value' => $r['calls'],
        ], $this->getCategories());
        $agents = array_map(static fn (array $r): array => [
            'label' => $r['agent'],
            'value' => $r['calls'],
        ], array_slice($this->getAgents(), 0, 10));
        $status = [];
        foreach ($this->getStatusMix() as $class => $count) {
            $status[] = ['key' => $class, 'label' => $class, 'value' => $count];
        }
        $funnel = $this->getFunnel();
        $mcp = $this->getMcpFunnel();
        $mcpSteps = [
            ['label' => (string) __('Discovery'), 'value' => $mcp['discover']],
            ['label' => (string) __('Tool and prompt lists'), 'value' => $mcp['list']],
            ['label' => (string) __('Tool calls'), 'value' => $mcp['tool_calls']],
            ['label' => (string) __('Cart tools'), 'value' => $mcp['cart']],
            ['label' => (string) __('Checkout tools'), 'value' => $mcp['checkout']],
            ['label' => (string) __('Orders'), 'value' => $mcp['orders']],
        ];

        return (string) $this->json->serialize([
            'days'       => $daily['days'],
            'series'     => $series,
            'categories' => $categories,
            'agents'     => $agents,
            'status'     => $status,
            'funnel'     => $funnel === null ? null : [
                ['label' => (string) __('Checkouts created'), 'value' => $funnel['created']],
                ['label' => (string) __('Handed to buyer'), 'value' => $funnel['escalated']],
                ['label' => (string) __('Orders placed'), 'value' => $funnel['orders']],
            ],
            'mcpFunnel'  => array_sum(array_column($mcpSteps, 'value')) > 0 ? $mcpSteps : null,
            'labels'     => [
                'calls'  => (string) __('Calls'),
                'orders' => (string) __('Orders'),
            ],
        ]);
    }
}

<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Console\Command;

use Angeo\AgentActivity\Model\Insights;
use Angeo\AgentActivity\Model\Insights\Rules;
use Angeo\AgentActivity\Model\Report;
use Angeo\AgentActivity\Model\Report\CsvExport;
use Angeo\AgentActivity\Model\Report\Filter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento angeo:agents:report [--days=30 | --from=Y-m-d --to=Y-m-d] [--store=ID] [--format=table|json]
 *     [--fail-on=critical|warning]
 *
 * --fail-on makes the command exit with 1 when a finding of that level (or
 * worse) exists, so it can run in CI or a monitoring cron.
 */
class ReportCommand extends Command
{
    /**
     * @param Report $report
     * @param Insights $insights
     * @param CsvExport $export
     */
    public function __construct(
        private readonly Report $report,
        private readonly Insights $insights,
        private readonly CsvExport $export
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('angeo:agents:report')
            ->setDescription('Show what AI agents did in the store (UCP, MCP, WebMCP, AI crawlers and agents).')
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Days to look back, including today', '30')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'First day, Y-m-d (UTC)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Last day, Y-m-d (UTC)')
            ->addOption('store', 's', InputOption::VALUE_REQUIRED, 'Store view ID')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'table or json', 'table')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Exit 1 on a finding of this level: critical or warning');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $input->getOption('store');
        $storeId = is_string($store) && ctype_digit($store) ? (int) $store : null;
        $from = (string) $input->getOption('from');
        $to = (string) $input->getOption('to');
        $filter = $from !== '' && $to !== ''
            ? Filter::range($from, $to, $storeId)
            : Filter::lastDays((int) $input->getOption('days'), $storeId);
        if ($filter === null) {
            $output->writeln('<error>--from and --to must be dates in Y-m-d format.</error>');
            return Command::INVALID;
        }

        $findings = $this->insights->build($filter);
        $failed = $this->failed($findings, (string) $input->getOption('fail-on'));

        if ($input->getOption('format') === 'json') {
            $output->writeln($this->export->buildJson($filter));
            return $failed ? Command::FAILURE : Command::SUCCESS;
        }

        $t = $this->report->totals($filter);
        $mcp = $this->report->mcpFunnel($filter);
        $output->writeln(sprintf('<info>AI agent activity, %s to %s (UTC)</info>', $filter->from, $filter->to));
        $output->writeln(sprintf(
            'Calls: %d · agents: %d · errors: %d · spoofed: %d · behind proxy: %d · orders: %d',
            $t['calls'],
            $t['agents'],
            $t['errors'],
            $t['spoofed'],
            $t['behind_proxy'],
            $t['orders']
        ));
        $output->writeln(sprintf(
            'MCP: %d discovery · %d lists · %d tool calls · %d cart · %d checkout · %d orders',
            $mcp['discover'],
            $mcp['list'],
            $mcp['tool_calls'],
            $mcp['cart'],
            $mcp['checkout'],
            $mcp['orders']
        ));

        $output->writeln('');
        $output->writeln('<comment>Findings</comment>');
        if ($findings === []) {
            $output->writeln('  (nothing to report)');
        }
        foreach ($findings as $f) {
            $tag = match ($f['level']) {
                Rules::CRITICAL => 'error',
                Rules::WARNING  => 'comment',
                default         => 'info',
            };
            $output->writeln(sprintf(
                '  <%1$s>[%2$s]</%1$s> %3$s',
                $tag,
                strtoupper($f['level']),
                Rules::render($f['title'], $f['args'])
            ));
            $output->writeln('      ' . Rules::render($f['detail'], $f['args']));
        }

        $this->table($output, 'By agent type', ['Type', 'Calls', 'Agents'], $this->report->byCategory($filter));
        $this->table(
            $output,
            'Top agents',
            ['Agent', 'Operator', 'Type', 'Calls', 'Errors', 'Verified', 'Spoofed', 'Proxy', 'Signed', 'Avg ms',
                'Last day'],
            $this->report->byAgent($filter)
        );
        $this->table(
            $output,
            'MCP clients',
            ['Client', 'Operator', 'Calls', 'Discovery', 'Lists', 'Tool calls', 'Errors', 'Avg ms', 'Last day'],
            $this->report->mcpClients($filter)
        );
        $this->table(
            $output,
            'Tool calls',
            ['Channel', 'Tool', 'Calls', 'Errors', 'Clients', 'Avg ms'],
            $this->report->byTool($filter, 25)
        );
        $this->table(
            $output,
            'What agents read',
            ['Page type', 'Fetches', 'Avg ms', 'Cache hits', 'Cache misses', 'Errors', 'With query'],
            array_map(static fn (array $r): array => [
                $r['page_type'], $r['calls'], $r['avg_ms'], $r['cache_hits'], $r['cache_misses'], $r['errors'],
                $r['query_calls'],
            ], $this->report->contentMix($filter))
        );
        $this->table(
            $output,
            'Top operations',
            ['Channel', 'Operation', 'Calls', 'Errors', 'Avg ms'],
            $this->report->byOperation($filter)
        );
        $this->table(
            $output,
            'Most fetched pages',
            ['Path', 'Fetches', 'Training', 'Search', 'User', 'Agent', 'Engines', 'Other'],
            $this->report->topPaths($filter, 10)
        );
        $this->table(
            $output,
            'Agent-built orders',
            ['Channel', 'Currency', 'Orders', 'Revenue'],
            $this->report->ordersByChannel($filter)
        );
        $ucp = $this->report->ucpFunnel($filter);
        if ($ucp !== null) {
            $this->table(
                $output,
                'UCP checkout',
                ['Created', 'Handed to buyer', 'Completed', 'Canceled', 'Orders'],
                [$ucp]
            );
        }
        $output->writeln('');
        $output->writeln('<comment>Totals are rebuilt every hour; run angeo:agents:aggregate for up-to-the-minute numbers.</comment>');
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Whether a finding reaches the --fail-on level.
     *
     * @param array<int, array<string, mixed>> $findings
     * @param string $level
     * @return bool
     */
    private function failed(array $findings, string $level): bool
    {
        $levels = match ($level) {
            Rules::CRITICAL => [Rules::CRITICAL],
            Rules::WARNING  => [Rules::CRITICAL, Rules::WARNING],
            default         => [],
        };
        foreach ($findings as $finding) {
            if (in_array($finding['level'], $levels, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param OutputInterface $output
     * @param string $title
     * @param array<int, string> $headers
     * @param array<int, array<int|string, mixed>> $rows
     * @return void
     */
    private function table(OutputInterface $output, string $title, array $headers, array $rows): void
    {
        $output->writeln('');
        $output->writeln('<comment>' . $title . '</comment>');
        if ($rows === []) {
            $output->writeln('  (no data)');
            return;
        }
        (new Table($output))->setHeaders($headers)->setRows(array_map('array_values', $rows))->render();
    }
}

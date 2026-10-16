<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Console\Command;

use Angeo\AgentActivity\Model\Report;
use Angeo\AgentActivity\Model\Report\Filter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento angeo:agents:report [--days=30 | --from=Y-m-d --to=Y-m-d] [--store=ID] [--format=table|json]
 */
class ReportCommand extends Command
{
    /**
     * @param Report $report
     */
    public function __construct(
        private readonly Report $report
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
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'table or json', 'table');
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

        $data = [
            'from'       => $filter->from,
            'to'         => $filter->to,
            'store_id'   => $filter->storeId,
            'totals'     => $this->report->totals($filter),
            'categories' => $this->report->byCategory($filter),
            'agents'     => $this->report->byAgent($filter),
            'operations' => $this->report->byOperation($filter),
            'status'     => $this->report->statusMix($filter),
            'paths'      => $this->report->topPaths($filter, 10),
            'orders'     => $this->report->ordersByChannel($filter),
            'ucp_funnel' => $this->report->ucpFunnel($filter),
        ];

        if ($input->getOption('format') === 'json') {
            $output->writeln((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $t = $data['totals'];
        $output->writeln(sprintf('<info>AI agent activity, %s to %s (UTC)</info>', $filter->from, $filter->to));
        $output->writeln(sprintf(
            'Calls: %d · agents: %d · errors: %d · spoofed: %d · orders: %d',
            $t['calls'],
            $t['agents'],
            $t['errors'],
            $t['spoofed'],
            $t['orders']
        ));
        $this->table($output, 'By agent type', ['Type', 'Calls', 'Agents'], $data['categories']);
        $this->table(
            $output,
            'Top agents',
            ['Agent', 'Operator', 'Type', 'Calls', 'Errors', 'Verified', 'Spoofed', 'Signed', 'Last day'],
            $data['agents']
        );
        $this->table($output, 'Top operations', ['Channel', 'Operation', 'Calls', 'Errors', 'Avg ms'], $data['operations']);
        $this->table($output, 'Most fetched pages', ['Path', 'Fetches', 'Training', 'Search', 'User', 'Agent'], $data['paths']);
        $this->table($output, 'Agent-built orders', ['Channel', 'Currency', 'Orders', 'Revenue'], $data['orders']);
        if ($data['ucp_funnel'] !== null) {
            $this->table(
                $output,
                'UCP checkout',
                ['Created', 'Handed to buyer', 'Completed', 'Canceled', 'Orders'],
                [$data['ucp_funnel']]
            );
        }
        $output->writeln('');
        $output->writeln('<comment>Totals are rebuilt every hour; run angeo:agents:aggregate for up-to-the-minute numbers.</comment>');
        return Command::SUCCESS;
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

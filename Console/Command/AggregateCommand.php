<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Console\Command;

use Angeo\AgentActivity\Model\Aggregator;
use Angeo\AgentActivity\Model\Report\Filter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento angeo:agents:aggregate [--days=2]
 *
 * Rebuilds the daily totals the report reads, for the last N days.
 */
class AggregateCommand extends Command
{
    /**
     * @param Aggregator $aggregator
     */
    public function __construct(
        private readonly Aggregator $aggregator
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('angeo:agents:aggregate')
            ->setDescription('Rebuild the AI agent activity daily totals (the report reads these).')
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Days to rebuild, including today', '2');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filter = Filter::lastDays((int) $input->getOption('days'));
        $this->aggregator->rebuild($filter->from, $filter->to);
        $output->writeln(sprintf('<info>Rebuilt daily totals for %s to %s (UTC).</info>', $filter->from, $filter->to));
        return Command::SUCCESS;
    }
}

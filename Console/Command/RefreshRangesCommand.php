<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Console\Command;

use Angeo\AgentActivity\Model\Verify\RangeRefresher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento angeo:agents:refresh-ranges
 *
 * Downloads the vendors' published bot IP ranges now (cron does it daily).
 */
class RefreshRangesCommand extends Command
{
    /**
     * @param RangeRefresher $refresher
     */
    public function __construct(
        private readonly RangeRefresher $refresher
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('angeo:agents:refresh-ranges')
            ->setDescription('Download the published IP ranges of OpenAI, Anthropic, Perplexity and Google-Agent.');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $failed = 0;
        foreach ($this->refresher->refresh() as $url => $result) {
            $ok = str_starts_with($result, 'ok');
            $failed += $ok ? 0 : 1;
            $output->writeln(sprintf('%s %s — %s', $ok ? '<info>✓</info>' : '<error>✗</error>', $url, $result));
        }
        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}

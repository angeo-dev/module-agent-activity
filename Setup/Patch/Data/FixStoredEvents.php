<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Setup\Patch\Data;

use Angeo\AgentActivity\Model\Agent\Catalog;
use Angeo\AgentActivity\Model\Aggregator;
use Angeo\AgentActivity\Model\EventRepository;
use Angeo\AgentActivity\Model\Report;
use Angeo\AgentActivity\Model\RequestClassifier;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\FlagManager;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * 1.2.0: brings events stored by 1.0.x/1.1.0 in line with the new rules,
 * then rebuilds the daily totals so the report shows one consistent story.
 *
 *  - Callers that named nobody move from category "platform" to "unknown".
 *  - Crawler rows stored as "page" for /catalog/category/view/… and
 *    /catalog/product/view/… become "category" and "product" (1.0.x did not
 *    read the Magento route yet).
 *  - Crawler paths before today are rewrite targets, not the URLs crawlers
 *    asked for, so the "raw URL" check starts today (Report::FLAG_PATHS_FIXED).
 *
 * The IP range download is not done here: setup must not depend on outgoing
 * HTTPS. The first cron run, the CLI command or the report's Refresh button
 * downloads the lists.
 */
class FixStoredEvents implements DataPatchInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Aggregator $aggregator,
        private readonly FlagManager $flagManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        $connection = $this->resource->getConnection();
        $events = $this->resource->getTableName(EventRepository::TABLE);
        $daily = $this->resource->getTableName(Aggregator::DAILY_TABLE);

        foreach ([$events, $daily] as $table) {
            $connection->update(
                $table,
                ['category' => Catalog::CATEGORY_UNKNOWN],
                ['agent = ?' => 'unknown', 'category = ?' => Catalog::CATEGORY_PLATFORM]
            );
        }
        foreach (['/catalog/category/view/%' => 'category', '/catalog/product/view/%' => 'product'] as $like => $type) {
            $connection->update(
                $events,
                ['operation' => $type],
                [
                    'channel = ?'   => RequestClassifier::CHANNEL_CRAWLER,
                    'operation = ?' => 'page',
                    'path LIKE ?'   => $like,
                ]
            );
        }

        $this->flagManager->saveFlag(Report::FLAG_PATHS_FIXED, gmdate('Y-m-d'));

        try {
            $first = $connection->fetchOne($connection->select()->from($events, ['MIN(created_at)']));
            if (is_string($first) && $first !== '') {
                $this->aggregator->rebuild(substr($first, 0, 10), gmdate('Y-m-d'));
            }
        } catch (\Throwable $e) {
            // The hourly cron rebuilds recent days; older days keep their old totals.
            $this->logger->warning('[Angeo_AgentActivity] Could not rebuild daily totals: ' . $e->getMessage());
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}

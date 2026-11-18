<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Controller\Adminhtml\Report;

use Angeo\AgentActivity\Model\Aggregator;
use Angeo\AgentActivity\Model\Config;
use Angeo\AgentActivity\Model\Verify\RangeRefresher;
use Angeo\AgentActivity\Model\Verify\RangeStore;
use Angeo\AgentActivity\Model\Report\FilterResolver;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;

/**
 * Rebuilds the daily totals for the shown range (up to today) right away,
 * like "Refresh Statistics" in the core reports. When no vendor IP list
 * is stored yet, it also downloads them (cron may not be running).
 */
class Refresh extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Angeo_AgentActivity::refresh';

    /**
     * @param Context $context
     * @param Aggregator $aggregator
     * @param FilterResolver $filterResolver
     * @param RangeRefresher $rangeRefresher
     * @param RangeStore $rangeStore
     * @param Config $config
     */
    public function __construct(
        Context $context,
        private readonly Aggregator $aggregator,
        private readonly FilterResolver $filterResolver,
        private readonly RangeRefresher $rangeRefresher,
        private readonly RangeStore $rangeStore,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Redirect
    {
        $filter = $this->filterResolver->fromRequest($this->getRequest());
        try {
            $this->aggregator->rebuild($filter->from, gmdate('Y-m-d'));
            $this->messageManager->addSuccessMessage(__('The AI agent statistics were refreshed.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('The statistics could not be refreshed: %1', $e->getMessage()));
        }
        $this->refreshRangesWhenMissing();

        $params = array_intersect_key(
            (array) $this->getRequest()->getParams(),
            array_flip(['period', 'from', 'to', 'store'])
        );
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $redirect->setPath('*/*/index', ['_query' => $params]);
    }

    /**
     * Download the vendor IP lists once when none is stored yet, so the
     * bot check starts working without waiting for cron.
     */
    private function refreshRangesWhenMissing(): void
    {
        if (!$this->config->verifyIp() || $this->rangeStore->all() !== []) {
            return;
        }
        try {
            $report = $this->rangeRefresher->refresh();
            $failed = count(array_filter($report, static fn (string $r): bool => str_starts_with($r, 'failed')));
            if ($failed === 0) {
                $this->messageManager->addSuccessMessage(__('The bot IP lists were downloaded.'));
            } else {
                $this->messageManager->addWarningMessage(
                    __('%1 of %2 bot IP lists could not be downloaded. Check outgoing HTTPS.', $failed, count($report))
                );
            }
        } catch (\Throwable $e) {
            $this->messageManager->addWarningMessage(__('The bot IP lists could not be downloaded: %1', $e->getMessage()));
        }
    }
}

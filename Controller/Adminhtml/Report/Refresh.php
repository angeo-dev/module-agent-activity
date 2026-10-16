<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Controller\Adminhtml\Report;

use Angeo\AgentActivity\Model\Aggregator;
use Angeo\AgentActivity\Model\Report\FilterResolver;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;

/**
 * Rebuilds the daily totals for the shown range (up to today) right away,
 * like "Refresh Statistics" in the core reports.
 */
class Refresh extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Angeo_AgentActivity::refresh';

    /**
     * @param Context $context
     * @param Aggregator $aggregator
     * @param FilterResolver $filterResolver
     */
    public function __construct(
        Context $context,
        private readonly Aggregator $aggregator,
        private readonly FilterResolver $filterResolver
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

        $params = array_intersect_key(
            (array) $this->getRequest()->getParams(),
            array_flip(['period', 'from', 'to', 'store'])
        );
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $redirect->setPath('*/*/index', ['_query' => $params]);
    }
}

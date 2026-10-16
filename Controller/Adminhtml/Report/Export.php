<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Controller\Adminhtml\Report;

use Angeo\AgentActivity\Model\Report\CsvExport;
use Angeo\AgentActivity\Model\Report\FilterResolver;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;

/**
 * Downloads the report for the shown scope as CSV.
 */
class Export extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Angeo_AgentActivity::report';

    /**
     * @param Context $context
     * @param FileFactory $fileFactory
     * @param CsvExport $csvExport
     * @param FilterResolver $filterResolver
     */
    public function __construct(
        Context $context,
        private readonly FileFactory $fileFactory,
        private readonly CsvExport $csvExport,
        private readonly FilterResolver $filterResolver
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): ResponseInterface
    {
        $filter = $this->filterResolver->fromRequest($this->getRequest());
        $name = sprintf('ai-agent-activity_%s_%s.csv', $filter->from, $filter->to);
        return $this->fileFactory->create(
            $name,
            $this->csvExport->build($filter),
            DirectoryList::VAR_DIR,
            'text/csv'
        );
    }
}

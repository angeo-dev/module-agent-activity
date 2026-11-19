<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Observer;

use Angeo\AgentActivity\Model\Insights;
use Angeo\AgentActivity\Model\Report;
use Angeo\AgentActivity\Model\Report\FilterResolver;
use Angeo\AgentActivity\Model\Report\Help;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Facts of the agent activity report for the optional AI assistant
 * (angeo/module-ai-assist). No dependency on it: without it the event is
 * never dispatched.
 *
 * Source "agent_activity", ref = the page filters ("period=30&store=1").
 * Checks Angeo_AgentActivity::report first. Only aggregates are sent:
 * counts per agent, operation, page type and path. No IP addresses, no
 * customers, no order details.
 */
class AiAssistContext implements ObserverInterface
{
    private const ACL = 'Angeo_AgentActivity::report';

    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly FilterResolver $filterResolver,
        private readonly Report $report,
        private readonly Insights $insights,
        private readonly Help $help
    ) {
    }

    public function execute(Observer $observer): void
    {
        $context = $observer->getData('context');
        if ((string) $observer->getData('source') !== 'agent_activity' || !$context instanceof DataObject) {
            return;
        }
        if (!$this->authorization->isAllowed(self::ACL)) {
            $context->setData('denied', true);
            return;
        }

        $params = [];
        $ref = (string) $observer->getData('ref');
        if ($ref !== 'default') {
            parse_str($ref, $params);
        }
        $filter   = $this->filterResolver->fromValues(array_intersect_key($params, array_flip(['period', 'from', 'to', 'store'])));
        $previous = $filter->previous();

        $findings = [];
        foreach ($this->insights->build($filter) as $finding) {
            $args = array_values((array) ($finding['args'] ?? []));
            $findings[] = [
                'level'  => (string) ($finding['level'] ?? ''),
                'title'  => (string) __((string) ($finding['title'] ?? ''), ...$args),
                'detail' => (string) __((string) ($finding['detail'] ?? ''), ...$args),
            ];
        }

        $context->setData([
            'title'    => sprintf('AI agent activity, %s to %s%s', $filter->from, $filter->to, $filter->storeId !== null ? ', store ' . $filter->storeId : ''),
            'summary'  => 'Requests from AI crawlers and agents that reached Magento, counted by agent, operation, page type and path. '
                . 'Pages served from a full-page cache or CDN are not counted, so crawler numbers are a lower bound.',
            'facts'    => [
                'period'            => ['from' => $filter->from, 'to' => $filter->to, 'days' => $filter->days()],
                'totals'            => $this->report->totals($filter),
                'previous_period_totals' => $this->report->totals($previous),
                'findings'          => $findings,
                'by_category'       => $this->report->byCategory($filter),
                'agents'            => $this->report->byAgent($filter, 15),
                'status_mix'        => $this->report->statusMix($filter),
                'mcp_funnel'        => $this->report->mcpFunnel($filter),
                'ucp_funnel'        => $this->report->ucpFunnel($filter),
                'mcp_clients'       => $this->report->mcpClients($filter, 10),
                'tools'             => $this->report->byTool($filter, 15),
                'content_mix'       => $this->report->contentMix($filter),
                'top_paths'         => $this->report->topPaths($filter, 15),
                'error_paths'       => $this->report->errorPaths($filter, 10),
                'query_keys'        => $this->report->queryKeys($filter, 10),
            ],
            'glossary' => $this->help->all(),
        ]);
    }
}

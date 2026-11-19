<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model\Report;

/**
 * "How to read this" text for every section of the report (1.3.0).
 *
 * Plain English, translatable. Shown under each heading; also sent to the
 * optional AI assistant as a glossary, so it reads the report the same way.
 *
 * @api
 * @since 1.3.0
 */
class Help
{
    /**
     * @return array<string,string> section code => text
     */
    public function all(): array
    {
        return [
            'summary' => (string) __('Calls are requests from AI crawlers and agents that reached Magento. The arrow compares with the same number of days before. Error rate is the share of calls that got a 4xx or 5xx answer.'),
            'findings' => (string) __('Rules that read the numbers below and point at something worth fixing, most serious first. Each one names the number it is based on.'),
            'daily' => (string) __('Calls per day, split by channel. A sudden drop to zero often means a firewall or CDN rule started blocking a crawler.'),
            'agents' => (string) __('One row per AI crawler or agent. "Verified" means the request came from the address range or signature the operator publishes; unverified traffic can be a bot that only claims the name.'),
            'mcp_clients' => (string) __('Apps that talk to your MCP server (for example Claude or ChatGPT connectors), with the protocol version they use. Old versions still work but may lose features.'),
            'tools' => (string) __('MCP tools agents called, how often, and how often they failed. A tool that fails often is worth testing by hand.'),
            'content' => (string) __('Which kinds of pages AI crawlers fetch: products, categories, CMS pages, search results. Many fetches of search or filter URLs waste crawl budget.'),
            'orders' => (string) __('Orders placed from carts that an agent built through UCP, MCP or WebMCP. Revenue is in the base currency.'),
            'operations' => (string) __('Every kind of call (page fetch, robots.txt, llms.txt, MCP method, UCP endpoint) and its error rate.'),
            'paths' => (string) __('The pages crawlers fetch most. If they are not the pages you want in AI answers, check your sitemap, internal links and llms.txt.'),
            'query_keys' => (string) __('Query parameter names crawlers use. Filter and sort parameters create many copies of one page.'),
            'coverage' => (string) __('Only requests that reach Magento are counted. Pages served from a full-page cache or CDN are not, so crawler numbers are a lower bound. Agent endpoints are never cached and are complete.'),
        ];
    }

    public function get(string $section): string
    {
        return $this->all()[$section] ?? '';
    }
}

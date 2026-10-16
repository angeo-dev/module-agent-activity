# Angeo AI Agent Activity for Magento 2

[![CI](https://github.com/angeo-dev/module-agent-activity/actions/workflows/ci.yml/badge.svg)](https://github.com/angeo-dev/module-agent-activity/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/angeo/module-agent-activity)](https://packagist.org/packages/angeo/module-agent-activity)
[![PHP](https://img.shields.io/badge/PHP-8.1%20%E2%80%93%208.5-777bb4)](composer.json)
[![Magento](https://img.shields.io/badge/Magento-2.4.6%20%E2%80%93%202.4.9-f26322)](composer.json)
[![License: MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)

**See what AI agents do in your store — and what they bring.**

Which agents come, what they fetch, which calls fail, which "bots" are fake,
and how many agent-built carts turn into orders. One report with charts, one
event log, no IP addresses stored.

## What it counts

| Channel | What is counted |
|---|---|
| **UCP** | `/.well-known/ucp`, UCP catalog, checkout and order calls, `continue_url` opens |
| **MCP** | Calls to the store MCP endpoint `/mcp`, by JSON-RPC method and tool |
| **WebMCP** | Tool calls from an agent in the shopper's browser ([`angeo/module-webmcp`](https://github.com/angeo-dev/module-webmcp) 1.0.1+ tags them by tool) |
| **Web pages** | Storefront pages fetched by known AI crawlers and agents |
| **Orders** | Orders whose cart an agent built: UCP checkout, MCP cart or WebMCP add to cart — with revenue |

Every agent has a **type**, so you can tell why it came:

| Type | Examples |
|---|---|
| Agent platform | A UCP platform (named by its profile host), an MCP client, a browser agent using WebMCP |
| Browsing agent | Google-Agent, ChatGPT agent (Web Bot Auth), GrokAgent |
| User-triggered fetch | ChatGPT-User, Claude-User, Perplexity-User, MistralAI-User, DuckAssistBot |
| AI search | OAI-SearchBot, Claude-SearchBot, PerplexityBot, Applebot, Amzn-SearchBot |
| Training crawler | GPTBot, ClaudeBot, Meta-ExternalAgent, Amazonbot, CCBot, Bytespider |
| Search engine | Googlebot, Bingbot — off by default |

## Is that bot real?

Anyone can send `User-Agent: GPTBot`. When the setting is on, the module
checks requests from OpenAI, Anthropic, Perplexity and Google-Agent against
the IP ranges these vendors publish and marks them **verified** or
**spoofed**. The lists are downloaded once a day by cron; the client IP is
used for the check and never stored.

Requests signed with **Web Bot Auth** (`Signature-Agent` +
`tag="web-bot-auth"`) are marked as *signed*, and agents that browse with an
ordinary Chrome user agent (ChatGPT agent, Google-Agent) are named by their
signer. The signature itself is not verified here; do that at your CDN.

## The report

*Reports → Marketing → AI Agent Activity*

- Period: last 7 / 30 / 90 days or a custom range; one store view or all
- Summary: agent calls, distinct agents, agent-built orders and revenue,
  error rate, spoofed bot calls — each with the change versus the previous
  period
- Charts: calls per day by channel, calls by agent type, top 10 agents,
  responses by status, UCP checkout funnel
- Tables: agents (company, type, errors, IP check, last seen), agent-built
  orders with links to the orders, operations (errors, average time), most
  fetched pages split by agent type
- **Export CSV** of the whole report, **Refresh now** to rebuild the totals

Charts use the Chart.js that ships with Magento (3.9 in 2.4.6, 4.x in
2.4.7+): no CDN, no inline JavaScript. Every chart has a table with the same
numbers.

*Reports → Marketing → AI Agent Events* is the event log: a standard admin
grid with filters, column controls and CSV/XML export.

**CLI**

```bash
bin/magento angeo:agents:report --days=30            # or --from=2026-10-01 --to=2026-10-15
bin/magento angeo:agents:report --store=1 --format=json
bin/magento angeo:agents:aggregate --days=2          # rebuild the totals now
bin/magento angeo:agents:refresh-ranges              # download the bot IP lists now
```

## Install

```bash
composer require angeo/module-agent-activity
bin/magento module:enable Angeo_AgentActivity
bin/magento setup:upgrade
bin/magento setup:di:compile        # production mode
bin/magento setup:static-content:deploy   # production mode
bin/magento cache:flush
```

Logging starts at once. Make sure Magento cron runs: it builds the totals
every hour and downloads the IP lists once a day. To see numbers right
after install, press **Refresh now** in the report.

## Settings

*Stores → Configuration → Angeo → AI Agent Activity*

| Setting | Default | |
|---|---|---|
| Log Agent Activity | Yes | Master switch |
| Log AI Crawler and Agent Page Visits | Yes | Storefront pages fetched by AI crawlers and agents |
| Also Log Googlebot and Bingbot | No | Classic search engines would dominate the numbers |
| Check Bots Against Published IP Ranges | Yes | Verified / spoofed marks |
| Keep Single Events (days) | 90 | The event log |
| Keep Daily Totals (days) | 730 | What the report reads |

## Privacy

No IP address, no full user agent, no request body, no query string, no
customer data. An agent is a short name (`chatgpt-user`) or the host of its
UCP platform profile. See [SECURITY.md](SECURITY.md) for exactly what is
stored.

## How it works

```
storefront request ──▶ controller_front_send_response_before
                         ├─ AgentDetector: UCP-Agent → Signature-Agent → user agent
                         ├─ RequestClassifier: ucp / mcp / webmcp / web page, or ignore
                         ├─ IpVerifier: vendor IP list (local copy) → verified / spoofed
                         └─ one INSERT into angeo_agent_event
order placed ──▶ checkout_submit_all_after → UCP session / MCP cart / WebMCP cart?
cron hourly  ──▶ Aggregator → angeo_agent_event_daily, angeo_agent_path_daily
admin report ──▶ reads the daily totals
```

Ordinary shoppers cost a few header reads. Each agent request costs one
INSERT. The report never scans the event log.

## Good to know

- Only requests that reach Magento are counted. Pages served from Varnish,
  LiteSpeed cache or a CDN are not, so web page numbers are a lower bound.
  UCP, MCP and WebMCP endpoints are not cached and are always counted.
- Behind a load balancer or CDN, set up Magento's trusted proxy headers so
  the IP check sees the client address. If Magento only sees a private
  address, the check gives no verdict.
- Days and the report range are UTC.
- Works without the other Angeo modules. With
  [`module-ucp-checkout`](https://github.com/angeo-dev/module-ucp-checkout),
  [`module-mcp-checkout`](https://github.com/angeo-dev/module-mcp-checkout)
  and [`module-webmcp`](https://github.com/angeo-dev/module-webmcp) it also
  attributes orders and shows the UCP funnel.

## Requirements

Magento Open Source, Mage-OS or Adobe Commerce 2.4.6 – 2.4.9, PHP 8.1 – 8.5.
Luma and Hyvä storefronts (the module has no storefront code).

## License

MIT. Made by [angeo.dev](https://angeo.dev).

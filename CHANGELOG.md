# Changelog

All notable changes to `angeo/module-agent-activity` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-16

First release.

### Added

- **Event log** `angeo_agent_event`: channel, agent, company, agent type,
  operation, HTTP status, duration, IP check result, signature flag, page
  path (crawlers) and order number and total. No IP, user agent string,
  body or customer data.
- **Request observer** (`controller_front_send_response_before`, frontend)
  for UCP, MCP and WebMCP calls and for storefront pages fetched by AI
  crawlers and agents.
- **Agent catalogue** of about 35 crawlers and agents with company and type
  (training, AI search, user-triggered fetch, browsing agent), checked
  against vendor documentation in October 2026. Includes Google-Agent,
  Claude-SearchBot, Perplexity-User, GrokAgent and Amzn-SearchBot.
  Robots.txt-only tokens (Google-Extended, Applebot-Extended) are not
  treated as agents, because they never send requests.
- **Web Bot Auth**: requests with `Signature-Agent` and
  `tag="web-bot-auth"` are marked as signed; agents that browse with a normal
  browser user agent (ChatGPT agent, Google-Agent) are named by the signer.
- **IP check** against the published ranges of OpenAI, Anthropic,
  Perplexity and Google-Agent; lists downloaded daily by cron; the address
  is not stored.
- **Order attribution** for UCP checkout sessions
  (`angeo/module-ucp-checkout`), MCP carts (`angeo/module-mcp-checkout`) and
  WebMCP add to cart (`angeo/module-webmcp`).
- **Daily totals** (`angeo_agent_event_daily`, `angeo_agent_path_daily`),
  rebuilt every hour by cron, by *Refresh now* in the report, or by
  `bin/magento angeo:agents:aggregate`. Separate retention for events
  (default 90 days) and daily totals (default 730 days).
- **Admin report** (Reports → Marketing → AI Agent Activity): period presets
  and custom range, store view filter, summary tiles with change versus the
  previous period, charts (calls per day by channel, calls by agent type,
  top agents, responses by status, UCP checkout funnel) using the Chart.js
  that ships with Magento, tables for agents, orders, operations and most
  fetched pages, and CSV export.
- **Event log grid** (Reports → Marketing → AI Agent Events) with filters,
  column controls and CSV/XML export.
- CLI: `angeo:agents:report` (`--days`, `--from`, `--to`, `--store`,
  `--format=json`), `angeo:agents:aggregate`, `angeo:agents:refresh-ranges`.
- ACL resources for report, refresh, event log and settings.

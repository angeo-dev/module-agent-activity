# Changelog

All notable changes to `angeo/module-agent-activity` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-10-19

More data per call and a report that explains itself.

### Added

- **Findings** in the report, the CLI and the exports: plain-language checks
  with a level and a stable code — fake bots, IP check behind a proxy,
  missing IP lists, MCP clients that never call a tool, MCP carts and
  checkouts that do not finish, JSON-RPC errors, AI crawlers that read only
  robots.txt, no llms.txt reads, crawl waste on account/cart/checkout/search
  pages, URLs without a rewrite, query-string URLs, slow pages, error pages,
  no user-triggered fetches, young data.
- **MCP client names** from `clientInfo` (`initialize`) and from
  `params._meta["io.modelcontextprotocol/clientInfo"]` (stateless MCP
  2026-07-28); client version and protocol version are stored too. Calls
  that were "unknown" now show the client (`claude-ai`, `cursor` …) with its
  company. `Mcp-Method` / `Mcp-Name` headers fill in when the body has no
  method. Agent-built MCP orders are named the same way.
- **JSON-RPC errors**: MCP responses with an `error` or a tool result with
  `isError: true` count as errors, although the HTTP status is 200.
- **MCP funnel** chart and tables: MCP clients (discovery, lists, tool
  calls, errors, speed), tool calls by tool (MCP and WebMCP), client and
  protocol versions.
- **What agents read**: crawler fetches by page type (product, category,
  cms, home, search, account, cart, checkout, not found …, from the Magento
  route rather than the URL) and agent type, with average time, page cache
  hit rate, query strings and errors.
- **Page cache hit or miss** for crawler pages (built-in full page cache).
- **Query string flag** for crawler pages (yes/no; the query is not stored).
- **IP check for Googlebot, GoogleOther, NotebookLM and Bingbot**
  (Google's new `developers.google.com/static/crawling/ipranges/` lists,
  `bing.com/toolbox/bingbot.json`).
- **Behind proxy** IP state: a bot with a vendor list seen from a private
  address is no longer silently "not checked".
- Export: each section as its own CSV table (`?section=agents`), and the
  whole report with findings and their facts as JSON (`?format=json`).
- Report shows when tracking started; login URLs in "Most fetched pages"
  show the page they came from (decoded `referer`); system URLs are tagged
  "no rewrite"; search engine and other columns added.
- CLI `angeo:agents:report`: findings, MCP clients, tool calls, page types,
  JSON with findings, `--fail-on=critical|warning`.
- Event log grid: MCP client, client version, protocol, JSON-RPC error,
  page cache and query string columns; IP check filter has "Behind proxy".

### Changed

- Web Bot Auth signers that are not known AI agents (ad verification,
  monitoring, e.g. `crawler-key.proximic.com`) get the new type *Other
  signed bot* instead of *Browsing agent*; their operator is the domain.
- Error counts include JSON-RPC errors.

### Fixed

- Event log grid: **CSV and Excel XML export failed** with "Invalid Form
  Key". The grid had no selections column, so the export button sent an
  empty POST body and the admin ajax setup lost the form key. The grid now
  has a selections column; export uses the shown filters, or only the
  ticked rows.
- Event log grid: removed the keyword search box. The log has no full-text
  index, so a search failed with a server error.
- "Back to Report" button in the event log uses a full route, so it also
  works after the grid reloads.

### Upgrade

`bin/magento setup:upgrade`, then `bin/magento angeo:agents:refresh-ranges`
to download the new Google and Bing lists. Events from before the upgrade
have no client, cache or query data; the report treats them as unknown.

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

# Security Policy

Report security problems to **info@angeo.dev** with the subject
`[security] module-agent-activity`, not in a public issue. I aim to answer
within 72 hours.

## What is stored

One row per agent request: time, store view, channel, agent name, company,
operation, HTTP status, duration, IP check result (verified / spoofed /
behind proxy / not checked), whether a Web Bot Auth signature was present,
and for crawler pages the URL path, whether the URL had a query string
(yes/no only) and whether the full page cache served it. For MCP calls: the
client name, client version and protocol version the client sent in
`clientInfo` (product names such as `claude-ai`, not people), and whether
the response was a JSON-RPC error. For agent-built orders: order number,
entity id and base grand total.

The MCP request body and the first 64 KB of the MCP response are read in
memory to find the method, tool name, client name and error flag; neither
is stored.

**Never stored:** IP address, full user agent, request or response body,
query string, cookies, customer name, email or address.

## IP check

When *Check Bots Against Published IP Ranges* is on, the client address of a
request that claims to be a known bot (by user agent) is compared with the
vendor's published range list, then dropped. The lists are downloaded by a
daily cron job over HTTPS (10 s timeout, 2 MB cap, HTTPS-only redirects) and
kept in the `flag` table and the cache. Storefront requests never call out.
A list that fails to download keeps its previous copy.

The address Magento sees depends on your proxy setup. Behind a load
balancer or CDN, configure Magento's trusted proxy headers. When Magento
only sees a private or reserved address (a proxy), the module gives no
verdict instead of marking real bots as spoofed.

## Web Bot Auth

A `Signature-Agent` header with `tag="web-bot-auth"` in `Signature-Input`
is recorded as *signed* and its host is used as the agent name. The
signature itself is **not** verified by this module; verify it at the CDN
or WAF (Cloudflare, Akamai, Fastly, AWS WAF) if you need proof.

## Access

- Report: ACL `Angeo_AgentActivity::report`
- Refresh statistics: `Angeo_AgentActivity::refresh`
- Event log grid and its CSV/XML export: `Angeo_AgentActivity::events`
- Settings: `Angeo_AgentActivity::config`

CSV exports neutralise cells that a spreadsheet would run as a formula.

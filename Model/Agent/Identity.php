<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model\Agent;

/**
 * Who sent a request, as far as the request itself says.
 *
 * source: how the name was found — "ucp" (UCP-Agent profile host),
 * "signature" (Web Bot Auth Signature-Agent host), "user_agent" (known
 * token), "browser" (WebMCP call from a shopper's browser) or "unknown".
 */
class Identity
{
    public const SOURCE_UCP        = 'ucp';
    public const SOURCE_SIGNATURE  = 'signature';
    public const SOURCE_USER_AGENT = 'user_agent';
    public const SOURCE_BROWSER    = 'browser';
    public const SOURCE_UNKNOWN    = 'unknown';

    /**
     * @param string $name short agent name, e.g. "chatgpt-user" or "platform.example.com"
     * @param string $operator company behind the agent, or the platform host
     * @param string $category one of Catalog::CATEGORY_*
     * @param string $source one of self::SOURCE_*
     * @param string|null $ranges URL of the vendor IP range list, when published
     * @param bool $signed the request carries a Web Bot Auth signature
     */
    public function __construct(
        public readonly string $name,
        public readonly string $operator,
        public readonly string $category,
        public readonly string $source,
        public readonly ?string $ranges = null,
        public readonly bool $signed = false
    ) {
    }

    /**
     * Whether the user agent named a known AI agent or crawler.
     */
    public function isKnownAiAgent(): bool
    {
        return $this->source === self::SOURCE_USER_AGENT || $this->source === self::SOURCE_SIGNATURE;
    }
}

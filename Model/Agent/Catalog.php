<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model\Agent;

/**
 * Known AI agents and crawlers, matched by a lower-case user-agent token.
 *
 * Sources (checked October 2026): vendor documentation for OpenAI
 * (developers.openai.com/api/docs/bots), Anthropic (support.claude.com
 * article 8896518), Perplexity (docs.perplexity.ai), Google
 * (developers.google.com/crawling/docs/crawlers-fetchers, IP lists under
 * developers.google.com/static/crawling/ipranges/), Microsoft
 * (bing.com/toolbox/bingbot.json). Robots.txt-only
 * tokens that never send requests (Google-Extended, Applebot-Extended) are
 * deliberately absent.
 *
 * Categories:
 *  - training       collects content for model training
 *  - search         builds an AI search / answer index
 *  - user_fetch     fetches a page because a person asked an assistant
 *  - agent          browses or acts for a person (clicks, carts, forms)
 *  - search_engine  classic search engine (logged only when enabled)
 *  - other          signed bot that is not a known AI agent
 *
 * ranges: URL of the vendor's published IP range list (JSON with
 * "prefixes": [{"ipv4Prefix": …} | {"ipv6Prefix": …}]). shared: the list
 * covers several of the vendor's bots, so a match proves the vendor, not
 * the exact bot.
 *
 * Order matters: the first token found in the user agent wins, so more
 * specific tokens come first.
 */
class Catalog
{
    public const CATEGORY_TRAINING      = 'training';
    public const CATEGORY_SEARCH        = 'search';
    public const CATEGORY_USER_FETCH    = 'user_fetch';
    public const CATEGORY_AGENT         = 'agent';
    public const CATEGORY_SEARCH_ENGINE = 'search_engine';

    /** Not a crawler: a UCP platform, an MCP client or a browser agent. */
    public const CATEGORY_PLATFORM = 'platform';

    /** A signed bot that is not a known AI agent (ad verification, monitoring). */
    public const CATEGORY_OTHER = 'other';

    private const OPENAI = 'OpenAI';
    private const ANTHROPIC = 'Anthropic';
    private const ANTHROPIC_RANGES = 'https://claude.com/crawling/bots.json';
    private const GOOGLE_COMMON = 'https://developers.google.com/static/crawling/ipranges/common-crawlers.json';
    private const GOOGLE_USER_FETCH
        = 'https://developers.google.com/static/crawling/ipranges/user-triggered-fetchers-google.json';

    /**
     * @var array<string, array{name: string, operator: string, category: string, ranges?: string, shared?: bool}>
     */
    private const AGENTS = [
        // OpenAI
        'chatgpt-user'   => ['name' => 'chatgpt-user', 'operator' => self::OPENAI,
            'category' => self::CATEGORY_USER_FETCH, 'ranges' => 'https://openai.com/chatgpt-user.json'],
        'oai-searchbot'  => ['name' => 'openai-search', 'operator' => self::OPENAI,
            'category' => self::CATEGORY_SEARCH, 'ranges' => 'https://openai.com/searchbot.json'],
        'gptbot'         => ['name' => 'openai-training', 'operator' => self::OPENAI,
            'category' => self::CATEGORY_TRAINING, 'ranges' => 'https://openai.com/gptbot.json'],
        // Anthropic: one shared list for all three bots
        'claude-user'      => ['name' => 'claude-user', 'operator' => self::ANTHROPIC,
            'category' => self::CATEGORY_USER_FETCH, 'ranges' => self::ANTHROPIC_RANGES, 'shared' => true],
        'claude-searchbot' => ['name' => 'claude-search', 'operator' => self::ANTHROPIC,
            'category' => self::CATEGORY_SEARCH, 'ranges' => self::ANTHROPIC_RANGES, 'shared' => true],
        'claudebot'        => ['name' => 'anthropic-training', 'operator' => self::ANTHROPIC,
            'category' => self::CATEGORY_TRAINING, 'ranges' => self::ANTHROPIC_RANGES, 'shared' => true],
        // Perplexity
        'perplexity-user' => ['name' => 'perplexity-user', 'operator' => 'Perplexity',
            'category' => self::CATEGORY_USER_FETCH, 'ranges' => 'https://www.perplexity.com/perplexity-user.json'],
        'perplexitybot'   => ['name' => 'perplexity', 'operator' => 'Perplexity',
            'category' => self::CATEGORY_SEARCH, 'ranges' => 'https://www.perplexity.com/perplexitybot.json'],
        // Google
        'google-agent'          => ['name' => 'google-agent', 'operator' => 'Google',
            'category' => self::CATEGORY_AGENT,
            'ranges' => 'https://developers.google.com/static/crawling/ipranges/user-triggered-agents.json'],
        'google-gemininotebook' => ['name' => 'gemini-notebook', 'operator' => 'Google',
            'category' => self::CATEGORY_USER_FETCH, 'ranges' => self::GOOGLE_USER_FETCH, 'shared' => true],
        'google-notebooklm'     => ['name' => 'gemini-notebook', 'operator' => 'Google',
            'category' => self::CATEGORY_USER_FETCH, 'ranges' => self::GOOGLE_USER_FETCH, 'shared' => true],
        'google-cloudvertexbot' => ['name' => 'google-vertex', 'operator' => 'Google',
            'category' => self::CATEGORY_TRAINING],
        'googleother'           => ['name' => 'google-other', 'operator' => 'Google',
            'category' => self::CATEGORY_TRAINING, 'ranges' => self::GOOGLE_COMMON, 'shared' => true],
        // Others
        'grokagent'            => ['name' => 'grok-agent', 'operator' => 'xAI', 'category' => self::CATEGORY_AGENT],
        'meta-externalfetcher' => ['name' => 'meta-fetcher', 'operator' => 'Meta',
            'category' => self::CATEGORY_USER_FETCH],
        'meta-externalagent'   => ['name' => 'meta', 'operator' => 'Meta', 'category' => self::CATEGORY_TRAINING],
        'mistralai-user'       => ['name' => 'mistral-user', 'operator' => 'Mistral AI',
            'category' => self::CATEGORY_USER_FETCH],
        'duckassistbot'        => ['name' => 'duckassist', 'operator' => 'DuckDuckGo',
            'category' => self::CATEGORY_USER_FETCH],
        'amzn-searchbot'       => ['name' => 'amazon-search', 'operator' => 'Amazon',
            'category' => self::CATEGORY_SEARCH],
        'amazonbot'            => ['name' => 'amazon', 'operator' => 'Amazon', 'category' => self::CATEGORY_TRAINING],
        'applebot'             => ['name' => 'apple', 'operator' => 'Apple', 'category' => self::CATEGORY_SEARCH],
        'bravebot'             => ['name' => 'brave', 'operator' => 'Brave', 'category' => self::CATEGORY_SEARCH],
        'youbot'               => ['name' => 'you', 'operator' => 'You.com', 'category' => self::CATEGORY_SEARCH],
        'linerbot'             => ['name' => 'liner', 'operator' => 'Liner', 'category' => self::CATEGORY_USER_FETCH],
        'kimibot'              => ['name' => 'kimi', 'operator' => 'Moonshot AI',
            'category' => self::CATEGORY_TRAINING],
        'bytespider'           => ['name' => 'bytedance', 'operator' => 'ByteDance',
            'category' => self::CATEGORY_TRAINING],
        'ccbot'                => ['name' => 'common-crawl', 'operator' => 'Common Crawl',
            'category' => self::CATEGORY_TRAINING],
        'cohere-ai'            => ['name' => 'cohere', 'operator' => 'Cohere', 'category' => self::CATEGORY_TRAINING],
        'diffbot'              => ['name' => 'diffbot', 'operator' => 'Diffbot', 'category' => self::CATEGORY_TRAINING],
        'petalbot'             => ['name' => 'petal', 'operator' => 'Huawei', 'category' => self::CATEGORY_SEARCH],
        // Classic search engines: logged only when the setting is on.
        'googlebot'            => ['name' => 'googlebot', 'operator' => 'Google',
            'category' => self::CATEGORY_SEARCH_ENGINE, 'ranges' => self::GOOGLE_COMMON, 'shared' => true],
        'bingbot'              => ['name' => 'bing', 'operator' => 'Microsoft',
            'category' => self::CATEGORY_SEARCH_ENGINE, 'ranges' => 'https://www.bing.com/toolbox/bingbot.json'],
    ];

    /**
     * Web Bot Auth signing origins (host of Signature-Agent) => agent.
     *
     * @var array<string, array{name: string, operator: string, category: string}>
     */
    private const SIGNATURE_AGENTS = [
        'chatgpt.com'     => ['name' => 'chatgpt-agent', 'operator' => self::OPENAI, 'category' => self::CATEGORY_AGENT],
        'agent.bot.goog'  => ['name' => 'google-agent', 'operator' => 'Google', 'category' => self::CATEGORY_AGENT],
    ];

    /**
     * Agent for a user agent string, or null when it is not a known AI agent.
     *
     * @return array{name: string, operator: string, category: string, ranges?: string, shared?: bool}|null
     */
    public function matchUserAgent(string $userAgent): ?array
    {
        $ua = strtolower($userAgent);
        if ($ua === '') {
            return null;
        }
        foreach (self::AGENTS as $token => $agent) {
            if (str_contains($ua, $token)) {
                return $agent;
            }
        }
        return null;
    }

    /**
     * Agent for a Web Bot Auth signing host, or a generic signed agent.
     *
     * @return array{name: string, operator: string, category: string}
     */
    public function matchSignatureAgent(string $host): array
    {
        return self::SIGNATURE_AGENTS[$host]
            ?? ['name' => $host, 'operator' => self::operatorFromHost($host), 'category' => self::CATEGORY_OTHER];
    }

    /**
     * Operator and category for a stored agent name (for reports).
     *
     * @return array{operator: string, category: string}
     */
    public function describe(string $name): array
    {
        foreach (self::AGENTS + self::SIGNATURE_AGENTS as $agent) {
            if ($agent['name'] === $name) {
                return ['operator' => $agent['operator'], 'category' => $agent['category']];
            }
        }
        return ['operator' => '', 'category' => self::CATEGORY_PLATFORM];
    }

    /**
     * Distinct IP range list URLs, for the range refresh job.
     *
     * @return array<int, string>
     */
    public function rangeUrls(): array
    {
        $urls = [];
        foreach (self::AGENTS as $agent) {
            if (isset($agent['ranges'])) {
                $urls[$agent['ranges']] = true;
            }
        }
        return array_keys($urls);
    }

    /**
     * MCP client name (clientInfo.name, lower case) prefix => operator.
     * The first matching prefix wins.
     *
     * @var array<string, string>
     */
    private const MCP_CLIENTS = [
        'claude'        => self::ANTHROPIC,
        'anthropic'     => self::ANTHROPIC,
        'chatgpt'       => self::OPENAI,
        'openai'        => self::OPENAI,
        'codex'         => self::OPENAI,
        'gemini'        => 'Google',
        'google'        => 'Google',
        'copilot'       => 'Microsoft',
        'visual-studio' => 'Microsoft',
        'vscode'        => 'Microsoft',
        'cursor'        => 'Cursor',
        'windsurf'      => 'Windsurf',
        'perplexity'    => 'Perplexity',
        'mistral'       => 'Mistral AI',
        'le-chat'       => 'Mistral AI',
        'n8n'           => 'n8n',
        'zapier'        => 'Zapier',
        'mcp-inspector' => 'MCP Inspector (developer tool)',
        'inspector'     => 'MCP Inspector (developer tool)',
        'mcp-remote'    => 'mcp-remote (proxy)',
    ];

    /**
     * Agent name and operator for an MCP client that named itself.
     *
     * @return array{name: string, operator: string}|null null for an empty or unusable name
     */
    public function matchMcpClient(string $clientName): ?array
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($clientName)), '-');
        if ($slug === '') {
            return null;
        }
        $slug = substr($slug, 0, 64);
        foreach (self::MCP_CLIENTS as $prefix => $operator) {
            if (str_starts_with($slug, $prefix)) {
                return ['name' => $slug, 'operator' => $operator];
            }
        }
        return ['name' => $slug, 'operator' => ''];
    }

    /**
     * Whether an agent has a published IP range list (for "not checked" hints).
     */
    public function hasRanges(string $name): bool
    {
        return $this->rangesFor($name) !== null;
    }

    /**
     * URL of the IP range list for a stored agent name, or null.
     */
    public function rangesFor(string $name): ?string
    {
        foreach (self::AGENTS as $agent) {
            if ($agent['name'] === $name) {
                return $agent['ranges'] ?? null;
            }
        }
        return null;
    }

    /**
     * "crawler-key.proximic.com" => "proximic.com".
     */
    private static function operatorFromHost(string $host): string
    {
        $parts = explode('.', $host);
        return count($parts) > 2 ? implode('.', array_slice($parts, -2)) : $host;
    }

    /**
     * Every category with a human label, in display order.
     *
     * @return array<string, string>
     */
    public static function categories(): array
    {
        return [
            self::CATEGORY_PLATFORM      => 'Agent platform',
            self::CATEGORY_AGENT         => 'Browsing agent',
            self::CATEGORY_USER_FETCH    => 'User-triggered fetch',
            self::CATEGORY_SEARCH        => 'AI search',
            self::CATEGORY_TRAINING      => 'Training crawler',
            self::CATEGORY_SEARCH_ENGINE => 'Search engine',
            self::CATEGORY_OTHER         => 'Other signed bot',
        ];
    }
}

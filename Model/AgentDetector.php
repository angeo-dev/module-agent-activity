<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model;

use Angeo\AgentActivity\Model\Agent\Catalog;
use Angeo\AgentActivity\Model\Agent\Identity;

/**
 * Names the agent behind a request, without storing anything personal.
 *
 * Order of evidence:
 *  1. UCP-Agent header → host of the platform profile ("platform.example.com");
 *  2. a known AI user-agent token → a short stable name ("chatgpt-user");
 *  3. a Web Bot Auth signature (Signature-Agent + tag="web-bot-auth") → the
 *     signing host ("chatgpt.com" → "chatgpt-agent"), for agents that browse
 *     with an ordinary browser user agent;
 *  4. otherwise unknown.
 *
 * The signature is recorded as present, not as verified: checking it needs
 * the signer's key directory and is left to the CDN or WAF in front of the
 * store.
 */
class AgentDetector
{
    private const MAX_NAME = 64;

    public function __construct(
        private readonly Catalog $catalog
    ) {
    }

    /**
     * @param string $ucpAgent UCP-Agent header
     * @param string $userAgent User-Agent header
     * @param string $signatureAgent Signature-Agent header
     * @param string $signatureInput Signature-Input header
     */
    public function detect(
        string $ucpAgent,
        string $userAgent,
        string $signatureAgent = '',
        string $signatureInput = ''
    ): Identity {
        $signedBy = $this->signatureHost($signatureAgent, $signatureInput);
        $signed = $signedBy !== null;

        $profileHost = $this->ucpProfileHost($ucpAgent);
        if ($profileHost !== null) {
            return new Identity($profileHost, $profileHost, Catalog::CATEGORY_PLATFORM, Identity::SOURCE_UCP, null, $signed);
        }

        $known = $this->catalog->matchUserAgent($userAgent);
        if ($known !== null) {
            return new Identity(
                $known['name'],
                $known['operator'],
                $known['category'],
                Identity::SOURCE_USER_AGENT,
                $known['ranges'] ?? null,
                $signed
            );
        }

        if ($signedBy !== null) {
            $agent = $this->catalog->matchSignatureAgent($signedBy);
            return new Identity(
                mb_substr($agent['name'], 0, self::MAX_NAME),
                mb_substr($agent['operator'], 0, self::MAX_NAME),
                $agent['category'],
                Identity::SOURCE_SIGNATURE,
                null,
                true
            );
        }

        return new Identity('unknown', '', Catalog::CATEGORY_PLATFORM, Identity::SOURCE_UNKNOWN);
    }

    /**
     * Host of the profile URL in a UCP-Agent header (RFC 8941 dictionary).
     */
    private function ucpProfileHost(string $header): ?string
    {
        if ($header === '' || !preg_match('/profile="([^"]+)"/i', $header, $m)) {
            return null;
        }
        $host = strtolower((string) parse_url($m[1], PHP_URL_HOST));
        return $host !== '' ? mb_substr($host, 0, self::MAX_NAME) : null;
    }

    /**
     * Signing host when the request carries a Web Bot Auth signature.
     *
     * Signature-Agent comes in two forms: the legacy quoted string
     * ("https://chatgpt.com") and the dictionary form of the current draft
     * (sig1="https://chatgpt.com"). Both are accepted. Signature-Input must
     * carry tag="web-bot-auth".
     */
    private function signatureHost(string $signatureAgent, string $signatureInput): ?string
    {
        if ($signatureAgent === '' || stripos($signatureInput, 'tag="web-bot-auth"') === false) {
            return null;
        }
        if (!preg_match('#(https?://[^"\s,;]+)#i', $signatureAgent, $m)) {
            return null;
        }
        $host = strtolower((string) parse_url($m[1], PHP_URL_HOST));
        return $host !== '' && preg_match('/^[a-z0-9.\-]+$/', $host) ? $host : null;
    }
}

<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model\Verify;

use Angeo\AgentActivity\Model\Agent\Identity;

/**
 * Checks a request address against the vendor's published IP ranges.
 *
 * Uses only the locally stored lists (RangeStore). The address is used for
 * this check and then dropped; it is never written anywhere.
 *
 * Result: STATE_VERIFIED (inside the vendor list), STATE_FAILED (outside:
 * the user agent is probably spoofed), STATE_BEHIND_PROXY (the agent has a
 * list, but Magento sees a private address, i.e. a proxy or load balancer
 * without trusted forwarding headers) or null (no list for this agent, or
 * the list was never downloaded).
 */
class IpVerifier
{
    public const STATE_FAILED = 0;
    public const STATE_VERIFIED = 1;
    public const STATE_BEHIND_PROXY = 3;

    public function __construct(
        private readonly RangeStore $store,
        private readonly CidrMatcher $matcher
    ) {
    }

    public function verify(Identity $identity, string $ip): ?int
    {
        if ($identity->ranges === null || $ip === '') {
            return null;
        }
        if (!$this->isPublic($ip)) {
            return self::STATE_BEHIND_PROXY;
        }
        $prefixes = $this->store->prefixes($identity->ranges);
        if ($prefixes === null || $prefixes === []) {
            return null;
        }
        return $this->matcher->containsAny($prefixes, $ip) ? self::STATE_VERIFIED : self::STATE_FAILED;
    }

    /**
     * A private or reserved address means Magento sees a proxy, not the
     * client (trusted proxy headers not set up). No verdict then: comparing
     * the proxy address would mark every real bot as spoofed.
     */
    private function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}

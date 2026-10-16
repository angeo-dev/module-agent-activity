<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model\Verify;

/**
 * IPv4 / IPv6 CIDR membership, binary-safe (inet_pton), no extensions needed.
 */
class CidrMatcher
{
    /**
     * Whether $ip is inside $cidr ("192.0.2.0/24", "2001:db8::/32").
     */
    public function contains(string $cidr, string $ip): bool
    {
        $parts = explode('/', trim($cidr), 2);
        if (filter_var($parts[0], FILTER_VALIDATE_IP) === false || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $network = inet_pton($parts[0]);
        $address = inet_pton($ip);
        if ($network === false || $address === false || strlen($network) !== strlen($address)) {
            return false;
        }
        $maxBits = strlen($network) * 8;
        $bits = isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : $maxBits;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        if (strncmp($network, $address, $fullBytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($network[$fullBytes]) & $mask) === (ord($address[$fullBytes]) & $mask);
    }

    /**
     * Whether $ip is inside any of $cidrs.
     *
     * @param array<int, string> $cidrs
     */
    public function containsAny(array $cidrs, string $ip): bool
    {
        foreach ($cidrs as $cidr) {
            if ($this->contains($cidr, $ip)) {
                return true;
            }
        }
        return false;
    }
}

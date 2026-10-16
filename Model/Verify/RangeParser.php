<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model\Verify;

/**
 * Reads a vendor IP range list: {"prefixes":[{"ipv4Prefix":…},{"ipv6Prefix":…}]}.
 *
 * OpenAI, Anthropic, Perplexity and Google publish this shape.
 */
class RangeParser
{
    private const MAX_PREFIXES = 20000;

    /**
     * CIDR prefixes from the document.
     *
     * @param string $json
     * @return array<int, string>
     * @throws \RuntimeException when the document is not a usable list
     */
    public function parse(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_array($data['prefixes'] ?? null)) {
            throw new \RuntimeException('no "prefixes" array');
        }
        $prefixes = [];
        foreach ($data['prefixes'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $prefix = $row['ipv4Prefix'] ?? $row['ipv6Prefix'] ?? null;
            if (is_string($prefix) && preg_match('#^[0-9a-fA-F:.]+/\d{1,3}$#', $prefix)) {
                $prefixes[] = $prefix;
            }
            if (count($prefixes) >= self::MAX_PREFIXES) {
                break;
            }
        }
        if ($prefixes === []) {
            throw new \RuntimeException('empty list');
        }
        return $prefixes;
    }
}

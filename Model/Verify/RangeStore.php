<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model\Verify;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Vendor IP range lists, kept locally.
 *
 * Saved in the flag table (survives a cache flush) and mirrored in the
 * application cache for the request path. Requests never fetch anything
 * over the network: the lists are refreshed by cron (RangeRefresher).
 *
 * Shape: url => ['fetched_at' => int, 'prefixes' => string[]]
 */
class RangeStore
{
    public const FLAG_CODE = 'angeo_agent_activity_ip_ranges';
    private const CACHE_ID = 'angeo_agent_activity_ip_ranges';
    private const CACHE_TAG = 'CONFIG';
    private const CACHE_LIFETIME = 86400;

    /** @var array<string, array{fetched_at: int, prefixes: array<int, string>}>|null */
    private ?array $memo = null;

    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer
    ) {
    }

    /**
     * Prefixes for one list URL, or null when the list was never fetched.
     *
     * @return array<int, string>|null
     */
    public function prefixes(string $url): ?array
    {
        $all = $this->all();
        return isset($all[$url]) ? $all[$url]['prefixes'] : null;
    }

    /**
     * @return array<string, array{fetched_at: int, prefixes: array<int, string>}>
     */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }
        $cached = $this->cache->load(self::CACHE_ID);
        if (is_string($cached) && $cached !== '') {
            $data = $this->serializer->unserialize($cached);
            if (is_array($data)) {
                return $this->memo = $data;
            }
        }
        $data = $this->flagManager->getFlagData(self::FLAG_CODE);
        $this->memo = is_array($data) ? $data : [];
        $this->cache->save(
            (string) $this->serializer->serialize($this->memo),
            self::CACHE_ID,
            [self::CACHE_TAG],
            self::CACHE_LIFETIME
        );
        return $this->memo;
    }

    /**
     * @param array<string, array{fetched_at: int, prefixes: array<int, string>}> $lists
     */
    public function save(array $lists): void
    {
        $this->flagManager->saveFlag(self::FLAG_CODE, $lists);
        $this->cache->remove(self::CACHE_ID);
        $this->memo = $lists;
    }
}

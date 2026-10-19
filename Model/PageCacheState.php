<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model;

/**
 * Whether the built-in full page cache served this request (shared instance).
 *
 * Set by Plugin\PageCacheKernel, read by Observer\RecordRequest. Stays null
 * when the built-in cache did not run: cache off, Varnish, or a route that
 * is never cached. Varnish hits never reach Magento at all.
 */
class PageCacheState
{
    private ?bool $hit = null;

    public function set(bool $hit): void
    {
        $this->hit = $hit;
    }

    /**
     * True for a hit, false for a miss, null when unknown.
     */
    public function get(): ?bool
    {
        return $this->hit;
    }
}

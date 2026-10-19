<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Plugin;

use Angeo\AgentActivity\Model\PageCacheState;
use Magento\Framework\App\PageCache\Kernel;

/**
 * Notes whether the built-in full page cache had the page.
 *
 * Kernel::load() returns the cached response or false. It is called only
 * for cacheable GET/HEAD requests, so "unknown" stays the answer for all
 * other requests.
 */
class PageCacheKernel
{
    public function __construct(
        private readonly PageCacheState $state
    ) {
    }

    /**
     * @param Kernel $subject
     * @param mixed $result cached response, or false
     * @return mixed
     */
    public function afterLoad(Kernel $subject, mixed $result): mixed
    {
        $this->state->set($result !== false && $result !== null);
        return $result;
    }
}

<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model\Report;

use Magento\Framework\App\RequestInterface;

/**
 * Reads the report scope from request parameters:
 * period=7|30|90, or from=Y-m-d&to=Y-m-d, plus store=<store view id>.
 */
class FilterResolver
{
    public const PERIODS = [7, 30, 90];
    public const DEFAULT_PERIOD = 30;

    /**
     * Filter for the request; falls back to the last 30 days.
     */
    public function fromRequest(RequestInterface $request): Filter
    {
        $storeId = $this->storeId($request);
        $custom = $this->custom($request, $storeId);
        return $custom ?? Filter::lastDays($this->presetPeriod($request), $storeId);
    }

    /**
     * Preset period in use, or 0 when a custom range is in use.
     */
    public function period(RequestInterface $request): int
    {
        return $this->custom($request, null) !== null ? 0 : $this->presetPeriod($request);
    }

    private function presetPeriod(RequestInterface $request): int
    {
        $period = (int) $request->getParam('period', self::DEFAULT_PERIOD);
        return in_array($period, self::PERIODS, true) ? $period : self::DEFAULT_PERIOD;
    }

    private function custom(RequestInterface $request, ?int $storeId): ?Filter
    {
        $from = (string) $request->getParam('from', '');
        $to = (string) $request->getParam('to', '');
        return $from !== '' && $to !== '' ? Filter::range($from, $to, $storeId) : null;
    }

    private function storeId(RequestInterface $request): ?int
    {
        $store = (string) $request->getParam('store', '');
        return ctype_digit($store) && (int) $store > 0 ? (int) $store : null;
    }
}

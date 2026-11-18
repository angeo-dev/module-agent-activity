<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model\Verify;

use Angeo\AgentActivity\Model\Agent\Catalog;
use Magento\Framework\FlagManager;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

/**
 * Downloads the vendor IP range lists (cron, daily, or CLI).
 *
 * HTTPS only, 10 s timeout, at most 3 redirects (HTTPS only), 2 MB cap.
 * A list that fails to download or parse keeps its previous copy, so a
 * vendor outage never turns verified bots into "spoofed".
 */
class RangeRefresher
{
    /** Flag with the outcome of the last run, for the report and the CLI. */
    public const FLAG_STATUS = 'angeo_agent_activity_ip_ranges_status';

    private const TIMEOUT = 10;
    private const MAX_BYTES = 2097152;

    public function __construct(
        private readonly Catalog $catalog,
        private readonly RangeStore $store,
        private readonly RangeParser $parser,
        private readonly CurlFactory $curlFactory,
        private readonly LoggerInterface $logger,
        private readonly FlagManager $flagManager
    ) {
    }

    /**
     * Refresh every list.
     *
     * @return array<string, string> url => "ok: N prefixes" or "failed: reason"
     */
    public function refresh(): array
    {
        $lists = $this->store->all();
        $report = [];
        foreach ($this->catalog->rangeUrls() as $url) {
            try {
                $prefixes = $this->parser->parse($this->download($url));
                $lists[$url] = ['fetched_at' => time(), 'prefixes' => $prefixes];
                $report[$url] = sprintf('ok: %d prefixes', count($prefixes));
            } catch (\Throwable $e) {
                $report[$url] = 'failed: ' . $e->getMessage();
                $this->logger->info(sprintf('[Angeo_AgentActivity] IP range list %s: %s', $url, $e->getMessage()));
            }
        }
        $this->store->save($lists);
        $failed = array_filter($report, static fn (string $r): bool => str_starts_with($r, 'failed'));
        $this->flagManager->saveFlag(self::FLAG_STATUS, [
            'ran_at' => time(),
            'ok'     => count($report) - count($failed),
            'failed' => count($failed),
            'errors' => array_slice($failed, 0, 5, true),
        ]);
        return $report;
    }

    /**
     * Outcome of the last run, or null when it never ran.
     *
     * @return array{ran_at: int, ok: int, failed: int, errors: array<string, string>}|null
     */
    public function lastStatus(): ?array
    {
        $data = $this->flagManager->getFlagData(self::FLAG_STATUS);
        if (!is_array($data) || !isset($data['ran_at'])) {
            return null;
        }
        return [
            'ran_at' => (int) $data['ran_at'],
            'ok'     => (int) ($data['ok'] ?? 0),
            'failed' => (int) ($data['failed'] ?? 0),
            'errors' => is_array($data['errors'] ?? null) ? $data['errors'] : [],
        ];
    }

    /**
     * @throws \RuntimeException on a network or HTTP error
     */
    private function download(string $url): string
    {
        if (!str_starts_with($url, 'https://')) {
            throw new \RuntimeException('not an HTTPS URL');
        }
        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT);
        $curl->setOptions([
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $curl->addHeader('Accept', 'application/json');
        $curl->get($url);

        $status = $curl->getStatus();
        $body = $curl->getBody();
        if ($status !== 200) {
            throw new \RuntimeException('HTTP ' . $status);
        }
        if (strlen($body) > self::MAX_BYTES) {
            throw new \RuntimeException('response too large');
        }
        return $body;
    }
}

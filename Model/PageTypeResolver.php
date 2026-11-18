<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Model;

use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Psr\Log\LoggerInterface;

/**
 * Page type for a crawler request that never reached the router.
 *
 * A full page cache hit is answered before routing, so Magento does not
 * know the route ("catalog_product_view"). Up to 1.1.0 such hits were
 * all logged as "page". This looks the path up in the URL rewrite table
 * (one indexed query, only for known AI crawlers and agents) and returns
 * the route the rewrite leads to.
 *
 * @since 1.2.0
 */
class PageTypeResolver
{
    private const ENTITY_ROUTES = [
        'product'  => 'catalog_product_view',
        'category' => 'catalog_category_view',
        'cms-page' => 'cms_page_view',
    ];

    /** @var array<string, string> */
    private array $memo = [];

    public function __construct(
        private readonly UrlFinderInterface $urlFinder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Route name ("catalog_product_view", …) for a request path, or '' when unknown.
     */
    public function actionFor(string $path, int $storeId): string
    {
        $requestPath = ltrim($path, '/');
        if ($requestPath === '' || strlen($requestPath) > 255 || str_ends_with($requestPath, '.txt')) {
            return '';
        }
        $key = $storeId . ':' . $requestPath;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        try {
            $rewrite = $this->urlFinder->findOneByData([
                UrlRewrite::REQUEST_PATH => $requestPath,
                UrlRewrite::STORE_ID     => $storeId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->debug('[Angeo_AgentActivity] URL rewrite lookup failed: ' . $e->getMessage());
            $rewrite = null;
        }
        $type = $rewrite !== null ? (string) $rewrite->getEntityType() : '';
        return $this->memo[$key] = self::ENTITY_ROUTES[$type] ?? '';
    }
}

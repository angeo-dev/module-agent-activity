<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Test\Unit\Model;

use Angeo\AgentActivity\Model\PageTypeResolver;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class PageTypeResolverTest extends TestCase
{
    public function testRewriteEntityTypeNamesTheRoute(): void
    {
        $rewrite = $this->createMock(UrlRewrite::class);
        $rewrite->method('getEntityType')->willReturn('product');
        $finder = $this->createMock(UrlFinderInterface::class);
        $finder->expects(self::once())->method('findOneByData')
            ->with([UrlRewrite::REQUEST_PATH => 'hero-hoodie.html', UrlRewrite::STORE_ID => 1])
            ->willReturn($rewrite);

        $resolver = new PageTypeResolver($finder, new NullLogger());
        self::assertSame('catalog_product_view', $resolver->actionFor('/hero-hoodie.html', 1));
        self::assertSame('catalog_product_view', $resolver->actionFor('/hero-hoodie.html', 1), 'memoised');
    }

    public function testUnknownPathsAndFailuresGiveNothing(): void
    {
        $finder = $this->createMock(UrlFinderInterface::class);
        $finder->method('findOneByData')->willThrowException(new \RuntimeException('db down'));

        $resolver = new PageTypeResolver($finder, new NullLogger());
        self::assertSame('', $resolver->actionFor('/about.html', 1));
        self::assertSame('', $resolver->actionFor('/robots.txt', 1));
        self::assertSame('', $resolver->actionFor('/', 1));
    }
}

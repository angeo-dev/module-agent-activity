<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Test\Unit\Model\Verify;

use Angeo\AgentActivity\Model\Verify\RangeParser;
use PHPUnit\Framework\TestCase;

class RangeParserTest extends TestCase
{
    public function testVendorShape(): void
    {
        $json = '{"creationTime":"2026-10-01T00:00:00Z","prefixes":['
            . '{"ipv4Prefix":"216.73.216.0/22"},{"ipv6Prefix":"2600:1f1c::/32"},{"other":"x"},"junk",'
            . '{"ipv4Prefix":"1.2.3.4/32; DROP"}]}';
        self::assertSame(['216.73.216.0/22', '2600:1f1c::/32'], (new RangeParser())->parse($json));
    }

    public function testNotAListThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new RangeParser())->parse('<html>maintenance</html>');
    }

    public function testEmptyListThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new RangeParser())->parse('{"prefixes":[]}');
    }
}

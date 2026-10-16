<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Test\Unit\Model\Verify;

use Angeo\AgentActivity\Model\Verify\CidrMatcher;
use PHPUnit\Framework\TestCase;

class CidrMatcherTest extends TestCase
{
    public function testIpv4(): void
    {
        $m = new CidrMatcher();
        self::assertTrue($m->contains('216.73.216.0/22', '216.73.219.255'));
        self::assertFalse($m->contains('216.73.216.0/22', '216.73.220.0'));
        self::assertTrue($m->contains('34.162.230.222/32', '34.162.230.222'));
        self::assertFalse($m->contains('34.162.230.222/32', '34.162.230.223'));
        self::assertTrue($m->contains('0.0.0.0/0', '8.8.8.8'));
        self::assertTrue($m->contains('10.1.2.3', '10.1.2.3'), 'no prefix length = single address');
    }

    public function testIpv6(): void
    {
        $m = new CidrMatcher();
        self::assertTrue($m->contains('2600:1f1c::/32', '2600:1f1c:abcd::1'));
        self::assertFalse($m->contains('2600:1f1c::/32', '2600:1f1d::1'));
        self::assertTrue($m->contains('2001:db8::/33', '2001:db8:7fff::1'));
        self::assertFalse($m->contains('2001:db8::/33', '2001:db8:8000::1'));
    }

    public function testMixedFamiliesAndGarbageNeverMatch(): void
    {
        $m = new CidrMatcher();
        self::assertFalse($m->contains('2600:1f1c::/32', '216.73.216.1'));
        self::assertFalse($m->contains('216.73.216.0/22', '::ffff:216.73.216.1'));
        self::assertFalse($m->contains('not-a-cidr', '1.2.3.4'));
        self::assertFalse($m->contains('1.2.3.0/40', '1.2.3.4'));
        self::assertFalse($m->contains('1.2.3.0/24', ''));
        self::assertTrue($m->containsAny(['9.9.9.0/24', '1.2.3.0/24'], '1.2.3.4'));
        self::assertFalse($m->containsAny([], '1.2.3.4'));
    }
}

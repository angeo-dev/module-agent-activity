<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Test\Unit\Model\Verify;

use Angeo\AgentActivity\Model\Agent\Identity;
use Angeo\AgentActivity\Model\Verify\CidrMatcher;
use Angeo\AgentActivity\Model\Verify\IpVerifier;
use Angeo\AgentActivity\Model\Verify\RangeStore;
use PHPUnit\Framework\TestCase;

class IpVerifierTest extends TestCase
{
    private const LIST = 'https://openai.com/gptbot.json';

    private function verifier(?array $prefixes): IpVerifier
    {
        $store = $this->createMock(RangeStore::class);
        $store->method('prefixes')->willReturnCallback(
            static fn (string $url) => $url === self::LIST ? $prefixes : null
        );
        return new IpVerifier($store, new CidrMatcher());
    }

    private function gptbot(): Identity
    {
        return new Identity('openai-training', 'OpenAI', 'training', Identity::SOURCE_USER_AGENT, self::LIST);
    }

    public function testInsideIsVerifiedOutsideIsSpoofed(): void
    {
        $verifier = $this->verifier(['20.171.206.0/24']);
        self::assertSame(IpVerifier::STATE_VERIFIED, $verifier->verify($this->gptbot(), '20.171.206.17'));
        self::assertSame(IpVerifier::STATE_FAILED, $verifier->verify($this->gptbot(), '203.0.113.9'));
    }

    public function testUnknownWhenNoListOrNoAddress(): void
    {
        self::assertNull($this->verifier(null)->verify($this->gptbot(), '20.171.206.17'), 'list never downloaded');
        self::assertNull($this->verifier(['20.171.206.0/24'])->verify($this->gptbot(), ''));
        self::assertSame(
            IpVerifier::STATE_BEHIND_PROXY,
            $this->verifier(['20.171.206.0/24'])->verify($this->gptbot(), '10.0.0.5'),
            'proxy address'
        );
        self::assertSame(
            IpVerifier::STATE_BEHIND_PROXY,
            $this->verifier(['20.171.206.0/24'])->verify($this->gptbot(), '127.0.0.1'),
            'loopback'
        );
        $noList = new Identity('bytedance', 'ByteDance', 'training', Identity::SOURCE_USER_AGENT);
        self::assertNull($this->verifier(['20.171.206.0/24'])->verify($noList, '20.171.206.17'));
    }
}

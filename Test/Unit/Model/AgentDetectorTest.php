<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AgentActivity\Test\Unit\Model;

use Angeo\AgentActivity\Model\Agent\Catalog;
use Angeo\AgentActivity\Model\Agent\Identity;
use Angeo\AgentActivity\Model\AgentDetector;
use PHPUnit\Framework\TestCase;

class AgentDetectorTest extends TestCase
{
    private AgentDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new AgentDetector(new Catalog());
    }

    public function testUcpProfileHostWins(): void
    {
        $id = $this->detector->detect(
            'profile="https://Platform.Example.com/.well-known/ucp"; version="2026-08-25"',
            'Mozilla/5.0 (compatible; ChatGPT-User/1.0)'
        );
        self::assertSame('platform.example.com', $id->name);
        self::assertSame(Catalog::CATEGORY_PLATFORM, $id->category);
        self::assertSame(Identity::SOURCE_UCP, $id->source);
        self::assertFalse($id->isKnownAiAgent());
    }

    public function testKnownUserAgents(): void
    {
        $cases = [
            'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ChatGPT-User/1.0; +https://openai.com/bot' => ['chatgpt-user', 'OpenAI', 'user_fetch'],
            'Mozilla/5.0 (compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot)' => ['openai-search', 'OpenAI', 'search'],
            'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2' => ['openai-training', 'OpenAI', 'training'],
            'Mozilla/5.0 (compatible; Claude-User/1.0; +Claude-User@anthropic.com)' => ['claude-user', 'Anthropic', 'user_fetch'],
            'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)' => ['anthropic-training', 'Anthropic', 'training'],
            'Mozilla/5.0 (compatible; Claude-SearchBot/1.0)' => ['claude-search', 'Anthropic', 'search'],
            'Mozilla/5.0 (compatible; PerplexityBot/1.0)' => ['perplexity', 'Perplexity', 'search'],
            'Mozilla/5.0 (compatible; Perplexity-User/1.0)' => ['perplexity-user', 'Perplexity', 'user_fetch'],
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko; compatible; Google-Agent; +https://developers.google.com/crawling/docs/crawlers-fetchers/google-agent) Chrome/141.0 Safari/537.36' => ['google-agent', 'Google', 'agent'],
            'meta-externalagent/1.1' => ['meta', 'Meta', 'training'],
            'Mozilla/5.0 (compatible; GrokAgent/1.0)' => ['grok-agent', 'xAI', 'agent'],
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' => ['googlebot', 'Google', 'search_engine'],
        ];
        foreach ($cases as $ua => [$name, $operator, $category]) {
            $id = $this->detector->detect('', $ua);
            self::assertSame($name, $id->name, $ua);
            self::assertSame($operator, $id->operator, $ua);
            self::assertSame($category, $id->category, $ua);
            self::assertTrue($id->isKnownAiAgent(), $ua);
        }
    }

    public function testVendorRangesAreAttached(): void
    {
        self::assertSame('https://openai.com/gptbot.json', $this->detector->detect('', 'GPTBot/1.2')->ranges);
        self::assertSame('https://claude.com/crawling/bots.json', $this->detector->detect('', 'ClaudeBot/1.0')->ranges);
        self::assertNull($this->detector->detect('', 'Bytespider')->ranges);
        self::assertSame(
            'https://developers.google.com/static/crawling/ipranges/common-crawlers.json',
            $this->detector->detect('', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')->ranges
        );
        self::assertSame(
            'https://www.bing.com/toolbox/bingbot.json',
            $this->detector->detect('', 'Mozilla/5.0 (compatible; bingbot/2.0)')->ranges
        );
    }

    public function testOrdinaryBrowserIsUnknown(): void
    {
        $id = $this->detector->detect('', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 Safari/605.1.15');
        self::assertSame('unknown', $id->name);
        self::assertFalse($id->isKnownAiAgent());
    }

    public function testWebBotAuthSignatureNamesABrowsingAgent(): void
    {
        $chrome = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/140.0 Safari/537.36';
        $input = 'sig1=("@authority" "signature-agent");created=1735689600;keyid="abc";tag="web-bot-auth"';

        $legacy = $this->detector->detect('', $chrome, '"https://chatgpt.com"', $input);
        self::assertSame('chatgpt-agent', $legacy->name);
        self::assertSame(Catalog::CATEGORY_AGENT, $legacy->category);
        self::assertTrue($legacy->signed);
        self::assertTrue($legacy->isKnownAiAgent());

        $dictionary = $this->detector->detect('', $chrome, 'sig1="https://agent.bot.goog"', $input);
        self::assertSame('google-agent', $dictionary->name);

        $unknownSigner = $this->detector->detect('', $chrome, '"https://bots.example.net"', $input);
        self::assertSame('bots.example.net', $unknownSigner->name);
        self::assertSame(Catalog::CATEGORY_OTHER, $unknownSigner->category, 'unknown signers are not AI agents');
        self::assertSame('example.net', $unknownSigner->operator);
    }

    public function testSignatureWithoutWebBotAuthTagIsIgnored(): void
    {
        $id = $this->detector->detect('', 'Mozilla/5.0', '"https://chatgpt.com"', 'sig1=("@method");tag="other"');
        self::assertSame('unknown', $id->name);
        self::assertFalse($id->signed);
    }

    public function testKnownUserAgentKeepsSignedFlag(): void
    {
        $id = $this->detector->detect(
            '',
            'Mozilla/5.0 (compatible; ChatGPT-User/1.0)',
            '"https://chatgpt.com"',
            'sig1=();tag="web-bot-auth"'
        );
        self::assertSame('chatgpt-user', $id->name);
        self::assertTrue($id->signed);
    }
}

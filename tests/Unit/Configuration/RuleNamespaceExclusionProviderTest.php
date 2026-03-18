<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Configuration;

use AiMessDetector\Configuration\RuleNamespaceExclusionProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuleNamespaceExclusionProvider::class)]
final class RuleNamespaceExclusionProviderTest extends TestCase
{
    private RuleNamespaceExclusionProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new RuleNamespaceExclusionProvider();
    }

    public function testRuleWithNoExclusionsReturnsFalse(): void
    {
        self::assertFalse($this->provider->isExcluded('some.rule', 'App\\Core'));
        self::assertSame([], $this->provider->getExclusions('some.rule'));
    }

    public function testExactMatch(): void
    {
        $this->provider->setExclusions('rule1', ['App\\Core']);

        self::assertTrue($this->provider->isExcluded('rule1', 'App\\Core'));
        self::assertFalse($this->provider->isExcluded('rule1', 'App\\Service'));
    }

    public function testPrefixMatch(): void
    {
        $this->provider->setExclusions('rule1', ['App\\Core']);

        self::assertTrue($this->provider->isExcluded('rule1', 'App\\Core\\Exception'));
        self::assertTrue($this->provider->isExcluded('rule1', 'App\\Core\\Symbol\\Deep'));
    }

    public function testNoFalsePrefixMatch(): void
    {
        $this->provider->setExclusions('rule1', ['App\\Core']);

        self::assertFalse($this->provider->isExcluded('rule1', 'App\\CoreExtra'));
    }

    public function testTrailingBackslashHandled(): void
    {
        $this->provider->setExclusions('rule1', ['App\\Core\\']);

        self::assertTrue($this->provider->isExcluded('rule1', 'App\\Core'));
        self::assertTrue($this->provider->isExcluded('rule1', 'App\\Core\\Sub'));
    }

    public function testDifferentRulesHaveIndependentExclusions(): void
    {
        $this->provider->setExclusions('rule1', ['App\\Core']);
        $this->provider->setExclusions('rule2', ['App\\Service']);

        self::assertTrue($this->provider->isExcluded('rule1', 'App\\Core'));
        self::assertFalse($this->provider->isExcluded('rule1', 'App\\Service'));

        self::assertFalse($this->provider->isExcluded('rule2', 'App\\Core'));
        self::assertTrue($this->provider->isExcluded('rule2', 'App\\Service'));
    }

    public function testEmptyArrayNotStored(): void
    {
        $this->provider->setExclusions('rule1', []);

        self::assertSame([], $this->provider->getExclusions('rule1'));
        self::assertFalse($this->provider->isExcluded('rule1', 'App\\Core'));
    }

    public function testResetClearsAllExclusions(): void
    {
        $this->provider->setExclusions('rule1', ['App\\Core']);
        $this->provider->setExclusions('rule2', ['App\\Service']);

        $this->provider->reset();

        self::assertFalse($this->provider->isExcluded('rule1', 'App\\Core'));
        self::assertFalse($this->provider->isExcluded('rule2', 'App\\Service'));
        self::assertSame([], $this->provider->getExclusions('rule1'));
    }

    public function testGetExclusions(): void
    {
        $prefixes = ['App\\Core', 'App\\Tests'];
        $this->provider->setExclusions('rule1', $prefixes);

        self::assertSame($prefixes, $this->provider->getExclusions('rule1'));
    }

    public function testMultiplePrefixes(): void
    {
        $this->provider->setExclusions('rule1', ['App\\Core', 'App\\Tests']);

        self::assertTrue($this->provider->isExcluded('rule1', 'App\\Core'));
        self::assertTrue($this->provider->isExcluded('rule1', 'App\\Tests'));
        self::assertTrue($this->provider->isExcluded('rule1', 'App\\Tests\\Unit'));
        self::assertFalse($this->provider->isExcluded('rule1', 'App\\Service'));
    }
}

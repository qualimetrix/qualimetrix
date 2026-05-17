<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\RuleNamespaceExclusionProvider;

#[CoversClass(RuleNamespaceExclusionProvider::class)]
final class RuleNamespaceExclusionProviderTest extends TestCase
{
    private RuleNamespaceExclusionProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new RuleNamespaceExclusionProvider();
    }

    #[Test]
    public function itReturnsFalseForRuleWithNoExclusions(): void
    {
        self::assertFalse($this->provider->isExcluded('some.rule', 'App\\Core'));
        self::assertSame([], $this->provider->getExclusions('some.rule'));
    }

    #[Test]
    public function itMatchesExactNamespace(): void
    {
        $this->provider->setExclusions('rule1', ['App\\Core']);

        self::assertTrue($this->provider->isExcluded('rule1', 'App\\Core'));
        self::assertFalse($this->provider->isExcluded('rule1', 'App\\Service'));
    }

    #[Test]
    public function itMatchesByNamespacePrefix(): void
    {
        $this->provider->setExclusions('rule1', ['App\\Core']);

        self::assertTrue($this->provider->isExcluded('rule1', 'App\\Core\\Exception'));
        self::assertTrue($this->provider->isExcluded('rule1', 'App\\Core\\Symbol\\Deep'));
    }

    #[Test]
    public function itDoesNotFalselyMatchPrefix(): void
    {
        $this->provider->setExclusions('rule1', ['App\\Core']);

        self::assertFalse($this->provider->isExcluded('rule1', 'App\\CoreExtra'));
    }

    #[Test]
    public function itHandlesTrailingBackslash(): void
    {
        $this->provider->setExclusions('rule1', ['App\\Core\\']);

        self::assertTrue($this->provider->isExcluded('rule1', 'App\\Core'));
        self::assertTrue($this->provider->isExcluded('rule1', 'App\\Core\\Sub'));
    }

    #[Test]
    public function itKeepsDifferentRulesExclusionsIndependent(): void
    {
        $this->provider->setExclusions('rule1', ['App\\Core']);
        $this->provider->setExclusions('rule2', ['App\\Service']);

        self::assertTrue($this->provider->isExcluded('rule1', 'App\\Core'));
        self::assertFalse($this->provider->isExcluded('rule1', 'App\\Service'));

        self::assertFalse($this->provider->isExcluded('rule2', 'App\\Core'));
        self::assertTrue($this->provider->isExcluded('rule2', 'App\\Service'));
    }

    #[Test]
    public function itDoesNotStoreEmptyArray(): void
    {
        $this->provider->setExclusions('rule1', []);

        self::assertSame([], $this->provider->getExclusions('rule1'));
        self::assertFalse($this->provider->isExcluded('rule1', 'App\\Core'));
    }

    #[Test]
    public function itClearsAllExclusionsOnReset(): void
    {
        $this->provider->setExclusions('rule1', ['App\\Core']);
        $this->provider->setExclusions('rule2', ['App\\Service']);

        $this->provider->reset();

        self::assertFalse($this->provider->isExcluded('rule1', 'App\\Core'));
        self::assertFalse($this->provider->isExcluded('rule2', 'App\\Service'));
        self::assertSame([], $this->provider->getExclusions('rule1'));
    }

    #[Test]
    public function itGetsExclusions(): void
    {
        $prefixes = ['App\\Core', 'App\\Tests'];
        $this->provider->setExclusions('rule1', $prefixes);

        self::assertSame($prefixes, $this->provider->getExclusions('rule1'));
    }

    #[Test]
    public function itHandlesMultiplePrefixes(): void
    {
        $this->provider->setExclusions('rule1', ['App\\Core', 'App\\Tests']);

        self::assertTrue($this->provider->isExcluded('rule1', 'App\\Core'));
        self::assertTrue($this->provider->isExcluded('rule1', 'App\\Tests'));
        self::assertTrue($this->provider->isExcluded('rule1', 'App\\Tests\\Unit'));
        self::assertFalse($this->provider->isExcluded('rule1', 'App\\Service'));
    }
}

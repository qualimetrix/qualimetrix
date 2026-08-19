<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Exclusion\RuleNamespaceExclusionProvider;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;

#[CoversClass(RuleNamespaceExclusionProvider::class)]
final class RuleNamespaceExclusionProviderTest extends TestCase
{
    private RuleNamespaceExclusionProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new RuleNamespaceExclusionProvider();
    }

    #[Test]
    public function itConfiguresAndQueriesNamespaceExclusionsWithoutProviderAccess(): void
    {
        $configuration = new RuleOptionsRegistry();
        $configuration->configureNamespaceExclusions('test.rule', ['App\\Generated']);

        self::assertTrue($configuration->isNamespaceExcluded('test.rule', 'App\\Generated\\Model'));
        self::assertFalse($configuration->isNamespaceExcluded('test.rule', 'App\\Domain'));
    }

    #[Test]
    public function itConfiguresAndQueriesNamespaceChannelExclusionsWithoutProviderAccess(): void
    {
        $configuration = new RuleOptionsRegistry();
        $configuration->configureNamespaceChannelExclusions('computed.health', [
            'health.cohesion' => ['App\\Generated'],
        ]);

        self::assertTrue($configuration->isNamespaceChannelExcluded(
            'computed.health',
            'health.cohesion',
            'App\\Generated\\Model',
        ));
        self::assertFalse($configuration->isNamespaceChannelExcluded(
            'computed.health',
            'health.coupling',
            'App\\Generated\\Model',
        ));
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

    #[Test]
    public function itScopesNamespaceExclusionToTheViolationCode(): void
    {
        $this->provider->setChannelExclusions(
            'computed.health',
            'health.cohesion',
            ['App\\Metrics'],
        );

        self::assertTrue($this->provider->isChannelExcluded(
            'computed.health',
            'health.cohesion',
            'App\\Metrics\\Coupling',
        ));
        self::assertFalse($this->provider->isChannelExcluded(
            'computed.health',
            'health.coupling',
            'App\\Metrics\\Coupling',
        ));
        self::assertFalse($this->provider->isChannelExcluded(
            'other.producer',
            'health.cohesion',
            'App\\Metrics\\Coupling',
        ));
    }

    #[Test]
    public function itUsesGroupSelectorSemanticsForChannelExclusions(): void
    {
        $this->provider->setChannelExclusions('computed.health', 'health.*', ['App\\Metrics']);

        self::assertTrue($this->provider->isChannelExcluded(
            'computed.health',
            'health.cohesion',
            'App\\Metrics',
        ));
        self::assertFalse($this->provider->isChannelExcluded(
            'computed.health',
            'computed.risk',
            'App\\Metrics',
        ));
    }

    #[Test]
    public function itDoesNotTreatABareSelectorPrefixAsAGroup(): void
    {
        // The option's own docblock used to advertise `health` as a group.
        $this->provider->setChannelExclusions('computed.health', 'health', ['App\\Metrics']);

        self::assertFalse($this->provider->isChannelExcluded(
            'computed.health',
            'health.cohesion',
            'App\\Metrics',
        ));
    }

    #[Test]
    public function itClearsChannelExclusionsOnReset(): void
    {
        $this->provider->setChannelExclusions('computed.health', 'health.cohesion', ['App\\Metrics']);

        $this->provider->reset();

        self::assertSame([], $this->provider->getChannelExclusions('computed.health'));
        self::assertFalse($this->provider->isChannelExcluded(
            'computed.health',
            'health.cohesion',
            'App\\Metrics',
        ));
    }
}

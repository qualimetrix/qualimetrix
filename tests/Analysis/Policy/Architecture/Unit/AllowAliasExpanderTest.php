<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit\Configuration\Allow;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\AllowAliasExpander;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureConfigurationException;

#[CoversClass(AllowAliasExpander::class)]
final class AllowAliasExpanderTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Direct DependencyType tokens
    // -------------------------------------------------------------------------

    #[Test]
    public function itExpandsASingleDirectTokenToItsOwnEnumCase(): void
    {
        $result = AllowAliasExpander::expand(['extends'], 'architecture.allow.app[0]');

        self::assertSame([DependencyType::Extends], $result);
    }

    #[Test]
    public function itPreservesInputOrderForMultipleDirectTokens(): void
    {
        $result = AllowAliasExpander::expand(
            ['static_call', 'extends', 'attribute'],
            'architecture.allow.app[0]',
        );

        self::assertSame(
            [DependencyType::StaticCall, DependencyType::Extends, DependencyType::Attribute],
            $result,
        );
    }

    /**
     * Reflective drift test: every existing {@see DependencyType} case must
     * round-trip through the expander unchanged. This is the mechanism that
     * keeps the user-facing surface in sync with the collector: when a new
     * case is added to the enum, this data-provider grows automatically and
     * the test will fail loudly if the expander accidentally hard-codes the
     * accepted list instead of consulting {@see DependencyType::cases()}.
     *
     * @return iterable<string, array{DependencyType}>
     */
    public static function everyDependencyTypeCase(): iterable
    {
        foreach (DependencyType::cases() as $case) {
            yield $case->value => [$case];
        }
    }

    #[Test]
    #[DataProvider('everyDependencyTypeCase')]
    public function itAcceptsEveryDependencyTypeCaseAsADirectToken(DependencyType $case): void
    {
        $result = AllowAliasExpander::expand([$case->value], 'architecture.allow.app[0]');

        self::assertSame([$case], $result);
    }

    // -------------------------------------------------------------------------
    // Alias expansion
    // -------------------------------------------------------------------------

    #[Test]
    public function itExpandsTheInheritanceAliasToExtendsImplementsAndTraitUse(): void
    {
        $result = AllowAliasExpander::expand(['inheritance'], 'architecture.allow.app[0]');

        self::assertSame(
            [DependencyType::Extends, DependencyType::Implements, DependencyType::TraitUse],
            $result,
        );
    }

    #[Test]
    public function itExpandsTheStaticAccessAliasToStaticCallStaticPropertyAndClassConst(): void
    {
        $result = AllowAliasExpander::expand(['static_access'], 'architecture.allow.app[0]');

        self::assertSame(
            [
                DependencyType::StaticCall,
                DependencyType::StaticPropertyFetch,
                DependencyType::ClassConstFetch,
            ],
            $result,
        );
    }

    #[Test]
    public function itExpandsTheTypeReferenceAliasToFourTypeKinds(): void
    {
        $result = AllowAliasExpander::expand(['type_reference'], 'architecture.allow.app[0]');

        self::assertSame(
            [
                DependencyType::TypeHint,
                DependencyType::PropertyType,
                DependencyType::IntersectionType,
                DependencyType::UnionType,
            ],
            $result,
        );
    }

    #[Test]
    public function itExpandsTheRuntimeCheckAliasToCatchAndInstanceof(): void
    {
        $result = AllowAliasExpander::expand(['runtime_check'], 'architecture.allow.app[0]');

        self::assertSame(
            [DependencyType::Catch_, DependencyType::Instanceof_],
            $result,
        );
    }

    #[Test]
    public function itTreatsAttributeAsAStandaloneTokenNotAnAlias(): void
    {
        // `attribute` is intentionally NOT grouped under any alias — ADR 0007
        // marks it as a distinct metadata category. Confirm the token round-trips
        // through the direct-value path (no expansion).
        $result = AllowAliasExpander::expand(['attribute'], 'architecture.allow.app[0]');

        self::assertSame([DependencyType::Attribute], $result);
    }

    // -------------------------------------------------------------------------
    // Mix + dedup
    // -------------------------------------------------------------------------

    #[Test]
    public function itDedupesADirectTokenThatRepeatsAPrecedingAliasMember(): void
    {
        $result = AllowAliasExpander::expand(
            ['inheritance', 'extends'],
            'architecture.allow.app[0]',
        );

        // `extends` is already produced by the `inheritance` alias; dedup
        // drops it on the second occurrence.
        self::assertSame(
            [DependencyType::Extends, DependencyType::Implements, DependencyType::TraitUse],
            $result,
        );
    }

    #[Test]
    public function itAppendsAnUnrelatedDirectTokenAfterAliasMembers(): void
    {
        $result = AllowAliasExpander::expand(
            ['inheritance', 'static_call'],
            'architecture.allow.app[0]',
        );

        self::assertSame(
            [
                DependencyType::Extends,
                DependencyType::Implements,
                DependencyType::TraitUse,
                DependencyType::StaticCall,
            ],
            $result,
        );
    }

    #[Test]
    public function itDedupesRepeatedDirectTokens(): void
    {
        $result = AllowAliasExpander::expand(
            ['extends', 'extends', 'attribute', 'extends'],
            'architecture.allow.app[0]',
        );

        self::assertSame(
            [DependencyType::Extends, DependencyType::Attribute],
            $result,
        );
    }

    #[Test]
    public function itDedupesAcrossOverlappingAliasMembers(): void
    {
        // Aliases never overlap as of Phase 2 ADR 0007, but the expander must
        // remain correct if a future alias accidentally shares a member.
        // Simulate that with two known aliases plus the shared `attribute`
        // direct value to pin the dedup invariant explicitly.
        $result = AllowAliasExpander::expand(
            ['inheritance', 'static_access', 'attribute', 'inheritance'],
            'architecture.allow.app[0]',
        );

        $values = array_map(static fn(DependencyType $t): string => $t->value, $result);
        self::assertSame($values, array_values(array_unique($values)), 'expander must dedup across all sources');
    }

    // -------------------------------------------------------------------------
    // Error cases
    // -------------------------------------------------------------------------

    #[Test]
    public function itRejectsAnUnknownTokenWithBothKnownListsInTheMessage(): void
    {
        try {
            AllowAliasExpander::expand(['tipes'], 'architecture.allow.app[0]');
            self::fail('Expected ArchitectureConfigurationException');
        } catch (ArchitectureConfigurationException $e) {
            $message = $e->getMessage();
            self::assertStringContainsString("unknown relation kind 'tipes'", $message);
            // Known direct values list MUST mention enum cases dynamically.
            self::assertStringContainsString("'extends'", $message);
            self::assertStringContainsString("'static_call'", $message);
            // Known alias list MUST mention all four Phase-2 aliases.
            self::assertStringContainsString("'inheritance'", $message);
            self::assertStringContainsString("'static_access'", $message);
            self::assertStringContainsString("'type_reference'", $message);
            self::assertStringContainsString("'runtime_check'", $message);
            // Path prefix is preserved.
            self::assertStringContainsString('architecture.allow.app[0]', $message);
        }
    }

    #[Test]
    public function itRejectsAnEmptyStringToken(): void
    {
        $this->expectException(ArchitectureConfigurationException::class);
        $this->expectExceptionMessage('must be a non-empty string');

        AllowAliasExpander::expand([''], 'architecture.allow.app[0]');
    }

    #[Test]
    public function itRejectsANonStringToken(): void
    {
        $this->expectException(ArchitectureConfigurationException::class);
        $this->expectExceptionMessage('must be a non-empty string');

        /** @phpstan-ignore-next-line — intentional malformed input */
        AllowAliasExpander::expand([42], 'architecture.allow.app[0]');
    }

    #[Test]
    public function itReturnsAnEmptyListForAnEmptyTokenList(): void
    {
        // The caller is expected to reject `relations: []` at validator-level
        // (with a hint to use the bare-string short form). The expander itself
        // is the more general helper and simply returns an empty list when
        // given no tokens — this prevents two layers of error-path branching.
        self::assertSame([], AllowAliasExpander::expand([], 'architecture.allow.app[0]'));
    }

    // -------------------------------------------------------------------------
    // parseList — high-level entry point for the `relations:` long-form key
    // -------------------------------------------------------------------------

    #[Test]
    public function itReturnsNullWhenTheRelationsKeyIsAbsent(): void
    {
        // null (key absent) flows through to AllowTarget::$relations = null
        // (= "any relation allowed"). Defensive null-guard so callers can
        // forward raw YAML values directly.
        self::assertNull(AllowAliasExpander::parseList(null, 'architecture.allow.app[0]'));
    }

    #[Test]
    public function itRejectsAnEmptyRelationsListWithABareStringHint(): void
    {
        $this->expectException(ArchitectureConfigurationException::class);
        $this->expectExceptionMessage('must list at least one relation kind');

        AllowAliasExpander::parseList([], 'architecture.allow.app[0]');
    }

    #[Test]
    public function itRejectsAnAssociativeArrayForRelations(): void
    {
        // YAML `relations: {foo: bar}` would arrive here as an associative
        // array; rejecting it explicitly avoids a confusing downstream error
        // from `expand()`.
        $this->expectException(ArchitectureConfigurationException::class);
        $this->expectExceptionMessage('must be a list of relation kinds or aliases');

        AllowAliasExpander::parseList(['foo' => 'bar'], 'architecture.allow.app[0]');
    }

    #[Test]
    public function itRejectsAScalarRelationsValueWithAListHint(): void
    {
        $this->expectException(ArchitectureConfigurationException::class);
        $this->expectExceptionMessage('must be a list of relation kinds or aliases');

        AllowAliasExpander::parseList('extends', 'architecture.allow.app[0]');
    }

    #[Test]
    public function itDelegatesAValidRelationsListToExpand(): void
    {
        $result = AllowAliasExpander::parseList(['inheritance'], 'architecture.allow.app[0]');

        self::assertSame(
            [DependencyType::Extends, DependencyType::Implements, DependencyType::TraitUse],
            $result,
        );
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit\Configuration\Allow;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\AllowAliasExpander;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\ArchitectureDocument;

#[CoversClass(AllowAliasExpander::class)]
final class AllowAliasExpanderTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Direct DependencyType tokens
    // -------------------------------------------------------------------------

    #[Test]
    public function itExpandsASingleDirectTokenToItsOwnEnumCase(): void
    {
        $result = AllowAliasExpander::parseList(ArchitectureDocument::relations(['extends']), 'architecture.allow.app[0]');

        self::assertSame([DependencyType::Extends], $result);
    }

    #[Test]
    public function itPreservesInputOrderForMultipleDirectTokens(): void
    {
        $result = AllowAliasExpander::parseList(
            ArchitectureDocument::relations(['static_call', 'extends', 'attribute']),
            'architecture.allow.app[0]',
        );

        self::assertSame(
            [DependencyType::StaticCall, DependencyType::Extends, DependencyType::Attribute],
            $result,
        );
    }

    // -------------------------------------------------------------------------
    // Alias expansion
    // -------------------------------------------------------------------------

    #[Test]
    public function itExpandsTheInheritanceAliasToExtendsImplementsAndTraitUse(): void
    {
        $result = AllowAliasExpander::parseList(ArchitectureDocument::relations(['inheritance']), 'architecture.allow.app[0]');

        self::assertSame(
            [DependencyType::Extends, DependencyType::Implements, DependencyType::TraitUse],
            $result,
        );
    }

    #[Test]
    public function itExpandsTheStaticAccessAliasToStaticCallStaticPropertyAndClassConst(): void
    {
        $result = AllowAliasExpander::parseList(ArchitectureDocument::relations(['static_access']), 'architecture.allow.app[0]');

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
        $result = AllowAliasExpander::parseList(ArchitectureDocument::relations(['type_reference']), 'architecture.allow.app[0]');

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
        $result = AllowAliasExpander::parseList(ArchitectureDocument::relations(['runtime_check']), 'architecture.allow.app[0]');

        self::assertSame(
            [DependencyType::Catch_, DependencyType::Instanceof_],
            $result,
        );
    }

    #[Test]
    public function itTreatsAttributeAsAStandaloneTokenNotAnAlias(): void
    {
        // `attribute` is intentionally NOT grouped under any alias — ADR 0059
        // marks it as a distinct metadata category. Confirm the token round-trips
        // through the direct-value path (no expansion).
        $result = AllowAliasExpander::parseList(ArchitectureDocument::relations(['attribute']), 'architecture.allow.app[0]');

        self::assertSame([DependencyType::Attribute], $result);
    }

    // -------------------------------------------------------------------------
    // Mix + dedup
    // -------------------------------------------------------------------------

    #[Test]
    public function itDedupesADirectTokenThatRepeatsAPrecedingAliasMember(): void
    {
        $result = AllowAliasExpander::parseList(
            ArchitectureDocument::relations(['inheritance', 'extends']),
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
        $result = AllowAliasExpander::parseList(
            ArchitectureDocument::relations(['inheritance', 'static_call']),
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
        $result = AllowAliasExpander::parseList(
            ArchitectureDocument::relations(['extends', 'extends', 'attribute', 'extends']),
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
        // Aliases do not overlap under ADR 0059, but the expander must
        // remain correct if a future alias accidentally shares a member.
        // Simulate that with two known aliases plus the shared `attribute`
        // direct value to pin the dedup invariant explicitly.
        $result = AllowAliasExpander::parseList(
            ArchitectureDocument::relations(['inheritance', 'static_access', 'attribute', 'inheritance']),
            'architecture.allow.app[0]',
        );

        self::assertNotNull($result);
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
            AllowAliasExpander::parseList(ArchitectureDocument::relations(['tipes']), 'architecture.allow.app[0]');
            self::fail('Expected ConfigurationRefusal');
        } catch (ConfigurationRefusal $e) {
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
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('must be a non-empty string');

        AllowAliasExpander::parseList(ArchitectureDocument::relations(['']), 'architecture.allow.app[0]');
    }

    #[Test]
    public function itRejectsANonStringToken(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('must be a non-empty string');

        AllowAliasExpander::parseList(ArchitectureDocument::relations([42]), 'architecture.allow.app[0]');
    }

    // -------------------------------------------------------------------------
    // parseList — high-level entry point for the `relations:` long-form key
    // -------------------------------------------------------------------------

    #[Test]
    public function itReturnsNullWhenTheRelationsKeyIsAbsent(): void
    {
        // Not written flows through to AllowTarget::$relations = null
        // (= "any relation allowed").
        self::assertNull(AllowAliasExpander::parseList(ArchitectureDocument::relations(null), 'architecture.allow.app[0]'));
    }

    #[Test]
    public function itRejectsAnEmptyRelationsListWithABareStringHint(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('must list at least one relation kind');

        AllowAliasExpander::parseList(ArchitectureDocument::relations([]), 'architecture.allow.app[0]');
    }

    #[Test]
    public function itRejectsAnAssociativeArrayForRelations(): void
    {
        // YAML `relations: {foo: bar}` would arrive here as an associative
        // array; rejecting it explicitly avoids a confusing downstream error
        // from `expand()`.
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('must be a list of relation kinds or aliases');

        AllowAliasExpander::parseList(ArchitectureDocument::relations(['foo' => 'bar']), 'architecture.allow.app[0]');
    }

    #[Test]
    public function itRejectsAScalarRelationsValueWithAListHint(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('must be a list of relation kinds or aliases');

        AllowAliasExpander::parseList(ArchitectureDocument::relations('extends'), 'architecture.allow.app[0]');
    }

    #[Test]
    public function itDelegatesAValidRelationsListToExpand(): void
    {
        $result = AllowAliasExpander::parseList(ArchitectureDocument::relations(['inheritance']), 'architecture.allow.app[0]');

        self::assertSame(
            [DependencyType::Extends, DependencyType::Implements, DependencyType::TraitUse],
            $result,
        );
    }
}

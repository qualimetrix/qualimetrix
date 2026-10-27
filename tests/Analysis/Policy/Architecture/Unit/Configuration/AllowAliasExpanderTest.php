<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Policy\Architecture\Allow\AllowAliasExpander;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\AllowValidator;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\LongFormAllowEntryNormalizer;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\SectionSpot;
use Qualimetrix\Tests\Analysis\Policy\Architecture\Support\ArchitectureDocument;

#[CoversClass(AllowAliasExpander::class)]
#[CoversClass(LongFormAllowEntryNormalizer::class)]
final class AllowAliasExpanderTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Direct DependencyType tokens
    // -------------------------------------------------------------------------

    #[Test]
    public function itExpandsASingleDirectTokenToItsOwnEnumCase(): void
    {
        $result = $this->parseRelationList(ArchitectureDocument::relations(['extends']), 'architecture.allow.app[0]');

        self::assertSame([DependencyType::Extends], $result);
    }

    #[Test]
    public function itPreservesInputOrderForMultipleDirectTokens(): void
    {
        $result = $this->parseRelationList(
            ArchitectureDocument::relations(['static_call', 'extends', 'attribute']),
            'architecture.allow.app[0]',
        );

        self::assertSame(
            [DependencyType::StaticCall, DependencyType::Extends, DependencyType::Attribute],
            $result,
        );
    }

    #[Test]
    public function itAcceptsAttributeArgumentAsAStandaloneRelationKind(): void
    {
        $result = $this->parseRelationList(
            ArchitectureDocument::relations(['static_access', 'attribute_argument']),
            'architecture.allow.app[0]',
        );

        self::assertSame(
            [
                DependencyType::StaticCall,
                DependencyType::StaticPropertyFetch,
                DependencyType::ClassConstFetch,
                DependencyType::AttributeArgument,
            ],
            $result,
        );
    }

    // -------------------------------------------------------------------------
    // Mix + dedup
    // -------------------------------------------------------------------------

    #[Test]
    public function itDedupesADirectTokenThatRepeatsAPrecedingAliasMember(): void
    {
        $result = $this->parseRelationList(
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
        $result = $this->parseRelationList(
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
        $result = $this->parseRelationList(
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
        $result = $this->parseRelationList(
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
            $this->parseRelationList(ArchitectureDocument::relations(['tipes']), 'architecture.allow.app[0]');
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
    public function itRejectsTheRemovedShapeTokensAndNamesThePositionReplacement(): void
    {
        foreach (['union_type', 'intersection_type'] as $removed) {
            try {
                $this->parseRelationList(
                    ArchitectureDocument::relations([$removed]),
                    'architecture.allow.app[0]',
                );
                self::fail('Expected ConfigurationRefusal');
            } catch (ConfigurationRefusal $e) {
                self::assertStringContainsString("unknown relation kind '$removed'", $e->getMessage());
                self::assertStringContainsString(
                    "'$removed' names a type shape, not a relation kind",
                    $e->getMessage(),
                );
                self::assertStringContainsString(
                    "use 'type_reference' or a concrete type position; shape is graph metadata",
                    $e->getMessage(),
                );
            }
        }
    }

    #[Test]
    public function itRejectsAnEmptyStringToken(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('must be a non-empty string');

        $this->parseRelationList(ArchitectureDocument::relations(['']), 'architecture.allow.app[0]');
    }

    #[Test]
    public function itRejectsANonStringToken(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('must be a non-empty string');

        $this->parseRelationList(ArchitectureDocument::relations([42]), 'architecture.allow.app[0]');
    }

    // -------------------------------------------------------------------------
    // parseRelationList — high-level entry point for the `relations:` long-form key
    // -------------------------------------------------------------------------

    #[Test]
    public function itReturnsNullWhenTheRelationsKeyIsAbsent(): void
    {
        // Not written flows through to AllowTarget::$relations = null
        // (= "any relation allowed").
        self::assertNull($this->parseRelationList(ArchitectureDocument::relations(null), 'architecture.allow.app[0]'));
    }

    #[Test]
    public function itRejectsAnEmptyRelationsListWithABareStringHint(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('must list at least one relation kind');

        $this->parseRelationList(ArchitectureDocument::relations([]), 'architecture.allow.app[0]');
    }

    #[Test]
    public function itRejectsAnAssociativeArrayForRelations(): void
    {
        // YAML `relations: {foo: bar}` would arrive here as an associative
        // array; rejecting it explicitly avoids a confusing downstream error
        // from `expand()`.
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('must be a list of relation kinds or aliases');

        $this->parseRelationList(ArchitectureDocument::relations(['foo' => 'bar']), 'architecture.allow.app[0]');
    }

    #[Test]
    public function itRejectsAScalarRelationsValueWithAListHint(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('must be a list of relation kinds or aliases');

        $this->parseRelationList(ArchitectureDocument::relations('extends'), 'architecture.allow.app[0]');
    }

    #[Test]
    public function itDelegatesAValidRelationsListToExpand(): void
    {
        $result = $this->parseRelationList(ArchitectureDocument::relations(['inheritance']), 'architecture.allow.app[0]');

        self::assertSame(
            [DependencyType::Extends, DependencyType::Implements, DependencyType::TraitUse],
            $result,
        );
    }
    /** @return list<DependencyType>|null */
    private function parseRelationList(SectionSpot $relations, string $_context): ?array
    {
        $warnings = [];
        $entries = (new AllowValidator())->validate(
            ArchitectureDocument::allow([
                'app' => [[
                    'target' => 'domain',
                    'relations' => $relations->value(),
                ]],
            ]),
            ['app', 'domain'],
            $warnings,
        );

        return $entries[0]->targets[0]->relations;
    }

}

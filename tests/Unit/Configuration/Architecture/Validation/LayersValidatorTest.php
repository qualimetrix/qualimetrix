<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Architecture\Validation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Domain\Layer\ExcludeSpec;
use Qualimetrix\Architecture\Domain\Layer\LayerDefinition;
use Qualimetrix\Architecture\Domain\Layer\MatchMode;
use Qualimetrix\Architecture\Domain\Layer\MembershipSpec;
use Qualimetrix\Architecture\Domain\Layer\TemplateLayerDefinition;
use Qualimetrix\Configuration\Architecture\Validation\LayersValidator;
use Qualimetrix\Configuration\Exception\ConfigLoadException;

#[CoversClass(LayersValidator::class)]
#[CoversClass(MembershipSpec::class)]
#[CoversClass(ExcludeSpec::class)]
#[CoversClass(MatchMode::class)]
final class LayersValidatorTest extends TestCase
{
    private LayersValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new LayersValidator();
    }

    /**
     * Extracts display names from a mixed entry list (static layers expose
     * {@code name()}; templates expose {@code nameTemplate()}).
     *
     * @param list<LayerDefinition|TemplateLayerDefinition> $entries
     *
     * @return list<string>
     */
    private static function namesOf(array $entries): array
    {
        return array_map(
            static fn(LayerDefinition|TemplateLayerDefinition $entry): string => $entry instanceof TemplateLayerDefinition
                ? $entry->nameTemplate()
                : $entry->name(),
            $entries,
        );
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function emptyInputProducesEmptyRegistry(): void
    {
        $entries = $this->validator->validate([]);

        self::assertSame([], $entries);
        self::assertSame([], self::namesOf($entries));
    }

    #[Test]
    public function nullInputProducesEmptyRegistry(): void
    {
        $entries = $this->validator->validate(null);

        self::assertSame([], $entries);
    }

    #[Test]
    public function singleLayerWithListPatternRegistersAllPatterns(): void
    {
        $entries = $this->validator->validate([
            ['name' => 'service', 'patterns' => ['App\\Service', 'App\\Domain\\Service']],
        ]);

        self::assertCount(1, $entries);
        $entry = $entries[0];
        \assert($entry instanceof LayerDefinition);
        self::assertSame('service', $entry->name());
        self::assertSame(['App\\Service', 'App\\Domain\\Service'], $entry->patterns());
    }

    #[Test]
    public function layersListPreservesDeclarationOrder(): void
    {
        $entries = $this->validator->validate([
            ['name' => 'zebra', 'patterns' => ['App\\Zebra']],
            ['name' => 'alpha', 'patterns' => ['App\\Alpha']],
            ['name' => 'beta', 'patterns' => ['App\\Beta']],
        ]);

        // NOT sorted — declaration order is preserved through the registry.
        self::assertSame(['zebra', 'alpha', 'beta'], self::namesOf($entries));
    }

    // -------------------------------------------------------------------------
    // Layer-list shape validation
    // -------------------------------------------------------------------------

    #[Test]
    public function legacyMapShapeForLayersIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/ordered list of layer entries/');

        // Legacy map shape ('layer-name' => pattern) is no longer accepted.
        $this->validator->validate(['controller' => 'App\\Controller']);
    }

    #[Test]
    public function singleKeyMapShorthandForLayerEntryIsRejected(): void
    {
        // ADR 0006 explicitly rejects the `- controller: 'App\Controller\**'`
        // shorthand. Only the long form (`- name: ... patterns: [...]`) is accepted.
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/unknown key\(s\) "controller"/');

        $this->validator->validate([
            ['controller' => 'App\\Controller\\**'],
        ]);
    }

    #[Test]
    public function layersAsScalarIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.layers');

        $this->validator->validate('App\\Controller');
    }

    // -------------------------------------------------------------------------
    // Per-entry validation
    // -------------------------------------------------------------------------

    #[Test]
    public function layerEntryWithoutNameIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/missing or empty "name"/');

        $this->validator->validate([
            ['patterns' => ['App\\Controller']],
        ]);
    }

    #[Test]
    public function layerEntryWithEmptyNameIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/missing or empty "name"/');

        $this->validator->validate([
            ['name' => '', 'patterns' => ['App\\Controller']],
        ]);
    }

    #[Test]
    public function layerEntryNameAsNonStringIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/missing or empty "name"/');

        $this->validator->validate([
            ['name' => 42, 'patterns' => ['App\\Controller']],
        ]);
    }

    #[Test]
    public function layerEntryAsScalarIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/each entry must be a map/');

        $this->validator->validate(['just-a-string']);
    }

    #[Test]
    public function layerEntryAsListIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/each entry must be a map/');

        $this->validator->validate([
            [0 => 'foo', 1 => 'bar'],
        ]);
    }

    #[Test]
    public function unknownKeyOnLayerEntryIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/unknown key/');

        $this->validator->validate([
            ['name' => 'controller', 'patterns' => ['App\\Controller'], 'unexpected' => 'foo'],
        ]);
    }

    #[Test]
    public function omittedMatchKeyDefaultsToAny(): void
    {
        $entries = $this->validator->validate([
            ['name' => 'service', 'patterns' => ['App\\Service']],
        ]);

        $definitions = $entries;
        self::assertCount(1, $definitions);
        self::assertSame(MatchMode::Any, $definitions[0]->membership()->mode);
    }

    #[Test]
    public function explicitMatchAnyParsesToAny(): void
    {
        $entries = $this->validator->validate([
            ['name' => 'service', 'patterns' => ['App\\Service'], 'match' => 'any'],
        ]);

        self::assertSame(MatchMode::Any, $entries[0]->membership()->mode);
    }

    #[Test]
    public function explicitMatchAllParsesToAll(): void
    {
        $entries = $this->validator->validate([
            ['name' => 'service', 'patterns' => ['App\\Service'], 'match' => 'all'],
        ]);

        self::assertSame(MatchMode::All, $entries[0]->membership()->mode);
    }

    #[Test]
    public function unknownMatchValueIsRejected(): void
    {
        try {
            $this->validator->validate([
                ['name' => 'service', 'patterns' => ['App\\Service'], 'match' => 'maybe'],
            ]);
            self::fail('Expected ConfigLoadException for unknown match mode.');
        } catch (ConfigLoadException $e) {
            self::assertSame('architecture', $e->configPath);
            self::assertStringContainsString('"match"', $e->getMessage());
            self::assertStringContainsString('"any"', $e->getMessage());
            self::assertStringContainsString('"all"', $e->getMessage());
            self::assertStringContainsString('"maybe"', $e->getMessage());
        }
    }

    #[Test]
    public function nonStringMatchValueIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/"match".+int/');

        $this->validator->validate([
            ['name' => 'service', 'patterns' => ['App\\Service'], 'match' => 42],
        ]);
    }

    #[Test]
    public function layerEntryWithoutAnyCriterionIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/must declare at least one of "patterns", "suffix", "attributes", "implements" or "extends"/');

        $this->validator->validate([
            ['name' => 'controller'],
        ]);
    }

    #[Test]
    public function layerEntryWithEmptyPatternsListIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/"patterns" must contain at least one entry/');

        $this->validator->validate([
            ['name' => 'controller', 'patterns' => []],
        ]);
    }

    #[Test]
    public function layerEntryWithPatternsAsScalarIsAcceptedAsSingletonShorthand(): void
    {
        // YAML scalar shorthand: `patterns: 'App\Foo'` is equivalent to
        // `patterns: ['App\Foo']`. The shorthand is consistent across all
        // five criterion kinds (suffix / attributes / implements / extends
        // documented examples in the Phase 2 design use the shorthand).
        $entries = $this->validator->validate([
            ['name' => 'controller', 'patterns' => 'App\\Controller'],
        ]);

        $entry = $entries[0];
        \assert($entry instanceof LayerDefinition);
        self::assertSame(['App\\Controller'], $entry->patterns());
    }

    #[Test]
    public function layerEntryWithPatternsAsMapIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/"patterns" must be a string or a non-empty list of strings/');

        $this->validator->validate([
            ['name' => 'controller', 'patterns' => ['foo' => 'App\\Controller']],
        ]);
    }

    #[Test]
    public function emptyPatternStringInsideListIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/non-empty string/');

        $this->validator->validate([
            ['name' => 'controller', 'patterns' => ['App\\Controller', '']],
        ]);
    }

    #[Test]
    public function nonStringPatternInsideListIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/non-empty string/');

        $this->validator->validate([
            ['name' => 'controller', 'patterns' => ['App\\Controller', 42]],
        ]);
    }

    #[Test]
    public function invalidLayerNameIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/UpperCaseName/');

        $this->validator->validate([
            ['name' => 'UpperCaseName', 'patterns' => ['App\\Foo']],
        ]);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function selectorMetacharsInLayerNameProvider(): iterable
    {
        // Phase 2 Step C / ADR 0007: selector metachars (`* ? [ { }`) reserved
        // for the allow-list grammar must NOT appear in layer names. The
        // existing layer-name regex {@code [a-z][a-z0-9_-]*} already enforces
        // this; the dataset pins each individual metachar so a future regex
        // relaxation cannot silently break selector parsing.
        yield 'star' => ['foo*'];
        yield 'question' => ['foo?'];
        yield 'open bracket' => ['foo['];
        yield 'open brace' => ['foo{'];
        yield 'close brace' => ['foo}'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('selectorMetacharsInLayerNameProvider')]
    public function layerNameContainingSelectorMetacharIsRejected(string $invalidName): void
    {
        $this->expectException(ConfigLoadException::class);

        $this->validator->validate([
            ['name' => $invalidName, 'patterns' => ['App\\Foo']],
        ]);
    }

    // -------------------------------------------------------------------------
    // Per-criterion validation (Phase 2 direction 1)
    // -------------------------------------------------------------------------

    #[Test]
    public function suffixCriterionAcceptsShortName(): void
    {
        $entries = $this->validator->validate([
            ['name' => 'repository', 'suffix' => 'Repository'],
        ]);

        self::assertSame(['Repository'], $entries[0]->membership()->suffix);
    }

    #[Test]
    public function suffixCriterionAcceptsListOfShortNames(): void
    {
        $entries = $this->validator->validate([
            ['name' => 'persistence', 'suffix' => ['Repository', 'Dao']],
        ]);

        self::assertSame(['Repository', 'Dao'], $entries[0]->membership()->suffix);
    }

    #[Test]
    public function suffixCriterionRejectsBackslashEntry(): void
    {
        try {
            $this->validator->validate([
                ['name' => 'r', 'suffix' => 'App\\Repository'],
            ]);
            self::fail('Expected ConfigLoadException for FQN-shaped suffix.');
        } catch (ConfigLoadException $e) {
            self::assertSame('architecture', $e->configPath);
            self::assertStringContainsString('"suffix"', $e->getMessage());
            self::assertStringContainsString('short class-name suffix', $e->getMessage());
            self::assertStringContainsString('App\\Repository', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function fqnCriterionProvider(): iterable
    {
        yield 'attributes' => ['attributes'];
        yield 'implements' => ['implements'];
        yield 'extends' => ['extends'];
    }

    #[DataProvider('fqnCriterionProvider')]
    #[Test]
    public function fqnCriterionAcceptsFqnString(string $kind): void
    {
        $entries = $this->validator->validate([
            ['name' => 'r', $kind => 'App\\Some\\Fqn'],
        ]);

        self::assertSame(['App\\Some\\Fqn'], self::criterionField($entries[0]->membership(), $kind));
    }

    #[DataProvider('fqnCriterionProvider')]
    #[Test]
    public function fqnCriterionAcceptsListOfFqns(string $kind): void
    {
        $entries = $this->validator->validate([
            ['name' => 'r', $kind => ['App\\A', 'App\\B']],
        ]);

        self::assertSame(['App\\A', 'App\\B'], self::criterionField($entries[0]->membership(), $kind));
    }

    /**
     * @return list<string>
     */
    private static function criterionField(MembershipSpec $spec, string $kind): array
    {
        return match ($kind) {
            'patterns' => $spec->patterns,
            'suffix' => $spec->suffix,
            'attributes' => $spec->attributes,
            'implements' => $spec->implements,
            'extends' => $spec->extends,
            default => throw new InvalidArgumentException('Unknown criterion kind: ' . $kind),
        };
    }

    #[DataProvider('fqnCriterionProvider')]
    #[Test]
    public function fqnCriterionRejectsShortNameEntry(string $kind): void
    {
        try {
            $this->validator->validate([
                ['name' => 'r', $kind => 'Entity'],
            ]);
            self::fail('Expected ConfigLoadException for short-name "' . $kind . '" entry.');
        } catch (ConfigLoadException $e) {
            self::assertSame('architecture', $e->configPath);
            self::assertStringContainsString('"' . $kind . '"', $e->getMessage());
            self::assertStringContainsString('fully-qualified', $e->getMessage());
            self::assertStringContainsString('Entity', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function allCriterionKindsProvider(): iterable
    {
        yield 'patterns' => ['patterns'];
        yield 'suffix' => ['suffix'];
        yield 'attributes' => ['attributes'];
        yield 'implements' => ['implements'];
        yield 'extends' => ['extends'];
    }

    #[DataProvider('allCriterionKindsProvider')]
    #[Test]
    public function criterionEmptyListIsRejected(string $kind): void
    {
        try {
            $this->validator->validate([
                ['name' => 'r', $kind => []],
            ]);
            self::fail('Expected ConfigLoadException for empty "' . $kind . '" list.');
        } catch (ConfigLoadException $e) {
            self::assertStringContainsString('"' . $kind . '"', $e->getMessage());
            self::assertStringContainsString('must contain at least one entry', $e->getMessage());
        }
    }

    #[DataProvider('allCriterionKindsProvider')]
    #[Test]
    public function criterionEmptyStringEntryIsRejected(string $kind): void
    {
        // Empty-string entry must be rejected BEFORE any kind-specific
        // semantic validation runs, so the index-0 spot is fine for every
        // criterion kind (we can't seed it with a kind-shape-valid string
        // first because suffix rejects backslashes and attributes/implements/
        // extends require them — empty-first is the only universal probe).
        try {
            $this->validator->validate([
                ['name' => 'r', $kind => ['']],
            ]);
            self::fail('Expected ConfigLoadException for empty entry in "' . $kind . '" list.');
        } catch (ConfigLoadException $e) {
            self::assertStringContainsString('non-empty string', $e->getMessage());
        }
    }

    #[DataProvider('allCriterionKindsProvider')]
    #[Test]
    public function criterionMapShapeIsRejected(string $kind): void
    {
        try {
            $this->validator->validate([
                ['name' => 'r', $kind => ['foo' => 'App\\Foo']],
            ]);
            self::fail('Expected ConfigLoadException for map-shaped "' . $kind . '" value.');
        } catch (ConfigLoadException $e) {
            self::assertStringContainsString('"' . $kind . '"', $e->getMessage());
            self::assertStringContainsString('string or a non-empty list of strings', $e->getMessage());
        }
    }

    #[Test]
    public function membershipSpecCarriesEveryDeclaredCriterion(): void
    {
        // End-to-end: a single layer entry with all five criteria flows through
        // the validator and produces a MembershipSpec with the expected fields.
        $entries = $this->validator->validate([
            [
                'name' => 'kitchen-sink',
                'patterns' => ['App\\Foo'],
                'suffix' => ['Bar'],
                'attributes' => ['App\\Attr\\X'],
                'implements' => ['App\\Iface\\Y'],
                'extends' => ['App\\Base\\Z'],
                'match' => 'all',
            ],
        ]);

        $spec = $entries[0]->membership();
        self::assertSame(['App\\Foo'], $spec->patterns);
        self::assertSame(['Bar'], $spec->suffix);
        self::assertSame(['App\\Attr\\X'], $spec->attributes);
        self::assertSame(['App\\Iface\\Y'], $spec->implements);
        self::assertSame(['App\\Base\\Z'], $spec->extends);
        self::assertSame(MatchMode::All, $spec->mode);
    }

    // -------------------------------------------------------------------------
    // Cross-entry validation
    // -------------------------------------------------------------------------

    #[Test]
    public function duplicateLayerNameAcrossListEntriesIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/duplicate layer name "service"/');

        $this->validator->validate([
            ['name' => 'service', 'patterns' => ['App\\Service']],
            ['name' => 'service', 'patterns' => ['App\\OtherService']],
        ]);
    }

    #[Test]
    public function duplicatePatternAcrossLayersIsRejected(): void
    {
        try {
            $this->validator->validate([
                ['name' => 'a', 'patterns' => ['App\\Shared']],
                ['name' => 'b', 'patterns' => ['App\\Shared']],
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertSame('architecture', $e->configPath);
            self::assertStringContainsString('App\\Shared', $e->getMessage());
            self::assertStringContainsString('"a"', $e->getMessage());
            self::assertStringContainsString('"b"', $e->getMessage());
            self::assertStringContainsString('unreachable', $e->getMessage());
        }
    }

    #[Test]
    public function duplicateWildcardPatternAcrossLayersIsRejected(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/App\\\\\\*\\*/');

        $this->validator->validate([
            ['name' => 'a', 'patterns' => ['App\\**']],
            ['name' => 'b', 'patterns' => ['App\\**']],
        ]);
    }

    #[Test]
    public function duplicatePatternWithTrailingBackslashIsTreatedAsDuplicate(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/unreachable/');

        // 'App\\Service' and 'App\\Service\\' are normalized identically
        $this->validator->validate([
            ['name' => 'a', 'patterns' => ['App\\Service']],
            ['name' => 'b', 'patterns' => ['App\\Service\\']],
        ]);
    }

    #[Test]
    public function samePatternWithinOneLayerIsNotADuplicate(): void
    {
        // Cross-layer duplicates are rejected; within-layer repetition is allowed.
        $entries = $this->validator->validate([
            ['name' => 'a', 'patterns' => ['App\\Shared', 'App\\Shared']],
        ]);

        self::assertSame(['a'], self::namesOf($entries));
    }

    // -------------------------------------------------------------------------
    // Mode-aware duplicate-pattern skip (H1 remediation, Phase 1.2)
    // -------------------------------------------------------------------------

    #[Test]
    public function duplicatePatternIsRejectedWhenBothEntriesAreMatchAny(): void
    {
        // Pin OLD behavior: `match: any` (default) on both sides keeps the
        // duplicate-pattern check active because pattern alone is sufficient
        // for the earlier entry to claim every match.
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/unreachable/');

        $this->validator->validate([
            ['name' => 'a', 'patterns' => ['App\\Shared'], 'match' => 'any'],
            ['name' => 'b', 'patterns' => ['App\\Shared'], 'match' => 'any'],
        ]);
    }

    #[Test]
    public function duplicatePatternIsAllowedWhenBothEntriesAreMatchAllWithNonPatternCriteria(): void
    {
        // H1 fix: when BOTH duplicates declare `match: all` together with a
        // non-empty non-pattern criterion, each one narrows its pattern
        // matches to a different subset and the patterns can legitimately
        // overlap.
        $entries = $this->validator->validate([
            ['name' => 'a', 'patterns' => ['App\\Shared'], 'suffix' => 'Service', 'match' => 'all'],
            ['name' => 'b', 'patterns' => ['App\\Shared'], 'suffix' => 'Repository', 'match' => 'all'],
        ]);

        self::assertSame(['a', 'b'], self::namesOf($entries));
    }

    #[Test]
    public function duplicatePatternIsAllowedWhenOnlyEarlierEntryIsMatchAllWithNonPatternCriteria(): void
    {
        // H1 fix: the earlier entry narrows its claim with `suffix`, so the
        // later entry can legitimately catch the residue of `App\Shared`
        // classes whose short name does not end with "Service".
        $entries = $this->validator->validate([
            ['name' => 'a', 'patterns' => ['App\\Shared'], 'suffix' => 'Service', 'match' => 'all'],
            ['name' => 'b', 'patterns' => ['App\\Shared']],
        ]);

        self::assertSame(['a', 'b'], self::namesOf($entries));
    }

    #[Test]
    public function duplicatePatternIsAllowedWhenOnlyLaterEntryIsMatchAllWithNonPatternCriteria(): void
    {
        // H1 fix (order-symmetric "one or both" predicate): the later entry's
        // narrowing condition is recognized even though, in isolation, this
        // case is technically unreachable. Trade-off documented on
        // `LayersValidator::rejectDuplicatePatterns`: losing a rare
        // "unreachable layer" warning is preferred over false-positives.
        $entries = $this->validator->validate([
            ['name' => 'a', 'patterns' => ['App\\Shared']],
            ['name' => 'b', 'patterns' => ['App\\Shared'], 'suffix' => 'Service', 'match' => 'all'],
        ]);

        self::assertSame(['a', 'b'], self::namesOf($entries));
    }

    /**
     * @return iterable<string, array{0: string, 1: string|list<string>}>
     */
    public static function nonPatternCriterionProvider(): iterable
    {
        yield 'suffix' => ['suffix', 'Service'];
        yield 'attributes' => ['attributes', 'App\\Attr\\Service'];
        yield 'implements' => ['implements', 'App\\Iface\\Service'];
        yield 'extends' => ['extends', 'App\\Base\\Service'];
    }

    /**
     * @param string|list<string> $value
     */
    #[DataProvider('nonPatternCriterionProvider')]
    #[Test]
    public function duplicatePatternIsAllowedForEveryNonPatternCriterionUnderMatchAll(string $kind, string|array $value): void
    {
        // H1 fix: any of suffix / attributes / implements / extends qualifies
        // as a narrowing non-pattern criterion under `match: all`.
        $entries = $this->validator->validate([
            ['name' => 'a', 'patterns' => ['App\\Shared'], $kind => $value, 'match' => 'all'],
            ['name' => 'b', 'patterns' => ['App\\Shared']],
        ]);

        self::assertSame(['a', 'b'], self::namesOf($entries));
    }

    #[Test]
    public function duplicatePatternIsRejectedWhenMatchAllHasNoNonPatternCriteria(): void
    {
        // Pin OLD behavior: `match: all` without any non-pattern criterion
        // collapses to the same semantics as `match: any` (patterns alone
        // claim every match), so the duplicate is still unreachable.
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/unreachable/');

        $this->validator->validate([
            ['name' => 'a', 'patterns' => ['App\\Shared'], 'match' => 'all'],
            ['name' => 'b', 'patterns' => ['App\\Shared'], 'match' => 'all'],
        ]);
    }

    #[Test]
    public function duplicatePatternIsRejectedWhenEarlierIsMatchAnyAndLaterIsMatchAllWithoutNonPatternCriteria(): void
    {
        // Pin OLD behavior: neither side narrows — the earlier blanket
        // `match: any` entry claims every match of the pattern and the
        // later `match: all` entry without extra criteria is unreachable.
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/unreachable/');

        $this->validator->validate([
            ['name' => 'a', 'patterns' => ['App\\Shared']],
            ['name' => 'b', 'patterns' => ['App\\Shared'], 'match' => 'all'],
        ]);
    }

    #[Test]
    public function duplicatePatternModeAwareSkipExtendsAcrossMoreThanTwoEntries(): void
    {
        // Mode-aware skip is pair-wise. When several entries share a pattern
        // and at least one of any colliding pair narrows, all are accepted.
        $entries = $this->validator->validate([
            ['name' => 'a', 'patterns' => ['App\\Shared'], 'suffix' => 'Service', 'match' => 'all'],
            ['name' => 'b', 'patterns' => ['App\\Shared'], 'suffix' => 'Repository', 'match' => 'all'],
            ['name' => 'c', 'patterns' => ['App\\Shared'], 'suffix' => 'Controller', 'match' => 'all'],
        ]);

        self::assertSame(['a', 'b', 'c'], self::namesOf($entries));
    }

    #[Test]
    public function duplicatePatternModeAwareSkipAppliesToTemplateLayerEntries(): void
    {
        // The skip walks LayerDefinition and TemplateLayerDefinition
        // uniformly through `membership()`, so template-vs-template and
        // template-vs-static collisions follow the same rule.
        $entries = $this->validator->validate([
            [
                'name' => 'module-{m}',
                'patterns' => ['App\\Module\\{m}\\**'],
                'suffix' => 'Service',
                'match' => 'all',
            ],
            ['name' => 'shared', 'patterns' => ['App\\Module\\{m}\\**']],
        ]);

        self::assertSame(['module-{m}', 'shared'], self::namesOf($entries));
    }

    #[Test]
    public function duplicateWildcardPatternIsAllowedUnderMatchAllNarrowing(): void
    {
        // The normalized-pattern key is the same for both entries; the
        // mode-aware skip still applies and the wildcard duplicate passes.
        $entries = $this->validator->validate([
            ['name' => 'a', 'patterns' => ['App\\**'], 'suffix' => 'Service', 'match' => 'all'],
            ['name' => 'b', 'patterns' => ['App\\**'], 'suffix' => 'Repository', 'match' => 'all'],
        ]);

        self::assertSame(['a', 'b'], self::namesOf($entries));
    }

    #[Test]
    public function configPathIsArchitectureForAllErrors(): void
    {
        try {
            $this->validator->validate('bad');
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertSame('architecture', $e->configPath);
        }
    }

    // -------------------------------------------------------------------------
    // exclude clause (Step F)
    // -------------------------------------------------------------------------

    #[Test]
    public function excludeBlockProducesExcludeSpec(): void
    {
        $entries = $this->validator->validate([
            [
                'name' => 'service',
                'patterns' => ['App\\Service\\**'],
                'exclude' => ['patterns' => ['App\\Service\\Legacy\\**']],
            ],
        ]);

        self::assertCount(1, $entries);
        $layer = $entries[0];
        self::assertInstanceOf(LayerDefinition::class, $layer);
        self::assertNotNull($layer->membership()->exclude);
        self::assertSame(['App\\Service\\Legacy\\**'], $layer->membership()->exclude->patterns);
        self::assertSame(MatchMode::Any, $layer->membership()->exclude->mode);
    }

    #[Test]
    public function excludeBlockAcceptsStringShorthandForCriterionLists(): void
    {
        $entries = $this->validator->validate([
            [
                'name' => 'service',
                'patterns' => ['App\\Service\\**'],
                'exclude' => [
                    'patterns' => 'App\\Service\\Legacy\\**',
                    'suffix' => 'Bridge',
                ],
            ],
        ]);

        $layer = $entries[0];
        self::assertInstanceOf(LayerDefinition::class, $layer);
        self::assertNotNull($layer->membership()->exclude);
        self::assertSame(['App\\Service\\Legacy\\**'], $layer->membership()->exclude->patterns);
        self::assertSame(['Bridge'], $layer->membership()->exclude->suffix);
    }

    #[Test]
    public function excludeMatchKeyParsesIntoExcludeSpecMode(): void
    {
        $entries = $this->validator->validate([
            [
                'name' => 'service',
                'patterns' => ['App\\Service\\**'],
                'exclude' => [
                    'patterns' => ['App\\Service\\Legacy\\**'],
                    'suffix' => ['Bridge'],
                    'match' => 'all',
                ],
            ],
        ]);

        $layer = $entries[0];
        self::assertInstanceOf(LayerDefinition::class, $layer);
        self::assertNotNull($layer->membership()->exclude);
        self::assertSame(MatchMode::All, $layer->membership()->exclude->mode);
    }

    #[Test]
    public function excludeMatchKeyDefaultsToAny(): void
    {
        $entries = $this->validator->validate([
            [
                'name' => 'service',
                'patterns' => ['App\\Service\\**'],
                'exclude' => ['patterns' => ['App\\Service\\Legacy\\**']],
            ],
        ]);

        $layer = $entries[0];
        self::assertInstanceOf(LayerDefinition::class, $layer);
        self::assertNotNull($layer->membership()->exclude);
        self::assertSame(MatchMode::Any, $layer->membership()->exclude->mode);
    }

    #[Test]
    public function omittedExcludeKeyLeavesMembershipExcludeNull(): void
    {
        $entries = $this->validator->validate([
            ['name' => 'service', 'patterns' => ['App\\Service\\**']],
        ]);

        $layer = $entries[0];
        self::assertInstanceOf(LayerDefinition::class, $layer);
        self::assertNull($layer->membership()->exclude);
    }

    #[Test]
    public function excludeBlockWithoutAnyCriterionIsRejected(): void
    {
        try {
            $this->validator->validate([
                [
                    'name' => 'service',
                    'patterns' => ['App\\Service\\**'],
                    'exclude' => ['match' => 'any'],
                ],
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertStringContainsString('"exclude" must declare at least one of', $e->getMessage());
            self::assertStringContainsString('omit the "exclude" key to leave it undeclared', $e->getMessage());
        }
    }

    #[Test]
    public function excludeBlockAsSequentialListIsRejected(): void
    {
        try {
            $this->validator->validate([
                [
                    'name' => 'service',
                    'patterns' => ['App\\Service\\**'],
                    'exclude' => ['App\\Service\\Legacy\\**'],
                ],
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertStringContainsString('"exclude" must be a non-empty map', $e->getMessage());
            self::assertStringContainsString('sequential list', $e->getMessage());
        }
    }

    #[Test]
    public function excludeBlockAsEmptyArrayIsRejected(): void
    {
        try {
            $this->validator->validate([
                [
                    'name' => 'service',
                    'patterns' => ['App\\Service\\**'],
                    'exclude' => [],
                ],
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertStringContainsString('"exclude" must be a non-empty map', $e->getMessage());
            self::assertStringContainsString('empty list', $e->getMessage());
        }
    }

    #[Test]
    public function excludeBlockAsScalarIsRejected(): void
    {
        try {
            $this->validator->validate([
                [
                    'name' => 'service',
                    'patterns' => ['App\\Service\\**'],
                    'exclude' => 'App\\Service\\Legacy',
                ],
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertStringContainsString('"exclude" must be a non-empty map', $e->getMessage());
            self::assertStringContainsString('got string', $e->getMessage());
        }
    }

    #[Test]
    public function excludeBlockWithUnknownKeyIsRejected(): void
    {
        try {
            $this->validator->validate([
                [
                    'name' => 'service',
                    'patterns' => ['App\\Service\\**'],
                    'exclude' => [
                        'patterns' => ['App\\Service\\Legacy\\**'],
                        'mode' => 'all',
                    ],
                ],
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertStringContainsString('unknown key(s) "mode" inside "exclude"', $e->getMessage());
        }
    }

    #[Test]
    public function nestedExcludeInsideExcludeIsRejectedWithHint(): void
    {
        try {
            $this->validator->validate([
                [
                    'name' => 'service',
                    'patterns' => ['App\\Service\\**'],
                    'exclude' => [
                        'patterns' => ['App\\Service\\Legacy\\**'],
                        'exclude' => ['patterns' => ['App\\Service\\Legacy\\Internal\\**']],
                    ],
                ],
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertStringContainsString('"exclude"', $e->getMessage());
            self::assertStringContainsString('Nested "exclude" is not supported', $e->getMessage());
        }
    }

    #[Test]
    public function captureVariableInStaticLayerExcludePatternsIsRejected(): void
    {
        try {
            $this->validator->validate([
                [
                    'name' => 'service',
                    'patterns' => ['App\\Service\\**'],
                    'exclude' => ['patterns' => ['App\\Service\\{module}\\Legacy\\**']],
                ],
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertStringContainsString('contains a capture variable', $e->getMessage());
            self::assertStringContainsString('the layer name "service" has none', $e->getMessage());
        }
    }

    #[Test]
    public function captureVariableInStaticLayerExcludeSuffixIsRejected(): void
    {
        try {
            $this->validator->validate([
                [
                    'name' => 'service',
                    'patterns' => ['App\\Service\\**'],
                    'exclude' => ['suffix' => ['{m}Bridge']],
                ],
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertStringContainsString('contains a capture variable', $e->getMessage());
        }
    }

    #[Test]
    public function captureVariableInTemplateExcludeSuffixIsRejected(): void
    {
        // Even for template layers, captures are accepted in exclude.patterns
        // only — suffix/attributes/implements/extends remain fixed strings.
        try {
            $this->validator->validate([
                [
                    'name' => 'module-{m}',
                    'patterns' => ['App\\Module\\{m}\\**'],
                    'exclude' => ['suffix' => ['{m}Bridge']],
                ],
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertStringContainsString('contains a capture variable', $e->getMessage());
            self::assertStringContainsString('only allowed in exclude.patterns', $e->getMessage());
        }
    }

    #[Test]
    public function captureVariableInTemplateExcludePatternsIsAcceptedWhenDeclared(): void
    {
        $entries = $this->validator->validate([
            [
                'name' => 'module-{m}',
                'patterns' => ['App\\Module\\{m}\\**'],
                'exclude' => ['patterns' => ['App\\Module\\{m}\\Generated\\**']],
            ],
        ]);

        $template = $entries[0];
        self::assertInstanceOf(TemplateLayerDefinition::class, $template);
        self::assertNotNull($template->membership()->exclude);
        self::assertSame(['App\\Module\\{m}\\Generated\\**'], $template->membership()->exclude->patterns);
    }

    #[Test]
    public function captureVariableInTemplateExcludeNotDeclaredByTemplateIsRejected(): void
    {
        // `{n}` does not appear in the template name or capture-producing
        // patterns — exclude can't introduce new variables.
        try {
            $this->validator->validate([
                [
                    'name' => 'module-{m}',
                    'patterns' => ['App\\Module\\{m}\\**'],
                    'exclude' => ['patterns' => ['App\\Module\\{n}\\Generated\\**']],
                ],
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertStringContainsString('exclude clause references undeclared variable(s) "n"', $e->getMessage());
            self::assertStringContainsString('declared variables: "m"', $e->getMessage());
        }
    }

    #[Test]
    public function excludeRetainsLayerNameInErrorPathForCriterionValidation(): void
    {
        // The exclude-path layer name is "<layer>.exclude" so the user can
        // tell which clause the per-criterion validation message refers to.
        try {
            $this->validator->validate([
                [
                    'name' => 'service',
                    'patterns' => ['App\\Service\\**'],
                    'exclude' => ['suffix' => ['App\\Backslash']],
                ],
            ]);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertStringContainsString('"service.exclude"', $e->getMessage());
            self::assertStringContainsString('"suffix"', $e->getMessage());
            self::assertStringContainsString('no backslash', $e->getMessage());
        }
    }
}

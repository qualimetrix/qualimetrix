<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Architecture\Validation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\Architecture\Validation\LayersValidator;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Core\Architecture\Layer\MatchMode;
use Qualimetrix\Core\Architecture\Layer\MembershipSpec;

#[CoversClass(LayersValidator::class)]
#[CoversClass(MembershipSpec::class)]
#[CoversClass(MatchMode::class)]
final class LayersValidatorTest extends TestCase
{
    private LayersValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new LayersValidator();
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function emptyInputProducesEmptyRegistry(): void
    {
        $registry = $this->validator->validate([]);

        self::assertTrue($registry->isEmpty());
        self::assertSame([], $registry->layerNames());
    }

    #[Test]
    public function nullInputProducesEmptyRegistry(): void
    {
        $registry = $this->validator->validate(null);

        self::assertTrue($registry->isEmpty());
    }

    #[Test]
    public function singleLayerWithListPatternRegistersAllPatterns(): void
    {
        $registry = $this->validator->validate([
            ['name' => 'service', 'patterns' => ['App\\Service', 'App\\Domain\\Service']],
        ]);

        $definitions = $registry->definitions();
        self::assertCount(1, $definitions);
        self::assertSame('service', $definitions[0]->name());
        self::assertSame(['App\\Service', 'App\\Domain\\Service'], $definitions[0]->patterns());
    }

    #[Test]
    public function layersListPreservesDeclarationOrder(): void
    {
        $registry = $this->validator->validate([
            ['name' => 'zebra', 'patterns' => ['App\\Zebra']],
            ['name' => 'alpha', 'patterns' => ['App\\Alpha']],
            ['name' => 'beta', 'patterns' => ['App\\Beta']],
        ]);

        // NOT sorted — declaration order is preserved through the registry.
        self::assertSame(['zebra', 'alpha', 'beta'], $registry->layerNames());
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

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function phase2ReservedKeyProvider(): iterable
    {
        // `suffix`, `attributes`, `implements`, `extends` are opened in Phase 2
        // Step B and now produce regular per-criterion validation errors instead
        // of the reserved-key sentinel. `exclude` remains reserved until Step F
        // (direction 3) ships.
        yield 'exclude' => ['exclude'];
    }

    #[DataProvider('phase2ReservedKeyProvider')]
    #[Test]
    public function phase2ReservedKeyOnLayerEntryIsRejectedWithDedicatedHint(string $reservedKey): void
    {
        try {
            $this->validator->validate([
                ['name' => 'controller', 'patterns' => ['App\\Controller'], $reservedKey => ['placeholder']],
            ]);
            self::fail('Expected ConfigLoadException for reserved key "' . $reservedKey . '".');
        } catch (ConfigLoadException $e) {
            self::assertSame('architecture', $e->configPath);
            self::assertStringContainsString('unknown key', $e->getMessage());
            self::assertStringContainsString('"' . $reservedKey . '"', $e->getMessage());
            self::assertStringContainsString('reserved for an upcoming Phase 2 feature', $e->getMessage());
        }
    }

    #[Test]
    public function omittedMatchKeyDefaultsToAny(): void
    {
        $registry = $this->validator->validate([
            ['name' => 'service', 'patterns' => ['App\\Service']],
        ]);

        $definitions = $registry->definitions();
        self::assertCount(1, $definitions);
        self::assertSame(MatchMode::Any, $definitions[0]->membership()->mode);
    }

    #[Test]
    public function explicitMatchAnyParsesToAny(): void
    {
        $registry = $this->validator->validate([
            ['name' => 'service', 'patterns' => ['App\\Service'], 'match' => 'any'],
        ]);

        self::assertSame(MatchMode::Any, $registry->definitions()[0]->membership()->mode);
    }

    #[Test]
    public function explicitMatchAllParsesToAll(): void
    {
        $registry = $this->validator->validate([
            ['name' => 'service', 'patterns' => ['App\\Service'], 'match' => 'all'],
        ]);

        self::assertSame(MatchMode::All, $registry->definitions()[0]->membership()->mode);
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
        $registry = $this->validator->validate([
            ['name' => 'controller', 'patterns' => 'App\\Controller'],
        ]);

        self::assertSame(['App\\Controller'], $registry->definitions()[0]->patterns());
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

    // -------------------------------------------------------------------------
    // Per-criterion validation (Phase 2 direction 1)
    // -------------------------------------------------------------------------

    #[Test]
    public function suffixCriterionAcceptsShortName(): void
    {
        $registry = $this->validator->validate([
            ['name' => 'repository', 'suffix' => 'Repository'],
        ]);

        self::assertSame(['Repository'], $registry->definitions()[0]->membership()->suffix);
    }

    #[Test]
    public function suffixCriterionAcceptsListOfShortNames(): void
    {
        $registry = $this->validator->validate([
            ['name' => 'persistence', 'suffix' => ['Repository', 'Dao']],
        ]);

        self::assertSame(['Repository', 'Dao'], $registry->definitions()[0]->membership()->suffix);
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
        $registry = $this->validator->validate([
            ['name' => 'r', $kind => 'App\\Some\\Fqn'],
        ]);

        self::assertSame(['App\\Some\\Fqn'], self::criterionField($registry->definitions()[0]->membership(), $kind));
    }

    #[DataProvider('fqnCriterionProvider')]
    #[Test]
    public function fqnCriterionAcceptsListOfFqns(string $kind): void
    {
        $registry = $this->validator->validate([
            ['name' => 'r', $kind => ['App\\A', 'App\\B']],
        ]);

        self::assertSame(['App\\A', 'App\\B'], self::criterionField($registry->definitions()[0]->membership(), $kind));
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
        $registry = $this->validator->validate([
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

        $spec = $registry->definitions()[0]->membership();
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
        $registry = $this->validator->validate([
            ['name' => 'a', 'patterns' => ['App\\Shared', 'App\\Shared']],
        ]);

        self::assertSame(['a'], $registry->layerNames());
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
}

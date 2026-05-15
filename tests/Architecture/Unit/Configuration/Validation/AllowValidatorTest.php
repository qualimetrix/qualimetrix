<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Unit\Configuration\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Configuration\Allow\AllowAliasExpander;
use Qualimetrix\Architecture\Configuration\Validation\AllowValidator;
use Qualimetrix\Architecture\Domain\Allow\AllowListEntry;
use Qualimetrix\Architecture\Domain\Allow\LayerSelector;
use Qualimetrix\Architecture\Domain\Allow\SelectorKind;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Configuration\Pipeline\DeferredWarning;
use Qualimetrix\Core\Dependency\DependencyType;

#[CoversClass(AllowValidator::class)]
#[CoversClass(AllowAliasExpander::class)]
#[CoversClass(DeferredWarning::class)]
#[CoversClass(LayerSelector::class)]
final class AllowValidatorTest extends TestCase
{
    private AllowValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new AllowValidator();
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function emptyAllowProducesEmptyEntryList(): void
    {
        $warnings = [];
        $entries = $this->validator->validate([], ['controller'], $warnings);

        self::assertSame([], $entries);
        self::assertSame([], $warnings);
    }

    #[Test]
    public function nullAllowProducesEmptyEntryList(): void
    {
        $warnings = [];
        $entries = $this->validator->validate(null, ['controller'], $warnings);

        self::assertSame([], $entries);
    }

    #[Test]
    public function singleSourceAndTargetIsParsedAsExactExact(): void
    {
        $warnings = [];
        $entries = $this->validator->validate(
            ['controller' => ['service']],
            ['controller', 'service'],
            $warnings,
        );

        self::assertCount(1, $entries);
        self::assertExactEntry($entries[0], 'controller', ['service']);
        self::assertSame([], $warnings);
    }

    #[Test]
    public function nullTargetsProducesEmptyTargetList(): void
    {
        $warnings = [];
        $entries = $this->validator->validate(
            ['controller' => null],
            ['controller'],
            $warnings,
        );

        self::assertCount(1, $entries);
        self::assertExactEntry($entries[0], 'controller', []);
    }

    // -------------------------------------------------------------------------
    // Self-reference and dedup
    // -------------------------------------------------------------------------

    #[Test]
    public function selfReferenceIsSilentlyStripped(): void
    {
        $warnings = [];
        $entries = $this->validator->validate(
            ['controller' => ['controller', 'service']],
            ['controller', 'service'],
            $warnings,
        );

        // 'controller' should not appear in its own explicit target list.
        self::assertCount(1, $entries);
        self::assertExactEntry($entries[0], 'controller', ['service']);
        self::assertSame([], $warnings);
    }

    #[Test]
    public function duplicateExactTargetsAreDeduplicated(): void
    {
        $warnings = [];
        $entries = $this->validator->validate(
            ['a' => ['b', 'b']],
            ['a', 'b'],
            $warnings,
        );

        self::assertCount(1, $entries);
        self::assertExactEntry($entries[0], 'a', ['b']);
    }

    // -------------------------------------------------------------------------
    // Long-form allow entries
    // -------------------------------------------------------------------------

    #[Test]
    public function longFormAllowEntryWithoutTypesIsAcceptedSilently(): void
    {
        $warnings = [];
        $entries = $this->validator->validate(
            [
                'controller' => [
                    ['target' => 'service'],
                ],
            ],
            ['controller', 'service'],
            $warnings,
        );

        self::assertCount(1, $entries);
        self::assertExactEntry($entries[0], 'controller', ['service']);
        self::assertSame([], $warnings);
    }

    #[Test]
    public function longFormAllowEntryWithLegacyTypesKeyIsRejectedAsUnknown(): void
    {
        // The Step C/D forward-compat placeholder `types:` was renamed to
        // `relations:` when Step G wired the filter. A stale `types:` key in
        // a user config now surfaces as a plain "unknown long-form key" so the
        // user can rename it confidently.
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage("unknown long-form key 'types'");

        $this->validator->validate(
            [
                'controller' => [
                    ['target' => 'service', 'types' => ['method_call']],
                ],
            ],
            ['controller', 'service'],
            $warnings,
        );
    }

    #[Test]
    public function longFormAllowEntryWithoutTargetKeyIsRejected(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow.controller[0]');

        $this->validator->validate(
            [
                'controller' => [
                    ['relations' => ['extends']],
                ],
            ],
            ['controller', 'service'],
            $warnings,
        );
    }

    #[Test]
    public function longFormAllowEntryWithEmptyTargetIsRejected(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow.controller[0]');

        $this->validator->validate(
            [
                'controller' => [
                    ['target' => ''],
                ],
            ],
            ['controller', 'service'],
            $warnings,
        );
    }

    #[Test]
    public function longFormAllowEntryWithRelationsExpandsDirectValues(): void
    {
        // Step G: `relations:` is fully wired. Each direct token round-trips
        // through DependencyType::tryFrom() reflectively — adding a new enum
        // case automatically becomes accepted by the YAML config.
        $warnings = [];

        $entries = $this->validator->validate(
            [
                'controller' => [
                    ['target' => 'service', 'relations' => ['extends', 'implements']],
                ],
            ],
            ['controller', 'service'],
            $warnings,
        );

        self::assertCount(1, $entries);
        self::assertCount(1, $entries[0]->targets);
        self::assertSame(
            [DependencyType::Extends, DependencyType::Implements],
            $entries[0]->targets[0]->relations,
        );
        self::assertSame([], $warnings);
    }

    #[Test]
    public function longFormAllowEntryWithRelationsAliasExpandsToConstituents(): void
    {
        $warnings = [];

        $entries = $this->validator->validate(
            [
                'controller' => [
                    ['target' => 'service', 'relations' => ['inheritance']],
                ],
            ],
            ['controller', 'service'],
            $warnings,
        );

        self::assertCount(1, $entries);
        self::assertSame(
            [DependencyType::Extends, DependencyType::Implements, DependencyType::TraitUse],
            $entries[0]->targets[0]->relations,
        );
    }

    #[Test]
    public function longFormAllowEntryWithRelationsAliasAndDirectMixDeduplicates(): void
    {
        $warnings = [];

        $entries = $this->validator->validate(
            [
                'controller' => [
                    ['target' => 'service', 'relations' => ['inheritance', 'extends', 'static_call']],
                ],
            ],
            ['controller', 'service'],
            $warnings,
        );

        // `inheritance` expands to [Extends, Implements, TraitUse]; the trailing
        // `extends` is absorbed by dedup; `static_call` appends fresh.
        self::assertSame(
            [
                DependencyType::Extends,
                DependencyType::Implements,
                DependencyType::TraitUse,
                DependencyType::StaticCall,
            ],
            $entries[0]->targets[0]->relations,
        );
    }

    #[Test]
    public function longFormAllowEntryWithEmptyRelationsListIsRejected(): void
    {
        // An empty list is meaningless: the bare-string short form already
        // expresses "any relation allowed". Reject so the user can choose
        // between "remove the entry entirely" and "list at least one kind".
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('must list at least one relation kind');

        $this->validator->validate(
            [
                'controller' => [
                    ['target' => 'service', 'relations' => []],
                ],
            ],
            ['controller', 'service'],
            $warnings,
        );
    }

    #[Test]
    public function longFormAllowEntryWithNonListRelationsIsRejected(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow.controller[0].relations: must be a list');

        $this->validator->validate(
            [
                'controller' => [
                    ['target' => 'service', 'relations' => 'extends'],
                ],
            ],
            ['controller', 'service'],
            $warnings,
        );
    }

    #[Test]
    public function longFormAllowEntryWithUnknownRelationTokenIsRejected(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage("unknown relation kind 'tipes'");

        $this->validator->validate(
            [
                'controller' => [
                    ['target' => 'service', 'relations' => ['tipes']],
                ],
            ],
            ['controller', 'service'],
            $warnings,
        );
    }

    #[Test]
    public function bareStringAllowTargetLeavesRelationsNull(): void
    {
        $warnings = [];

        $entries = $this->validator->validate(
            ['controller' => ['service']],
            ['controller', 'service'],
            $warnings,
        );

        self::assertNull($entries[0]->targets[0]->relations);
    }

    #[Test]
    public function bareAndLongFormSiblings_bothReachPolicy_underUnionSemantics(): void
    {
        // Step G dedup boundary: a bare 'service' AND a long-form
        // `[target: service, relations: [extends]]` are semantically distinct
        // (UNION) — the validator must emit both AllowTargets so the policy
        // can apply union semantics. Regression guard: if the dedup helper
        // ever reverts to "skip duplicate exact name" without consulting the
        // bare/long-form discriminator, this test fails.
        $warnings = [];

        $entries = $this->validator->validate(
            [
                'controller' => [
                    ['target' => 'service', 'relations' => ['extends']],
                    'service',
                ],
            ],
            ['controller', 'service'],
            $warnings,
        );

        self::assertCount(2, $entries[0]->targets);
        self::assertSame([DependencyType::Extends], $entries[0]->targets[0]->relations);
        self::assertNull($entries[0]->targets[1]->relations);
    }

    #[Test]
    public function longFormAllowEntryAcceptsBothRelationsAndAllowCrossInstance(): void
    {
        // M2 coverage: `relations:` and `allow_cross_instance:` are
        // independent long-form fields; both reach AllowTarget unchanged.
        $warnings = [];

        $entries = $this->validator->validate(
            [
                'app-{module}' => [
                    [
                        'target' => 'domain-{module}',
                        'relations' => ['inheritance'],
                        'allow_cross_instance' => true,
                    ],
                ],
            ],
            ['app-Order', 'domain-Order'],
            $warnings,
        );

        self::assertCount(1, $entries[0]->targets);
        $target = $entries[0]->targets[0];
        self::assertTrue($target->allowCrossInstance);
        self::assertSame(
            [DependencyType::Extends, DependencyType::Implements, DependencyType::TraitUse],
            $target->relations,
        );
    }

    #[Test]
    public function longFormAllowEntryAcceptsAllowCrossInstanceFlag(): void
    {
        $warnings = [];

        $entries = $this->validator->validate(
            [
                'app-{m}' => [
                    ['target' => 'domain-{m}', 'allow_cross_instance' => true],
                ],
            ],
            ['app-Order', 'domain-Order'],
            $warnings,
        );

        self::assertCount(1, $entries);
        self::assertCount(1, $entries[0]->targets);
        self::assertTrue($entries[0]->targets[0]->allowCrossInstance);
        self::assertSame([], $warnings);
    }

    #[Test]
    public function longFormAllowEntryDefaultsAllowCrossInstanceToFalse(): void
    {
        $warnings = [];

        $entries = $this->validator->validate(
            [
                'app-{m}' => [
                    ['target' => 'domain-{m}'],
                ],
            ],
            ['app-Order', 'domain-Order'],
            $warnings,
        );

        self::assertCount(1, $entries);
        self::assertFalse($entries[0]->targets[0]->allowCrossInstance);
    }

    #[Test]
    public function longFormAllowEntryRejectsNonBooleanAllowCrossInstance(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage("'allow_cross_instance' must be a boolean, got string");

        $this->validator->validate(
            [
                'app-{m}' => [
                    ['target' => 'domain-{m}', 'allow_cross_instance' => 'yes'],
                ],
            ],
            ['app-Order', 'domain-Order'],
            $warnings,
        );
    }

    #[Test]
    public function longFormAllowEntryWithUnknownKeyIsRejected(): void
    {
        // A typo in a long-form key (e.g. `tipes:` instead of `types:`) would
        // otherwise be silently dropped on the floor. Surface as an explicit
        // configuration error.
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage("unknown long-form key 'tipes'");

        $this->validator->validate(
            [
                'controller' => [
                    ['target' => 'service', 'tipes' => ['method_call']],
                ],
            ],
            ['controller', 'service'],
            $warnings,
        );
    }

    // -------------------------------------------------------------------------
    // Step C: selector kinds
    // -------------------------------------------------------------------------

    #[Test]
    public function globSourceSelectorIsRecognised(): void
    {
        $warnings = [];
        $entries = $this->validator->validate(
            ['domain-*' => ['shared']],
            ['domain-Order', 'shared'],
            $warnings,
        );

        self::assertCount(1, $entries);
        self::assertSame(SelectorKind::Glob, $entries[0]->source->kind);
        self::assertSame('domain-*', $entries[0]->source->originalString());
        // Glob source skips registry cross-validation (templates may produce
        // matching layers later).
    }

    #[Test]
    public function globSourceSkipsRegistryCrossValidation(): void
    {
        // No concrete layer named `unknown-*` exists; glob sources are not
        // cross-validated because Step D template-expansion may produce them.
        $warnings = [];
        $entries = $this->validator->validate(
            ['unknown-*' => ['shared']],
            ['shared'],
            $warnings,
        );

        self::assertCount(1, $entries);
        self::assertSame(SelectorKind::Glob, $entries[0]->source->kind);
    }

    #[Test]
    public function exactSourceStillRejectsUnknownLayer(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow.controller: unknown layer');

        $this->validator->validate(
            ['controller' => ['service']],
            ['service'],
            $warnings,
        );
    }

    #[Test]
    public function exactTargetReferencingUnknownLayerIsRejected(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage("architecture.allow.controller[0]: unknown layer 'servise'");

        $this->validator->validate(
            ['controller' => ['servise']],
            ['controller', 'service'],
            $warnings,
        );
    }

    #[Test]
    public function globTargetSelectorSkipsRegistryCrossValidation(): void
    {
        // `unknown-*` matches no concrete layer today; glob targets are not
        // cross-validated for the same reason as glob sources.
        $warnings = [];
        $entries = $this->validator->validate(
            ['controller' => ['unknown-*']],
            ['controller', 'service'],
            $warnings,
        );

        self::assertCount(1, $entries);
        self::assertCount(1, $entries[0]->targets);
        self::assertSame(SelectorKind::Glob, $entries[0]->targets[0]->target->kind);
    }

    #[Test]
    public function capturedSourceSelectorIsRecognised(): void
    {
        $warnings = [];
        $entries = $this->validator->validate(
            ['app-{m}' => []],
            ['app-Order'],
            $warnings,
        );

        self::assertCount(1, $entries);
        self::assertSame(SelectorKind::Captured, $entries[0]->source->kind);
        self::assertSame('app-{m}', $entries[0]->source->originalString());
    }

    #[Test]
    public function capturedTargetSelectorIsRecognised(): void
    {
        // Captured target is accepted only when its capture variables are a
        // subset of the source-side captures — otherwise the runtime binding
        // would be undefined. Pair captured source with captured target sharing
        // the same {@code m} variable.
        $warnings = [];
        $entries = $this->validator->validate(
            ['app-{m}' => ['domain-{m}']],
            ['app-Order', 'domain-Order'],
            $warnings,
        );

        self::assertCount(1, $entries);
        self::assertSame(SelectorKind::Captured, $entries[0]->targets[0]->target->kind);
        self::assertFalse($entries[0]->targets[0]->allowCrossInstance);
    }

    // -------------------------------------------------------------------------
    // Step C: grammar enforcement (selector parser errors rewrapped at this layer)
    // -------------------------------------------------------------------------

    #[Test]
    public function unbalancedOpenBraceIsRejectedWithPathContext(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow.controller[0]');

        $this->validator->validate(
            ['controller' => ['domain-{m']],
            ['controller'],
            $warnings,
        );
    }

    #[Test]
    public function unbalancedCloseBraceIsRejectedWithPathContext(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage("architecture.allow.controller[0]");

        $this->validator->validate(
            ['controller' => ['domain-m}']],
            ['controller'],
            $warnings,
        );
    }

    #[Test]
    public function unbalancedBraceOnSourceKeyIsRejected(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow.app-{m');

        $this->validator->validate(
            ['app-{m' => []],
            ['app-Order'],
            $warnings,
        );
    }

    #[Test]
    public function unknownCaptureQuantifierIsRejected(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage("only ':**' is supported");

        $this->validator->validate(
            ['controller' => ['domain-{m:weird}']],
            ['controller'],
            $warnings,
        );
    }

    #[Test]
    public function invalidCaptureNameIsRejected(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('invalid capture name');

        $this->validator->validate(
            ['controller' => ['domain-{0bad}']],
            ['controller'],
            $warnings,
        );
    }

    // -------------------------------------------------------------------------
    // Shape validation
    // -------------------------------------------------------------------------

    #[Test]
    public function allowAsSequentialListIsRejected(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow');

        $this->validator->validate(['a', 'b'], ['a'], $warnings);
    }

    #[Test]
    public function allowAsScalarIsRejected(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow');

        $this->validator->validate('wrong', ['a'], $warnings);
    }

    #[Test]
    public function allowTargetsAsScalarIsRejected(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow.controller');

        $this->validator->validate(
            ['controller' => 'service'],
            ['controller', 'service'],
            $warnings,
        );
    }

    #[Test]
    public function emptyTargetStringIsRejected(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('architecture.allow.controller[0]');

        $this->validator->validate(
            ['controller' => ['']],
            ['controller'],
            $warnings,
        );
    }

    #[Test]
    public function configPathIsArchitectureForAllErrors(): void
    {
        $warnings = [];

        try {
            $this->validator->validate('wrong', ['a'], $warnings);
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertSame('architecture', $e->configPath);
        }
    }

    // -------------------------------------------------------------------------
    // Step E: capture-binding cross-validation
    // -------------------------------------------------------------------------

    #[Test]
    public function capturedTargetWithUndeclaredVariableIsRejected(): void
    {
        // 'app-{x}': ['domain-{y}'] — target {y} is not bound by source {x}.
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage("captured target 'domain-{y}' references variable(s) 'y' not declared by source 'app-{x}'");

        $this->validator->validate(
            ['app-{x}' => ['domain-{y}']],
            ['app-Order', 'domain-Order'],
            $warnings,
        );
    }

    #[Test]
    public function capturedTargetWithGlobSourceIsRejected(): void
    {
        // 'shared-*': ['domain-{m}'] — source produces no binding for {m}.
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage("source 'shared-*' is a glob selector and declares no capture variables");

        $this->validator->validate(
            ['shared-*' => ['domain-{m}']],
            ['shared-Lib', 'domain-Order'],
            $warnings,
        );
    }

    #[Test]
    public function capturedTargetWithExactSourceIsRejected(): void
    {
        // 'controller': ['domain-{m}'] — exact source has no captures.
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage("source 'controller' is an exact layer name and declares no capture variables");

        $this->validator->validate(
            ['controller' => ['domain-{m}']],
            ['controller', 'domain-Order'],
            $warnings,
        );
    }

    #[Test]
    public function capturedTargetWithSubsetOfSourceVariablesIsAccepted(): void
    {
        // '{a}-{b}': ['domain-{a}'] — target uses {a} only, which IS declared
        // by the source. Subset references are legal.
        $warnings = [];

        $entries = $this->validator->validate(
            ['{a}-{b}' => ['domain-{a}']],
            ['app-Order', 'domain-app'],
            $warnings,
        );

        self::assertCount(1, $entries);
        self::assertSame(SelectorKind::Captured, $entries[0]->targets[0]->target->kind);
    }

    #[Test]
    public function exactTargetSkipsCrossValidation(): void
    {
        // 'domain-{m}': ['vendor'] — exact target ignores binding entirely;
        // any captured-source-to-exact-target pairing is legal.
        $warnings = [];

        $entries = $this->validator->validate(
            ['domain-{m}' => ['vendor']],
            ['domain-Order', 'vendor'],
            $warnings,
        );

        self::assertCount(1, $entries);
        self::assertSame(SelectorKind::Exact, $entries[0]->targets[0]->target->kind);
    }

    #[Test]
    public function globTargetSkipsCrossValidation(): void
    {
        // 'domain-{m}': ['shared-*'] — glob target ignores binding entirely.
        $warnings = [];

        $entries = $this->validator->validate(
            ['domain-{m}' => ['shared-*']],
            ['domain-Order', 'shared-Lib'],
            $warnings,
        );

        self::assertCount(1, $entries);
        self::assertSame(SelectorKind::Glob, $entries[0]->targets[0]->target->kind);
    }

    #[Test]
    public function singleSegmentSourceWithMultiSegmentTargetIsRejected(): void
    {
        // 'app-{m}': ['domain-{m:**}'] — runtime substitutes the single-segment
        // value, target's :** annotation would be silently ignored.
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage("'m' (source: {var}, target: {var:**})");

        $this->validator->validate(
            ['app-{m}' => ['domain-{m:**}']],
            ['app-Order', 'domain-Order'],
            $warnings,
        );
    }

    #[Test]
    public function multiSegmentSourceWithSingleSegmentTargetIsRejected(): void
    {
        // Reverse direction: source binds multi-segment, target declares single.
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage("'m' (source: {var:**}, target: {var})");

        $this->validator->validate(
            ['app-{m:**}' => ['domain-{m}']],
            ['app-Order', 'domain-Order'],
            $warnings,
        );
    }

    #[Test]
    public function matchingMultiSegmentShapesAreAccepted(): void
    {
        // 'app-{m:**}': ['domain-{m:**}'] — shapes match on both sides.
        $warnings = [];

        $entries = $this->validator->validate(
            ['app-{m:**}' => ['domain-{m:**}']],
            ['app-Order', 'domain-Order'],
            $warnings,
        );

        self::assertCount(1, $entries);
    }

    #[Test]
    public function allowCrossInstanceFlagDoesNotRelaxCaptureCrossValidation(): void
    {
        // The flag affects runtime binding identity, NOT the grammar — a
        // captured target with an undeclared variable is rejected at config
        // load regardless of the flag, because the variable would be
        // meaningless at runtime even in cross-instance mode.
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage("captured target 'domain-{y}' references variable(s) 'y' not declared by source 'app-{x}'");

        $this->validator->validate(
            [
                'app-{x}' => [
                    ['target' => 'domain-{y}', 'allow_cross_instance' => true],
                ],
            ],
            ['app-Order', 'domain-Order'],
            $warnings,
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param list<string> $expectedTargetNames
     */
    private static function assertExactEntry(AllowListEntry $entry, string $expectedSource, array $expectedTargetNames): void
    {
        self::assertSame(SelectorKind::Exact, $entry->source->kind, 'Expected source selector to be exact.');
        self::assertSame($expectedSource, $entry->source->originalString());
        self::assertCount(\count($expectedTargetNames), $entry->targets);
        foreach ($entry->targets as $i => $target) {
            self::assertSame(SelectorKind::Exact, $target->target->kind, "Expected target[$i] to be exact.");
            self::assertSame($expectedTargetNames[$i], $target->target->originalString());
        }
    }
}

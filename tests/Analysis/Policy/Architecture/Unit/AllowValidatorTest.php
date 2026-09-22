<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit\Configuration\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\AllowAliasExpander;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\AllowListEntry;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\LayerSelector;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\SelectorKind;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\AllowValidator;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\LongFormAllowEntryNormalizer;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureConfigurationWarning;

#[CoversClass(AllowValidator::class)]
#[CoversClass(AllowAliasExpander::class)]
#[CoversClass(LongFormAllowEntryNormalizer::class)]
#[CoversClass(ArchitectureConfigurationWarning::class)]
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
    public function itProducesAnEmptyEntryListForAnEmptyAllow(): void
    {
        $warnings = [];
        $entries = $this->validator->validate([], ['controller'], $warnings);

        self::assertSame([], $entries);
        self::assertSame([], $warnings);
    }

    #[Test]
    public function itProducesAnEmptyEntryListForANullAllow(): void
    {
        $warnings = [];
        $entries = $this->validator->validate(null, ['controller'], $warnings);

        self::assertSame([], $entries);
    }

    #[Test]
    public function itParsesASingleSourceAndTargetAsExactExact(): void
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
    public function itProducesAnEmptyTargetListForNullTargets(): void
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
    public function itPreservesSelfReferenceForExactCycleValidation(): void
    {
        $warnings = [];
        $entries = $this->validator->validate(
            ['controller' => ['controller', 'service']],
            ['controller', 'service'],
            $warnings,
        );

        // The downstream ExactAllowCycleValidator needs the explicit self-edge.
        self::assertCount(1, $entries);
        self::assertExactEntry($entries[0], 'controller', ['controller', 'service']);
        self::assertSame([], $warnings);
    }

    #[Test]
    public function itDeduplicatesDuplicateExactTargets(): void
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
    public function itSilentlyAcceptsALongFormAllowEntryWithoutTypes(): void
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
    public function itRejectsALongFormAllowEntryWithALegacyTypesKeyAsUnknown(): void
    {
        // The Step C/D forward-compat placeholder `types:` was renamed to
        // `relations:` when Step G wired the filter. A stale `types:` key in
        // a user config now surfaces as a plain "unknown long-form key" so the
        // user can rename it confidently.
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
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
    public function itRejectsALongFormAllowEntryWithoutATargetKey(): void
    {
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
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
    public function itRejectsALongFormAllowEntryWithAnEmptyTarget(): void
    {
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
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
    public function itExpandsDirectValuesInALongFormAllowEntryWithRelations(): void
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
    public function itExpandsARelationsAliasToItsConstituentsInALongFormAllowEntry(): void
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
    public function itDeduplicatesAMixOfRelationsAliasAndDirectValuesInALongFormAllowEntry(): void
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
    public function itRejectsALongFormAllowEntryWithAnEmptyRelationsList(): void
    {
        // An empty list is meaningless: the bare-string short form already
        // expresses "any relation allowed". Reject so the user can choose
        // between "remove the entry entirely" and "list at least one kind".
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
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
    public function itRejectsALongFormAllowEntryWithNonListRelations(): void
    {
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
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
    public function itRejectsALongFormAllowEntryWithAnUnknownRelationToken(): void
    {
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
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
    public function itLeavesRelationsNullForABareStringAllowTarget(): void
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
    public function itLetsBareAndLongFormSiblingsBothReachPolicyUnderUnionSemantics(): void
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
    public function itAcceptsBothRelationsAndAllowCrossInstanceInALongFormAllowEntry(): void
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
    public function itAcceptsAnAllowCrossInstanceFlagInALongFormAllowEntry(): void
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
    public function itDefaultsAllowCrossInstanceToFalseInALongFormAllowEntry(): void
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
    public function itRejectsANonBooleanAllowCrossInstanceInALongFormAllowEntry(): void
    {
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
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
    public function itAcceptsACamelCaseAllowCrossInstanceFlagInALongFormAllowEntry(): void
    {
        // M12: Phase 3.5 made the architecture subtree preserve user-supplied
        // key spellings, so both `allow_cross_instance` (canonical) and
        // `allowCrossInstance` (camelCase) need to resolve identically.
        $warnings = [];

        $entries = $this->validator->validate(
            [
                'app-{m}' => [
                    ['target' => 'domain-{m}', 'allowCrossInstance' => true],
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
    public function itProducesTheSameResultForSnakeAndCamelCaseFlagsInALongFormAllowEntry(): void
    {
        // Same fixture parsed twice, once per spelling — the resulting
        // AllowTarget state must be bit-for-bit identical.
        $warnings = [];
        $snake = $this->validator->validate(
            [
                'app-{m}' => [
                    ['target' => 'domain-{m}', 'allow_cross_instance' => true],
                ],
            ],
            ['app-Order', 'domain-Order'],
            $warnings,
        );

        $warnings = [];
        $camel = $this->validator->validate(
            [
                'app-{m}' => [
                    ['target' => 'domain-{m}', 'allowCrossInstance' => true],
                ],
            ],
            ['app-Order', 'domain-Order'],
            $warnings,
        );

        self::assertSame(
            $snake[0]->targets[0]->allowCrossInstance,
            $camel[0]->targets[0]->allowCrossInstance,
        );
        self::assertSame(
            $snake[0]->targets[0]->relations,
            $camel[0]->targets[0]->relations,
        );
        self::assertSame(
            $snake[0]->source->originalString(),
            $camel[0]->source->originalString(),
        );
    }

    #[Test]
    public function itRejectsBothSpellingsOnTheSameLongFormAllowEntry(): void
    {
        // Ambiguity — silently picking one over the other based on key order
        // would surprise the user. Reject explicitly.
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage("specify either 'allow_cross_instance' or 'allowCrossInstance', not both");

        $this->validator->validate(
            [
                'app-{m}' => [
                    [
                        'target' => 'domain-{m}',
                        'allow_cross_instance' => true,
                        'allowCrossInstance' => false,
                    ],
                ],
            ],
            ['app-Order', 'domain-Order'],
            $warnings,
        );
    }

    #[Test]
    public function itRejectsANonBooleanCamelCaseAllowCrossInstanceInALongFormAllowEntry(): void
    {
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage("'allowCrossInstance' must be a boolean, got string");

        $this->validator->validate(
            [
                'app-{m}' => [
                    ['target' => 'domain-{m}', 'allowCrossInstance' => 'yes'],
                ],
            ],
            ['app-Order', 'domain-Order'],
            $warnings,
        );
    }

    #[Test]
    public function itRejectsALongFormAllowEntryWithAnUnknownKey(): void
    {
        // A typo in a long-form key (e.g. `tipes:` instead of `types:`) would
        // otherwise be silently dropped on the floor. Surface as an explicit
        // configuration error.
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
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
    public function itRecognisesAGlobSourceSelector(): void
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
    public function itSkipsRegistryCrossValidationForAGlobSource(): void
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
    public function itStillRejectsAnUnknownLayerForAnExactSource(): void
    {
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('architecture.allow.controller: unknown layer');

        $this->validator->validate(
            ['controller' => ['service']],
            ['service'],
            $warnings,
        );
    }

    #[Test]
    public function itRejectsAnExactTargetReferencingAnUnknownLayer(): void
    {
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage("architecture.allow.controller[0]: unknown layer 'servise'");

        $this->validator->validate(
            ['controller' => ['servise']],
            ['controller', 'service'],
            $warnings,
        );
    }

    #[Test]
    public function itSkipsRegistryCrossValidationForAGlobTargetSelector(): void
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
    public function itRecognisesACapturedSourceSelector(): void
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
    public function itRecognisesACapturedTargetSelector(): void
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
    public function itRejectsAnUnbalancedOpenBraceWithPathContext(): void
    {
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('architecture.allow.controller[0]');

        $this->validator->validate(
            ['controller' => ['domain-{m']],
            ['controller'],
            $warnings,
        );
    }

    #[Test]
    public function itRejectsAnUnbalancedCloseBraceWithPathContext(): void
    {
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage("architecture.allow.controller[0]");

        $this->validator->validate(
            ['controller' => ['domain-m}']],
            ['controller'],
            $warnings,
        );
    }

    #[Test]
    public function itRejectsAnUnbalancedBraceOnTheSourceKey(): void
    {
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('architecture.allow.app-{m');

        $this->validator->validate(
            ['app-{m' => []],
            ['app-Order'],
            $warnings,
        );
    }

    #[Test]
    public function itRejectsAnUnknownCaptureQuantifier(): void
    {
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage("only ':*' is supported");

        $this->validator->validate(
            ['controller' => ['domain-{m:weird}']],
            ['controller'],
            $warnings,
        );
    }

    #[Test]
    public function itRejectsAnInvalidCaptureName(): void
    {
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
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
    public function itRejectsAllowAsASequentialList(): void
    {
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('architecture.allow');

        $this->validator->validate(['a', 'b'], ['a'], $warnings);
    }

    #[Test]
    public function itRejectsAllowAsAScalar(): void
    {
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('architecture.allow');

        $this->validator->validate('wrong', ['a'], $warnings);
    }

    #[Test]
    public function itRejectsAllowTargetsAsAScalar(): void
    {
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('architecture.allow.controller');

        $this->validator->validate(
            ['controller' => 'service'],
            ['controller', 'service'],
            $warnings,
        );
    }

    #[Test]
    public function itRejectsAnEmptyTargetString(): void
    {
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('architecture.allow.controller[0]');

        $this->validator->validate(
            ['controller' => ['']],
            ['controller'],
            $warnings,
        );
    }

    #[Test]
    public function itAddressesTheResolvedDocumentForAllErrors(): void
    {
        $warnings = [];

        try {
            $this->validator->validate('wrong', ['a'], $warnings);
            self::fail('Expected ConfigurationRefusal');
        } catch (ConfigurationRefusal $e) {
            self::assertSame(ConfigurationSource::Resolved, $e->origin()->source());
        }
    }

    // -------------------------------------------------------------------------
    // Step E: capture-binding cross-validation
    // -------------------------------------------------------------------------

    #[Test]
    public function itRejectsACapturedTargetWithAnUndeclaredVariable(): void
    {
        // 'app-{x}': ['domain-{y}'] — target {y} is not bound by source {x}.
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage("captured target 'domain-{y}' references variable(s) 'y' not declared by source 'app-{x}'");

        $this->validator->validate(
            ['app-{x}' => ['domain-{y}']],
            ['app-Order', 'domain-Order'],
            $warnings,
        );
    }

    #[Test]
    public function itRejectsACapturedTargetWithAGlobSource(): void
    {
        // 'shared-*': ['domain-{m}'] — source produces no binding for {m}.
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage("source 'shared-*' is a glob selector and declares no capture variables");

        $this->validator->validate(
            ['shared-*' => ['domain-{m}']],
            ['shared-Lib', 'domain-Order'],
            $warnings,
        );
    }

    #[Test]
    public function itRejectsACapturedTargetWithAnExactSource(): void
    {
        // 'controller': ['domain-{m}'] — exact source has no captures.
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage("source 'controller' is an exact layer name and declares no capture variables");

        $this->validator->validate(
            ['controller' => ['domain-{m}']],
            ['controller', 'domain-Order'],
            $warnings,
        );
    }

    #[Test]
    public function itAcceptsACapturedTargetWithASubsetOfSourceVariables(): void
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
    public function itSkipsCrossValidationForAnExactTarget(): void
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
    public function itSkipsCrossValidationForAGlobTarget(): void
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
    public function itRejectsAMultiSegmentCaptureOnTheTarget(): void
    {
        // 'app-{m}': ['domain-{m:**}'] — runtime substitutes the single-segment
        // value, target's :** annotation would be silently ignored.
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage("quantifier ':**' is not supported");

        $this->validator->validate(
            ['app-{m}' => ['domain-{m:**}']],
            ['app-Order', 'domain-Order'],
            $warnings,
        );
    }

    #[Test]
    public function itRejectsAMultiSegmentCaptureOnTheSource(): void
    {
        // Reverse direction: source binds multi-segment, target declares single.
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage("quantifier ':**' is not supported");

        $this->validator->validate(
            ['app-{m:**}' => ['domain-{m}']],
            ['app-Order', 'domain-Order'],
            $warnings,
        );
    }

    #[Test]
    public function itAcceptsMatchingExplicitSingleSegmentShapes(): void
    {
        // `:*` spells the only capture shape a concrete layer name can carry.
        $warnings = [];

        $entries = $this->validator->validate(
            ['app-{m:*}' => ['domain-{m:*}']],
            ['app-Order', 'domain-Order'],
            $warnings,
        );

        self::assertCount(1, $entries);
    }

    #[Test]
    public function itDoesNotRelaxCaptureCrossValidationWithAllowCrossInstance(): void
    {
        // The flag affects runtime binding identity, NOT the grammar — a
        // captured target with an undeclared variable is rejected at config
        // load regardless of the flag, because the variable would be
        // meaningless at runtime even in cross-instance mode.
        $warnings = [];

        $this->expectException(ConfigurationRefusal::class);
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

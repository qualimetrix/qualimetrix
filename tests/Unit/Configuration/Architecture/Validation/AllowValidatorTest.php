<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Architecture\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\Architecture\Validation\AllowValidator;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Configuration\Pipeline\DeferredWarning;
use Qualimetrix\Core\Architecture\Allow\AllowListEntry;
use Qualimetrix\Core\Architecture\Allow\LayerSelector;
use Qualimetrix\Core\Architecture\Allow\SelectorKind;

#[CoversClass(AllowValidator::class)]
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
    public function longFormAllowEntryWithTypesEmitsDeprecationWarning(): void
    {
        $warnings = [];
        $entries = $this->validator->validate(
            [
                'controller' => [
                    ['target' => 'service', 'types' => ['method_call']],
                ],
            ],
            ['controller', 'service'],
            $warnings,
        );

        self::assertCount(1, $entries);
        self::assertExactEntry($entries[0], 'controller', ['service']);
        self::assertCount(1, $warnings);
        self::assertSame('warning', $warnings[0]->level);
        self::assertStringContainsString("'types' filter declared but not yet enforced", $warnings[0]->message);
        self::assertStringContainsString('architecture.allow.controller', $warnings[0]->message);
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
                    ['types' => ['method_call']],
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
    public function longFormAllowEntryWithReservedRelationsKeyIsRejected(): void
    {
        // ADR 0007 reserves `relations:` for Step G. Accepting it silently
        // would let users write a constrained allow row that actually allows
        // every dependency type — silent widening of policy. Reject at config
        // load with an actionable error.
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage("long-form key 'relations' is reserved for Step G");

        $this->validator->validate(
            [
                'controller' => [
                    ['target' => 'service', 'relations' => ['extends']],
                ],
            ],
            ['controller', 'service'],
            $warnings,
        );
    }

    #[Test]
    public function longFormAllowEntryWithReservedAllowCrossInstanceKeyIsRejected(): void
    {
        $warnings = [];

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage("long-form key 'allow_cross_instance' is reserved for Step E");

        $this->validator->validate(
            [
                'controller' => [
                    ['target' => 'service', 'allow_cross_instance' => true],
                ],
            ],
            ['controller', 'service'],
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
        $warnings = [];
        $entries = $this->validator->validate(
            ['controller' => ['domain-{m}']],
            ['controller'],
            $warnings,
        );

        self::assertCount(1, $entries);
        self::assertSame(SelectorKind::Captured, $entries[0]->targets[0]->target->kind);
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

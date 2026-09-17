<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\DirectiveVocabulary;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxDirectiveAudit\HeterogeneityFloor;
use QmxDirectiveAudit\MeasuredEffects;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveEffect;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveUnmeasurableReason;

/**
 * Agreement between two repository vocabularies: what `composer
 * directives:audit`'s tooling names as measured and required, against what
 * the product's own {@see DirectiveEffect} and
 * {@see DirectiveUnmeasurableReason} enums can actually publish.
 *
 * The library has no PSR-4 entry, the same as `scripts/finding-gate/`, so
 * this test loads it the way its own scripts do.
 */
final class DirectiveEffectVocabularyAgreementTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        foreach (
            [
                'AuditReportError',
                'MeasuredEffects',
                'AuditedVerdict',
                'VerdictReport',
                'HeterogeneityFloor',
                'EnumeratedSite',
                'SiteEnumeration',
                'Population',
            ] as $part
        ) {
            require_once \dirname(__DIR__, 2) . '/scripts/directive-audit/' . $part . '.php';
        }
    }

    #[Test]
    public function itNamesEveryVerdictTheProductCanPublishAndNoOther(): void
    {
        $published = array_map(static fn(DirectiveEffect $effect): string => $effect->value, DirectiveEffect::cases());
        $named = array_keys(MeasuredEffects::TABLE);

        sort($published);
        sort($named);

        self::assertSame($published, $named);
    }

    /**
     * The table must still say what the condition it replaced said. Completeness
     * alone would pass a table with a boolean flipped, and so would a live run:
     * nothing in this tree publishes an unmeasured-only report.
     */
    #[Test]
    public function itKeepsTheMeasuredMeaningOfEveryVerdictKnownToday(): void
    {
        foreach (DirectiveEffect::cases() as $effect) {
            self::assertSame(
                $effect->value !== 'unmeasured',
                MeasuredEffects::isMeasured($effect->value),
                $effect->value,
            );
        }
    }

    /** A verdict the product can publish and the floor cannot name is a floor that guesses. */
    #[Test]
    public function itAsksForEveryVerdictTheProductCanPublishAndNoOther(): void
    {
        $published = array_map(static fn(DirectiveEffect $effect): string => $effect->value, DirectiveEffect::cases());
        $required = HeterogeneityFloor::REQUIRED_EFFECTS;

        sort($published);
        sort($required);

        self::assertSame($published, $required);
    }

    /**
     * The same in the other vocabulary, and the one that carries the point: a
     * masking coalition is `unmeasured` like any other refusal, so a floor
     * written over verdicts alone never asks for it.
     */
    #[Test]
    public function itAsksForEveryRefusalTheProductCanPublishAndNoOther(): void
    {
        $published = array_map(
            static fn(DirectiveUnmeasurableReason $reason): string => $reason->value,
            DirectiveUnmeasurableReason::cases(),
        );
        $required = HeterogeneityFloor::REQUIRED_REASONS;

        sort($published);
        sort($required);

        self::assertSame($published, $required);
    }
}

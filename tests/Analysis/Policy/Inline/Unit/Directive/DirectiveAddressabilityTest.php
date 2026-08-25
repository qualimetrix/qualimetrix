<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveAddressability;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;

/**
 * Round-11 regressions on the two refusal texts this class builds.
 *
 * `problemWithThreshold` used to run its own `channel:level` arithmetic
 * instead of asking {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelAddressing::problemWithRulePair()},
 * so an invalid level half and an invalid rule half both got the same
 * "addresses a channel at a level" wording, and the `--rule-opt` advice
 * printed even though neither half had been checked. `problemWithSuppression`
 * built its refusal by prefixing a shared answer that already carried its own
 * subject with "Suppression %s", reading as two subjects in one sentence.
 */
#[CoversClass(DirectiveAddressability::class)]
final class DirectiveAddressabilityTest extends TestCase
{
    private const string FILE = 'src/Foo.php';

    #[Test]
    public function itNamesTheBadHalfWhenTheThresholdLevelDoesNotParse(): void
    {
        $rejection = self::addressability()->problemWithThreshold(self::threshold('coupling.cbo:bogus'));

        self::assertNotNull($rejection);
        self::assertFalse($rejection->ruleExistsButCannotBeRetuned);
        self::assertStringContainsString('"bogus" is not a level', $rejection->message);
        self::assertStringNotContainsString(
            'addresses a channel at a level',
            $rejection->message,
            '"bogus" is not a level at all, so the refusal must not claim the pair names one.',
        );
        self::assertStringNotContainsString(
            '--rule-opt',
            $rejection->message,
            'The level half never parsed, so no --rule-opt spelling built from it can be recommended.',
        );
    }

    #[Test]
    public function itNamesTheBadHalfWhenTheThresholdRuleDoesNotExist(): void
    {
        $rejection = self::addressability()->problemWithThreshold(self::threshold('nosuchrule:class'));

        self::assertNotNull($rejection);
        self::assertFalse($rejection->ruleExistsButCannotBeRetuned);
        self::assertStringContainsString('"nosuchrule" is not a rule name', $rejection->message);
        self::assertStringNotContainsString(
            'addresses a channel at a level',
            $rejection->message,
            '"nosuchrule" is not a rule, so the refusal must not claim the pair addresses one at a level.',
        );
        self::assertStringNotContainsString(
            '--rule-opt',
            $rejection->message,
            'The rule half never resolved, so no --rule-opt spelling built from it can be recommended.',
        );
    }

    #[Test]
    public function itOnlyRecommendsRuleOptWhenBothHalvesOfTheThresholdPairAreValid(): void
    {
        $rejection = self::addressability()->problemWithThreshold(self::threshold('complexity.cyclomatic:class'));

        self::assertNotNull($rejection);
        self::assertStringContainsString('--rule-opt complexity.cyclomatic:class.<option>=<value>', $rejection->message);
    }

    #[Test]
    public function itGivesTheSuppressionRefusalOneSubjectNotTwo(): void
    {
        $message = self::addressability()->problemWithSuppression(self::suppression('coupling.cbo:project'));

        self::assertNotNull($message);
        self::assertStringStartsWith('Suppression "coupling.cbo:project" addresses', $message);
        self::assertStringNotContainsString(
            'Suppression Channel selector',
            $message,
            'The pair refusal already names its own subject; prefixing "Suppression %s" onto it glues two subjects into one sentence.',
        );
    }

    /**
     * Orchestrator-decided ordering (round 11, r11-claude-06): the retired
     * `rule#code` spelling is refused before the pair grammar is asked about
     * the level half, because its correction is a deletion and a level
     * complaint about text that is not even a channel selector would be
     * useless.
     */
    #[Test]
    public function itRefusesTheRetiredPairSpellingBeforeAskingAboutTheLevel(): void
    {
        $message = self::addressability()->problemWithSuppression(self::suppression('coupling.cbo#coupling.cbo:class'));

        self::assertNotNull($message);
        self::assertStringContainsString('retired channel-pair form', $message);
        self::assertStringNotContainsString('names no level', $message);
        self::assertStringNotContainsString('is not a level', $message);
    }

    private static function addressability(): DirectiveAddressability
    {
        return new DirectiveAddressability(new ChannelUniverse(
            [
                'complexity.cyclomatic' => ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Class_),
                'coupling.cbo' => ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Class_),
            ],
            [
                'complexity.cyclomatic' => ['complexity.cyclomatic'],
                'coupling.cbo' => ['coupling.cbo'],
            ],
            [
                'complexity.cyclomatic' => true,
                'coupling.cbo' => true,
            ],
            'computed.health',
            new ResolvedComputedMetricDefinitions([]),
        ));
    }

    private static function threshold(string $rulePattern): ThresholdOverride
    {
        return new ThresholdOverride($rulePattern, 10, null, 1, self::declarationSubject(), ControlScope::Class_);
    }

    private static function suppression(string $rule): Suppression
    {
        return new Suppression($rule, null, 1, SuppressionType::File);
    }

    private static function declarationSubject(): MetricSubject
    {
        return MetricSubject::declaration(DeclarationPath::of(
            SymbolPath::forClass('App', 'Foo'),
            RelativePath::fromString(self::FILE),
            DeclarationOrdinal::fromRank(0),
        ));
    }
}

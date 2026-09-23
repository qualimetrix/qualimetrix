<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit\Directive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\JudgedMetrics;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveRefusal;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveAddressability;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
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

    /**
     * A threshold naming the metric key a report prints beside the channel.
     *
     * A fixture using `complexity.ccn` would not keep the needed distinction:
     * the channel and metric it judges both have that name, so the metric
     * key was not itself a rule name. ADR 0060 records the rename to
     * `complexity.ccn` too, which made the metric key **and** the rule name
     * identical — the premise this test exists to check disappeared, and
     * `problemWithThreshold()` started returning `null` because the name now
     * resolves as a rule. `code-smell.parameter-count` keeps the premise
     * true: it is the metric {@see \Qualimetrix\Analysis\Evidence\CodeSmell\LongParameterListRule}
     * and {@see \Qualimetrix\Analysis\Evidence\CodeSmell\ConstructorOverinjectionRule}
     * both judge, and it is nine edits from the one channel this fixture
     * declares for it, so the near-spelling search cannot reach the answer:
     * without the declared relation the author is told nothing is close to
     * what they typed.
     */
    #[Test]
    public function itAnswersAThresholdNamingAJudgedMetricWithTheChannelThatJudgesIt(): void
    {
        $rejection = self::addressability()->problemWithThreshold(self::threshold('code-smell.parameter-count'));

        self::assertNotNull($rejection);
        self::assertFalse($rejection->ruleExistsButCannotBeRetuned);
        self::assertStringContainsString('"code-smell.parameter-count" is a metric, not a rule', $rejection->message);
        self::assertStringContainsString(
            'channel "code-smell.long-parameter-list" of rule "code-smell.long-parameter-list"',
            $rejection->message,
        );
    }

    /**
     * The answer comes from the declaration and from nothing else: a key in
     * the same family, one no channel declares, gets no pair — which is what
     * fails if the branch is ever rewritten to match on spelling.
     */
    #[Test]
    public function itOffersNoPairForAMetricNoChannelDeclares(): void
    {
        $rejection = self::addressability()->problemWithThreshold(self::threshold('complexity.cognitive.max'));

        self::assertNotNull($rejection);
        self::assertStringNotContainsString('is a metric, not a rule', $rejection->message);
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
        $rejection = self::addressability()->problemWithThreshold(self::threshold('complexity.ccn:class'));

        self::assertNotNull($rejection);
        self::assertStringContainsString('--rule-opt complexity.ccn:class.<option>=<value>', $rejection->message);
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
     * The retired
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

    /**
     * `duplication.clone:class` names an
     * impossible pair (the channel reports at project level only) AND reaches
     * the ban, and {@see DirectiveAddressability::problemWithSuppression()}
     * asks the pair grammar first. Reordering the two checks would silently
     * swap the published refusal text — and pass this test only if it were
     * changed to match, which is the point of pinning it here rather than
     * trusting the docblock alone.
     */
    #[Test]
    public function itJudgesAnImpossiblePairBeforeTheBanOnAChannelBothReject(): void
    {
        $message = self::addressability()->problemWithSuppression(
            self::suppression('duplication.clone:class'),
        );

        self::assertNotNull($message);
        self::assertStringContainsString('does not report at level "class"', $message);
        self::assertStringContainsString('The pair can never match anything', $message);
        self::assertStringNotContainsString('no directive may silence', $message);
    }

    /**
     * The two refusals decided inside extraction are answered here like any
     * other, so the author hears about them through the same channel on the
     * same line. Before, both were dropped where they were found.
     */
    #[Test]
    public function itAnswersForATagNoGrammarReads(): void
    {
        $problem = self::addressability()->problemWithSuppression(new Suppression(
            'complexity.ccn',
            null,
            1,
            SuppressionType::Symbol,
            refusal: DirectiveRefusal::formNotRecognised('ignore-lines'),
        ));

        self::assertIsString($problem);
        self::assertStringContainsString('@qmx-ignore-lines complexity.ccn', $problem);
        self::assertStringContainsString('is not a tag this tool reads', $problem);
    }

    #[Test]
    public function itAnswersForADeclarationFormWithNothingToBindTo(): void
    {
        $problem = self::addressability()->problemWithSuppression(new Suppression(
            'complexity.ccn',
            null,
            1,
            SuppressionType::Symbol,
            refusal: DirectiveRefusal::noDeclarationToBind(),
        ));

        self::assertIsString($problem);
        self::assertStringContainsString('@qmx-ignore complexity.ccn', $problem);
        self::assertStringContainsString('@qmx-ignore-next-line', $problem);
    }

    /**
     * A refusal is not a channel question, so it is answered before every
     * channel question — including the one that returns "nothing to say" for a
     * directive naming no channel at all.
     */
    #[Test]
    public function itAnswersForARefusedDirectiveThatNamesNoChannel(): void
    {
        $problem = self::addressability()->problemWithSuppression(new Suppression(
            '*',
            null,
            1,
            SuppressionType::Symbol,
            refusal: DirectiveRefusal::noDeclarationToBind(),
        ));

        self::assertIsString($problem);
    }

    private static function addressability(): DirectiveAddressability
    {
        return new DirectiveAddressability(new ChannelUniverse(
            [
                'complexity.ccn' => ChannelDeclaration::judging(
                    WorseDirection::Higher,
                    JudgedMetrics::of('complexity.ccn', 'complexity.ccn.max'),
                    SymbolLevel::Class_,
                ),
                'coupling.cbo' => ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Class_),
                'duplication.clone' => ChannelDeclaration::magnitude(
                    WorseDirection::Higher,
                    SymbolLevel::Project,
                ),
                'code-smell.long-parameter-list' => ChannelDeclaration::judging(
                    WorseDirection::Higher,
                    JudgedMetrics::of('code-smell.parameter-count'),
                    SymbolLevel::Callable,
                ),
            ],
            [
                'complexity.ccn' => ['complexity.ccn'],
                'coupling.cbo' => ['coupling.cbo'],
                'duplication.clone' => ['duplication.clone'],
                'code-smell.long-parameter-list' => ['code-smell.long-parameter-list'],
            ],
            [
                'complexity.ccn' => true,
                'coupling.cbo' => true,
                'duplication.clone' => true,
                'code-smell.long-parameter-list' => true,
            ],
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

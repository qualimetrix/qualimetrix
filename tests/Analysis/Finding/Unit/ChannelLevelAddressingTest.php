<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelAddressing;
use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * The three questions a seam may ask about an authored `channel:level` pair,
 * and the one place their answers are worded.
 *
 * The universe below is the counterexample from round 11 in miniature:
 * `coupling.cbo` reports at two levels, `coupling.class-rank` at one, and both
 * live under the same `coupling.*` wildcard.
 */
#[CoversClass(ChannelLevelAddressing::class)]
final class ChannelLevelAddressingTest extends TestCase
{
    /** @var array<string, list<SymbolLevel>> */
    private const array UNIVERSE = [
        'coupling.cbo' => [SymbolLevel::Class_, SymbolLevel::Namespace_],
        'coupling.class-rank' => [SymbolLevel::Class_],
        'duplication.code-duplication' => [SymbolLevel::Project],
        'computed.debt' => [],
    ];

    /**
     * Which of the universe's rules can be retuned. Spelled separately from
     * {@see UNIVERSE} because the two are separate declarations in production
     * too: a rule's channels and its `@qmx-threshold` support are different
     * facts, and the pair refusal reads both.
     */
    private const array RETUNABLE = [
        'coupling.cbo' => true,
        'coupling.class-rank' => true,
        'duplication.code-duplication' => true,
        'computed.debt' => false,
    ];

    /**
     * The global question and a separate membership check are two independent
     * existentials, and their witnesses need not be the same channel: the
     * level comes from `coupling.cbo`, the membership from
     * `coupling.class-rank`, and the accepted pair can never suppress anything
     * the configured rule emits.
     */
    #[Test]
    public function aPairIsRefusedInsideASetWhoseOnlyMemberDoesNotReportAtTheLevel(): void
    {
        $addressing = $this->addressing();

        self::assertNull($addressing->problemWith('coupling.*:namespace'));

        $problem = $addressing->problemWithAmong(
            'coupling.*:namespace',
            [new FindingChannel('coupling.class-rank')],
            'the channels rule "coupling.class-rank" produces',
        );

        self::assertNotNull($problem);
        self::assertStringContainsString('it does not report at level "namespace"', $problem);
        self::assertStringContainsString('coupling.class-rank', $problem);
    }

    /** One channel satisfying all three conditions at once is the whole requirement. */
    #[Test]
    public function aPairIsAcceptedInsideASetWhenOneMemberReportsAtTheLevel(): void
    {
        $problem = $this->addressing()->problemWithAmong(
            'coupling.*:namespace',
            [new FindingChannel('coupling.cbo'), new FindingChannel('coupling.class-rank')],
            'the channels rule "coupling.cbo" produces',
        );

        self::assertNull($problem);
    }

    #[Test]
    public function aPairAddressingNothingInTheSetSaysSoRatherThanNamingLevels(): void
    {
        $problem = $this->addressing()->problemWithAmong(
            'duplication.*:project',
            [new FindingChannel('coupling.cbo')],
            'the channels rule "coupling.cbo" produces',
        );

        self::assertNotNull($problem);
        self::assertStringContainsString('addresses none of the channels rule "coupling.cbo" produces', $problem);
    }

    /** Membership is the subject of the question, so level-free text is judged too. */
    #[Test]
    public function levelFreeTextIsJudgedAgainstTheSetAsWell(): void
    {
        $addressing = $this->addressing();
        $candidates = [new FindingChannel('coupling.cbo')];

        self::assertNull($addressing->problemWithAmong('coupling.*', $candidates, 'this rule\'s channels'));
        self::assertNotNull(
            $addressing->problemWithAmong('coupling.class-rank', $candidates, 'this rule\'s channels'),
        );
    }

    /**
     * Two answers stay with the caller, which has a did-you-mean hint this
     * seam cannot build: text that is no selector, and a selector addressing
     * nothing anywhere.
     */
    #[Test]
    public function theSetQuestionStaysSilentOnTextThatAddressesNothingAnywhere(): void
    {
        $addressing = $this->addressing();
        $candidates = [new FindingChannel('coupling.cbo')];

        self::assertNull($addressing->problemWithAmong('nosuch.channel', $candidates, 'this rule\'s channels'));
        self::assertNull($addressing->problemWithAmong('not a selector!', $candidates, 'this rule\'s channels'));
    }

    /**
     * A producer may be stopped only when narrowing by level narrows nothing:
     * one selector on a single-level channel, or several selectors whose union
     * covers every declared level.
     */
    #[Test]
    public function aLevelQualifiedSelectorCoversASingleLevelChannel(): void
    {
        $addressing = $this->addressing();

        self::assertTrue($addressing->selectorsCoverEveryDeclaredLevelOf(
            ['duplication.code-duplication:project'],
            ['duplication.code-duplication'],
        ));
        self::assertTrue($addressing->selectorsCoverEveryDeclaredLevelOf(
            ['duplication.code-duplication'],
            ['duplication.code-duplication'],
        ));
    }

    #[Test]
    public function oneLevelOfATwoLevelChannelIsNotCoverageButTheUnionOfBothIs(): void
    {
        $addressing = $this->addressing();

        self::assertFalse($addressing->selectorsCoverEveryDeclaredLevelOf(['coupling.cbo:class'], ['coupling.cbo']));
        self::assertTrue($addressing->selectorsCoverEveryDeclaredLevelOf(
            ['coupling.cbo:class', 'coupling.cbo:namespace'],
            ['coupling.cbo'],
        ));
    }

    /** Every channel of the producer, not merely one of them. */
    #[Test]
    public function coverageIsQuantifiedOverEveryChannelOfTheProducer(): void
    {
        $addressing = $this->addressing();

        self::assertFalse($addressing->selectorsCoverEveryDeclaredLevelOf(
            ['coupling.cbo:class', 'coupling.cbo:namespace'],
            ['coupling.cbo', 'coupling.class-rank'],
        ));
        self::assertTrue($addressing->selectorsCoverEveryDeclaredLevelOf(
            ['coupling.*'],
            ['coupling.cbo', 'coupling.class-rank'],
        ));
    }

    /**
     * An empty set of declared levels would make "every declared level is
     * covered" vacuously true and stop a producer whose levels come from
     * configuration; an empty set of channels would do the same.
     */
    #[Test]
    public function nothingDeclaredIsNotCoverage(): void
    {
        $addressing = $this->addressing();

        self::assertFalse($addressing->selectorsCoverEveryDeclaredLevelOf(['computed.debt'], ['computed.debt']));
        self::assertFalse($addressing->selectorsCoverEveryDeclaredLevelOf(['computed.debt:class'], ['computed.debt']));
        self::assertFalse($addressing->selectorsCoverEveryDeclaredLevelOf(['coupling.*'], []));
        self::assertFalse($addressing->selectorsCoverEveryDeclaredLevelOf([], ['coupling.cbo']));
    }

    /**
     * The mistyped level and the unknown rule must be named as such: the
     * `--rule-opt` advice built out of an unchecked half recommends a command
     * the CLI accepts and silently ignores.
     */
    #[Test]
    public function aRulePairIsRefusedByLevelThenByRuleAndOnlyThenAdvises(): void
    {
        $addressing = $this->addressing();

        $mistypedLevel = $addressing->problemWithRulePair('coupling.cbo:bogus', '@qmx-threshold "coupling.cbo:bogus"');
        self::assertNotNull($mistypedLevel);
        self::assertStringContainsString('"bogus" is not a level', $mistypedLevel);
        self::assertStringNotContainsString('--rule-opt', $mistypedLevel);

        $unknownRule = $addressing->problemWithRulePair('nosuchrule:class', '@qmx-threshold "nosuchrule:class"');
        self::assertNotNull($unknownRule);
        self::assertStringContainsString('names no rule', $unknownRule);
        self::assertStringNotContainsString('--rule-opt', $unknownRule);

        $levelBlind = $addressing->problemWithRulePair('coupling.cbo:class', '@qmx-threshold "coupling.cbo:class"');
        self::assertNotNull($levelBlind);
        self::assertStringContainsString('--rule-opt coupling.cbo:class.<option>=<value>', $levelBlind);
    }

    /**
     * If this disappears, the product goes back to answering a threshold on a
     * non-retunable rule with advice that does nothing: `--rule-opt
     * X:level.<option>=<value>` is accepted by the CLI, warns "Unknown option",
     * and exits zero.
     */
    #[Test]
    public function aRulePairOnARuleThatCannotBeRetunedIsRefusedWithoutAdvisingANoOp(): void
    {
        $problem = $this->addressing()->problemWithRulePair(
            'computed.debt:class',
            '@qmx-threshold "computed.debt:class"',
        );

        self::assertNotNull($problem);
        self::assertStringContainsString('declares no @qmx-threshold support', $problem);
        self::assertStringNotContainsString(
            '--rule-opt',
            $problem,
            'The rule has no threshold to set at a level, so no --rule-opt spelling can be recommended.',
        );
        self::assertStringNotContainsString(
            'Retune the whole rule',
            $problem,
            'The rule declares it cannot be retuned at all, so retuning it whole is not the alternative.',
        );
    }

    /** Two separators are a level mistake, not an invitation to advise on half a name. */
    #[Test]
    public function aRulePairCarryingTwoSeparatorsIsRefusedAsALevel(): void
    {
        $problem = $this->addressing()->problemWithRulePair('coupling.cbo:a:b', '@qmx-threshold "coupling.cbo:a:b"');

        self::assertNotNull($problem);
        self::assertStringContainsString('"b" is not a level', $problem);
        self::assertStringNotContainsString('--rule-opt', $problem);
    }

    #[Test]
    public function aPlainRuleNameIsNotTheRulePairQuestion(): void
    {
        self::assertNull($this->addressing()->problemWithRulePair('coupling.cbo', '@qmx-threshold "coupling.cbo"'));
    }

    /**
     * The caller's subject replaces this seam's own, so a sentence cannot end
     * up with two of them ("Suppression Channel selector "…" addresses …").
     */
    #[Test]
    public function theCallersSubjectIsTheOnlySubjectOfTheSentence(): void
    {
        $problem = $this->addressing()->problemWith('coupling.class-rank:project', 'Suppression "x"');

        self::assertNotNull($problem);
        self::assertStringStartsWith('Suppression "x" addresses ', $problem);
        self::assertStringNotContainsString('Channel selector', $problem);
    }

    /**
     * The wording seams that have not been handed a subject yet still read,
     * asserted in full because those seams are not this package's to change.
     */
    #[Test]
    public function theSubjectLessWordingOfEveryRefusalIsUnchanged(): void
    {
        $addressing = $this->addressing();

        self::assertSame(
            '"coupling.cbo:bogus" names no level after ":". A level is one of'
            . ' "callable", "class", "file", "namespace", "project".',
            $addressing->problemWith('coupling.cbo:bogus'),
        );
        self::assertSame(
            '"a#b:class" is written as a channel-and-level pair, but "a#b" is not a channel selector.'
            . ' Write an exact channel name, or "X.*" for the channels below X, then ":class".',
            $addressing->problemWith('a#b:class'),
        );
        self::assertSame(
            '"nosuch.channel:class" addresses no channel, so it cannot address one at level "class".',
            $addressing->problemWith('nosuch.channel:class'),
        );
        self::assertSame(
            'Channel selector "coupling.class-rank" addresses "coupling.class-rank", and it does not report at'
            . ' level "project" — the levels available are "class". The pair can never match anything.',
            $addressing->problemWith('coupling.class-rank:project'),
        );
    }

    #[Test]
    public function textWithoutASeparatorIsNotTheGlobalQuestion(): void
    {
        self::assertNull($this->addressing()->problemWith('coupling.cbo'));
        self::assertNull($this->addressing()->problemWith('not a selector!'));
    }

    private function addressing(): ChannelLevelAddressing
    {
        $identity = self::createStub(ChannelIdentityInterface::class);
        $identity->method('levelsOf')->willReturnCallback(
            static fn(string $code): array => self::UNIVERSE[$code] ?? [],
        );
        $identity->method('hasRule')->willReturnCallback(
            static fn(string $name): bool => \array_key_exists($name, self::UNIVERSE),
        );
        $identity->method('supportsThresholdOverride')->willReturnCallback(
            static fn(string $name): bool => self::RETUNABLE[$name] ?? false,
        );
        $identity->method('expand')->willReturnCallback(
            static function (NameSelector $selector): array {
                $addressed = [];

                foreach (array_keys(self::UNIVERSE) as $code) {
                    if ($selector->matches($code)) {
                        $addressed[] = new FindingChannel($code);
                    }
                }

                return $addressed;
            },
        );

        return new ChannelLevelAddressing($identity);
    }
}

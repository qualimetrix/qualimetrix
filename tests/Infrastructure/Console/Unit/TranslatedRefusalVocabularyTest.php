<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use JsonException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The twenty-three configuration positions an earlier round moved out of
 * silent acceptance, held to what that round actually delivered: each one
 * still ends the run with exit 3, and each one still answers in words of its
 * own subject rather than in a shared phrase about refusal.
 *
 * Until this file existed the invariant rested on nothing but a green suite —
 * "not broken by what is here" is not "checked". Later rounds add refusals of
 * their own, and a shared sentence is the cheapest way to add them; that is
 * precisely the regression this file is pointed at.
 *
 * **Where the list comes from.** The rows are transcribed from
 * `docs/internal/plans/configuration-refusal/measurement/verdicts-30-75.md`
 * §3 — the measuring round's own remeasurement, which names the twenty-three
 * positions by number and records the input each was measured with. The list
 * is deliberately *not* derived from today's sources: today's code can show
 * that a refusal exists, never that it is one of the twenty-three.
 *
 * What that source does not see:
 *
 * - It is a sample. The surrounding enumeration runs to 133 positions; rows
 *   outside 30–75 carry no claim here, and neither do the positions that
 *   document records as still silent or still crashing.
 * - Five inputs are abbreviated `{...}` in the table and are completed here,
 *   which makes those rows this file's reading of the source rather than a
 *   transcription of it: 30 as `callabel: {warning: 1}`; 34, 40 and 41 as
 *   `callable: {warning: 1, error: 2}`; 43 as a one-entry channel map over a
 *   namespace that exists nowhere. Two further rows inherit a completion —
 *   52 is 30 under `--format=json`, and 73's elided rule prefix is read as
 *   `complexity.ccn` from its neighbours. A refusal that turned out to depend
 *   on the completed part rather than on the misspelling would be a case about
 *   this file's guess; none of the five does today, because each answer names
 *   the misspelled key itself.
 * - The door differs from the one that was measured. The source drove a
 *   probe-local `qmx.yaml` discovered through `-d`; these rows pass
 *   `--config=<file>`. Same loader, different way in.
 * - The vocabulary half pins that a sentence *names its subject*, not that it
 *   spells it the way the author typed it. The normaliser answers `CALLABLE`
 *   as `cALLABLE` and `max_warning` as `maxWarning`; that is a known limit of
 *   ADR 0044 at this seam, pinned deliberately and verbatim next door in
 *   {@see RuleOptionKeyDoorSymmetryTest}, so the folding below must stay blind
 *   to it or the two files would fight.
 * - The exit code is the command's return value, taken through
 *   {@see CommandTester}. That it reaches the process unchanged is a property
 *   of the application ladder, proven in
 *   {@see \Qualimetrix\Tests\Infrastructure\Console\Integration\ConfigurationRefusalRoutingTest};
 *   the twenty-three were also run as real processes once, by hand, at the
 *   time this file was written, and agreed.
 *
 * This file is not {@see RuleOptionKeyDoorSymmetryTest}. That one asks whether
 * the two doors a rule option can be written at answer alike; this one asks
 * whether a named historical set of positions still answers at all, and
 * separately.
 *
 * **How "its own vocabulary" is made checkable.** Every quoted span of the
 * refusal is folded (lower-cased, `_` and `-` removed) and the row's declared
 * subject — its rule, its level where it has one, its offending key where it
 * has one — must appear among those spans. Only quoted spans count: the
 * sentences also list the options a rule accepts, unquoted, and a generic
 * "rule X accepts: …" reply would otherwise satisfy a plain substring search
 * for `warning`. On top of that the sentences must be a *function of the
 * subject, injective on distinct subjects*: two rows read alike exactly when
 * they name the same subject, which is what collapsing into a shared phrase
 * would break and what a swapped answer would break too.
 *
 * Rejected alternatives: pinning all twenty-three sentences verbatim — any
 * honest wording improvement then reddens nineteen cases at once and trains
 * the next author to bulk-accept the diff, which destroys the signal; counting
 * distinct sentences against a hardcoded number — a weaker proxy for the same
 * injectivity that invites bumping the number; and asserting the message is
 * non-empty — satisfied by the single shared phrase this file exists to
 * forbid.
 */
#[CoversNothing]
final class TranslatedRefusalVocabularyTest extends TestCase
{
    private const string ANALYSED_PATH = 'tests/Fixtures/Ast/empty_file.php';

    private const string CCN = 'complexity.ccn';

    /**
     * The twenty-three positions. `rules` is the body of a `rules:` block,
     * `flags` a list of `--rule-opt` values; `rule`, `level` and `key` are the
     * subject the answer must name, and `json` marks the one row measured
     * under a machine-readable format, where the refusal travels in the stdout
     * envelope instead of on stderr.
     *
     * @var array<string, array{rules: ?string, flags: list<string>, rule: string, level: ?string, key: ?string, json: bool}>
     */
    private const array POSITIONS = [
        '30 an unrecognised depth-1 key' => [
            'rules' => "  complexity.ccn:\n    callabel:\n      warning: 1\n",
            'flags' => [], 'rule' => self::CCN, 'level' => null, 'key' => 'callabel', 'json' => false,
        ],
        '31 a level this rule does not have' => [
            'rules' => "  complexity.ccn:\n    method:\n      warning: 1\n",
            'flags' => [], 'rule' => self::CCN, 'level' => null, 'key' => 'method', 'json' => false,
        ],
        '32 another level this rule does not have' => [
            'rules' => "  complexity.ccn:\n    namespace:\n      warning: 1\n",
            'flags' => [], 'rule' => self::CCN, 'level' => null, 'key' => 'namespace', 'json' => false,
        ],
        '34 a level slot in upper case' => [
            'rules' => "  complexity.ccn:\n    CALLABLE:\n      warning: 1\n      error: 2\n",
            'flags' => [], 'rule' => self::CCN, 'level' => null, 'key' => 'CALLABLE', 'json' => false,
        ],
        '35 a typo on a rule with no levels' => [
            'rules' => "  coupling.class-rank:\n    warnign: 0.01\n    error: 0.02\n",
            'flags' => [], 'rule' => 'coupling.class-rank', 'level' => null, 'key' => 'warnign', 'json' => false,
        ],
        '36 an option belonging to another rule' => [
            'rules' => "  complexity.ccn:\n    max_distance_warning: 1\n",
            'flags' => [], 'rule' => self::CCN, 'level' => null, 'key' => 'max_distance_warning', 'json' => false,
        ],
        '37 a threshold on a rule that takes none' => [
            'rules' => "  code-smell.eval:\n    threshold: 3\n",
            'flags' => [], 'rule' => 'code-smell.eval', 'level' => null, 'key' => 'threshold', 'json' => false,
        ],
        '38 a pluralised option name' => [
            'rules' => "  complexity.ccn:\n    thresholds: 1\n",
            'flags' => [], 'rule' => self::CCN, 'level' => null, 'key' => 'thresholds', 'json' => false,
        ],
        '40 the switch spelled without its d' => [
            'rules' => "  complexity.ccn:\n    enable: false\n    callable:\n      warning: 1\n      error: 2\n",
            'flags' => [], 'rule' => self::CCN, 'level' => null, 'key' => 'enable', 'json' => false,
        ],
        '41 a severity key the rule does not take' => [
            'rules' => "  complexity.ccn:\n    severity: warning\n    callable:\n      warning: 1\n      error: 2\n",
            'flags' => [], 'rule' => self::CCN, 'level' => null, 'key' => 'severity', 'json' => false,
        ],
        '43 a suppression key with a dropped letter' => [
            'rules' => "  complexity.ccn:\n    suppress_namespace_chanels:\n      complexity.ccn: ['Zzz\\Nope']\n",
            'flags' => [], 'rule' => self::CCN, 'level' => null, 'key' => 'suppress_namespace_chanels', 'json' => false,
        ],
        '48 a retired option at depth 2' => [
            'rules' => "  complexity.ccn:\n    callable:\n      warning: 1\n      error: 2\n      exclude_paths: ['*']\n",
            'flags' => [], 'rule' => self::CCN, 'level' => 'callable', 'key' => 'exclude_paths', 'json' => false,
        ],
        '52 the depth-1 typo under a machine-readable format' => [
            'rules' => "  complexity.ccn:\n    callabel:\n      warning: 1\n",
            'flags' => [], 'rule' => self::CCN, 'level' => null, 'key' => 'callabel', 'json' => true,
        ],
        '53 a typo inside a level' => [
            'rules' => "  complexity.ccn:\n    callable:\n      warnign: 1\n      error: 2\n",
            'flags' => [], 'rule' => self::CCN, 'level' => 'callable', 'key' => 'warnign', 'json' => false,
        ],
        '54 two typos inside a level' => [
            'rules' => "  complexity.ccn:\n    callable:\n      warnign: 1\n      errro: 2\n",
            'flags' => [], 'rule' => self::CCN, 'level' => 'callable', 'key' => 'warnign', 'json' => false,
        ],
        '55 two abbreviations inside a level' => [
            'rules' => "  complexity.ccn:\n    callable:\n      warn: 1\n      errors: 2\n",
            'flags' => [], 'rule' => self::CCN, 'level' => 'callable', 'key' => 'warn', 'json' => false,
        ],
        '56 level options in upper case' => [
            'rules' => "  complexity.ccn:\n    callable:\n      WARNING: 1\n      ERROR: 2\n",
            'flags' => [], 'rule' => self::CCN, 'level' => 'callable', 'key' => 'WARNING', 'json' => false,
        ],
        '57 the option names of the other level' => [
            'rules' => "  complexity.ccn:\n    callable:\n      max_warning: 1\n      max_error: 2\n",
            'flags' => [], 'rule' => self::CCN, 'level' => 'callable', 'key' => 'max_warning', 'json' => false,
        ],
        '58 one level options written at the other level' => [
            'rules' => "  complexity.ccn:\n    class:\n      warning: 1\n      error: 2\n",
            'flags' => [], 'rule' => self::CCN, 'level' => 'class', 'key' => 'warning', 'json' => false,
        ],
        '59 a scalar where a level takes a map' => [
            'rules' => "  complexity.ccn:\n    callable: 10\n",
            'flags' => [], 'rule' => self::CCN, 'level' => 'callable', 'key' => null, 'json' => false,
        ],
        '72 a level this rule does not have, through the flag' => [
            'rules' => null, 'flags' => ['complexity.ccn:method.warning=1'],
            'rule' => self::CCN, 'level' => null, 'key' => 'method', 'json' => false,
        ],
        '73 a typo inside a level, through the flag' => [
            'rules' => null,
            'flags' => ['complexity.ccn:callable.warnign=1', 'complexity.ccn:callable.error=2'],
            'rule' => self::CCN, 'level' => 'callable', 'key' => 'warnign', 'json' => false,
        ],
        '74 a level option written at depth 1, through the flag' => [
            'rules' => null, 'flags' => ['complexity.ccn:warning=1'],
            'rule' => self::CCN, 'level' => null, 'key' => 'warning', 'json' => false,
        ],
    ];

    /**
     * One run per row, reused across the cases below. The product is
     * deterministic on these inputs and each run rebuilds the container from
     * scratch, so a second run of the same row would cost a fresh container to
     * learn nothing.
     *
     * @var array<string, array{exit: int, refusal: string}>
     */
    private static array $runs = [];

    /** @var list<string> */
    private array $cleanUp = [];

    /** @return iterable<string, array{string}> */
    public static function provideTranslatedPositions(): iterable
    {
        foreach (array_keys(self::POSITIONS) as $id) {
            yield $id => [$id];
        }
    }

    /**
     * Half one: the verdict. A position that slid back to a warning, to a
     * silent acceptance, or to a crash reddens here whatever it says.
     */
    #[Test]
    #[DataProvider('provideTranslatedPositions')]
    public function itStillEndsEveryTranslatedPositionWithExitThree(string $id): void
    {
        $run = $this->positionRun($id);

        self::assertSame(3, $run['exit'], \sprintf(
            'Position "%s" was translated into a refusal and must still exit 3; it exited %d saying: %s',
            $id,
            $run['exit'],
            $run['refusal'] === '' ? '(nothing)' : $run['refusal'],
        ));
    }

    /**
     * Half two, first part: the answer names the subject the author got wrong,
     * inside quotes, where the options list cannot leak into the comparison.
     */
    #[Test]
    #[DataProvider('provideTranslatedPositions')]
    public function itNamesTheSubjectOfEveryTranslatedPositionInItsOwnAnswer(string $id): void
    {
        $row = self::POSITIONS[$id];
        $refusal = $this->positionRun($id)['refusal'];
        $spans = self::foldedQuotedSpans($refusal);

        self::assertNotSame([], $spans, \sprintf(
            'Position "%s" answered without naming anything: %s',
            $id,
            $refusal === '' ? '(nothing)' : $refusal,
        ));

        foreach (array_filter([$row['rule'], $row['level'], $row['key']]) as $subject) {
            self::assertContains(self::fold($subject), $spans, \sprintf(
                'Position "%s" must answer in words of its own subject: "%s" is named nowhere in %s',
                $id,
                $subject,
                $refusal,
            ));
        }
    }

    /**
     * Half two, second part — the one that a shared phrase cannot survive.
     * Two positions read alike exactly when they name the same subject: a
     * collapse into one sentence about refusal makes distinct subjects read
     * alike, and an answer handed to the wrong position makes equal subjects
     * read differently.
     */
    #[Test]
    public function itLetsTwoTranslatedPositionsReadAlikeOnlyWhenTheyNameTheSameSubject(): void
    {
        $sentences = [];
        $subjects = [];

        foreach (array_keys(self::POSITIONS) as $id) {
            $row = self::POSITIONS[$id];
            $sentences[$id] = $this->positionRun($id)['refusal'];
            $subjects[$id] = implode('|', [
                $row['rule'],
                $row['level'] ?? '',
                $row['key'] === null ? '' : self::fold($row['key']),
            ]);
        }

        foreach ($sentences as $left => $leftSentence) {
            foreach ($sentences as $right => $rightSentence) {
                if ($left >= $right) {
                    continue;
                }

                self::assertSame(
                    $subjects[$left] === $subjects[$right],
                    $leftSentence === $rightSentence,
                    \sprintf(
                        "Positions \"%s\" and \"%s\" name %s subjects but read %s.\n  %s\n  %s",
                        $left,
                        $right,
                        $subjects[$left] === $subjects[$right] ? 'the same' : 'different',
                        $leftSentence === $rightSentence ? 'alike' : 'differently',
                        $leftSentence,
                        $rightSentence,
                    ),
                );
            }
        }
    }

    /**
     * The negative case the rest of the file needs. Without it every assertion
     * above is equally satisfied by a command that refuses whatever it is
     * given, with one sentence per input by accident of quoting.
     */
    #[Test]
    public function itRunsToCompletionWhenTheSameOptionsAreSpelledCorrectly(): void
    {
        $run = $this->execute(
            $this->configFile("  complexity.ccn:\n    callable:\n      warning: 1\n      error: 2\n"),
            [],
            json: false,
        );

        self::assertNotSame(3, $run['exit'], $run['refusal']);
        self::assertSame('', $run['refusal']);
    }

    /** @return array{exit: int, refusal: string} */
    private function positionRun(string $id): array
    {
        if (isset(self::$runs[$id])) {
            return self::$runs[$id];
        }

        $row = self::POSITIONS[$id];

        return self::$runs[$id] = $this->execute(
            $row['rules'] === null ? null : $this->configFile($row['rules']),
            $row['flags'],
            $row['json'],
        );
    }

    /**
     * @param list<string> $flags
     *
     * @return array{exit: int, refusal: string}
     */
    private function execute(?string $configPath, array $flags, bool $json): array
    {
        $arguments = ['paths' => [self::ANALYSED_PATH], '--workers' => '0', '--fail-on' => 'none'];

        if ($configPath !== null) {
            $arguments['--config'] = $configPath;
        }

        if ($flags !== []) {
            $arguments['--rule-opt'] = $flags;
        }

        if ($json) {
            $arguments['--format'] = 'json';
        }

        $tester = $this->tester();
        $tester->execute($arguments, ['capture_stderr_separately' => true]);

        return [
            'exit' => $tester->getStatusCode(),
            'refusal' => $json
                ? self::envelopeError($tester->getDisplay())
                : self::refusalLine($tester->getErrorOutput()),
        ];
    }

    /**
     * A machine-readable run must never be handed a half-written report, so
     * its refusal travels in the stdout envelope rather than on stderr.
     */
    private static function envelopeError(string $stdout): string
    {
        try {
            /** @var array{error?: string} $envelope */
            $envelope = json_decode($stdout, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '';
        }

        return $envelope['error'] ?? '';
    }

    /**
     * The refusal, separated from the scope warning every run over a single
     * fixture file emits — that warning is the same in all twenty-three rows
     * and would make every sentence read alike.
     */
    private static function refusalLine(string $stderr): string
    {
        $lines = array_values(array_filter(
            array_map(trim(...), explode("\n", $stderr)),
            static fn(string $line): bool => $line !== '' && !str_starts_with($line, 'Warning: Analyzed paths'),
        ));

        return implode(' ', $lines);
    }

    /**
     * Every double-quoted span of a refusal, folded. Quoting is where the
     * product puts the things it is talking about; the options it goes on to
     * offer are listed bare, and counting those would let a sentence that
     * names no subject pass.
     *
     * @return list<string>
     */
    private static function foldedQuotedSpans(string $refusal): array
    {
        preg_match_all('/"([^"]*)"/', $refusal, $matches);

        return array_map(self::fold(...), $matches[1]);
    }

    /**
     * Separators and case are folded away before either side is compared: the
     * normaliser answers `CALLABLE` as `cALLABLE` and `max_warning` as
     * `maxWarning`, and this file must not restate what the author typed —
     * only that the letters of the subject came back.
     */
    private static function fold(string $token): string
    {
        return str_replace(['_', '-'], '', strtolower($token));
    }

    private function tester(): CommandTester
    {
        $container = (new ContainerFactory())->create();
        $command = $container->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $command);
        $refusalPresenter = $container->get(RefusalPresenter::class);
        self::assertInstanceOf(RefusalPresenter::class, $refusalPresenter);
        (new Application(new ErrorStream(), $refusalPresenter))->addCommand($command);

        return new CommandTester($command);
    }

    private function configFile(string $rulesBlock): string
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-translated-refusal-');
        self::assertNotFalse($path);
        file_put_contents($path, "rules:\n" . $rulesBlock);

        $this->cleanUp[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanUp as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        $this->cleanUp = [];
    }
}

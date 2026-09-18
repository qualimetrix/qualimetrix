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
 * Pins representative configuration refusals to exit code 3 and checks that
 * each refusal uses wording owned by its subject rather than a generic
 * sentence. The cases remain explicit so a refusal cannot disappear together
 * with its expected result.
 */
/**
 * The refusal check folds quoted spans and requires the case's rule, level,
 * and offending key to appear among them. It also requires distinct subjects
 * to receive distinct sentences while allowing equivalent subjects to share
 * wording. This checks useful specificity without pinning every sentence's
 * exact prose.
 */
#[CoversNothing]
final class TranslatedRefusalVocabularyTest extends TestCase
{
    private const string ANALYSED_PATH = 'tests/Fixtures/Ast/empty_file.php';

    private const string CCN = 'complexity.ccn';

    /**
     * Each case's `rules` value is the body of a `rules:` block,
     * `flags` a list of `--rule-opt` values; `rule`, `level` and `key` are the
     * subject the answer must name, and `json` marks the case using a
     * under a machine-readable format, where the refusal travels in the stdout
     * envelope instead of on stderr.
     *
     * @var array<string, array{rules: ?string, flags: list<string>, rule: string, level: ?string, key: ?string, json: bool}>
     */
    private const array POSITIONS = [
        'unrecognised-depth-1-key' => [
            'rules' => "  complexity.ccn:\n    callabel:\n      warning: 1\n",
            'flags' => [], 'rule' => self::CCN, 'level' => null, 'key' => 'callabel', 'json' => false,
        ],
        'unsupported-method-level' => [
            'rules' => "  complexity.ccn:\n    method:\n      warning: 1\n",
            'flags' => [], 'rule' => self::CCN, 'level' => null, 'key' => 'method', 'json' => false,
        ],
        'unsupported-namespace-level' => [
            'rules' => "  complexity.ccn:\n    namespace:\n      warning: 1\n",
            'flags' => [], 'rule' => self::CCN, 'level' => null, 'key' => 'namespace', 'json' => false,
        ],
        'uppercase-level-name' => [
            'rules' => "  complexity.ccn:\n    CALLABLE:\n      warning: 1\n      error: 2\n",
            'flags' => [], 'rule' => self::CCN, 'level' => null, 'key' => 'CALLABLE', 'json' => false,
        ],
        'typo-on-rule-without-levels' => [
            'rules' => "  coupling.class-rank:\n    warnign: 0.01\n    error: 0.02\n",
            'flags' => [], 'rule' => 'coupling.class-rank', 'level' => null, 'key' => 'warnign', 'json' => false,
        ],
        'option-owned-by-another-rule' => [
            'rules' => "  complexity.ccn:\n    max_distance_warning: 1\n",
            'flags' => [], 'rule' => self::CCN, 'level' => null, 'key' => 'max_distance_warning', 'json' => false,
        ],
        'unsupported-threshold-option' => [
            'rules' => "  code-smell.eval:\n    threshold: 3\n",
            'flags' => [], 'rule' => 'code-smell.eval', 'level' => null, 'key' => 'threshold', 'json' => false,
        ],
        'pluralised-option-name' => [
            'rules' => "  complexity.ccn:\n    thresholds: 1\n",
            'flags' => [], 'rule' => self::CCN, 'level' => null, 'key' => 'thresholds', 'json' => false,
        ],
        'misspelled-enabled-option' => [
            'rules' => "  complexity.ccn:\n    enable: false\n    callable:\n      warning: 1\n      error: 2\n",
            'flags' => [], 'rule' => self::CCN, 'level' => null, 'key' => 'enable', 'json' => false,
        ],
        'unsupported-severity-key' => [
            'rules' => "  complexity.ccn:\n    severity: warning\n    callable:\n      warning: 1\n      error: 2\n",
            'flags' => [], 'rule' => self::CCN, 'level' => null, 'key' => 'severity', 'json' => false,
        ],
        'misspelled-suppression-key' => [
            'rules' => "  complexity.ccn:\n    suppress_namespace_chanels:\n      complexity.ccn: ['Zzz\\Nope']\n",
            'flags' => [], 'rule' => self::CCN, 'level' => null, 'key' => 'suppress_namespace_chanels', 'json' => false,
        ],
        'retired-level-option' => [
            'rules' => "  complexity.ccn:\n    callable:\n      warning: 1\n      error: 2\n      exclude_paths: ['*']\n",
            'flags' => [], 'rule' => self::CCN, 'level' => 'callable', 'key' => 'exclude_paths', 'json' => false,
        ],
        'json-unrecognised-depth-1-key' => [
            'rules' => "  complexity.ccn:\n    callabel:\n      warning: 1\n",
            'flags' => [], 'rule' => self::CCN, 'level' => null, 'key' => 'callabel', 'json' => true,
        ],
        'typo-inside-level' => [
            'rules' => "  complexity.ccn:\n    callable:\n      warnign: 1\n      error: 2\n",
            'flags' => [], 'rule' => self::CCN, 'level' => 'callable', 'key' => 'warnign', 'json' => false,
        ],
        'two-typos-inside-level' => [
            'rules' => "  complexity.ccn:\n    callable:\n      warnign: 1\n      errro: 2\n",
            'flags' => [], 'rule' => self::CCN, 'level' => 'callable', 'key' => 'warnign', 'json' => false,
        ],
        'abbreviated-level-options' => [
            'rules' => "  complexity.ccn:\n    callable:\n      warn: 1\n      errors: 2\n",
            'flags' => [], 'rule' => self::CCN, 'level' => 'callable', 'key' => 'warn', 'json' => false,
        ],
        'uppercase-level-options' => [
            'rules' => "  complexity.ccn:\n    callable:\n      WARNING: 1\n      ERROR: 2\n",
            'flags' => [], 'rule' => self::CCN, 'level' => 'callable', 'key' => 'WARNING', 'json' => false,
        ],
        'options-for-another-level' => [
            'rules' => "  complexity.ccn:\n    callable:\n      max_warning: 1\n      max_error: 2\n",
            'flags' => [], 'rule' => self::CCN, 'level' => 'callable', 'key' => 'max_warning', 'json' => false,
        ],
        'cross-level-options' => [
            'rules' => "  complexity.ccn:\n    class:\n      warning: 1\n      error: 2\n",
            'flags' => [], 'rule' => self::CCN, 'level' => 'class', 'key' => 'warning', 'json' => false,
        ],
        'scalar-for-level-options-map' => [
            'rules' => "  complexity.ccn:\n    callable: 10\n",
            'flags' => [], 'rule' => self::CCN, 'level' => 'callable', 'key' => null, 'json' => false,
        ],
        'flag-unsupported-level' => [
            'rules' => null, 'flags' => ['complexity.ccn:method.warning=1'],
            'rule' => self::CCN, 'level' => null, 'key' => 'method', 'json' => false,
        ],
        'flag-typo-inside-level' => [
            'rules' => null,
            'flags' => ['complexity.ccn:callable.warnign=1', 'complexity.ccn:callable.error=2'],
            'rule' => self::CCN, 'level' => 'callable', 'key' => 'warnign', 'json' => false,
        ],
        'flag-level-option-at-rule-depth' => [
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
    public static function provideRefusalCases(): iterable
    {
        foreach (array_keys(self::POSITIONS) as $case) {
            yield $case => [$case];
        }
    }

    /**
     * Half one: the verdict. A case that regresses to a warning, a
     * silent acceptance, or to a crash reddens here whatever it says.
     */
    #[Test]
    #[DataProvider('provideRefusalCases')]
    public function itStillEndsEveryCaseWithExitThree(string $case): void
    {
        $run = $this->positionRun($case);

        self::assertSame(3, $run['exit'], \sprintf(
            'Case "%s" must still refuse with exit code 3; it exited %d saying: %s',
            $case,
            $run['exit'],
            $run['refusal'] === '' ? '(nothing)' : $run['refusal'],
        ));
    }

    /**
     * Half two, first part: the answer names the subject the author got wrong,
     * inside quotes, where the options list cannot leak into the comparison.
     */
    #[Test]
    #[DataProvider('provideRefusalCases')]
    public function itNamesEachCasesSubjectInItsOwnAnswer(string $case): void
    {
        $row = self::POSITIONS[$case];
        $refusal = $this->positionRun($case)['refusal'];
        $spans = self::foldedQuotedSpans($refusal);

        self::assertNotSame([], $spans, \sprintf(
            'Case "%s" answered without naming anything: %s',
            $case,
            $refusal === '' ? '(nothing)' : $refusal,
        ));

        foreach (array_filter([$row['rule'], $row['level'], $row['key']]) as $subject) {
            self::assertContains(self::fold($subject), $spans, \sprintf(
                'Case "%s" must answer in words of its own subject: "%s" is named nowhere in %s',
                $case,
                $subject,
                $refusal,
            ));
        }
    }

    /**
     * Half two, second part — the one that a shared phrase cannot survive.
     * Two cases read alike exactly when they name the same subject: a
     * collapse into one sentence about refusal makes distinct subjects read
     * alike, and an answer handed to the wrong case makes equal subjects
     * read differently.
     */
    #[Test]
    public function itLetsTwoTranslatedPositionsReadAlikeOnlyWhenTheyNameTheSameSubject(): void
    {
        $sentences = [];
        $subjects = [];

        foreach (array_keys(self::POSITIONS) as $case) {
            $row = self::POSITIONS[$case];
            $sentences[$case] = $this->positionRun($case)['refusal'];
            $subjects[$case] = implode('|', [
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
                        "Cases \"%s\" and \"%s\" name %s subjects but read %s.\n  %s\n  %s",
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
     * fixture file emits — that warning is the same in every case
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

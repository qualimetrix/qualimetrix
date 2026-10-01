<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use JsonException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\ProductIdentity;
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
#[CoversNothing]
final class TranslatedRefusalVocabularyTest extends TestCase
{
    private const string ANALYSED_PATH = 'tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php';

    private const string CCN = 'complexity.ccn';

    /** @var array<string, string> Full authored subjects and their source; %s is the fixture file locator. */
    private const array REFUSALS = [
        'unrecognised-depth-1-key' => 'Configuration error: Unknown key "rules.complexity.ccn.callabel" in configuration file "%s" (did you mean "callable"?). Accepted keys: callable, class, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold.',
        'unsupported-method-level' => 'Configuration error: Unknown key "rules.complexity.ccn.method" in configuration file "%s". Accepted keys: callable, class, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold.',
        'unsupported-namespace-level' => 'Configuration error: Unknown key "rules.complexity.ccn.namespace" in configuration file "%s". Accepted keys: callable, class, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold.',
        'uppercase-level-name' => 'Configuration error: Key "rules.complexity.ccn.CALLABLE" in configuration file "%s" is not written in an accepted spelling; write "callable" (its snake_case, camelCase and kebab-case spellings are accepted).',
        'typo-on-rule-without-levels' => 'Configuration error: Unknown key "rules.coupling.class-rank.warnign" in configuration file "%s" (did you mean "warning"?). Accepted keys: error, warning, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold.',
        'option-owned-by-another-rule' => 'Configuration error: Unknown key "rules.complexity.ccn.max_distance_warning" in configuration file "%s". Accepted keys: callable, class, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold.',
        'unsupported-threshold-option' => 'Configuration error: Unknown key "rules.code-smell.eval.threshold" in configuration file "%s". Accepted keys: enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths.',
        'pluralised-option-name' => 'Configuration error: Unknown key "rules.complexity.ccn.thresholds" in configuration file "%s" (did you mean "threshold"?). Accepted keys: callable, class, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold.',
        'misspelled-enabled-option' => 'Configuration error: Unknown key "rules.complexity.ccn.enable" in configuration file "%s" (did you mean "enabled"?). Accepted keys: callable, class, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold.',
        'unsupported-severity-key' => 'Configuration error: Unknown key "rules.complexity.ccn.severity" in configuration file "%s". Accepted keys: callable, class, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold.',
        'misspelled-suppression-key' => 'Configuration error: Unknown key "rules.complexity.ccn.suppress_namespace_chanels" in configuration file "%s" (did you mean "suppress-namespace-channels"?). Accepted keys: callable, class, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold.',
        'retired-level-option' => 'Configuration error: Key "rules.complexity.ccn.callable.exclude_paths" in configuration file "%s" is retired. The "exclude-paths" option was retired. To suppress findings the analysis already produces, use "suppress-paths". To exclude files from analysis entirely (the finding is never produced), use the "exclude" option instead — it is a different mechanism, not a renamed one.',
        'json-unrecognised-depth-1-key' => 'Configuration error: Unknown key "rules.complexity.ccn.callabel" in configuration file "%s" (did you mean "callable"?). Accepted keys: callable, class, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold.',
        'typo-inside-level' => 'Configuration error: Unknown key "rules.complexity.ccn.callable.warnign" in configuration file "%s" (did you mean "warning"?). Accepted keys: enabled, error, warning, threshold.',
        'two-typos-inside-level' => 'Configuration error: Unknown key "rules.complexity.ccn.callable.warnign" in configuration file "%s" (did you mean "warning"?). Accepted keys: enabled, error, warning, threshold.',
        'abbreviated-level-options' => 'Configuration error: Unknown key "rules.complexity.ccn.callable.warn" in configuration file "%s" (did you mean "warning"?). Accepted keys: enabled, error, warning, threshold.',
        'uppercase-level-options' => 'Configuration error: Key "rules.complexity.ccn.callable.WARNING" in configuration file "%s" is not written in an accepted spelling; write "warning" (its snake_case, camelCase and kebab-case spellings are accepted).',
        'options-for-another-level' => 'Configuration error: Unknown key "rules.complexity.ccn.callable.max_warning" in configuration file "%s". Accepted keys: enabled, error, warning, threshold.',
        'cross-level-options' => 'Configuration error: Unknown key "rules.complexity.ccn.class.warning" in configuration file "%s". Accepted keys: enabled, max-error, max-warning, threshold.',
        'scalar-for-level-options-map' => 'Configuration error: "rules.complexity.ccn.callable" in configuration file "%s" must be a map, got int.',
        'flag-unsupported-level' => 'Configuration error: Option "method.warning" is not an option of rule "complexity.ccn". Options here: callable, class, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold. Source: option --rule-opt.',
        'flag-typo-inside-level' => 'Configuration error: Option "warnign" is not an option of rule "complexity.ccn" at level "callable". Options at that level: enabled, error, threshold, warning. Other levels of this rule take different options. Source: option --rule-opt.',
        'flag-level-option-at-rule-depth' => 'Configuration error: Option "warning" is not an option of rule "complexity.ccn". Options here: callable, class, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold. Source: option --rule-opt.',
    ];

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
     * @var array<string, array{exit: int, refusal: string, locator: ?string}>
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

    #[Test]
    #[DataProvider('provideRefusalCases')]
    public function itNamesEachCasesSubjectInItsOwnAnswer(string $case): void
    {
        $run = $this->positionRun($case);
        self::assertSame(self::expectedRefusal($case, $run['locator']), $run['refusal']);
    }

    #[Test]
    public function itLetsTwoTranslatedPositionsReadAlikeOnlyWhenTheyNameTheSameSubject(): void
    {
        $sentences = [];
        $subjects = [];
        foreach (array_keys(self::POSITIONS) as $case) {
            $run = $this->positionRun($case);
            $sentences[$case] = $run['locator'] === null
                ? $run['refusal']
                : str_replace('"' . $run['locator'] . '"', '"<configuration-file>"', $run['refusal']);
            $subjects[$case] = self::expectedRefusal($case, '<configuration-file>');
        }

        foreach ($sentences as $left => $leftSentence) {
            foreach ($sentences as $right => $rightSentence) {
                if ($left >= $right) {
                    continue;
                }
                self::assertSame(
                    $subjects[$left] === $subjects[$right],
                    $leftSentence === $rightSentence,
                    \sprintf('Cases "%s" and "%s" must distinguish their full authored subjects and doors.', $left, $right),
                );
            }
        }
    }

    private static function expectedRefusal(string $case, ?string $locator): string
    {
        return $locator === null ? self::REFUSALS[$case] : \sprintf(self::REFUSALS[$case], $locator);
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

    /** @return array{exit: int, refusal: string, locator: ?string} */
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
     * @return array{exit: int, refusal: string, locator: ?string}
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

        $locator = $configPath === null ? null : realpath($configPath);
        self::assertNotFalse($locator);

        return [
            'exit' => $tester->getStatusCode(),
            'locator' => $locator,
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
     * fixture file emits, and from the documentation pointer every refusal
     * now carries — both are the same in every case and would make every
     * sentence read alike.
     */
    private static function refusalLine(string $stderr): string
    {
        $lines = array_values(array_filter(
            array_map(trim(...), explode("\n", $stderr)),
            static fn(string $line): bool => $line !== ''
                && !str_starts_with($line, 'Warning: Analyzed paths')
                && $line !== ProductIdentity::pointerText(),
        ));

        return implode(' ', $lines);
    }

    private function tester(): CommandTester
    {
        $container = (new ContainerFactory())->create();
        $command = $container->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $command);
        $refusalPresenter = $container->get(RefusalPresenter::class);
        self::assertInstanceOf(RefusalPresenter::class, $refusalPresenter);
        (new Application(new ErrorStream(), $refusalPresenter, new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader()))->addCommand($command);

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

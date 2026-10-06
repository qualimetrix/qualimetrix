<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Configuration\RuleOptionsBuild;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The same declared vocabulary reached through configuration files and CLI flags.
 *
 * Every case reaches the command. Admission and exit codes agree, while each
 * refusal preserves its own authored spelling and source: the document reader
 * names the file and key path, and the CLI parser names the flag. Equal human
 * sentences would erase that distinction.
 */
#[CoversClass(RuleOptionsBuild::class)]
final class RuleOptionKeyDoorSymmetryTest extends TestCase
{
    /** @param 'file'|'alias'|'flag' $door */
    #[Test]
    #[TestWith(['file', '+2'])]
    #[TestWith(['alias', '+2'])]
    #[TestWith(['flag', '+2'])]
    #[TestWith(['file', '2.0'])]
    #[TestWith(['alias', '2.0'])]
    #[TestWith(['flag', '2.0'])]
    public function itExplainsYamlFloatInterpretationAtEveryIntegerDoor(string $door, string $written): void
    {
        $path = $this->configFile("  complexity.ccn:\n    callable:\n      warning: " . $written . "\n");
        $input = match ($door) {
            'file' => ['--config' => $path],
            'alias' => ['--cyclomatic-warning' => $written],
            'flag' => ['--rule-opt' => ['complexity.ccn:callable.warning=' . $written]],
        };
        $run = $this->check($input);
        self::assertSame(3, $run['exit'], $run['stderr']);
        self::assertStringContainsString('read as float (2.0)', $run['refusal']);
        self::assertStringContainsString('YAML interprets +2 and 2.0 as float; write 2 for an integer.', $run['refusal']);
        self::assertStringContainsString(match ($door) {
            'file' => $path,
            'alias' => '--cyclomatic-warning=' . $written,
            'flag' => '--rule-opt=complexity.ccn:callable.warning=' . $written,
        }, $run['refusal']);
    }

    #[Test]
    public function itRefusesTheMiscalledLevelInsteadOfFoldingItsCase(): void
    {
        $run = $this->check(['--rule-opt' => ['complexity.ccn:Callable.warning=1']]);
        self::assertSame(3, $run['exit'], $run['stderr']);
        self::assertStringContainsString('Callable', $run['refusal']);
        self::assertStringContainsString('callable', $run['refusal']);
    }

    #[Test]
    public function itKeepsTheFullAuthoredRuleStatementInAnEarlyValueRefusal(): void
    {
        $run = $this->check(['--rule-opt' => ['complexity.ccn:callable.warning=oops']]);
        self::assertSame(3, $run['exit'], $run['stderr']);
        self::assertStringContainsString('complexity.ccn:callable.warning=oops', $run['refusal']);
    }

    private const string ANALYSED_PATH = 'tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php';

    /** @var list<string> */
    private array $cleanUp = [];

    /** The level vocabulary is shared; each refusal names its actual input source. */
    #[Test]
    public function itRefusesAnUnrecognisedLevelKeyWithEachDoorsOwnSource(): void
    {
        $path = $this->configFile("  complexity.ccn:\n    callable:\n      max_warnign: 1\n");
        $throughTheFile = $this->check(['--config' => $path]);
        $throughTheFlag = $this->check(['--rule-opt' => ['complexity.ccn:callable.max_warnign=1']]);

        self::assertSame(3, $throughTheFile['exit'], $throughTheFile['stderr']);
        self::assertSame(3, $throughTheFlag['exit'], $throughTheFlag['stderr']);
        self::assertSame(
            \sprintf('Configuration error: Unknown key "rules.complexity.ccn.callable.max_warnign" in configuration file "%s". Accepted keys: enabled, error, warning, threshold.', $path),
            $throughTheFile['refusal'],
        );
        self::assertSame(
            'Configuration error: Option "max_warnign" is not an option of rule "complexity.ccn" at level "callable".'
            . ' Options at that level: enabled, error, threshold, warning.'
            . ' Other levels of this rule take different options. Written: --rule-opt=complexity.ccn:callable.max_warnign=1. Source: option --rule-opt.',
            $throughTheFlag['refusal'],
        );
    }

    /** An unknown key keeps the separators its author wrote at either door. */
    #[Test]
    #[DataProvider('provideBothDoors')]
    public function itPrintsTheAuthoredSpellingThroughBothDoors(string $door): void
    {
        $refusal = $this->check($this->write($door, 'callable', 'max_warnign', 1))['refusal'];

        self::assertStringContainsString('max_warnign', $refusal);
        self::assertStringNotContainsString('maxWarnign', $refusal);
    }

    /** The same typo is refused at both doors without erasing either source. */
    #[Test]
    public function itRefusesTheSameTypoWithEachDoorsOwnSource(): void
    {
        $path = $this->configFile("  complexity.ccn:\n    callable:\n      warnign: 1\n");
        $fromTheFile = $this->check(['--config' => $path]);
        $fromTheFlag = $this->check($this->write('flag', 'callable', 'warnign', 1));

        self::assertSame(3, $fromTheFile['exit']);
        self::assertSame(3, $fromTheFlag['exit']);
        self::assertSame(
            \sprintf('Configuration error: Unknown key "rules.complexity.ccn.callable.warnign" in configuration file "%s" (did you mean "warning"?). Accepted keys: enabled, error, warning, threshold.', $path),
            $fromTheFile['refusal'],
        );
        self::assertSame(
            'Configuration error: Option "warnign" is not an option of rule "complexity.ccn" at level "callable".'
            . ' Options at that level: enabled, error, threshold, warning.'
            . ' Other levels of this rule take different options. Written: --rule-opt=complexity.ccn:callable.warnign=1. Source: option --rule-opt.',
            $fromTheFlag['refusal'],
        );
    }

    /**
     * Enumeration row E61 measured the three equivalent spellings on the file
     * door only. Both doors recognise the declared spellings, and a run
     * that reaches an exit code other than 3 is a run whose configuration was
     * understood.
     */
    #[Test]
    #[DataProvider('provideEquivalentSpellingsAtBothDoors')]
    public function itAcceptsEveryCanonicalSpellingThroughBothDoors(string $door, string $spelling): void
    {
        $run = $this->check($this->write($door, 'class', $spelling, 5));

        self::assertNotSame(3, $run['exit'], $run['stderr']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideEquivalentSpellingsAtBothDoors(): iterable
    {
        foreach (['file', 'flag'] as $door) {
            foreach (['max_warning', 'maxWarning', 'max-warning'] as $spelling) {
                yield $door . ' ' . $spelling => [$door, $spelling];
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideBothDoors(): iterable
    {
        yield 'configuration file' => ['file'];
        yield 'rule-opt flag' => ['flag'];
    }

    /** The root vocabulary is judged separately from the level vocabulary. */
    #[Test]
    public function itRefusesAnUnrecognisedTopLevelKeyWithEachDoorsOwnSource(): void
    {
        $path = $this->configFile("  complexity.ccn:\n    warnign: 1\n");
        $throughTheFile = $this->check(['--config' => $path]);
        $throughTheFlag = $this->check(['--rule-opt' => ['complexity.ccn:warnign=1']]);

        self::assertSame(3, $throughTheFile['exit'], $throughTheFile['stderr']);
        self::assertSame(3, $throughTheFlag['exit'], $throughTheFlag['stderr']);
        self::assertSame(
            \sprintf('Configuration error: Unknown key "rules.complexity.ccn.warnign" in configuration file "%s". Accepted keys: callable, class, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold.', $path),
            $throughTheFile['refusal'],
        );
        self::assertSame(
            'Configuration error: Option "warnign" is not an option of rule "complexity.ccn".'
            . ' Options here: callable, class, enabled, suppress-namespace-channels, suppress-namespaces, suppress-paths, threshold.'
            . ' Written: --rule-opt=complexity.ccn:warnign=1. Source: option --rule-opt.',
            $throughTheFlag['refusal'],
        );
    }

    // -- routing ---------------------------------------------------------------

    /**
     * A structured run must never be handed a broken document to parse:
     * `json` is a machine-readable format, so the refusal is the `{error,
     * exit_code}` envelope on stdout, not a half-written report. stderr may
     * still carry the unrelated scope-coverage warning this fixture always
     * triggers, but never the refusal sentence — the envelope is the one
     * place that goes.
     */
    #[Test]
    public function itAnswersWithAParseableEnvelopeUnderJsonFormat(): void
    {
        $path = $this->configFile("  complexity.ccn:\n    callable:\n      max_warnign: 1\n");
        $run = $this->check(['--config' => $path], ['--format' => 'json']);

        self::assertSame(3, $run['exit']);
        self::assertStringNotContainsString('Configuration error:', $run['stderr']);
        /** @var array{error: string, exit_code: int, position: mixed, source: mixed} $envelope */
        $envelope = json_decode($run['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(3, $envelope['exit_code']);
        self::assertStringContainsString('Configuration error:', $envelope['error']);
        self::assertSame(
            \sprintf('Configuration error: Unknown key "rules.complexity.ccn.callable.max_warnign" in configuration file "%s". Accepted keys: enabled, error, warning, threshold.', $path),
            $envelope['error'],
        );
        self::assertSame([
            'path' => ['rules', 'complexity.ccn', 'callable', 'max_warnign'],
            'written' => 'max_warnign',
            'accepted' => ['enabled', 'error', 'warning', 'threshold'],
            'closed' => true,
        ], $envelope['position']);
        self::assertSame([['kind' => 'file', 'name' => $path, 'imported_by' => null]], $envelope['source']);
    }

    /**
     * `-q` silences progress output, not the sentence that ends a run; that
     * message survives quiet even though the progress frame and report do not.
     */
    #[Test]
    public function itStillRefusesUnderQuiet(): void
    {
        $path = $this->configFile("  complexity.ccn:\n    callable:\n      max_warnign: 1\n");
        $run = $this->check(['--config' => $path], verbosity: OutputInterface::VERBOSITY_QUIET);

        self::assertSame(3, $run['exit']);
        self::assertSame('', $run['stdout']);
        self::assertSame(
            \sprintf('Configuration error: Unknown key "rules.complexity.ccn.callable.max_warnign" in configuration file "%s". Accepted keys: enabled, error, warning, threshold.', $path),
            $run['refusal'],
            'quiet silences the report and the progress frame, never the sentence that ends the run',
        );
    }

    /**
     * The enumeration left worker-process diagnostics unmeasured. Options are
     * built in the parent, before any worker exists, so a parallel run refuses
     * exactly as a serial one does — asserted rather than trusted.
     */
    #[Test]
    public function itStillRefusesWithMultipleWorkers(): void
    {
        $path = $this->configFile("  complexity.ccn:\n    callable:\n      max_warnign: 1\n");
        $run = $this->check(['--config' => $path], ['--workers' => '2']);

        self::assertSame(3, $run['exit']);
        self::assertSame(
            \sprintf('Configuration error: Unknown key "rules.complexity.ccn.callable.max_warnign" in configuration file "%s". Accepted keys: enabled, error, warning, threshold.', $path),
            $run['refusal'],
        );
    }

    /**
     * Generic and retired keys keep one framing and exit code. The file's
     * retired advice uses canonical declared names while its key path keeps
     * the authored spelling; CLI advice keeps the spelling typed into the flag.
     */
    #[Test]
    public function itFramesEveryRefusalAsAConfigurationErrorRegardlessOfDoorOrCause(): void
    {
        $generic = $this->check($this->write('flag', 'callable', 'max_warnign', 1));
        $retired = $this->check(['--rule-opt' => ['complexity.ccn:exclude_paths=src/Generated']]);
        $path = $this->configFile("  complexity.ccn:\n    exclude_paths: ['src/Generated']\n");
        $retiredInAFile = $this->check(['--config' => $path]);

        self::assertSame(3, $generic['exit']);
        self::assertSame(3, $retired['exit']);
        self::assertSame(3, $retiredInAFile['exit']);
        self::assertStringStartsWith('Configuration error: ', $generic['refusal']);
        self::assertStringStartsWith('Configuration error: ', $retired['refusal']);
        self::assertStringContainsString('The "exclude_paths" option was retired', $retired['refusal']);
        self::assertStringNotContainsString('is not an option of rule', $retired['refusal']);
        self::assertStringStartsWith('Configuration error: ', $retiredInAFile['refusal']);
        self::assertStringContainsString('"rules.complexity.ccn.exclude_paths"', $retiredInAFile['refusal']);
        self::assertStringContainsString('The "exclude-paths" option was retired', $retiredInAFile['refusal']);
        self::assertStringContainsString('use "suppress-paths"', $retiredInAFile['refusal']);
        self::assertStringContainsString(\sprintf('in configuration file "%s"', $path), $retiredInAFile['refusal']);
    }

    /**
     * The negative case the rest of the file needs to mean anything: the same
     * option, spelled correctly, runs to completion through both doors. Without
     * it every assertion above is equally satisfied by a command that refuses
     * every configuration it is given.
     */
    #[Test]
    #[DataProvider('provideBothDoors')]
    public function itRunsToCompletionWhenTheSameOptionIsSpelledCorrectly(string $door): void
    {
        $run = $this->check($this->write($door, 'callable', 'warning', 4), ['--format' => 'json']);

        self::assertNotSame(3, $run['exit'], $run['stderr']);
        self::assertNotSame('', $run['stdout']);
        self::assertStringNotContainsString('is not an option of rule', $run['stderr']);
    }

    /**
     * One depth-2 option, written at whichever door the case names.
     *
     * @return array<string, mixed> arguments for {@see self::check()}
     */
    private function write(string $door, string $slot, string $key, int $value): array
    {
        return $door === 'file'
            ? ['--config' => $this->configFile(\sprintf("  complexity.ccn:\n    %s:\n      %s: %d\n", $slot, $key, $value))]
            : ['--rule-opt' => [\sprintf('complexity.ccn:%s.%s=%d', $slot, $key, $value)]];
    }

    /**
     * @param array<string, mixed> $door
     * @param array<string, mixed> $extra
     *
     * @return array{exit: int, stdout: string, stderr: string, refusal: string}
     */
    private function check(array $door, array $extra = [], ?int $verbosity = null): array
    {
        $options = ['capture_stderr_separately' => true];

        if ($verbosity !== null) {
            $options['verbosity'] = $verbosity;
        }

        $tester = $this->tester();
        $tester->execute(['paths' => [self::ANALYSED_PATH]] + $door + $extra, $options);

        $stderr = $tester->getErrorOutput();

        return [
            'exit' => $tester->getStatusCode(),
            'stdout' => $tester->getDisplay(),
            'stderr' => $stderr,
            'refusal' => self::refusalLine($stderr),
        ];
    }

    /**
     * The refusal, separated from the scope warning every run over a single
     * fixture file emits, and from the documentation pointer every refusal
     * now carries. Comparing whole streams would compare that warning and
     * that pointer too, and neither says anything about either door.
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
        $path = tempnam(sys_get_temp_dir(), 'qmx-door-symmetry-');
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

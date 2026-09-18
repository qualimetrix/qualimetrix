<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The two doors a rule option can be written at, asked the same question.
 *
 * A user has exactly two of them — a configuration file and `--rule-opt` — and
 * they arrive at the factory by different routes: the file through the loader's
 * per-section normalization policy, the flag through
 * {@see \Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParser}'s own
 * fold plus dot expansion. A third way exists in code and is **not** a door:
 * writing the registry directly leaves a depth-2 key spelled as typed, so a
 * symmetry proved through it would be a symmetry of a path no user walks. Every
 * case here goes through the command.
 *
 * What the file pins beyond the symmetry is the *spelling* the refusal answers
 * in. Both doors fold separators before the factory exists, so a key mistyped
 * `max_warnign` is answered as `maxWarnign`. That is a limit of ADR 0044's
 * normalization model at this seam, not a defect of the refusal, and it is
 * asserted verbatim so that an accidental improvement to it reddens and gets
 * decided rather than absorbed.
 */
#[CoversClass(RuleOptionsFactory::class)]
final class RuleOptionKeyDoorSymmetryTest extends TestCase
{
    private const string ANALYSED_PATH = 'tests/Fixtures/Ast/empty_file.php';

    /** @var list<string> */
    private array $cleanUp = [];

    /**
     * The same unrecognised depth-2 key, written both ways, answered in one
     * sentence — including the folded spelling, which is what makes this a
     * symmetry of the *text* rather than only of the verdict.
     */
    #[Test]
    public function itAnswersAnUnrecognisedLevelKeyIdenticallyThroughBothDoors(): void
    {
        $throughTheFile = $this->check(['--config' => $this->configFile("  complexity.ccn:\n    callable:\n      max_warnign: 1\n")]);
        $throughTheFlag = $this->check(['--rule-opt' => ['complexity.ccn:callable.max_warnign=1']]);

        self::assertSame(3, $throughTheFile['exit'], $throughTheFile['stderr']);
        self::assertSame(3, $throughTheFlag['exit'], $throughTheFlag['stderr']);
        self::assertSame($throughTheFile['refusal'], $throughTheFlag['refusal']);
        self::assertSame(
            'Configuration error: Option "maxWarnign" is not an option of rule "complexity.ccn" at level "callable".'
            . ' Options at that level: enabled, error, threshold, warning.'
            . ' Other levels of this rule take different options.',
            $throughTheFile['refusal'],
        );
    }

    /**
     * The named limit, pinned on its own so that it fails as itself. A key
     * whose *letters* are wrong — which is what a typo gets wrong — survives
     * the fold intact and is quoted exactly; a key whose separators are wrong
     * is quoted folded, and neither door can do better without a spelling
     * side-channel ADR 0044 declined to open.
     */
    #[Test]
    #[DataProvider('provideBothDoors')]
    public function itPrintsTheFoldedSpellingRatherThanTheOneTheUserTyped(string $door): void
    {
        $refusal = $this->check($this->write($door, 'callable', 'max_warnign', 1))['refusal'];

        self::assertStringContainsString('"maxWarnign"', $refusal);
        self::assertStringNotContainsString('max_warnign', $refusal);
    }

    /**
     * Enumeration rows E53 and E73: the same mistake typed into the file and
     * into the flag, refused in identical text. E73 exists as a separate row
     * precisely because the flag used to be believed to preserve the authored
     * spelling; it does not, and the two rows are one answer.
     */
    #[Test]
    public function itAnswersTheSameTypoTheSameWayWhicheverDoorItArrivesThrough(): void
    {
        $fromTheFile = $this->check($this->write('file', 'callable', 'warnign', 1));
        $fromTheFlag = $this->check($this->write('flag', 'callable', 'warnign', 1));

        self::assertSame(3, $fromTheFile['exit']);
        self::assertSame(3, $fromTheFlag['exit']);
        self::assertSame($fromTheFile['refusal'], $fromTheFlag['refusal']);
        self::assertStringContainsString('Option "warnign"', $fromTheFile['refusal']);
    }

    /**
     * Enumeration row E61 measured the three equivalent spellings on the file
     * door only. Both doors fold, so both must accept all three — and a run
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

    /**
     * The other cell of the two-by-two: the depth-1 walk, reached through each
     * door. Depth 1 and depth 2 are separate comparisons against separate
     * declarations, so a case at one says nothing about the other — and the
     * `--rule-opt` door reaches depth 1 without the dot expansion the depth-2
     * cases exercise.
     */
    #[Test]
    public function itAnswersAnUnrecognisedTopLevelKeyIdenticallyThroughBothDoors(): void
    {
        $throughTheFile = $this->check(['--config' => $this->configFile("  complexity.ccn:\n    warnign: 1\n")]);
        $throughTheFlag = $this->check(['--rule-opt' => ['complexity.ccn:warnign=1']]);

        self::assertSame(3, $throughTheFile['exit'], $throughTheFile['stderr']);
        self::assertSame(3, $throughTheFlag['exit'], $throughTheFlag['stderr']);
        self::assertSame($throughTheFile['refusal'], $throughTheFlag['refusal']);
        self::assertStringContainsString(
            'Configuration error: Option "warnign" is not an option of rule "complexity.ccn". Options here:',
            $throughTheFile['refusal'],
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
        $run = $this->check($this->write('file', 'callable', 'max_warnign', 1), ['--format' => 'json']);

        self::assertSame(3, $run['exit']);
        self::assertStringNotContainsString('Configuration error:', $run['stderr']);
        /** @var array{error: string, exit_code: int} $envelope */
        $envelope = json_decode($run['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(3, $envelope['exit_code']);
        self::assertStringContainsString('Configuration error:', $envelope['error']);
    }

    /**
     * `-q` silences progress output, not the sentence that ends a run; that
     * message survives quiet even though the progress frame and report do not.
     */
    #[Test]
    public function itStillRefusesUnderQuiet(): void
    {
        $run = $this->check($this->write('file', 'callable', 'max_warnign', 1), verbosity: OutputInterface::VERBOSITY_QUIET);

        self::assertSame(3, $run['exit']);
        self::assertSame('', $run['stdout']);
        self::assertStringContainsString(
            'Configuration error: Option "maxWarnign"',
            $run['stderr'],
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
        $run = $this->check($this->write('file', 'callable', 'max_warnign', 1), ['--workers' => '2']);

        self::assertSame(3, $run['exit']);
        self::assertStringContainsString('Configuration error: Option "maxWarnign"', $run['stderr']);
    }

    /**
     * Two refusals, one framing, one exit code. Both the generic key mistake
     * and the retired-option mistake are a {@see \Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal}
     * by the time either reaches the command, and {@see \Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter}
     * frames every one of them the same way — "framing lives here and only
     * here" (its class docblock). The asymmetry this test used to pin (generic
     * framed, retired verbatim) was a stale artifact of the retired option
     * still being an `InvalidArgumentException` printed by the command itself;
     * now that {@see \Qualimetrix\Analysis\Configuration\RetiredSuppressionOptions}
     * throws the same carrier, a second framing rule for it would be exactly
     * a per-dialect special case. Rejected alternative:
     * keep the retired message unframed by having the presenter pattern-match
     * on carrier content — that reopens the "framing happens at every call
     * site" problem the presenter was built to close.
     */
    #[Test]
    public function itFramesEveryRefusalAsAConfigurationErrorRegardlessOfDoorOrCause(): void
    {
        $generic = $this->check($this->write('flag', 'callable', 'max_warnign', 1));
        $retired = $this->check(['--rule-opt' => ['complexity.ccn:exclude_paths=src/Generated']]);
        $retiredInAFile = $this->check(['--config' => $this->configFile(
            "  complexity.ccn:\n    exclude_paths: ['src/Generated']\n",
        )]);

        self::assertSame(3, $generic['exit']);
        self::assertSame(3, $retired['exit']);
        self::assertSame(3, $retiredInAFile['exit']);
        self::assertStringStartsWith('Configuration error: ', $generic['refusal']);
        self::assertStringStartsWith('Configuration error: ', $retired['refusal']);
        self::assertStringContainsString('The "exclude_paths" option was retired', $retired['refusal']);
        self::assertStringNotContainsString('is not an option of rule', $retired['refusal']);
        self::assertStringStartsWith('Configuration error: ', $retiredInAFile['refusal']);
        self::assertStringContainsString('The "exclude_paths" option was retired', $retiredInAFile['refusal']);
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
     * fixture file emits. Comparing whole streams would compare that warning
     * too, and it says nothing about either door.
     */
    private static function refusalLine(string $stderr): string
    {
        $lines = array_values(array_filter(
            array_map(trim(...), explode("\n", $stderr)),
            static fn(string $line): bool => $line !== '' && !str_starts_with($line, 'Warning: Analyzed paths'),
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
        (new Application(new ErrorStream(), $refusalPresenter))->addCommand($command);

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

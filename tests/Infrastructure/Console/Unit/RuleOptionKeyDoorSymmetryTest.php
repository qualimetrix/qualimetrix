<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
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
     * Enumeration row 52. A structured run must never be handed a broken
     * document to parse: the refusal goes to stderr and stdout is left empty
     * rather than half-written.
     */
    #[Test]
    public function itLeavesStdoutEmptyUnderJsonFormat(): void
    {
        $run = $this->check($this->write('file', 'callable', 'max_warnign', 1), ['--format' => 'json']);

        self::assertSame(3, $run['exit']);
        self::assertSame('', $run['stdout']);
        self::assertStringContainsString('Configuration error:', $run['stderr']);
    }

    /**
     * Enumeration row 51: `-q` silenced the old warning, and a silenced
     * warning is why this position was invisible. A refusal is not a log line,
     * so the verdict survives quiet even though the sentence does not — which
     * is the behaviour pinned here, not a claim that the text is printed.
     */
    #[Test]
    public function itStillRefusesUnderQuiet(): void
    {
        $run = $this->check($this->write('file', 'callable', 'max_warnign', 1), verbosity: OutputInterface::VERBOSITY_QUIET);

        self::assertSame(3, $run['exit']);
        self::assertSame('', $run['stdout']);
        self::assertSame('', $run['stderr'], 'quiet silences the sentence; the exit code is what carries the refusal');
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
     * Two refusals, two framings, one exit code. The generic one is a
     * `ConfigLoadException` and carries the `Configuration error: ` prefix that
     * says the fault is in the configuration document; the retired-key one
     * reaching the flag door is an `InvalidArgumentException` printed verbatim,
     * because the migration text it carries is the message. The same retired
     * key written into a file is refused earlier, by the loader, and does carry
     * the prefix — the asymmetry is between doors as much as between refusals.
     * ADR 0049 decision 5 records why this plan left it that way; all three
     * refusals are asserted below so the record stays true.
     */
    #[Test]
    public function itFramesTheGenericRefusalAsAConfigurationErrorAndTheRetiredOneWithoutAPrefix(): void
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
        self::assertStringStartsWith('The "exclude_paths" option was retired', $retired['refusal']);
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
        (new Application(new ErrorStream()))->addCommand($command);

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

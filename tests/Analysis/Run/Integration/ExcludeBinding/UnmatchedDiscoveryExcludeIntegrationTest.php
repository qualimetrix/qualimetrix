<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Integration\ExcludeBinding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeAudit;
use Qualimetrix\Analysis\Run\ExcludeBinding\UnmatchedExcludeRule;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `discovery.unmatched-exclude` through the real command, because the value
 * this channel is about is lost three times on its way to a report and a unit
 * test of the predicate would not notice any of them.
 *
 * The three: the authored patterns are merged with the product's built-in
 * excludes in the resolver; the console rebuilds the run configuration after
 * resolving the scope; and the finding is assembled after rule execution, on
 * Run's side of the pipeline. A test that called the audit directly would pass
 * with the channel reaching no report at all.
 *
 * **The pair is the test.** A pattern that binds and one that misses produced
 * byte-identical output before this channel existed — which is what made the
 * door silent, and what makes "the miss reports" worthless alone: a producer
 * that always fired would satisfy it. So every reporting case here has a
 * silent twin on a fixture that differs in exactly one way.
 */
#[CoversClass(UnmatchedExcludeRule::class)]
#[CoversClass(UnmatchedExcludeAudit::class)]
final class UnmatchedDiscoveryExcludeIntegrationTest extends TestCase
{
    private string $fixture = '';

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/qmx-unmatched-exclude-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/src/Kept', 0o755, true);
        mkdir($this->fixture . '/src/Legacy/Deep', 0o755, true);

        // The scope predicate reads the production autoload roots, so without
        // this the whole tree would read as a narrowed run and every case
        // below would be silent for the wrong reason.
        file_put_contents(
            $this->fixture . '/composer.json',
            json_encode(['autoload' => ['psr-4' => ['Sample\\' => 'src/']]], \JSON_THROW_ON_ERROR),
        );

        file_put_contents($this->fixture . '/src/Kept/Service.php', <<<'PHP'
            <?php

            namespace Sample\Kept;

            class Service
            {
                public function value(): int
                {
                    return 1;
                }
            }
            PHP);

        file_put_contents($this->fixture . '/src/Legacy/Deep/Old.php', <<<'PHP'
            <?php

            namespace Sample\Legacy\Deep;

            class Old
            {
                public function value(): int
                {
                    return 2;
                }
            }
            PHP);
    }

    protected function tearDown(): void
    {
        foreach (['/src/Kept/Service.php', '/src/Legacy/Deep/Old.php', '/src/Only.php', '/composer.json', '/qmx.yaml'] as $file) {
            @unlink($this->fixture . $file);
        }

        foreach (['/src/Kept', '/src/Legacy/Deep', '/src/Legacy', '/src', ''] as $dir) {
            @rmdir($this->fixture . $dir);
        }
    }

    /** Half one of the CLI pair: `--exclude` names nothing, and the run says so. */
    #[Test]
    public function itReportsACommandLineExcludeThatMatchedNothing(): void
    {
        $findings = $this->findingsOnChannel($this->check(options: ['--exclude' => ['NoSuchDir']]));

        self::assertCount(1, $findings);
        self::assertSame('warning', $findings[0]['severity'] ?? null);
        self::assertStringContainsString('NoSuchDir', (string) ($findings[0]['message'] ?? ''));
    }

    /**
     * Half two: the same flag with a directory the tree really has.
     *
     * The independent witness that this is a hit rather than a second miss the
     * channel happens not to report is the file count — the excluded directory
     * leaves the analysed set — and it is read from the coverage projection,
     * which no finding enters.
     */
    #[Test]
    public function itStaysSilentWhenTheCommandLineExcludeMatched(): void
    {
        $miss = $this->check(options: ['--exclude' => ['NoSuchDir']]);
        $hit = $this->check(options: ['--exclude' => ['Legacy']]);

        self::assertSame([], $this->findingsOnChannel($hit));
        self::assertSame(2, $this->analysedFileCount($miss));
        self::assertSame(1, $this->analysedFileCount($hit), 'The hit must remove the directory from the run.');
    }

    /** The same pair through the configuration door, which loses the value at the same three places. */
    #[Test]
    public function itReportsAConfiguredExcludeThatMatchedNothingAndNotOneThatMatched(): void
    {
        $miss = $this->findingsOnChannel($this->check("exclude:\n  - NoSuchDir\n"));
        $hit = $this->findingsOnChannel($this->check("exclude:\n  - Legacy\n"));

        self::assertCount(1, $miss);
        self::assertStringContainsString('NoSuchDir', (string) ($miss[0]['message'] ?? ''));
        self::assertSame([], $hit);
    }

    /**
     * The subject question, which the coverage precondition above cannot
     * answer: `qmx check src/` covers this project's production autoload
     * roots, so an `exclude:` entry naming a directory outside them — the
     * repository's own `tests/`, written for `qmx check .` — used to be
     * reported as stale on every CI run. It names a directory that exists,
     * and existing is the whole difference: the twin below names one that
     * exists nowhere in the tree and is still reported by the same run.
     */
    #[Test]
    public function itJudgesAnExcludePatternAgainstTheWholeTreeAndNotTheAnalysedPaths(): void
    {
        mkdir($this->fixture . '/tests', 0o755, true);

        $findings = $this->findingsOnChannel($this->check("exclude:\n  - tests\n  - NoSuchDir\n"));
        $messages = implode(' | ', array_map(static fn(array $f): string => (string) ($f['message'] ?? ''), $findings));

        self::assertCount(1, $findings, $messages);
        self::assertStringContainsString('NoSuchDir', $messages);

        @rmdir($this->fixture . '/tests');
    }

    /**
     * The precondition, from the side that could hide a broken cure: the same
     * missed pattern on a run narrowed below the autoload roots must be
     * silent, because there the pattern binds nothing for a reason the author
     * did not choose.
     *
     * Paired with {@see itReportsACommandLineExcludeThatMatchedNothing()},
     * which is the same pattern on a run that does cover them. One observation
     * without the other does not distinguish a working precondition from a
     * channel that never speaks.
     */
    #[Test]
    public function itStaysSilentOnARunNarrowedBelowTheAutoloadRoots(): void
    {
        self::assertSame(
            [],
            $this->findingsOnChannel($this->check(paths: ['src/Kept'], options: ['--exclude' => ['NoSuchDir']])),
        );
    }

    /**
     * The product's own `vendor`, `node_modules` and `.git` are excluded on
     * every run whether the author asked or not, and `node_modules` is absent
     * from most PHP trees. Reporting them would put a finding on every run of
     * every project, which is why only the authored half of the merged list is
     * judged.
     */
    #[Test]
    public function itNeverReportsTheProductsOwnBuiltInExcludes(): void
    {
        self::assertSame([], $this->findingsOnChannel($this->check()));
    }

    /**
     * The two forms of {@see \Symfony\Component\Finder\Finder::exclude()},
     * which are different predicates: a bare name matches a directory at any
     * depth, a slashed pattern matches a path-segment sequence **relative to
     * the analysed path**, not to the project root. Both bind here, and a
     * probe modelling either one as the other would report a real hit as a
     * miss — measured: `src/Kept` binds nothing on `check src`, because
     * Finder's search root is already `src`.
     */
    #[Test]
    public function itHonoursBothFormsOfTheFinderExcludePattern(): void
    {
        $bound = $this->check(options: ['--exclude' => ['Kept', 'Legacy/Deep']]);

        self::assertSame([], $this->findingsOnChannel($bound));
        self::assertSame(0, $this->analysedFileCount($bound), 'Both forms must really have removed their directory.');
    }

    /** Two roots naming the same tree are one answer, not two: the binding is a boolean. */
    #[Test]
    public function itDoesNotDoubleReportAcrossOverlappingRoots(): void
    {
        $findings = $this->findingsOnChannel(
            $this->check(paths: ['src', 'src/Kept', 'src'], options: ['--exclude' => ['NoSuchDir']]),
        );

        self::assertCount(1, $findings);
    }

    /**
     * A run whose every path is a single file has no directory tree for an
     * exclusion to remove anything from, so the pattern bound nothing for a
     * reason that is not the author's — the honest answer is silence.
     */
    #[Test]
    public function itStaysSilentWhenEveryAnalysedPathIsAFile(): void
    {
        file_put_contents($this->fixture . '/src/Only.php', <<<'PHP'
            <?php

            namespace Sample;

            class Only
            {
                public function value(): int
                {
                    return 3;
                }
            }
            PHP);

        self::assertSame(
            [],
            $this->findingsOnChannel($this->check(paths: ['src/Only.php'], options: ['--exclude' => ['NoSuchDir']])),
        );
    }

    /**
     * The channel is the rule's, not
     * {@see \Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface}'s,
     * and this is the run that proves it rather than the declaration claiming
     * it: a configuration-error channel fails the run regardless of `fail_on`,
     * so this fixture would exit 2 under `--fail-on=none` had the channel been
     * declared by a validator.
     */
    #[Test]
    public function itLeavesTheRunGreenUnderFailOnNone(): void
    {
        $tester = $this->check(options: $this->onlyThisChannel());

        self::assertNotSame([], $this->findingsOnChannel($tester));
        self::assertSame(0, $tester->getStatusCode(), $tester->getErrorOutput());
    }

    /**
     * The other half of "not a configuration error": under `--fail-on=warning`
     * the finding trips the gate like any warning, at warning's own exit code.
     * A validator channel would trip it under both settings, so it is the pair
     * that carries the proof and neither run alone.
     *
     * Both halves run with `--only-rule` on this channel, so the code read is
     * this finding's and not the highest severity some neighbouring rule
     * happened to reach on the fixture.
     */
    #[Test]
    public function itFailsTheRunUnderFailOnWarning(): void
    {
        $tester = $this->check(options: [...$this->onlyThisChannel(), '--fail-on' => 'warning']);

        self::assertSame(1, $tester->getStatusCode(), 'A warning meeting --fail-on exits with warning\'s own code.');
    }

    /**
     * The miss, with every other producer switched off, so an exit code is
     * about this channel alone.
     *
     * @return array<string, mixed>
     */
    private function onlyThisChannel(): array
    {
        return ['--exclude' => ['NoSuchDir'], '--only-rule' => [UnmatchedExcludeRule::NAME]];
    }

    /**
     * `--disable-rule` reaching the channel is the proof that the assembled
     * finding really goes through `publishable()` — registry, selection,
     * severity and baseline — rather than being appended to the report behind
     * it.
     */
    #[Test]
    public function itIsSilencedByDisablingTheProducingRule(): void
    {
        $tester = $this->check(options: [
            '--exclude' => ['NoSuchDir'],
            '--disable-rule' => [UnmatchedExcludeRule::NAME],
        ]);

        self::assertSame([], $this->findingsOnChannel($tester));
    }

    /**
     * The rule's own options are the second switch, and reaching them is what
     * the audit's lazy registration buys: constructed eagerly it would hold an
     * Options object from before the console applied this, and would still
     * read `enabled: true`. Measured — this case failed on an eager
     * registration.
     */
    #[Test]
    public function itIsSilencedByDisablingTheRuleThroughItsOptions(): void
    {
        $yaml = "rules:\n  " . UnmatchedExcludeRule::NAME . ":\n    enabled: false\n";

        self::assertSame([], $this->findingsOnChannel($this->check($yaml, options: ['--exclude' => ['NoSuchDir']])));
    }

    /** Each missed pattern is its own mistake, and each gets its own finding. */
    #[Test]
    public function itReportsEveryUnboundPatternSeparately(): void
    {
        $findings = $this->findingsOnChannel(
            $this->check(options: ['--exclude' => ['NoSuchDir', 'Legacy', 'AlsoMissing']]),
        );

        $messages = array_map(static fn(array $finding): string => (string) ($finding['message'] ?? ''), $findings);

        self::assertCount(2, $findings, 'The bound pattern must not be reported: ' . implode(' | ', $messages));

        // Order is the report's, not the author's: findings on one channel and
        // one subject sort by occurrence key, and each pattern now has one, so
        // two stale patterns are two identities rather than a count of two.
        $named = array_filter($messages, static fn(string $m): bool => str_contains($m, 'NoSuchDir'));
        self::assertCount(1, $named, implode(' | ', $messages));
        self::assertCount(
            1,
            array_filter($messages, static fn(string $m): bool => str_contains($m, 'AlsoMissing')),
            implode(' | ', $messages),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findingsOnChannel(CommandTester $tester): array
    {
        $payload = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayHasKey('violations', $payload);
        $violations = $payload['violations'];
        self::assertIsList($violations);

        $matched = [];
        foreach ($violations as $violation) {
            self::assertIsArray($violation);
            if (($violation['rule'] ?? null) === UnmatchedExcludeRule::NAME) {
                $matched[] = $violation;
            }
        }

        return $matched;
    }

    private function analysedFileCount(CommandTester $tester): int
    {
        $payload = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $summary = $payload['summary'] ?? null;
        self::assertIsArray($summary);

        return (int) ($summary['filesAnalyzed'] ?? -1);
    }

    /**
     * @param list<string> $paths
     * @param array<string, mixed> $options
     */
    private function check(string $yaml = "cache:\n  enabled: false\n", array $paths = ['src'], array $options = []): CommandTester
    {
        file_put_contents($this->fixture . '/qmx.yaml', $yaml);

        $command = (new ContainerFactory())->create()->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $command);

        $tester = new CommandTester($command);

        // The project root comes from the process working directory, exactly
        // as `--working-dir` sets it on the real binary.
        $previous = (string) getcwd();
        chdir($this->fixture);

        try {
            $tester->execute(
                [
                    'paths' => $paths,
                    '--workers' => '0',
                    '--format' => 'json',
                    '--fail-on' => 'none',
                    ...$options,
                ],
                ['capture_stderr_separately' => true],
            );
        } finally {
            chdir($previous);
        }

        return $tester;
    }
}

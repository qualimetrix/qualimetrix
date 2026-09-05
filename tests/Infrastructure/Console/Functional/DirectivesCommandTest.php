<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveEffect;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSite;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdict;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DirectiveAuditReport;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\Command\DirectivesCommand;
use Qualimetrix\Infrastructure\Console\DirectiveAuditPresenter;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use ReflectionMethod;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command over real trees, through the production container.
 *
 * Every case here is a fixture written to disk and audited, because the thing
 * under test is what a run answers, and a hand-built report would only prove
 * that the renderer renders what it is handed.
 */
#[Group('integration')]
#[CoversClass(DirectivesCommand::class)]
#[CoversClass(DirectiveAuditPresenter::class)]
final class DirectivesCommandTest extends TestCase
{
    /**
     * The fixtures below are one class each, and a lone class is the whole
     * graph: `coupling.class-rank` reports every one of them. Narrowing here
     * rather than asserting around the noise keeps each case about the
     * directive it was written for.
     */
    private const string WITHOUT_COUPLING = "disabled_rules: ['coupling.*']\n";

    private string $tempDir;

    protected function setUp(): void
    {
        // `uniqid()` is unique within a process and not between them, and
        // this path leaves the tree, so the probe stand's clone does not
        // isolate it: two concurrent runs of this case landed in one
        // directory and each refused to write the other's baseline.
        $this->tempDir = sys_get_temp_dir()
            . '/qmx-directives-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/src', 0o755, true);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->tempDir);
    }

    #[Test]
    public function itExitsCleanWhenEveryDirectiveStillDoesSomething(): void
    {
        $this->writeSource('Live.php', self::sevenParameterMethod(
            '@qmx-threshold code-smell.long-parameter-list warning=9 error=12 — live',
        ));

        $tester = $this->audit(['paths' => [$this->tempDir . '/src']]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('effective: removing it changes what the rules produce.', $tester->getDisplay());
    }

    #[Test]
    public function itExitsTwoOnAnInertDirective(): void
    {
        $this->writeSource('Dead.php', self::sevenParameterMethod(
            '@qmx-threshold complexity.cyclomatic warning=50 error=80 — dead',
        ));

        $tester = $this->audit(['paths' => [$this->tempDir . '/src']]);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('inert: removing it changes nothing.', $tester->getDisplay());
    }

    /**
     * A directive that applied and moved only the boundary is not debt: it is a
     * statement a person has to read. Nothing here may push the exit code.
     */
    #[Test]
    public function itExitsCleanWhenTheOnlyFindingIsAnAppliedBoundaryThatMovedNothingElse(): void
    {
        $this->writeSource('Overrun.php', self::sevenParameterMethod(
            '@qmx-threshold code-smell.long-parameter-list warning=5 error=7 — still an error at seven',
        ));

        $tester = $this->audit(['paths' => [$this->tempDir . '/src']]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('applied; nothing moved except the boundary it prints.', $display);
        self::assertStringContainsString('the rule layer has no notion of stricter', $display);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    /** The absence of an answer is not a debt, and must not be reported as one. */
    #[Test]
    public function itExitsCleanWhenADirectiveCouldNotBeMeasured(): void
    {
        $this->writeSource('Unmeasured.php', <<<'SOURCE'
            <?php

            namespace Fixture;

            final class Unmeasured
            {
                /** @qmx-ignore * — no rule filter at all */
                public function trivial(): int
                {
                    return 1;
                }
            }
            SOURCE);

        $tester = $this->audit(['paths' => [$this->tempDir . '/src'], '--format' => 'json']);

        $report = self::decode($tester->getDisplay());
        self::assertSame(1, $report['summary']['unmeasured']);
        self::assertSame('addresses-every-channel', $report['directives'][0]['reason']);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    /**
     * The half of the subject nothing else here covers: a suppression that
     * actually silenced something. Without it the whole file passes even if
     * every `@qmx-ignore` in the world were reported inert.
     */
    #[Test]
    public function itCallsASuppressionEffectiveWhenItSilencedAFinding(): void
    {
        $this->writeSource('Silenced.php', self::sevenParameterMethod(
            '@qmx-ignore code-smell.long-parameter-list — silences the seven parameters below',
        ));

        $report = self::decode($this->audit([
            'paths' => [$this->tempDir . '/src'],
            '--format' => 'json',
        ])->getDisplay());

        self::assertSame('effective', $report['directives'][0]['effect']);
        self::assertSame(1, $report['summary']['effective']);
    }

    /**
     * Where the addressed rule publishes no boundary, an inert verdict cannot
     * be told from a boundary the value had already passed. Printing it is
     * honest; exiting 2 on it would demand the author act on a question the
     * report says was never asked.
     */
    #[Test]
    public function itDoesNotFailOnAnInertVerdictWhoseBoundaryWasNotObservable(): void
    {
        $report = new DirectiveAuditReport(
            [new DirectiveVerdict(
                site: new DirectiveSite(RelativePath::fromString('src/Foo.php'), 7, 'threshold', 'design.god-class'),
                effect: DirectiveEffect::Inert,
                boundaryObservable: false,
            )],
            new AnalysisCoverage([RelativePath::fromString('src/Foo.php')], [], []),
            1,
        );

        self::assertSame(Command::SUCCESS, self::exitCodeFor($report));

        $observable = new DirectiveAuditReport(
            [new DirectiveVerdict(
                site: new DirectiveSite(RelativePath::fromString('src/Foo.php'), 7, 'threshold', 'complexity.cyclomatic'),
                effect: DirectiveEffect::Inert,
            )],
            new AnalysisCoverage([RelativePath::fromString('src/Foo.php')], [], []),
            1,
        );

        self::assertSame(2, self::exitCodeFor($observable));
    }

    /**
     * A configuration validator's channels are exempt from annotation
     * suppression by the kind of thing they are — no run and no configuration
     * lets a directive silence one. Reporting such a directive as effective
     * tells the author to keep an annotation that provably cannot work.
     *
     * All three are asked, each through a directive that actually produces it:
     * the exemption is declared per channel, so a regression that reinstates
     * suppression for one of them leaves the other two answering correctly.
     * The verdict is half the claim — the finding the directive claimed to
     * silence has to be in the report for `inert` to mean "the suppression
     * failed" rather than "the channel never fired".
     */
    #[Test]
    #[DataProvider('provideConfigurationErrorChannels')]
    public function itDoesNotCallASuppressionOfAConfigurationErrorEffective(
        string $channel,
        string $producer,
    ): void {
        $this->writeSource('Unsuppressable.php', \sprintf(<<<'SOURCE'
            <?php
            /** @qmx-ignore-file %s — claims to silence the error below */

            namespace Fixture;

            final class Unsuppressable
            {
                /** %s */
                public function trivial(): int
                {
                    return 1;
                }
            }
            SOURCE, $channel, $producer));

        $config = $this->writeConfig(self::WITHOUT_COUPLING);

        $report = self::decode($this->audit([
            'paths' => [$this->tempDir . '/src'],
            '--config' => $config,
            '--format' => 'json',
        ])->getDisplay());

        $byTarget = [];
        foreach ($report['directives'] as $directive) {
            $byTarget[$directive['target']] = $directive['effect'];
        }

        self::assertSame('inert', $byTarget[$channel]);

        $reported = self::decode($this->runCheck([
            'paths' => [$this->tempDir . '/src'],
            '--config' => $config,
            '--format' => 'json',
        ])->getDisplay())['violations'];

        self::assertContains($channel, array_column($reported, 'channel'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideConfigurationErrorChannels(): iterable
    {
        yield 'an unresolvable name' => [
            'annotation.unresolved-directive',
            '@qmx-ignore no.such.rule — an unresolvable name',
        ];
        yield 'a rule that declares no override support' => [
            'annotation.unsupported-threshold',
            '@qmx-threshold annotation.directive warning=1 — a rule that supports no override',
        ];
        yield 'an unparsable payload' => [
            'annotation.invalid-threshold',
            '@qmx-threshold complexity.cyclomatic warning=abc — an unparsable payload',
        ];
    }

    /**
     * The channel that reports what a directive did is the one channel a
     * directive may not address. Twelve authored forms reach it — three tags
     * against the exact name, the exact name at the level it reports at, the
     * group that covers it, and that group at the same level — and each is
     * refused where it was written.
     *
     * Both commands are asked about the same fixture, and that is the point of
     * the case rather than a convenience: a form `check` refuses and
     * `directives` still judges would print two complaints about one authored
     * line, which is the shape this ban was written to avoid. The refusal
     * naming the **channel** matters for the group form, whose text does not
     * contain it.
     */
    #[Test]
    #[DataProvider('provideFormsThatReachTheBannedChannel')]
    public function itRefusesEveryDirectiveFormThatReachesTheBannedChannel(string $tag, string $target): void
    {
        [$source, $line] = self::directiveFixture($tag, $target);
        $this->writeSource('Banned.php', $source);
        $config = $this->writeConfig(self::WITHOUT_COUPLING);

        $check = $this->runCheck([
            'paths' => [$this->tempDir . '/src'],
            '--config' => $config,
            '--format' => 'json',
        ]);
        $report = self::decode($check->getDisplay());

        self::assertSame(2, $check->getStatusCode(), $check->getDisplay());
        self::assertCount(1, $report['violations']);
        self::assertSame('annotation.unresolved-directive', $report['violations'][0]['channel']);
        self::assertSame($line, $report['violations'][0]['line']);
        self::assertStringContainsString('annotation.unused-directive', $report['violations'][0]['message']);
        self::assertStringContainsString('which no directive may silence', $report['violations'][0]['message']);

        $audit = $this->audit([
            'paths' => [$this->tempDir . '/src'],
            '--config' => $config,
            '--format' => 'json',
        ]);
        $verdicts = self::decode($audit->getDisplay())['directives'];

        self::assertCount(1, $verdicts);
        self::assertSame('unmeasured', $verdicts[0]['effect']);
        self::assertSame('already-refused', $verdicts[0]['reason']);
        self::assertSame(Command::SUCCESS, $audit->getStatusCode(), $audit->getDisplay());
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideFormsThatReachTheBannedChannel(): iterable
    {
        foreach (['file', 'next-line', 'symbol'] as $tag) {
            foreach ([
                'the exact name' => 'annotation.unused-directive',
                'the exact name at file level' => 'annotation.unused-directive:file',
                'a group that covers it' => 'annotation.*',
                'that group at file level' => 'annotation.*:file',
            ] as $shape => $target) {
                yield $tag . ', ' . $shape => [$tag, $target];
            }
        }
    }

    /**
     * The ban is asked after the `channel:level` grammar, and an author who
     * wrote a level the channel never reports at must read about the level.
     * Asked first, the ban would answer a different mistake than the one made
     * — and the only observation that separates the two orders is this text.
     *
     * The group form is asked too, and not as a variation: it is the form the
     * ban swallows whole, since expanding `annotation.*` reaches the banned
     * channel. Only the exact form was covered, so an order that held for one
     * spelling and not the other would have gone unread.
     */
    #[Test]
    #[DataProvider('provideImpossiblePairsThatCoverTheBannedChannel')]
    public function itAnswersAnImpossiblePairAboutTheLevelRatherThanTheBan(
        string $target,
        string $expected,
    ): void {
        [$source] = self::directiveFixture('file', $target);
        $this->writeSource('Pair.php', $source);

        $report = self::decode($this->runCheck([
            'paths' => [$this->tempDir . '/src'],
            '--config' => $this->writeConfig(self::WITHOUT_COUPLING),
            '--format' => 'json',
        ])->getDisplay());

        self::assertCount(1, $report['violations']);
        self::assertStringContainsString($expected, $report['violations'][0]['message']);
        self::assertStringNotContainsString('no directive may silence', $report['violations'][0]['message']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideImpossiblePairsThatCoverTheBannedChannel(): iterable
    {
        yield 'the exact name' => [
            'annotation.unused-directive:class',
            'it does not report at level "class"',
        ];
        yield 'a group that covers it' => [
            'annotation.*:class',
            'none of them reports at level "class"',
        ];
    }

    /**
     * A directive with no rule filter names no channel, so there is nothing to
     * refuse — and it no longer silences the banned channel either. Its verdict
     * does not move; what moves is the finding, which comes back into the
     * report and is not counted as suppressed.
     */
    #[Test]
    public function itNoLongerLetsAFormWithoutARuleFilterSilenceTheBannedChannel(): void
    {
        $this->writeSource('NoFilter.php', <<<'SOURCE'
            <?php
            // @qmx-ignore-file -- whatever is here

            namespace Fixture;

            final class NoFilter
            {
                /** @qmx-ignore complexity.cyclomatic -- stale: a straight line reaches no boundary */
                public function trivial(): int
                {
                    return 1;
                }
            }
            SOURCE);

        $config = $this->writeConfig(self::WITHOUT_COUPLING);

        $report = self::decode($this->runCheck([
            'paths' => [$this->tempDir . '/src'],
            '--config' => $config,
            '--format' => 'json',
        ])->getDisplay());

        self::assertCount(1, $report['violations']);
        self::assertSame('annotation.unused-directive', $report['violations'][0]['channel']);
        self::assertSame(8, $report['violations'][0]['line']);
        // The severity is what keeps the returning finding out of the exit
        // code: it comes back as the ordinary debt it always was.
        self::assertSame('info', $report['violations'][0]['severity']);

        $suppressed = self::decode($this->runCheck([
            'paths' => [$this->tempDir . '/src'],
            '--config' => $config,
            '--format' => 'suppressed',
            '--show-suppressed' => true,
        ])->getDisplay());

        self::assertSame(0, $suppressed['byMechanism']['suppression']);
        self::assertSame([], $suppressed['suppressed']);

        $verdicts = self::decode($this->audit([
            'paths' => [$this->tempDir . '/src'],
            '--config' => $config,
            '--format' => 'json',
        ])->getDisplay())['directives'];

        $byLine = [];
        foreach ($verdicts as $verdict) {
            $byLine[$verdict['line']] = $verdict['effect'] . ' / ' . ($verdict['reason'] ?? '');
        }

        self::assertSame(
            [2 => 'unmeasured / addresses-every-channel', 8 => 'inert / '],
            $byLine,
        );
    }

    /**
     * A refusal answers one authored line and leaves the rest of the file
     * alone. Every refusal case is a fixture with one directive in it, so a
     * refusal that consumed the file's other verdicts — or that pushed its own
     * complaint onto someone else's line — would have read as correct.
     */
    #[Test]
    public function itRefusesOneDirectiveWithoutTouchingAnotherStaleOneBesideIt(): void
    {
        $this->writeSource('Both.php', <<<'SOURCE'
            <?php
            // @qmx-ignore-file annotation.unused-directive -- refused

            namespace Fixture;

            final class Both
            {
                /** @qmx-ignore complexity.cyclomatic -- stale: a straight line reaches no boundary */
                public function trivial(): int
                {
                    return 1;
                }
            }
            SOURCE);

        $config = $this->writeConfig(self::WITHOUT_COUPLING);

        $check = $this->runCheck([
            'paths' => [$this->tempDir . '/src'],
            '--config' => $config,
            '--format' => 'json',
        ]);
        $violations = self::decode($check->getDisplay())['violations'];

        self::assertSame(2, $check->getStatusCode(), $check->getDisplay());
        self::assertCount(2, $violations);

        $byLine = [];
        foreach ($violations as $violation) {
            $byLine[$violation['line']] = $violation['channel'];
        }

        self::assertSame(
            [2 => 'annotation.unresolved-directive', 8 => 'annotation.unused-directive'],
            $byLine,
        );
        self::assertStringContainsString(
            'which no directive may silence',
            (string) $violations[array_search(2, array_column($violations, 'line'), true)]['message'],
        );

        $audit = $this->audit([
            'paths' => [$this->tempDir . '/src'],
            '--config' => $config,
            '--format' => 'json',
        ]);
        $verdicts = self::decode($audit->getDisplay())['directives'];

        $byVerdictLine = [];
        foreach ($verdicts as $verdict) {
            $byVerdictLine[$verdict['line']] = $verdict['effect'] . ' / ' . ($verdict['reason'] ?? '');
        }

        self::assertSame(
            [2 => 'unmeasured / already-refused', 8 => 'inert / '],
            $byVerdictLine,
        );
        self::assertSame(2, $audit->getStatusCode(), $audit->getDisplay());
    }

    /**
     * The ban withdraws one mechanism from the channel and grants it no
     * exemption from any other. A finding on it is ordinary debt: the path
     * exclusions drop it and a baseline accepts it, exactly as before.
     *
     * The configuration errors are the precedent this case rules out. Those
     * are lifted out of the pipeline before the suppression stage and rejoin
     * the report at the very end, and reinstating that arrangement here would
     * silently take the channel out of both `suppress_paths` and the ratchet.
     */
    #[Test]
    public function itLeavesTheBannedChannelInsideEveryStageAfterSuppression(): void
    {
        $this->writeSource('Debt.php', <<<'SOURCE'
            <?php

            namespace Fixture;

            final class Debt
            {
                /** @qmx-ignore complexity.cyclomatic -- stale: a straight line reaches no boundary */
                public function trivial(): int
                {
                    return 1;
                }
            }
            SOURCE);

        $excluded = self::decode($this->runCheck([
            'paths' => [$this->tempDir . '/src'],
            '--config' => $this->writeConfig(self::WITHOUT_COUPLING . "suppress_paths: ['*Debt.php']\n"),
            '--format' => 'json',
        ])->getDisplay());

        self::assertSame([], $excluded['violations']);

        $baseline = $this->tempDir . '/baseline.json';
        $config = $this->writeConfig(self::WITHOUT_COUPLING);
        $container = (new ContainerFactory())->create();
        $generate = $container->get(BaselineGenerateCommand::class);
        self::assertInstanceOf(BaselineGenerateCommand::class, $generate);

        $generator = new CommandTester($generate);
        $generator->execute([
            'baseline' => $baseline,
            'paths' => [$this->tempDir . '/src'],
            '--config' => $config,
        ]);
        self::assertSame(0, $generator->getStatusCode(), $generator->getDisplay());

        $captured = json_decode((string) file_get_contents($baseline), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($captured);
        self::assertStringContainsString('annotation.unused-directive', (string) file_get_contents($baseline));

        $accepted = $this->runCheck([
            'paths' => [$this->tempDir . '/src'],
            '--config' => $config,
            '--baseline' => $baseline,
            '--format' => 'json',
        ]);

        self::assertSame([], self::decode($accepted->getDisplay())['violations']);
    }

    /**
     * One fixture, three lines, thirteen characters of difference: the tag.
     *
     * @return array{string, int} the source and the line the directive sits on
     */
    private static function directiveFixture(string $tag, string $target): array
    {
        $body = <<<SOURCE
            <?php
            %s
            namespace Fixture;

            final class Banned
            {
            %s
                public function trivial(): int
                {
                    return 1;
                }
            }
            SOURCE;

        return match ($tag) {
            'file' => [\sprintf($body, "// @qmx-ignore-file {$target} -- tested\n", ''), 2],
            'next-line' => [\sprintf($body, '', "    // @qmx-ignore-next-line {$target} -- tested"), 7],
            'symbol' => [\sprintf($body, '', "    /** @qmx-ignore {$target} -- tested */"), 7],
            default => throw new LogicException('unknown tag ' . $tag),
        };
    }

    /**
     * `@generated` files are discovered and then skipped, so a scope of nothing
     * else discovers files while measuring none — the fourth way to read zero,
     * and the one a guard on the discovered count lets through.
     */
    #[Test]
    public function itRefusesAScopeOfNothingButGeneratedFiles(): void
    {
        $this->writeSource('Generated.php', <<<'SOURCE'
            <?php

            /** @generated by a fixture */

            namespace Fixture;

            final class Generated
            {
                public function trivial(): int
                {
                    return 1;
                }
            }
            SOURCE);

        $tester = $this->audit(['paths' => [$this->tempDir . '/src']]);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('analysed no PHP files', $tester->getDisplay());
    }

    /** A run that measured nothing has no standing to call a tree clean. */
    #[Test]
    public function itRefusesAScopeThatAnalysedNoFiles(): void
    {
        $tester = $this->audit(['paths' => [$this->tempDir . '/src']]);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('analysed no PHP files', $tester->getDisplay());
    }

    /**
     * A run that could not read part of the tree has not earned the right to
     * call anything dead — even when it did find something inert in the part it
     * could read.
     */
    #[Test]
    public function itExitsFourWhenTheRunCouldNotParsePartOfTheTree(): void
    {
        $this->writeSource('Dead.php', self::sevenParameterMethod(
            '@qmx-threshold complexity.cyclomatic warning=50 error=80 — dead',
        ));
        file_put_contents($this->tempDir . '/src/Broken.php', "<?php\n\nclass {{{ Broken\n");

        $tester = $this->audit(['paths' => [$this->tempDir . '/src']]);

        self::assertSame(4, $tester->getStatusCode());
        self::assertStringContainsString('no directive can be called dead by this run', $tester->getDisplay());
    }

    #[Test]
    public function itRefusesAnUnknownFormat(): void
    {
        $tester = $this->audit(['paths' => [$this->tempDir . '/src'], '--format' => 'yaml']);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('Unknown format "yaml"', $tester->getDisplay());
    }

    /** No `--sweep` at all must run exactly what `--sweep=narrow` runs, not a third scope. */
    #[Test]
    public function itDefaultsToTheNarrowSweep(): void
    {
        $this->writeSource('Live.php', self::sevenParameterMethod(
            '@qmx-threshold code-smell.long-parameter-list warning=9 error=12 — live',
        ));

        $report = self::decode($this->audit([
            'paths' => [$this->tempDir . '/src'],
            '--format' => 'json',
        ])->getDisplay());

        self::assertSame('narrow', $report['sweep']);
    }

    #[Test]
    public function itAcceptsAnExplicitFullSweep(): void
    {
        $this->writeSource('Live.php', self::sevenParameterMethod(
            '@qmx-threshold code-smell.long-parameter-list warning=9 error=12 — live',
        ));

        $report = self::decode($this->audit([
            'paths' => [$this->tempDir . '/src'],
            '--format' => 'json',
            '--sweep' => 'full',
        ])->getDisplay());

        self::assertSame('full', $report['sweep']);
        self::assertSame('effective', $report['directives'][0]['effect']);
    }

    /**
     * The sweep field is printed on every run, not only the expensive one — a
     * reader comparing two reports must be able to see which measurement each
     * came from without re-running the command.
     */
    #[Test]
    public function itPrintsTheSweepScopeInBothFormats(): void
    {
        $this->writeSource('Live.php', self::sevenParameterMethod(
            '@qmx-threshold code-smell.long-parameter-list warning=9 error=12 — live',
        ));

        $text = $this->audit([
            'paths' => [$this->tempDir . '/src'],
            '--sweep' => 'full',
        ])->getDisplay();
        $json = self::decode($this->audit([
            'paths' => [$this->tempDir . '/src'],
            '--format' => 'json',
            '--sweep' => 'full',
        ])->getDisplay());

        self::assertSame('full', $json['sweep']);
        self::assertStringContainsString(
            'full — each directive is judged by re-executing every enabled rule',
            $text,
        );
    }

    /**
     * A caller who mistypes `--sweep` has asked for a measurement this command
     * cannot make. Both formats must refuse before running anything, exactly as
     * an unknown `--format` does.
     */
    #[Test]
    public function itRefusesAnUnknownSweep(): void
    {
        $tester = $this->audit(['paths' => [$this->tempDir . '/src'], '--sweep' => 'quick']);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('Unknown sweep "quick"', $tester->getDisplay());
        self::assertStringContainsString('narrow', $tester->getDisplay());
        self::assertStringContainsString('full', $tester->getDisplay());
    }

    #[Test]
    public function itRefusesAnUnknownSweepInJson(): void
    {
        $tester = $this->audit([
            'paths' => [$this->tempDir . '/src'],
            '--sweep' => 'quick',
            '--format' => 'json',
        ]);

        $decoded = self::decode($tester->getDisplay());
        self::assertSame(3, $tester->getStatusCode());
        self::assertSame(3, $decoded['exit_code']);
        self::assertStringContainsString('Unknown sweep "quick"', $decoded['error']);
    }

    #[Test]
    public function itReportsAnUnreadableConfigAsAConfigurationError(): void
    {
        $tester = $this->audit([
            'paths' => [$this->tempDir . '/src'],
            '--config' => $this->tempDir . '/does-not-exist.yaml',
        ]);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('Configuration error', $tester->getDisplay());
    }

    #[Test]
    public function itPrintsTheErrorEnvelopeInJson(): void
    {
        $tester = $this->audit([
            'paths' => [$this->tempDir . '/src'],
            '--config' => $this->tempDir . '/does-not-exist.yaml',
            '--format' => 'json',
        ]);

        $decoded = self::decode($tester->getDisplay());
        self::assertStringContainsString('Configuration error', $decoded['error']);
        self::assertSame(3, $decoded['exit_code']);
    }

    /** Two projections of one measurement cannot be allowed to say different things. */
    #[Test]
    public function itSaysTheSameThingInBothFormats(): void
    {
        $this->writeSource('Live.php', self::sevenParameterMethod(
            '@qmx-threshold code-smell.long-parameter-list warning=9 error=12 — live',
        ));
        $this->writeSource('Dead.php', self::sevenParameterMethod(
            '@qmx-threshold complexity.cyclomatic warning=50 error=80 — dead',
        ));

        $text = $this->audit(['paths' => [$this->tempDir . '/src']])->getDisplay();
        $json = self::decode($this->audit([
            'paths' => [$this->tempDir . '/src'],
            '--format' => 'json',
        ])->getDisplay());

        self::assertSame(2, $json['summary']['total']);
        self::assertSame(1, $json['summary']['effective']);
        self::assertSame(1, $json['summary']['inert']);
        self::assertStringContainsString('2 directive(s): 1 effective, 0 applied-boundary-only, 1 inert, 0 unmeasured', $text);
        foreach ($json['directives'] as $directive) {
            self::assertStringContainsString(
                \sprintf('%s:%d', $directive['file'], $directive['line']),
                $text,
            );
        }
    }

    /**
     * The audited file set must be the one an analysis of the same
     * configuration would have measured. Dropping the discovery the command
     * resolves — the plausible simplification — silently audits a wider tree
     * than the project analyses, and a verdict is relative to the tree.
     */
    #[Test]
    public function itAnalysesTheSameFilesAsCheckUnderTheSameExcludes(): void
    {
        $this->writeSource('Kept.php', self::sevenParameterMethod(
            '@qmx-threshold code-smell.long-parameter-list warning=9 error=12 — live',
        ));
        mkdir($this->tempDir . '/src/generated', 0o755, true);
        file_put_contents(
            $this->tempDir . '/src/generated/Skipped.php',
            self::sevenParameterMethod('@qmx-threshold complexity.cyclomatic warning=50 error=80 — dead', 'Skipped'),
        );
        $config = $this->writeConfig("exclude: ['generated']\n");

        $audit = self::decode($this->audit([
            'paths' => [$this->tempDir . '/src'],
            '--config' => $config,
            '--format' => 'json',
        ])->getDisplay());

        $check = self::decode($this->runCheck([
            'paths' => [$this->tempDir . '/src'],
            '--config' => $config,
            '--format' => 'json',
        ])->getDisplay());

        self::assertSame($check['coverage']['analyzed'], $audit['scope']['analyzed_files']);
        self::assertSame(1, $audit['scope']['analyzed_files']);
        self::assertCount(1, $audit['directives']);
        self::assertStringNotContainsString('generated', $audit['directives'][0]['file']);
    }

    /**
     * Switching the directive rule off silences its channels. It must not
     * silence the audit's own answer about suppressions, which would read as
     * "this tree carries no annotations" beside real threshold verdicts.
     */
    #[Test]
    public function itStillJudgesSuppressionsWhenTheDirectiveRuleIsDisabled(): void
    {
        $this->writeSource('Ignored.php', <<<'SOURCE'
            <?php

            namespace Fixture;

            final class Ignored
            {
                /** @qmx-ignore complexity.cyclomatic — nothing to silence */
                public function trivial(): int
                {
                    return 1;
                }
            }
            SOURCE);
        $config = $this->writeConfig("disabled_rules: ['annotation.directive']\n");

        $report = self::decode($this->audit([
            'paths' => [$this->tempDir . '/src'],
            '--config' => $config,
            '--format' => 'json',
        ])->getDisplay());

        self::assertCount(1, $report['directives']);
        self::assertSame('symbol', $report['directives'][0]['form']);
        self::assertSame(['annotation.directive'], $report['selection']['disabled']);
    }

    /**
     * Every run gets an explicit configuration document, and that is not
     * tidiness: without `--config` the resolver picks up the repository's own
     * `qmx.yaml` from the working directory, and the fixtures below would be
     * judged against this project's tuned boundaries instead of the defaults
     * they were written for.
     *
     * @param array<string, mixed> $input
     */
    private function audit(array $input): CommandTester
    {
        $input['--config'] ??= $this->writeConfig("paths: []\n");

        $container = (new ContainerFactory())->create();
        $command = $container->get(DirectivesCommand::class);
        self::assertInstanceOf(DirectivesCommand::class, $command);

        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    /**
     * The exit-code rule alone, reached reflectively. Two of its branches —
     * an unobservable boundary and an incomplete run — need a report shape a
     * fixture cannot produce on demand, and the rule is exactly where the
     * question "does this verdict deserve a red build" is answered.
     */
    private static function exitCodeFor(DirectiveAuditReport $report): int
    {
        $method = new ReflectionMethod(DirectivesCommand::class, 'exitCodeFor');

        $exitCode = $method->invoke(null, $report);
        self::assertIsInt($exitCode);

        return $exitCode;
    }

    /** @param array<string, mixed> $input */
    private function runCheck(array $input): CommandTester
    {
        $input['--config'] ??= $this->writeConfig("paths: []\n");

        $container = (new ContainerFactory())->create();
        $command = $container->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $command);

        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    private function writeSource(string $name, string $source): void
    {
        file_put_contents($this->tempDir . '/src/' . $name, $source);
    }

    private function writeConfig(string $body): string
    {
        $path = $this->tempDir . '/qmx-' . bin2hex(random_bytes(6)) . '.yaml';
        file_put_contents($path, $body);

        return $path;
    }

    /**
     * A directive on a rule its configuration switched off is not the author's
     * mistake, and the command must not demand its removal.
     *
     * Three configurations, one directive, and the reason they belong in one
     * test: before this was fixed the flat one already answered correctly
     * while both per-level ones answered `Inert` on exit code 2, because the
     * answer was re-derived from the top-level `enabled` key alone. Keeping
     * them side by side is what makes a regression visible as a disagreement
     * between rows rather than as one changed string.
     */
    #[Test]
    #[DataProvider('provideDisablingConfigurations')]
    public function itLeavesADirectiveUnmeasuredWhenItsRuleIsSwitchedOff(string $rules): void
    {
        $this->writeSource('Branchy.php', self::branchyMethod(
            '@qmx-threshold complexity.cyclomatic warning=1 error=2 — measured against a switched-off rule',
        ));

        $tester = $this->audit([
            'paths' => [$this->tempDir . '/src'],
            '--config' => $this->writeConfig("paths: []\nrules:\n" . $rules),
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString(
            'unmeasured: the producer of the addressed channel did not run.',
            $tester->getDisplay(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDisablingConfigurations(): iterable
    {
        yield 'the whole rule' => ["  complexity.cyclomatic:\n    enabled: false\n"];

        yield 'every level of it' => [
            "  complexity.cyclomatic:\n    callable:\n      enabled: false\n    class:\n      enabled: false\n",
        ];

        yield 'the level the directive sits on' => [
            "  complexity.cyclomatic:\n    callable:\n      enabled: false\n    class:\n      enabled: true\n",
        ];
    }

    /**
     * The other side of the same answer: a producer that does not report at
     * the directive's level was never switched off, and calling it disabled
     * would answer a question the directive did not ask. `coupling.cbo`
     * reports at class and namespace level, so a directive on a method is
     * simply inert.
     */
    #[Test]
    public function itDoesNotCallAProducerDisabledAtALevelItNeverReportsAt(): void
    {
        $this->writeSource('Branchy.php', self::branchyMethod(
            '@qmx-threshold coupling.cbo warning=1 error=2 — a level this rule never reports at',
        ));

        $tester = $this->audit(['paths' => [$this->tempDir . '/src']]);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('inert: removing it changes nothing.', $tester->getDisplay());
    }

    /** A method whose branches a cyclomatic boundary of 1/2 reports. */
    private static function branchyMethod(string $directive): string
    {
        return <<<PHP
            <?php

            namespace Fixture;

            class Branchy
            {
                /**
                 * {$directive}
                 */
                public function decide(int \$a, int \$b): int
                {
                    if (\$a > 0) { return 1; }
                    if (\$b > 0) { return 2; }
                    if (\$a > \$b) { return 3; }

                    return 4;
                }
            }
            PHP;
    }

    /**
     * Seven parameters and a straight line: the long-parameter-list boundaries
     * report it, and no complexity boundary ever will.
     */
    private static function sevenParameterMethod(string $directive, string $class = 'Fixture'): string
    {
        return <<<SOURCE
            <?php

            namespace Fixture;

            final class {$class}
            {
                /**
                 * {$directive}
                 */
                public function configure(
                    string \$one,
                    string \$two,
                    string \$three,
                    string \$four,
                    string \$five,
                    string \$six,
                    string \$seven,
                ): string {
                    return \$one . \$two . \$three . \$four . \$five . \$six . \$seven;
                }
            }
            SOURCE;
    }

    /** @return array<string, mixed> */
    private static function decode(string $display): array
    {
        $decoded = json_decode($display, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveEffect;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSite;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdict;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DirectiveAuditReport;
use Qualimetrix\Core\Path\RelativePath;
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
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx-directives-' . uniqid();
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
     * `annotation.unused-directive` is assembled after rule execution rather
     * than inside it, so a universe taken from the executor alone cannot
     * contain it — and every suppression aimed at it comes out inert while its
     * removal demonstrably adds findings to a `check` of the same tree.
     */
    #[Test]
    public function itJudgesASuppressionOfTheChannelProducedAfterRuleExecution(): void
    {
        $this->writeSource('Late.php', <<<'SOURCE'
            <?php
            /** @qmx-ignore-file annotation.unused-directive — hides the stale directive below */

            namespace Fixture;

            final class Late
            {
                /** @qmx-ignore complexity.cyclomatic — stale: a straight line reaches no boundary */
                public function trivial(): int
                {
                    return 1;
                }
            }
            SOURCE);

        $report = self::decode($this->audit([
            'paths' => [$this->tempDir . '/src'],
            '--format' => 'json',
        ])->getDisplay());

        $byTarget = [];
        foreach ($report['directives'] as $directive) {
            $byTarget[$directive['target']] = $directive['effect'];
        }

        self::assertSame(
            ['annotation.unused-directive' => 'effective', 'complexity.cyclomatic' => 'inert'],
            $byTarget,
        );
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
     * The directive that produces the complaint about itself must not be
     * credited with silencing it. A file-scoped suppression of the staleness
     * channel covers every line of its file, its own included, so leaving that
     * finding in the universe made the annotation prove itself alive — while
     * `check` reports the same tree identically with and without it.
     */
    #[Test]
    public function itDoesNotLetADirectiveJustifyItselfWithItsOwnComplaint(): void
    {
        $this->writeSource('SelfJustifying.php', <<<'SOURCE'
            <?php
            /** @qmx-ignore-file annotation.unused-directive — the only annotation in this file */

            namespace Fixture;

            final class SelfJustifying
            {
                public function trivial(): int
                {
                    return 1;
                }
            }
            SOURCE);

        $report = self::decode($this->audit([
            'paths' => [$this->tempDir . '/src'],
            '--format' => 'json',
        ])->getDisplay());

        self::assertSame('inert', $report['directives'][0]['effect']);
    }

    /**
     * A configuration validator's channels are exempt from annotation
     * suppression by the kind of thing they are — no run and no configuration
     * lets a directive silence one. Reporting such a directive as effective
     * tells the author to keep an annotation that provably cannot work.
     */
    #[Test]
    public function itDoesNotCallASuppressionOfAConfigurationErrorEffective(): void
    {
        $this->writeSource('Unsuppressable.php', <<<'SOURCE'
            <?php
            /** @qmx-ignore-file annotation.unresolved-directive — claims to silence the error below */

            namespace Fixture;

            final class Unsuppressable
            {
                /** @qmx-ignore no.such.rule — an unresolvable name */
                public function trivial(): int
                {
                    return 1;
                }
            }
            SOURCE);

        $report = self::decode($this->audit([
            'paths' => [$this->tempDir . '/src'],
            '--format' => 'json',
        ])->getDisplay());

        $byTarget = [];
        foreach ($report['directives'] as $directive) {
            $byTarget[$directive['target']] = $directive['effect'];
        }

        self::assertSame('inert', $byTarget['annotation.unresolved-directive']);
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
        $path = $this->tempDir . '/qmx-' . uniqid() . '.yaml';
        file_put_contents($path, $body);

        return $path;
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

<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Integration\Inheritance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\DitGlobalCollector;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\UnreadChainTally;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * What a user is told when DIT could not read a chain to its end.
 *
 * The collector's own unit tests answer the same questions against a fixed
 * parent source. They cannot answer this one: whether the sentence reaches a
 * person. That depends on the composer adapter placing files, on the container
 * binding a logger the collector would otherwise not receive, and on the
 * diagnostic stream surviving a machine-readable format -- three things no unit
 * test touches. So these cases run the real binary and read its stderr.
 *
 * The finding-gate corpus cannot carry them. A case that speaks produces a
 * `stderr:<surface>` artifact the reference side does not have, and the gate
 * records a one-sided key as a mismatch before it reaches any declaration,
 * which no declared delta can express and which would empty the whole derive
 * run. These shapes live here instead, and the gate keeps the job it is good
 * at -- proving no published number moved.
 *
 * Each shape is built rather than committed as a fixture, because two of them
 * are defined by what is *absent* from an install, and an absence is not a file
 * anyone can check in.
 */
#[CoversClass(DitGlobalCollector::class)]
#[CoversClass(UnreadChainTally::class)]
final class UnreadAncestryDiagnosticRunTest extends TestCase
{
    private string $workingDirectory;

    protected function setUp(): void
    {
        $this->workingDirectory = \sprintf(
            '%s/qmx_unread_ancestry_%s',
            sys_get_temp_dir(),
            bin2hex(random_bytes(6)),
        );

        if (!mkdir($this->workingDirectory . '/src', 0o777, true)) {
            throw new RuntimeException('Failed to create the working directory');
        }
    }

    protected function tearDown(): void
    {
        self::removeTree($this->workingDirectory);
    }

    /**
     * With no install, the run cannot name a class, because it never placed
     * one. The fixture has to carry a class with an external parent all the
     * same: four branches of the depth walk return before anything outside the
     * analysed path is consulted, so a tree without one asks nothing, observes
     * nothing, and would pass this case for the wrong reason.
     */
    #[Test]
    public function itSaysTheRunHadNoInstallToReadThrough(): void
    {
        $this->writeAnalysedClass('Vendor\\Absent\\Base');

        $stderr = $this->runBinary();

        self::assertStringContainsString('were not followed to a root', $stderr);
        self::assertStringContainsString('no composer install', $stderr);
        self::assertStringNotContainsString('Vendor\\Absent\\Base', $stderr);
    }

    /**
     * An install the run could read, which simply has no entry for the name.
     * This is the state the campaign had to keep separable from the one above:
     * the same source, a different answer about what this run could see.
     */
    #[Test]
    public function itNamesTheClassWhenAnInstallExistsButDoesNotCarryIt(): void
    {
        $this->writeAnalysedClass('Vendor\\Absent\\Base');
        $this->writeRootManifest();

        $stderr = $this->runBinary();

        self::assertStringContainsString('the walk stopped at: Vendor\\Absent\\Base', $stderr);
        self::assertStringNotContainsString('no composer install', $stderr);
    }

    /**
     * The break that is not at the first link: a package the install carries
     * whose own parent's package it does not. The depth published for the local
     * class is 2 rather than 1, so this is also the shape that shows the number
     * stopping short rather than being absent.
     */
    #[Test]
    public function itReportsABreakDeeperThanTheFirstLink(): void
    {
        $this->writeAnalysedClass('Acme\\Mid\\Middle');
        $this->writeRootManifest();
        $this->writeInstalledPackage();

        $stderr = $this->runBinary();

        self::assertStringContainsString('the walk stopped at: Acme\\Far\\Faraway', $stderr);
        self::assertStringNotContainsString('Acme\\Mid\\Middle', $stderr);
    }

    /**
     * Silence is the common case and has to be observable, or every assertion
     * above would also pass on a collector that shouted unconditionally.
     */
    #[Test]
    public function itSaysNothingWhenTheChainReachesARoot(): void
    {
        $this->writeAnalysedClass('Acme\\Mid\\Middle');
        $this->writeRootManifest();
        $this->writeInstalledPackage(parent: null);

        self::assertStringNotContainsString('were not followed to a root', $this->runBinary());
    }

    #[Test]
    public function itReadsACompleteAncestryFromANestedComposerClassmap(): void
    {
        $this->writeAnalysedClass('Acme\\Nested\\ParentClass');
        $this->writeRootManifest('build/dependencies');
        $this->write('/build/dependencies/acme/nested/src/ParentClass.php', <<<'PHP'
            <?php

            namespace Acme\Nested;

            class ParentClass extends \Probe\External\BaseClass {}
            PHP);
        $this->write('/external/BaseClass.php', <<<'PHP'
            <?php

            namespace Probe\External;

            class BaseClass {}
            PHP);
        $this->write('/build/dependencies/composer/autoload_classmap.php', <<<'PHP'
            <?php

            $vendorDir = dirname(__DIR__);
            $baseDir = dirname($vendorDir, 2);

            return array(
                'Acme\Nested\ParentClass' => $vendorDir . '/acme/nested/src/ParentClass.php',
                'Probe\External\BaseClass' => $baseDir . '/external/BaseClass.php',
            );
            PHP);

        $run = $this->runBinaryReport(['--dit-warning=0', '--dit-error=999']);

        self::assertSame(0, $run['exitCode']);
        self::assertCompleteCoverage($run['report']);
        self::assertSame(2, self::ditValue($run['report']));
        self::assertStringNotContainsString('were not followed to a root', $run['stderr']);
    }

    #[Test]
    public function itReportsAnUnreadChainWhenAnExternalFilesAliasesCollide(): void
    {
        $this->writeAnalysedClass('Acme\\Broken\\ParentClass');
        $this->writeRootManifest();
        $this->write('/vendor/acme/broken/src/ParentClass.php', <<<'PHP'
            <?php

            namespace Acme\Broken;

            use First\Package\BaseClass as ImportedBase;
            use Second\Package\BaseClass as ImportedBase;

            class ParentClass extends ImportedBase {}
            PHP);
        $this->write('/vendor/composer/installed.json', json_encode([
            'packages' => [[
                'name' => 'acme/broken',
                'version' => '1.0.0',
                'install-path' => '../acme/broken',
                'autoload' => ['psr-4' => ['Acme\\Broken\\' => 'src/']],
            ]],
            'dev' => false,
        ], \JSON_THROW_ON_ERROR));

        $run = $this->runBinaryReport(['--dit-warning=0', '--dit-error=999']);

        self::assertSame(0, $run['exitCode']);
        self::assertCompleteCoverage($run['report']);
        self::assertSame(1, self::ditValue($run['report']));
        self::assertStringContainsString('the walk stopped at: Acme\\Broken\\ParentClass', $run['stderr']);
        self::assertStringNotContainsString('Internal error', $run['stderr']);
    }

    /**
     * `baseline:generate` is the command that most needs the sentence: it
     * writes the depths into a file that outlives the run and gets diffed
     * later. It hears the diagnostic for a reason that is easy to lose --
     * not because it publishes DIT, but because it passes through the same
     * `RuntimeConfigurator::configure()` that installs the run's logger. A
     * future rewrite of that path would take the sentence away from this
     * command alone, silently, which is what this case is here to refuse.
     */
    #[Test]
    public function itSaysTheSameThingWhileGeneratingABaseline(): void
    {
        $this->writeAnalysedClass('Vendor\\Absent\\Base');
        $this->writeRootManifest();

        $process = new Process([
            \PHP_BINARY,
            \dirname(__DIR__, 6) . '/bin/qmx',
            'baseline:generate',
            'baseline.json',
            'src',
            '--no-progress',
        ], $this->workingDirectory);
        $process->run();

        self::assertFileExists($this->workingDirectory . '/baseline.json');
        self::assertStringContainsString('were not followed to a root', $process->getErrorOutput());
    }

    private function writeAnalysedClass(string $parentFqcn): void
    {
        $this->write('/src/Leaf.php', \sprintf(
            "<?php\n\nnamespace Probe\\App;\n\nclass Leaf extends \\%s\n{\n}\n",
            $parentFqcn,
        ));
    }

    private function writeRootManifest(?string $vendorDirectory = null): void
    {
        $manifest = [
            'name' => 'probe/unread-ancestry',
            'autoload' => ['psr-4' => ['Probe\\App\\' => 'src/']],
        ];

        if ($vendorDirectory !== null) {
            $manifest['config'] = ['vendor-dir' => $vendorDirectory];
        }

        $this->write('/composer.json', json_encode($manifest, \JSON_THROW_ON_ERROR));
    }

    /**
     * One installed package whose class either extends something the install
     * does not carry, or nothing at all.
     */
    private function writeInstalledPackage(?string $parent = 'Acme\\Far\\Faraway'): void
    {
        $this->write('/vendor/acme/mid/src/Middle.php', \sprintf(
            "<?php\n\nnamespace Acme\\Mid;\n\nclass Middle%s\n{\n}\n",
            $parent === null ? '' : ' extends \\' . $parent,
        ));

        $this->write('/vendor/composer/installed.json', json_encode([
            'packages' => [[
                'name' => 'acme/mid',
                'version' => '1.0.0',
                'install-path' => '../acme/mid',
                'autoload' => ['psr-4' => ['Acme\\Mid\\' => 'src/']],
            ]],
            'dev' => false,
        ], \JSON_THROW_ON_ERROR));
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->workingDirectory . $relative;
        $directory = \dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException('Failed to create ' . $directory);
        }

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Failed to write ' . $path);
        }
    }

    /**
     * The report goes to stdout and the diagnostic to stderr, which is the
     * separation this channel depends on: `--format=json` has to stay
     * machine-readable while the sentence still reaches a person.
     */
    private function runBinary(): string
    {
        return $this->runBinaryReport()['stderr'];
    }

    /**
     * @param list<string> $extraArguments
     *
     * @return array{report: array<string, mixed>, stderr: string, exitCode: int|null}
     */
    private function runBinaryReport(array $extraArguments = []): array
    {
        $process = new Process([
            \PHP_BINARY,
            \dirname(__DIR__, 6) . '/bin/qmx',
            'check',
            'src',
            '--workers=0',
            '--no-cache',
            '--no-progress',
            '--format=json',
            '--fail-on=none',
            ...$extraArguments,
        ], $this->workingDirectory);
        $process->run();

        /** @var array<string, mixed> $report */
        $report = json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('error', $report, 'The run ended with an internal error');

        return [
            'report' => $report,
            'stderr' => $process->getErrorOutput(),
            'exitCode' => $process->getExitCode(),
        ];
    }

    /** @param array<string, mixed> $report */
    private static function assertCompleteCoverage(array $report): void
    {
        $coverage = $report['coverage'] ?? null;

        self::assertIsArray($coverage);
        self::assertSame(true, $coverage['complete'] ?? null);
        self::assertSame(1, $coverage['analyzed'] ?? null);
        self::assertSame(0, $coverage['failed'] ?? null);
    }

    /** @param array<string, mixed> $report */
    private static function ditValue(array $report): int
    {
        $violations = $report['violations'] ?? null;
        self::assertIsArray($violations);

        foreach ($violations as $violation) {
            if (!\is_array($violation) || ($violation['rule'] ?? null) !== 'design.dit') {
                continue;
            }

            $value = $violation['metricValue'] ?? null;
            self::assertIsInt($value);

            return $value;
        }

        self::fail('The report did not contain a design.dit finding');
    }

    private static function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }
        }

        rmdir($path);
    }
}

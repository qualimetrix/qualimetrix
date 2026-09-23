<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Input/configuration errors must surface as exit code 3 ("input/config error"),
 * not as exit code 1 ("Unexpected error"). The documented contract reserves 3
 * for a family of user-fixable inputs that fail safely, and this class pins the
 * five that were previously misclassified as generic failures.
 *
 * Every case here runs under `--format=json`: a machine-readable format must
 * never pair exit code 3 with zero bytes of parseable stdout.
 */
final class CheckCommandConfigErrorExitCodeTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx-config-error-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->tempDir);
    }

    #[Test]
    public function itClassifiesInvalidComputedMetricFormulaAsConfigError(): void
    {
        $config = $this->writeFile('qmx.yaml', <<<'YAML'
            computed_metrics:
              computed.bad:
                formula: 'loc +* 2'
                levels: [namespace]
            YAML);

        $tester = $this->runCheck([
            '--format' => 'json',
            '--config' => $config,
        ]);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('Invalid formula syntax', self::envelopeError($tester));
    }

    #[Test]
    public function itClassifiesUnknownComputedMetricReferenceAsConfigError(): void
    {
        $config = $this->writeFile('qmx.yaml', <<<'YAML'
            computed_metrics:
              computed.ref:
                formula: 'm["computed.nonexistent"] + 1'
                levels: [namespace]
            YAML);

        $tester = $this->runCheck([
            '--format' => 'json',
            '--config' => $config,
        ]);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('references unknown metric', self::envelopeError($tester));
    }

    /**
     * The envelope's `position` promises what the throw site located, no more:
     * a merged value carries none even though its sentence names the key, and
     * a required key the author left out is where the path ends.
     */
    #[Test]
    public function itPublishesOnlyThePositionItsThrowSiteLocated(): void
    {
        $merged = $this->runCheck([
            '--format' => 'json',
            '--config' => $this->writeFile('merged.yaml', "memory_limit: 010M\n"),
        ]);
        $missing = $this->runCheck([
            '--format' => 'json',
            '--config' => $this->writeFile('missing.yaml', "computed_metrics:\n  computed.foo: ~\n"),
        ]);

        self::assertSame(3, $merged->getStatusCode());
        self::assertStringContainsString('memory_limit', self::envelopeError($merged));
        self::assertNull(self::envelope($merged)['position']);
        self::assertSame(3, $missing->getStatusCode());
        self::assertSame(
            ['path' => ['computed_metrics', 'computed.foo', 'formulas', 'namespace'], 'written' => 'namespace', 'accepted' => [], 'closed' => false],
            self::envelope($missing)['position'],
        );
    }

    #[Test]
    public function itClassifiesCorruptBaselineJsonAsConfigError(): void
    {
        $baseline = $this->writeFile('baseline.json', '{ not json');

        $tester = $this->runCheck([
            '--format' => 'json',
            '--baseline' => $baseline,
        ]);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('Invalid JSON in baseline file', self::envelopeError($tester));
    }

    #[Test]
    public function itClassifiesBaselineVersionTenAsConfigError(): void
    {
        $baseline = $this->writeFile('baseline.json', '{"version": 10}');

        $tester = $this->runCheck([
            '--format' => 'json',
            '--baseline' => $baseline,
        ]);

        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('Baseline version 10 cannot be converted', self::envelopeError($tester));
    }

    #[Test]
    public function itClassifiesGitReportOutsideRepositoryAsConfigError(): void
    {
        file_put_contents($this->tempDir . '/File.php', "<?php\n");

        $originalWorkingDirectory = getcwd();
        self::assertNotFalse($originalWorkingDirectory);
        self::assertTrue(chdir($this->tempDir), 'Unable to enter the fixture working directory');

        try {
            $tester = $this->runCheck([
                'paths' => [$this->tempDir],
                '--format' => 'json',
                '--report' => 'git:staged',
            ]);

            self::assertSame(3, $tester->getStatusCode());
            self::assertStringContainsString('Not inside a git repository', self::envelopeError($tester));
        } finally {
            chdir($originalWorkingDirectory);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runCheck(array $options): CommandTester
    {
        $container = (new ContainerFactory())->create();
        /** @var CheckCommand $command */
        $command = $container->get(CheckCommand::class);
        /** @var RefusalPresenter $refusalPresenter */
        $refusalPresenter = $container->get(RefusalPresenter::class);
        $application = new Application(new ErrorStream(), $refusalPresenter);
        $application->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute([
            'paths' => ['tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php'],
            '--format' => 'json',
            '--disable-rule' => ['computed', 'health.*', 'architecture.layer-violation'],
            ...$options,
        ], ['capture_stderr_separately' => true]);

        return $tester;
    }

    /** Parses the envelope off stdout and returns its message. */
    private static function envelopeError(CommandTester $tester): string
    {
        return self::envelope($tester)['error'];
    }

    /** @return array{error: string, exit_code: int, position: mixed} */
    private static function envelope(CommandTester $tester): array
    {
        /** @var array{error: string, exit_code: int, position: mixed} $envelope */
        $envelope = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);

        return $envelope;
    }

    private function writeFile(string $name, string $contents): string
    {
        $path = $this->tempDir . '/' . $name;
        file_put_contents($path, $contents . "\n");

        return $path;
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}

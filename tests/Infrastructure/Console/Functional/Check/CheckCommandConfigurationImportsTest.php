<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Check;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Check\CheckCommand;
use Symfony\Component\Process\Process;

/**
 * `imports` through the real binary: an entry resolves against the importing
 * file's directory, a path written inside it against the working directory,
 * and a refusal names the imported file together with its importer.
 */
#[CoversClass(CheckCommand::class)]
final class CheckCommandConfigurationImportsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qmx-check-imports-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/src', 0o777, true);
        mkdir($this->directory . '/sub');
        file_put_contents($this->directory . '/src/Demo.php', "<?php\nnamespace Demo;\nfinal class Demo {}\n");
        file_put_contents($this->directory . '/sub/qmx.yaml', "imports: [base.yaml]\n");
        file_put_contents($this->directory . '/sub/base.yaml', "paths: [src]\n");
    }

    protected function tearDown(): void
    {
        foreach (['src/Demo.php', 'sub/qmx.yaml', 'sub/base.yaml'] as $file) {
            @unlink($this->directory . '/' . $file);
        }
        @rmdir($this->directory . '/src');
        @rmdir($this->directory . '/sub');
        @rmdir($this->directory);
    }

    #[Test]
    public function itReadsTheImportBesideTheConfigFileAndItsPathsFromTheWorkingDirectory(): void
    {
        $json = $this->qmx('--format=json');
        self::assertSame(0, $json->getExitCode(), $json->getErrorOutput());
        /** @var array{summary: array{filesAnalyzed: int}} $report */
        $report = json_decode($json->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(1, $report['summary']['filesAnalyzed']);

        $verbose = $this->qmx('-v');
        self::assertStringContainsString('Configuration loaded from: defaults, sub/base.yaml, sub/qmx.yaml, cli', $verbose->getOutput() . $verbose->getErrorOutput());
    }

    #[Test]
    public function itNamesTheImporterOfAMissingFileInTheJsonEnvelope(): void
    {
        file_put_contents($this->directory . '/sub/qmx.yaml', "imports: [base.yaml, missing.yaml]\n");

        $process = $this->qmx('--format=json');

        self::assertSame(3, $process->getExitCode(), $process->getErrorOutput());
        /** @var array{error: string, source: list<array{kind: string, name: string, imported_by: mixed}>} $envelope */
        $envelope = json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Configuration error: Configuration file "sub/missing.yaml" (imported by configuration file "sub/qmx.yaml") was not found or is not accessible.', $envelope['error']);
        self::assertSame(
            [['kind' => 'file', 'name' => 'sub/missing.yaml', 'imported_by' => ['kind' => 'file', 'name' => 'sub/qmx.yaml', 'imported_by' => null]]],
            $envelope['source'],
        );
    }

    private function qmx(string $option): Process
    {
        $process = new Process(
            [\PHP_BINARY, \dirname(__DIR__, 5) . '/bin/qmx', 'check', '--config=sub/qmx.yaml', '--workers=0', '--no-cache', '--no-ansi', $option],
            $this->directory,
        );
        $process->run();

        return $process;
    }
}

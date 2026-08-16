<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CodeSmell\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end guard for D2: the global `--exclude-namespace` option must
 * suppress occurrence-style findings (e.g. `code-smell.eval`) whose *file*
 * symbol path carries no namespace, by resolving the declaring namespace from
 * the violation's subject.
 */
final class NamespaceExclusionOccurrenceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx-ns-excl-' . uniqid();
        mkdir($this->tempDir . '/Foo', 0777, true);
        mkdir($this->tempDir . '/Bar', 0777, true);

        file_put_contents($this->tempDir . '/Foo/Evil.php', <<<'PHP'
<?php

namespace Foo;

function run(string $code): void
{
    eval($code);
}
PHP);
        file_put_contents($this->tempDir . '/Bar/Evil.php', <<<'PHP'
<?php

namespace Bar;

function run(string $code): void
{
    eval($code);
}
PHP);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function itReportsBothEvalFindingsWithoutNamespaceExclusion(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => [$this->tempDir],
            '--format' => 'json',
            '--workers' => 0,
            '--no-cache' => true,
            '--no-progress' => true,
            '--only-rule' => ['code-smell.eval'],
        ]);

        $report = json_decode(self::extractJsonObject($tester->getDisplay()), true, 512, \JSON_THROW_ON_ERROR);
        $subjects = array_column($report['violations'], 'subject');

        self::assertCount(2, $subjects);
        self::assertSame(1, \count(array_filter(
            $subjects,
            static fn(string $subject): bool => str_contains($subject, 'Foo'),
        )));
        self::assertSame(1, \count(array_filter(
            $subjects,
            static fn(string $subject): bool => str_contains($subject, 'Bar'),
        )));
    }

    #[Test]
    public function itSuppressesEvalFindingInExcludedNamespaceButKeepsSibling(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => [$this->tempDir],
            '--format' => 'json',
            '--workers' => 0,
            '--no-cache' => true,
            '--no-progress' => true,
            '--only-rule' => ['code-smell.eval'],
            '--exclude-namespace' => ['Foo'],
        ]);

        $report = json_decode(self::extractJsonObject($tester->getDisplay()), true, 512, \JSON_THROW_ON_ERROR);
        $subjects = array_column($report['violations'], 'subject');

        self::assertCount(1, $subjects, 'Only the non-excluded namespace should keep its finding');
        self::assertStringContainsString('Bar', $subjects[0]);
    }

    private function createCommandTester(): CommandTester
    {
        /** @var CheckCommand $command */
        $command = (new ContainerFactory())->create()->get(CheckCommand::class);

        $application = new Application();
        $application->addCommand($command);

        return new CommandTester($command);
    }

    /**
     * Extracts a JSON object document from the captured stdout. Configuration
     * warnings may be written to the same stream before the document.
     */
    private static function extractJsonObject(string $output): string
    {
        foreach (str_split($output) as $offset => $char) {
            if ($char !== '{') {
                continue;
            }

            $candidate = substr($output, $offset);
            json_decode($candidate, true);
            if (json_last_error() === \JSON_ERROR_NONE) {
                return $candidate;
            }
        }

        return $output;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff((scandir($dir) !== false ? scandir($dir) : []), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}

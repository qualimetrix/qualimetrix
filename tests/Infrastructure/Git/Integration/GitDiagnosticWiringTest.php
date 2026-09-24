<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Git\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Composer\ComposerAutoloadMap;
use Qualimetrix\Infrastructure\Git\GitClient;
use Qualimetrix\Infrastructure\Git\ReportingGitScopeQuery;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Whether these diagnostics reach the person running the tool.
 *
 * A `NullLogger` constructor default is right for a class built directly in a
 * test and wrong for a service: a registration that omits the argument is
 * silently mute, and the omission looks like nothing at all. Unit tests that
 * hand the class a spy prove the message is composed; they cannot prove it is
 * heard, which is the half that was missing. Both cases below therefore run
 * the binary and read its stderr.
 *
 * Neither asserts the wording — only that the run named the file it dropped or
 * could not read. A message the user never sees and a message that says the
 * wrong thing are the same defect twice, so the assertion is on the name.
 */
#[CoversClass(ReportingGitScopeQuery::class)]
#[CoversClass(GitClient::class)]
#[CoversClass(ComposerAutoloadMap::class)]
final class GitDiagnosticWiringTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/qmx-diag-wiring-' . bin2hex(random_bytes(6));

        if (!mkdir($dir) || !is_dir($dir)) {
            throw new RuntimeException('Cannot create the fixture tree: ' . $dir);
        }

        $resolved = realpath($dir);

        if ($resolved === false) {
            throw new RuntimeException('Cannot resolve the fixture tree: ' . $dir);
        }

        $this->root = $resolved;
    }

    protected function tearDown(): void
    {
        $this->removeRecursive($this->root);
    }

    /**
     * A monorepo: the git tree holds the analysed project and a sibling. Both
     * change, and the sibling's row is dropped because it is not under the
     * project root — a legitimate drop the user still has to be told about,
     * because it is the difference between the changed set and the analysed
     * one.
     */
    #[Test]
    public function itTellsTheUserWhichChangedFilesItDropped(): void
    {
        $this->write('proj/src/Inside.php', "<?php\n\nclass Inside {}\n");
        $this->write('outside/Outsider.php', "<?php\n\nclass Outsider {}\n");
        $this->git('git init', $this->root);
        $this->git('git config user.email "test@example.com"', $this->root);
        $this->git('git config user.name "Test User"', $this->root);
        $this->git('git add -A', $this->root);

        $stderr = $this->qmx(['check', '.', '--report=git:staged', '--workers=0', '--no-cache'], $this->root . '/proj');

        self::assertStringContainsString(
            'Outsider.php',
            $stderr,
            'The run dropped a changed file without saying so. ReportingGitScopeQuery is not reaching the '
            . 'container\'s logger, so its warnings go to a NullLogger.',
        );
    }

    /**
     * A damaged manifest changes what the run measures — DIT stops one link
     * early and the parent turns into external coupling — so the run has to
     * say which file it could not read. The analysed path is the leaf's
     * directory only, which is what forces the parent to be looked up through
     * the install.
     */
    #[Test]
    public function itTellsTheUserWhichComposerManifestItCouldNotRead(): void
    {
        $this->write('composer.json', '{"autoload": {"psr-4": {"App\\\\": "src/"}}');
        $this->write('src/Base.php', "<?php\n\nnamespace App;\n\nclass Base {}\n");
        $this->write('src/Sub/Leaf.php', "<?php\n\nnamespace App\\Sub;\n\nclass Leaf extends \\App\\Base {}\n");

        $stderr = $this->qmx(['check', 'src/Sub', '--workers=0', '--no-cache'], $this->root);

        $hint = 'The run read a damaged manifest without saying so. ComposerAutoloadMap is not reaching the '
            . "container's logger, so its warnings go to a NullLogger.";

        self::assertStringContainsString('composer.json', $stderr, $hint);
        self::assertStringContainsString('invalid JSON', $stderr, $hint);
    }

    /**
     * @param list<string> $arguments
     */
    private function qmx(array $arguments, string $workingDirectory): string
    {
        $process = new Process(
            [\PHP_BINARY, \dirname(__DIR__, 4) . '/bin/qmx', ...$arguments],
            $workingDirectory,
        );
        $process->run();

        return $process->getErrorOutput() . $process->getOutput();
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        $directory = \dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create ' . $directory);
        }

        file_put_contents($path, $contents);
    }

    private function git(string $command, string $workingDirectory): void
    {
        $process = Process::fromShellCommandline($command, $workingDirectory);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(\sprintf('Command failed: %s — %s', $command, $process->getErrorOutput()));
        }
    }

    private function removeRecursive(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            is_dir($child) && !is_link($child) ? $this->removeRecursive($child) : @unlink($child);
        }

        @rmdir($path);
    }
}

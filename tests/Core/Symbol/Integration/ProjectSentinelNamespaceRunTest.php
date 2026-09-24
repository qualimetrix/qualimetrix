<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\Symbol\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolPath;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * `namespace __PROJECT__;` is legal PHP, and `__PROJECT__` used to be the
 * string that marked the project aggregate. A real run over such a namespace
 * merged its aggregate into the project's, dropped the namespace from every
 * class and method declared in it, and published project findings under the
 * same `namespace` value as the namespace's own findings.
 */
#[CoversClass(SymbolPath::class)]
final class ProjectSentinelNamespaceRunTest extends TestCase
{
    private static string $workingDirectory;

    /** @var list<array<string, mixed>> */
    private static array $symbols = [];

    /** @var list<array<string, mixed>> */
    private static array $violations = [];

    public static function setUpBeforeClass(): void
    {
        self::$workingDirectory = \sprintf('%s/qmx_project_sentinel_%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $analysed = self::$workingDirectory . '/src';

        if (!mkdir($analysed, 0o777, true) && !is_dir($analysed)) {
            throw new RuntimeException('Failed to create the fixture directory');
        }

        // Ten nested conditions: enough for complexity findings on the method,
        // the class and, through health, the namespace and the project.
        $nested = str_repeat('if ($a > 1) { ', 10) . 'return 1; ' . str_repeat('} ', 10);
        self::write(
            $analysed . '/Sentinel.php',
            "<?php\n\nnamespace __PROJECT__;\n\nclass Weird\n{\n    public function f(int \$a): int\n    {\n        {$nested}\n        return 0;\n    }\n}\n",
        );
        self::write(
            $analysed . '/Normal.php',
            "<?php\n\nnamespace Normal;\n\nclass Plain\n{\n    public function g(): int\n    {\n        return 1;\n    }\n}\n",
        );

        /** @var array{symbols?: list<array<string, mixed>>} $metrics */
        $metrics = self::analyse($analysed, ['--format=metrics']);
        /** @var array{violations?: list<array<string, mixed>>} $json */
        $json = self::analyse($analysed, ['--format=json', '--format-opt=violations=all']);

        self::$symbols = $metrics['symbols'] ?? [];
        self::$violations = $json['violations'] ?? [];
    }

    public static function tearDownAfterClass(): void
    {
        self::removeTree(self::$workingDirectory);
    }

    #[Test]
    public function itKeepsTheNamespaceOnDeclarationsInIt(): void
    {
        self::assertContains('__PROJECT__\\Weird', self::namesOf('class'));
        self::assertContains('__PROJECT__\\Weird::f', self::namesOf('method'));
    }

    #[Test]
    public function itMeasuresTheNamespaceApartFromTheProject(): void
    {
        self::assertContains('__PROJECT__', self::namesOf('namespace'));
        self::assertSame(['(project)'], self::namesOf('project'));
    }

    #[Test]
    public function itPublishesProjectFindingsUnderANamespaceNoAnalysedNamespaceCarries(): void
    {
        $projectNamespaces = [];
        $declarationNamespaces = [];

        foreach (self::$violations as $violation) {
            if (($violation['subject'] ?? null) === 'project:') {
                $projectNamespaces[] = $violation['namespace'] ?? null;
            } elseif (str_contains((string) ($violation['subject'] ?? ''), '__PROJECT__')) {
                $declarationNamespaces[] = $violation['namespace'] ?? null;
            }
        }

        self::assertNotSame([], $projectNamespaces, 'The fixture must produce a project-level finding');
        self::assertNotSame([], $declarationNamespaces, 'The fixture must produce a finding inside the namespace');
        self::assertSame(['(project)'], array_values(array_unique($projectNamespaces)));
        self::assertSame(['__PROJECT__'], array_values(array_unique($declarationNamespaces)));
    }

    /**
     * @return list<string>
     */
    private static function namesOf(string $type): array
    {
        $names = [];

        foreach (self::$symbols as $symbol) {
            if (($symbol['type'] ?? null) === $type && \is_string($symbol['name'] ?? null)) {
                $names[] = $symbol['name'];
            }
        }

        return $names;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array<string, mixed>
     */
    private static function analyse(string $analysed, array $arguments): array
    {
        $process = new Process([
            \PHP_BINARY,
            \dirname(__DIR__, 4) . '/bin/qmx',
            'check',
            $analysed,
            '--workers=0',
            '--no-cache',
            '--no-progress',
            '--fail-on=none',
            ...$arguments,
        ], self::$workingDirectory);
        $process->run();

        $output = $process->getOutput();
        $start = strpos($output, '{');
        $decoded = $start === false ? null : json_decode(substr($output, $start), true);

        if (!\is_array($decoded)) {
            self::removeTree(self::$workingDirectory);

            self::fail('The run produced no JSON document: ' . $process->getErrorOutput());
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function write(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Failed to write ' . $path);
        }
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

        foreach ($entries !== false ? $entries : [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }

        rmdir($path);
    }
}

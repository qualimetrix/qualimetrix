<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\Symbol\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\PhpBuiltinClassHierarchy;
use Qualimetrix\Core\Symbol\PhpBuiltinClassRegistry;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * PHP class names are case-insensitive, so `\countable` and `\Countable` are
 * one class. The registry used to recognise only the canonical spelling, and
 * the other one reached the dependency graph as an external class and the
 * inheritance walk as an unfollowed chain: coupling and the DIT diagnostic
 * moved with nothing but the case the source was written in.
 *
 * Only a real run shows it, because every consumer of the registry -- the
 * graph builder, both DIT passes and the external ancestry walk -- decides for
 * itself what to hand it. The two namespaces below differ in nothing but the
 * case of the builtin names they use.
 */
#[CoversClass(PhpBuiltinClassRegistry::class)]
#[CoversClass(PhpBuiltinClassHierarchy::class)]
final class BuiltinNameCaseRunTest extends TestCase
{
    private const string SOURCE = <<<'PHP'
        <?php

        namespace %s;

        class Holder implements \Countable
        {
            public function f(\ArrayObject $x, \SplStack $y): int
            {
                return 0;
            }

            public function count(): int
            {
                return 0;
            }
        }

        class Failure extends \RuntimeException
        {
        }

        PHP;

    private static string $workingDirectory;

    /** @var array<string, array<string, int|float|null>> class name => metrics */
    private static array $classes = [];

    private static string $errorOutput = '';

    public static function setUpBeforeClass(): void
    {
        self::$workingDirectory = \sprintf('%s/qmx_builtin_case_%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $analysed = self::$workingDirectory . '/src';

        if (!mkdir($analysed, 0o777, true) && !is_dir($analysed)) {
            throw new RuntimeException('Failed to create the fixture directory');
        }

        $canonical = \sprintf(self::SOURCE, 'Canonical');
        self::write($analysed . '/Canonical.php', $canonical);
        self::write($analysed . '/Folded.php', str_replace(
            ['namespace Canonical;', '\Countable', '\ArrayObject', '\SplStack', '\RuntimeException'],
            ['namespace Folded;', '\countable', '\arrayobject', '\SPLSTACK', '\runtimeexception'],
            $canonical,
        ));

        $process = new Process([
            \PHP_BINARY,
            \dirname(__DIR__, 4) . '/bin/qmx',
            'check',
            $analysed,
            '--workers=0',
            '--no-cache',
            '--no-progress',
            '--format=metrics',
            '--fail-on=none',
        ], self::$workingDirectory);
        $process->run();

        self::$errorOutput = $process->getErrorOutput();
        $output = $process->getOutput();
        $start = strpos($output, '{');
        $decoded = $start === false ? null : json_decode(substr($output, $start), true);

        if (!\is_array($decoded) || !\is_array($decoded['symbols'] ?? null)) {
            self::removeTree(self::$workingDirectory);

            self::fail('The run produced no metrics document: ' . self::$errorOutput);
        }

        foreach ($decoded['symbols'] as $symbol) {
            if (\is_array($symbol) && ($symbol['type'] ?? null) === 'class' && \is_string($symbol['name'] ?? null)) {
                /** @var array<string, int|float|null> $metrics */
                $metrics = $symbol['metrics'] ?? [];
                self::$classes[$symbol['name']] = $metrics;
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::removeTree(self::$workingDirectory);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function measuredProvider(): iterable
    {
        foreach (['Holder', 'Failure'] as $class) {
            foreach (['coupling.ce', 'coupling.cbo', 'coupling.instability', 'design.dit'] as $metric) {
                yield $class . ' ' . $metric => [$class, $metric];
            }
        }
    }

    #[DataProvider('measuredProvider')]
    #[Test]
    public function itMeasuresTheFoldedSpellingAsTheCanonicalOne(string $class, string $metric): void
    {
        self::assertSame(
            self::metricOf('Canonical\\' . $class, $metric),
            self::metricOf('Folded\\' . $class, $metric),
        );
    }

    /**
     * The legitimate case beside the cure: a builtin, in either spelling, is
     * not a dependency the analysed project owns.
     */
    #[Test]
    public function itCountsNoBuiltinAsAnEfferentDependencyInEitherSpelling(): void
    {
        self::assertSame(0, self::metricOf('Canonical\\Holder', 'coupling.ce'));
        self::assertSame(0, self::metricOf('Folded\\Holder', 'coupling.ce'));
    }

    #[Test]
    public function itReportsNoUnfollowedInheritanceChainForAFoldedBuiltinParent(): void
    {
        self::assertStringNotContainsString('inheritance chain(s)', self::$errorOutput);
    }

    private static function metricOf(string $class, string $metric): int|float|null
    {
        self::assertArrayHasKey($class, self::$classes, 'No class symbol named ' . $class);
        self::assertArrayHasKey($metric, self::$classes[$class], $class . ' carries no ' . $metric);

        return self::$classes[$class][$metric];
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

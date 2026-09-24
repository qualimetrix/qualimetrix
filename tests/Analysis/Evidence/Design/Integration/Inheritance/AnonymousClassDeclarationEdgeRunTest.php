<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Integration\Inheritance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\DitGlobalCollector;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\NocCollector;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * An anonymous class's own `extends` header used to lend its declaration to
 * the class that encloses it: `Host { return new class extends L1 {}; }`
 * recorded the edge with `Host` as source, so `Host` appeared to extend `L1`
 * itself.
 *
 * `InheritanceDepthCollector` cannot witness this: its own docblock says it
 * does not publish `design.dit` (the value the report ships comes from
 * {@see DitGlobalCollector}, which reads the dependency graph), and its
 * per-file visitor only ever tracks named classes, so it never saw the
 * anonymous edge either. Only the path that actually publishes the value --
 * extraction, the dependency graph, the global collectors, and aggregation --
 * can prove the defect is gone. Hence a real `bin/qmx check` run, not a
 * collector unit test.
 *
 * The run happens in an empty directory with the binary addressed absolutely,
 * so this repository's own `qmx.yaml` cannot contribute unrelated findings to
 * a run whose analysed path is a temporary directory.
 */
#[CoversClass(DitGlobalCollector::class)]
#[CoversClass(NocCollector::class)]
final class AnonymousClassDeclarationEdgeRunTest extends TestCase
{
    private static string $workingDirectory;

    private static string $analysedDirectory;

    /** @var array{symbols: list<array{type: string, name: string, metrics: array<string, int|float|null>}>} */
    private static array $document;

    public static function setUpBeforeClass(): void
    {
        self::$workingDirectory = \sprintf(
            '%s/qmx_anon_class_edge_%s',
            sys_get_temp_dir(),
            bin2hex(random_bytes(6)),
        );
        self::$analysedDirectory = self::$workingDirectory . '/src';

        if (!mkdir(self::$analysedDirectory . '/An', 0o777, true) && !is_dir(self::$analysedDirectory . '/An')) {
            throw new RuntimeException('Failed to create the An/ fixture directory');
        }
        if (!mkdir(self::$analysedDirectory . '/Bi', 0o777, true) && !is_dir(self::$analysedDirectory . '/Bi')) {
            throw new RuntimeException('Failed to create the Bi/ fixture directory');
        }

        // L0 <- L1 <- (anonymous class nested inside Host::make()). The
        // anonymous class has no declaration identity of its own, so its
        // `extends L1` header is the only record that Host references L1 at
        // all -- `new class extends L1 {}` produces no separate `New_` edge.
        self::write('An/L0.php', "namespace An;\n\nclass L0\n{\n}\n");
        self::write('An/L1.php', "namespace An;\n\nclass L1 extends L0\n{\n}\n");
        self::write(
            'An/Host.php',
            "namespace An;\n\nclass Host\n{\n    public function make(): object\n    {\n        return new class extends L1 {\n        };\n    }\n}\n",
        );

        // A second, independent fixture: the anonymous class extends a PHP
        // builtin instead of a project class. DependencyGraphBuilder retains
        // a builtin-parent edge only when its type stays Extends, so this
        // fixture guards against a cure that reclassifies the edge instead of
        // flagging it.
        self::write(
            'Bi/Factory.php',
            "namespace Bi;\n\nclass Factory\n{\n    public function make(): object\n    {\n        return new class extends \\stdClass {\n        };\n    }\n}\n",
        );

        $process = new Process([
            \PHP_BINARY,
            \dirname(__DIR__, 6) . '/bin/qmx',
            'check',
            self::$analysedDirectory,
            '--workers=0',
            '--no-cache',
            '--no-progress',
            '--format=metrics',
            '--fail-on=none',
        ], self::$workingDirectory);
        $process->run();

        $output = $process->getOutput();
        $start = strpos($output, '{');

        if ($start === false) {
            self::removeTree(self::$workingDirectory);

            self::fail('The run produced no metrics document: ' . $process->getErrorOutput());
        }

        /** @var array{symbols?: list<array{type?: string, name?: string, metrics?: array<string, int|float|null>}>}|null $decoded */
        $decoded = json_decode(substr($output, $start), true);

        if (!\is_array($decoded) || !isset($decoded['symbols'])) {
            self::removeTree(self::$workingDirectory);

            self::fail('The metrics document did not parse, or carries no symbols');
        }

        /** @var array{symbols: list<array{type: string, name: string, metrics: array<string, int|float|null>}>} $decoded */
        self::$document = $decoded;
    }

    public static function tearDownAfterClass(): void
    {
        // Removed as a tree: the run writes a `.qmx-cache/` into its working
        // directory even under `--no-cache`.
        self::removeTree(self::$workingDirectory);
    }

    #[Test]
    public function itLeavesTheEnclosingClassAtDitZeroInsteadOfInheritingTheAnonymousClasssParent(): void
    {
        // Before the cure: An\Host scored design.dit = 2 (0 for L1, +1 for the
        // flagged edge Host->L1, +1 because the edge was read as Host's own
        // ancestry). Correct: Host declares no parent of its own, so dit = 0.
        self::assertSame(0, self::metricOf('class', 'An\\Host', 'design.dit'));
    }

    #[Test]
    public function itDoesNotCountTheEnclosingClassAsAChildOfTheAnonymousClasssParent(): void
    {
        // Before the cure: An\L1 scored design.noc = 1 (Host counted as a
        // direct child, because the flagged edge named Host as the source of
        // an `extends L1`). Correct: L1 has no children in this fixture.
        self::assertSame(0, self::metricOf('class', 'An\\L1', 'design.noc'));
    }

    #[Test]
    public function itKeepsTheNamespaceDitAggregateAtTheCorrectedMaximum(): void
    {
        // Before the cure: namespace An's design.dit.max was 2, driven by the
        // same mislabelled Host.dit = 2. Correct maximum across {L0: 0, L1: 1,
        // Host: 0} is 1.
        self::assertSame(1, self::metricOf('namespace', 'An', 'design.dit.max'));
    }

    /**
     * The legitimate case the cure must not eat: `new class extends L1 {}`
     * produces no `New_` edge (InstantiationHandler fires only when the
     * target is a Name, not a Class_ node), so the flagged Extends edge is the
     * *only* recorded evidence that Host depends on L1 at all. Declaration
     * readers (DIT, NOC) must skip a flagged edge; dependency readers
     * (coupling among them) must keep reading it exactly as before.
     */
    #[Test]
    public function itPreservesCouplingForTheEdgeTheFlagMustNotHide(): void
    {
        self::assertSame(1, self::metricOf('class', 'An\\Host', 'coupling.ce'), 'Host.coupling.ce');
        self::assertSame(1, self::metricOf('class', 'An\\Host', 'coupling.cbo'), 'Host.coupling.cbo');
        self::assertSame(1, self::metricOf('class', 'An\\L1', 'coupling.ca'), 'L1.coupling.ca');
    }

    /**
     * A second, independent witness for the same invariant: design.dit stays
     * at the corrected value even though the parent is never a project class.
     * The edge to a PHP builtin parent counts toward no coupling, as a PHP
     * type named any other way does not, so coupling.ce is 0.
     */
    #[Test]
    public function itKeepsTheBuiltinParentEdgeWhileDitStaysCorrected(): void
    {
        self::assertSame(0, self::metricOf('class', 'Bi\\Factory', 'design.dit'), 'Bi\\Factory.design.dit');
        self::assertSame(0, self::metricOf('class', 'Bi\\Factory', 'coupling.ce'), 'Bi\\Factory.coupling.ce');
    }

    private static function metricOf(string $type, string $name, string $metric): int|float|null
    {
        foreach (self::$document['symbols'] as $symbol) {
            if ($symbol['type'] === $type && $symbol['name'] === $name) {
                self::assertArrayHasKey(
                    $metric,
                    $symbol['metrics'],
                    \sprintf('%s %s carries no %s metric', $type, $name, $metric),
                );

                return $symbol['metrics'][$metric];
            }
        }

        self::fail(\sprintf('No %s symbol named %s in the metrics document', $type, $name));
    }

    private static function write(string $relativePath, string $body): void
    {
        $written = file_put_contents(
            self::$analysedDirectory . '/' . $relativePath,
            "<?php\n\n" . $body,
        );

        if ($written === false) {
            throw new RuntimeException('Failed to write ' . $relativePath);
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

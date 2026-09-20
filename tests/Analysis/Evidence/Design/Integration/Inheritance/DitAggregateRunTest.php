<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Integration\Inheritance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\DitGlobalCollector;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\InheritanceDepthCollector;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The DIT a report aggregates is the DIT it publishes per class.
 *
 * The per-file pass cannot see a parent declared in another file, so it scores
 * such a child as though the chain ended there. The global pass repairs that
 * from the dependency graph -- but only the class-level values. Whether the
 * namespace and project rollups see the repair depends on which collector
 * declares the metric, because re-aggregation runs over the global collectors'
 * own definitions. That is invisible to every unit test of either collector,
 * and invisible to a fixture whose classes all live in one file.
 *
 * The interface is not decoration. Depth and population are two different ways
 * for these numbers to be wrong, and a fixture of plain classes can only show
 * the first: the global pass reaches every class-level symbol, so moving the
 * declaration without also holding the population silently enrols interfaces,
 * traits and enums into the denominator of every average. Only a fixture that
 * contains one can tell that apart.
 *
 * The run happens in an empty directory with the binary addressed absolutely,
 * so that this repository's own `qmx.yaml` cannot contribute findings about
 * this repository to a run whose analysed path is a temporary directory.
 */
#[CoversClass(InheritanceDepthCollector::class)]
#[CoversClass(DitGlobalCollector::class)]
final class DitAggregateRunTest extends TestCase
{
    private string $workingDirectory;

    private string $analysedDirectory;

    protected function setUp(): void
    {
        $this->workingDirectory = \sprintf(
            '%s/qmx_dit_aggregate_%s',
            sys_get_temp_dir(),
            bin2hex(random_bytes(6)),
        );
        $this->analysedDirectory = $this->workingDirectory . '/src';

        if (!mkdir($this->analysedDirectory, 0o777, true) && !is_dir($this->analysedDirectory)) {
            throw new RuntimeException('Failed to create the analysed directory');
        }

        // One class per file: a chain inside a single file is resolved by the
        // per-file pass alone and would pass with the defect in place.
        $this->write('Base.php', "class Base\n{\n}\n");
        $this->write('Middle.php', "class Middle extends Base\n{\n}\n");
        $this->write('Leaf.php', "class Leaf extends Middle\n{\n}\n");
        $this->write('Contract.php', "interface Contract\n{\n}\n");
    }

    protected function tearDown(): void
    {
        // Removed as a tree: the run writes a `.qmx-cache/` into its working
        // directory even under `--no-cache`.
        self::removeTree($this->workingDirectory);
    }

    #[Test]
    public function itAggregatesTheDepthItPublishesPerClass(): void
    {
        $project = $this->projectMetrics();

        // Base 0, Middle 1, Leaf 2 -- the chain crosses three files.
        self::assertSame(2, $project['design.dit.max']);
        // Delta, not identity: the document is JSON, so a whole average
        // arrives as an int and an exact-match assertion would fail on 1 vs 1.0.
        self::assertEqualsWithDelta(1.0, $project['design.dit.avg'], 1.0e-9);
        self::assertEqualsWithDelta(1.9, $project['design.dit.p95'], 1.0e-9);
    }

    #[Test]
    public function itCountsClassesRatherThanEveryClassLevelSymbol(): void
    {
        $project = $this->projectMetrics();

        self::assertSame(3, $project['design.dit.count']);
        self::assertSame(
            $project['size.class-count.sum'],
            $project['design.dit.count'],
            'DIT is denominated in something other than what this product counts as a class',
        );
    }

    /**
     * @return array<string, float|int>
     */
    private function projectMetrics(): array
    {
        $process = new Process([
            \PHP_BINARY,
            \dirname(__DIR__, 6) . '/bin/qmx',
            'check',
            $this->analysedDirectory,
            '--workers=0',
            '--no-cache',
            '--no-progress',
            '--format=metrics',
            '--fail-on=none',
        ], $this->workingDirectory);
        $process->run();

        $output = $process->getOutput();
        $start = strpos($output, '{');

        if ($start === false) {
            self::fail('The run produced no metrics document: ' . $process->getErrorOutput());
        }

        /** @var array<string, mixed>|null $document */
        $document = json_decode(substr($output, $start), true);

        if (!\is_array($document)) {
            self::fail('The metrics document did not parse');
        }

        return self::projectSection($document);
    }

    /**
     * The project section is the one node carrying `design.dit.count`
     * alongside a project-level class count; addressing it by shape keeps this
     * test independent of the document's nesting.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, float|int>
     */
    private static function projectSection(array $document): array
    {
        $found = null;
        $walk = static function (mixed $node) use (&$walk, &$found): void {
            if (!\is_array($node)) {
                return;
            }

            if (isset($node['design.dit.count'], $node['size.class-count.sum'])) {
                $found = $node;
            }

            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($document);

        if ($found === null) {
            self::fail('The metrics document carries no project-level DIT aggregate');
        }

        /** @var array<string, float|int> $found */
        return $found;
    }

    private function write(string $name, string $body): void
    {
        $written = file_put_contents(
            $this->analysedDirectory . '/' . $name,
            "<?php\n\nnamespace Chain;\n\n" . $body,
        );

        if ($written === false) {
            throw new RuntimeException('Failed to write ' . $name);
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

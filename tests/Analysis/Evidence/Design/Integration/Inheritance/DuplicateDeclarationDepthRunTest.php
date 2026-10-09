<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Integration\Inheritance;

use Closure;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\DependencyGraphBuilder;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyVisitor;
use Qualimetrix\Analysis\Evidence\DependencyModel\UnplacedExternalClassSpelling;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\DitGlobalCollector;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\ExternalAncestry;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\InheritanceDepthResolver;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\InheritanceOutcome;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\InheritanceRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Core\Ast\NameResolution;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Infrastructure\Composer\ComposerAutoloadMap;
use Qualimetrix\Infrastructure\Composer\ComposerManifestReader;
use Qualimetrix\Infrastructure\Composer\DeclaredParentReader;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The depth a `design.dit` finding carries belongs to the declaration it points at.
 *
 * This test checks the exact source identity and depth together in the emitted
 * finding, so a logical-name read cannot silently replace one body's answer.
 *
 * Two declarations of one name are ordinary PHP: a `class_exists()`-guarded
 * polyfill and its native counterpart are exactly this shape.
 *
 * The run happens in an empty directory with the binary addressed absolutely,
 * so that this repository's own `qmx.yaml` cannot contribute findings about
 * this repository to a run whose analysed path is a temporary directory.
 */
#[CoversClass(DitGlobalCollector::class)]
#[CoversClass(InheritanceRule::class)]
final class DuplicateDeclarationDepthRunTest extends TestCase
{
    private string $workingDirectory;

    private string $analysedDirectory;

    /** @var list<string> */
    private array $fixtureAutoloadQueries = [];

    private ?Closure $fixtureAutoloader = null;

    protected function setUp(): void
    {
        $this->workingDirectory = \sprintf(
            '%s/qmx_duplicate_declaration_%s',
            sys_get_temp_dir(),
            bin2hex(random_bytes(6)),
        );
        $this->fixtureAutoloader = function (string $name): void {
            if (str_starts_with($name, 'App\\') || str_starts_with($name, 'Vendor\\')) {
                $this->fixtureAutoloadQueries[] = $name;
            }
        };
        spl_autoload_register($this->fixtureAutoloader);
        $this->analysedDirectory = $this->workingDirectory . '/src';

        if (!mkdir($this->analysedDirectory, 0o777, true) && !is_dir($this->analysedDirectory)) {
            throw new RuntimeException('Failed to create the analysed directory');
        }

        $this->write('Base1.php', "class Base1\n{\n}\n");
        $this->write('Base2.php', "class Base2 extends Base1\n{\n}\n");
    }

    protected function tearDown(): void
    {
        if ($this->fixtureAutoloader !== null) {
            spl_autoload_unregister($this->fixtureAutoloader);
        }
        // Removed as a tree: the run writes a `.qmx-cache/` into its working
        // directory even under `--no-cache`.
        self::removeTree($this->workingDirectory);
    }

    /**
     * The witness for a chain the per-file pass cannot resolve.
     *
     * Without it, a rule reading the logical class and a rule reading the
     * declaration are indistinguishable until a name is declared twice: the
     * per-file pass leaves 1 on the declaration, and only the global pass
     * knows this chain is two deep.
     */
    #[Test]
    public function itPublishesTheGlobalDepthOfACrossFileChain(): void
    {
        $this->write('Middle.php', "class Middle extends Base1\n{\n}\n");
        $this->write('Leaf.php', "class Leaf extends Middle\n{\n}\n");

        self::assertSame(
            ['src/Base2.php' => 1, 'src/Leaf.php' => 2, 'src/Middle.php' => 1],
            $this->publishedDepths(),
        );
    }

    #[Test]
    public function itGivesEachDeclarationOfOneNameItsOwnDepth(): void
    {
        $this->write('Shallow.php', "class Shim extends Base1\n{\n}\n");
        $this->write('Deep.php', "class Shim extends Base2\n{\n}\n");
        $this->write('Consumer.php', "class Consumer extends Shim\n{\n}\n");

        self::assertSame(
            [
                'src/Base2.php' => 1,
                // The name's two declarations disagree, and the child of an
                // ambiguous name takes the deepest of them.
                'src/Consumer.php' => 3,
                'src/Deep.php' => 2,
                'src/Shallow.php' => 1,
            ],
            $this->publishedDepths(),
        );
    }

    /**
     * The defect this test pins was not a wrong number but an unstable one:
     * the name-keyed map let the file read last decide, so renaming a file
     * moved a published metric.
     */
    #[Test]
    public function itDoesNotMoveWhenTheTwoDeclarationsSwapFileNames(): void
    {
        $this->write('First.php', "class Shim extends Base1\n{\n}\n");
        $this->write('Second.php', "class Shim extends Base2\n{\n}\n");

        $before = $this->publishedDepths();

        $this->write('First.php', "class Shim extends Base2\n{\n}\n");
        $this->write('Second.php', "class Shim extends Base1\n{\n}\n");

        self::assertSame(['src/Base2.php' => 1, 'src/First.php' => 1, 'src/Second.php' => 2], $before);
        self::assertSame(['src/Base2.php' => 1, 'src/First.php' => 2, 'src/Second.php' => 1], $this->publishedDepths());
    }

    #[Test]
    public function itUsesAllParsedDeclarationsWhenAnExternalComposerParentReturnsToTheProject(): void
    {
        $this->writeBridgeSources('RuntimeException');
        foreach ([false, true] as $reverse) {
            foreach ([false, true] as $includeBridge) {
                [$resolver, $child] = $this->parsedBridge($reverse, $includeBridge);
                $answer = $resolver->depthOf($child);
                self::assertSame(4, $answer->depth);
                self::assertSame(InheritanceOutcome::Exact, $answer->outcome);
                self::assertNull($answer->reachesThrowable);
                self::assertSame('src/C.php', $child->file->value());
                self::assertSame(0, $child->ordinal->value);
            }
        }
        self::assertFalse(class_exists('App\C', false));
        self::assertFalse(class_exists('Vendor\B', false));
        self::assertSame([], $this->fixtureAutoloadQueries);
    }

    #[Test]
    public function itKeepsParsedMixedCyclesIndependentOfComposerSelectedRoot(): void
    {
        $this->writeBridgeSources('App\C');
        foreach ([false, true] as $reverse) {
            foreach ([false, true] as $includeBridge) {
                [$resolver, $child] = $this->parsedBridge($reverse, $includeBridge);
                $answer = $resolver->depthOf($child);
                self::assertSame(InheritanceOutcome::Loop, $answer->outcome);
                self::assertNull($answer->depth);
                self::assertNull($answer->reachesThrowable);
            }
        }
        self::assertFalse(class_exists('App\A', false));
        self::assertFalse(class_exists('Vendor\B', false));
        self::assertSame([], $this->fixtureAutoloadQueries);
    }

    private function writeBridgeSources(string $alternativeParent): void
    {
        file_put_contents($this->workingDirectory . '/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/', 'Vendor\\' => 'vendor-src/']]], \JSON_THROW_ON_ERROR));
        file_put_contents($this->analysedDirectory . '/A.php', '<?php namespace App; if (!class_exists(A::class, false)) { class A {} }');
        file_put_contents($this->analysedDirectory . '/Alternative.php', '<?php namespace App; if (!class_exists(A::class, false)) { abstract class A extends \\' . $alternativeParent . ' {} }');
        file_put_contents($this->analysedDirectory . '/C.php', '<?php namespace App; class C extends \\Vendor\\B {}');
        mkdir($this->workingDirectory . '/vendor-src');
        file_put_contents($this->workingDirectory . '/vendor-src/B.php', '<?php namespace Vendor; class B extends \\App\\A {}');
    }

    /** @return array{InheritanceDepthResolver, DeclarationPath} */
    private function parsedBridge(bool $reverse, bool $includeBridge): array
    {
        $files = $reverse ? ['src/Alternative.php', 'src/A.php', 'src/C.php'] : ['src/A.php', 'src/Alternative.php', 'src/C.php'];
        if ($includeBridge) {
            $files[] = 'vendor-src/B.php';
        }
        $facts = $edges = [];
        $child = null;
        $parser = (new ParserFactory())->createForHostVersion();
        foreach ($files as $file) {
            $contents = file_get_contents($this->workingDirectory . '/' . $file);
            self::assertIsString($contents);
            $ast = $parser->parse($contents);
            self::assertNotNull($ast);
            NameResolution::resolve($ast);
            $registrar = (new DeclarationRegistrarFactory())->createForFile();
            $visitor = new DependencyVisitor();
            $visitor->beginFile(RelativePath::fromString($file), $registrar->index());
            (new NodeTraverser($registrar, $visitor))->traverse($ast);
            array_push($facts, ...$visitor->classLikeDeclarations());
            array_push($edges, ...$visitor->dependencies());
            if ($file === 'src/C.php') {
                $child = $visitor->classLikeDeclarations()[0]->declaration;
            }
        }
        self::assertNotNull($child);
        self::assertCount($includeBridge ? 4 : 3, $facts);
        $reader = new DeclaredParentReader(new ComposerAutoloadMap(new ComposerManifestReader()));
        $reader->pointAt($this->workingDirectory, ['src']);
        self::assertNull($reader->parentOf('App\A')->parent);
        $graph = (new DependencyGraphBuilder(new UnplacedExternalClassSpelling()))->build($edges, $facts)->graph;
        self::assertCount($includeBridge ? 3 : 2, $graph->getDeclarationDependencies());

        return [InheritanceDepthResolver::fromGraph($graph, new ExternalAncestry($reader)), $child];
    }

    /**
     * The depth each `design.dit` finding reports, keyed by the file of the
     * declaration it points at and sorted so the comparison is order-free.
     *
     * @return array<string, int>
     */
    private function publishedDepths(): array
    {
        $process = new Process([
            \PHP_BINARY,
            \dirname(__DIR__, 6) . '/bin/qmx',
            'check',
            $this->analysedDirectory,
            '--workers=0',
            '--no-cache',
            '--no-progress',
            '--format=json',
            '--fail-on=none',
            '--only-rule=design.dit',
            '--rule-opt=design.dit:warning=1',
            '--rule-opt=design.dit:error=9',
        ], $this->workingDirectory);
        $process->run();

        $output = $process->getOutput();
        $start = strpos($output, '{');

        if ($start === false) {
            self::fail('The run produced no report: ' . $process->getErrorOutput());
        }

        /** @var array{violations?: list<array{file?: string, metricValue?: float|int|null, subject?: string}>}|null $report */
        $report = json_decode(substr($output, $start), true);

        if (!\is_array($report) || !isset($report['violations'])) {
            self::fail('The report did not parse, or carries no violations');
        }

        $depths = [];

        foreach ($report['violations'] as $violation) {
            $file = $violation['file'] ?? null;
            $subject = $violation['subject'] ?? null;

            // A finding whose subject is not an exact declaration would make
            // the key ambiguous for the very case this test is about.
            self::assertIsString($subject);
            self::assertStringStartsWith('declaration:class:', $subject);
            self::assertIsString($file);

            $depths[$file] = (int) ($violation['metricValue'] ?? -1);
        }

        ksort($depths);

        return $depths;
    }

    private function write(string $name, string $body): void
    {
        $written = file_put_contents(
            $this->analysedDirectory . '/' . $name,
            "<?php\n\nnamespace Duplicate;\n\n" . $body,
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

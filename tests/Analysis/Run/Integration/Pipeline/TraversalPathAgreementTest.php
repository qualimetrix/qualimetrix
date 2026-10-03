<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Integration\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyVisitor;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\FileMeasurement\CompositeCollector;
use Qualimetrix\Analysis\Run\Contract\Configuration\{AutoloadDevPolicy, GeneratedFilePolicy, ProjectScopeMeasurement, ProjectScopeState, RunConfiguration};
use Qualimetrix\Analysis\Run\Discovery\EntryInspector;
use Qualimetrix\Analysis\Run\Discovery\GeneratedFileFilter;
use Qualimetrix\Analysis\Run\Discovery\ProjectFiles;
use Qualimetrix\Analysis\Run\Discovery\ProjectWalk;
use Qualimetrix\Analysis\Run\Pipeline\DependencyGraphAnalyzer;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Infrastructure\Ast\PhpFileParser;
use SplFileInfo;

/**
 * Both traversal paths number one file the same way.
 *
 * The comparison is on `DeclarationPath` objects, not on rendered output: the
 * graph export carries logical paths, so no canonical declaration key appears
 * in it and comparing exports would prove something else. The graph path is
 * also the one path with no guard on it — `FileProcessor` is not on it — so
 * this is the whole of its evidence.
 */
#[CoversClass(DependencyGraphAnalyzer::class)]
#[CoversClass(CompositeCollector::class)]
final class TraversalPathAgreementTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx-paths-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/src', 0o777, true);
        file_put_contents($this->root . '/src/Dup.php', <<<'PHP'
            <?php

            namespace App;

            if (\PHP_VERSION_ID > 80000) {
                class Greeter implements Port
                {
                }
            } else {
                class Greeter implements Port
                {
                }
            }
            PHP);
    }

    protected function tearDown(): void
    {
        unlink($this->root . '/src/Dup.php');
        rmdir($this->root . '/src');
        rmdir($this->root);
    }

    #[Test]
    public function itGivesEdgeSourcesTheSameDeclarationPathOnBothPaths(): void
    {
        $checkPath = $this->edgeSourcesFromCheckPath();

        self::assertSame([
            'declaration:class:App\Greeter@src/Dup.php',
            'declaration:class:App\Greeter@src/Dup.php#1',
        ], $checkPath, 'the check path must discriminate the two declarations');
        self::assertSame($checkPath, $this->edgeSourcesFromGraphPath());
    }

    /** @return list<string> */
    private function edgeSourcesFromCheckPath(): array
    {
        $file = new SplFileInfo($this->root . '/src/Dup.php');
        $ast = (new PhpFileParser())->parseContent($file, (string) file_get_contents($file->getPathname()));
        $collector = new CompositeCollector([], new DeclarationRegistrarFactory(), [], new DependencyVisitor());

        return self::canonicalSources(
            $collector->collect($file, $ast, PathFactory::published(AbsolutePath::fromString($file->getPathname()), $this->projectRoot()))->dependencies,
        );
    }

    /** @return list<string> */
    private function edgeSourcesFromGraphPath(): array
    {
        $builder = new class (self::createStub(DependencyGraphInterface::class)) implements DependencyGraphBuilderInterface {
            /** @var list<Dependency> */
            public array $dependencies = [];

            public function __construct(private readonly DependencyGraphInterface $graph) {}

            public function build(array $dependencies, iterable $logicalClassUniverse): DependencyGraphInterface
            {
                $this->dependencies = $dependencies;

                return $this->graph;
            }
        };

        $analyzer = new DependencyGraphAnalyzer(
            new ProjectFiles(new ProjectWalk(new EntryInspector()), new GeneratedFileFilter()),
            new PhpFileParser(),
            new DependencyVisitor(),
            $builder,
            new DeclarationRegistrarFactory(),
        );
        $result = $analyzer->analyze($this->configuration());

        self::assertSame([], $result->coverage->failures);

        return self::canonicalSources($builder->dependencies);
    }

    private function configuration(): RunConfiguration
    {
        $root = $this->projectRoot();
        return new RunConfiguration([], $root, GeneratedFilePolicy::Exclude, new ProjectScopeMeasurement(universe: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(projectRoot: $root, pathsAuthored: true, denominator: [], prunedTargets: [], reasons: [], namespaceMapUsable: true, pathResolutions: []), paths: [$root], scopeState: ProjectScopeState::Covered, uncoveredRoots: []), [], AutoloadDevPolicy::Exclude);
    }

    private function projectRoot(): AbsolutePath
    {
        // The graph path canonicalizes its root, and the temporary directory
        // lives behind a symlink on macOS: an uncanonicalized root would make
        // the two paths disagree about the file, not about the numbering.
        return AbsolutePath::fromString($this->root)->canonicalize();
    }

    /**
     * @param list<Dependency> $dependencies
     *
     * @return list<string>
     */
    private static function canonicalSources(array $dependencies): array
    {
        $sources = array_map(
            static fn(Dependency $dependency): string => $dependency->source->toCanonical(),
            $dependencies,
        );
        sort($sources);

        return $sources;
    }
}

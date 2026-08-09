<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Pipeline;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeTraverser;
use Qualimetrix\Analysis\Collection\Dependency\DependencyGraphBuilder;
use Qualimetrix\Analysis\Collection\Dependency\DependencyVisitor;
use Qualimetrix\Analysis\Discovery\FileDiscoveryInterface;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Dependency\Dependency;
use Qualimetrix\Core\Exception\ParseException;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Throwable;

/**
 * Owns discovery, parsing, AST traversal and graph construction as one analysis.
 *
 * Keeping file terminal states beside graph construction prevents artifact
 * consumers from mistaking a partial graph for a complete one.
 */
final readonly class DependencyGraphAnalyzer implements DependencyGraphAnalyzerInterface
{
    public function __construct(
        private FileDiscoveryInterface $fileDiscovery,
        private FileParserInterface $fileParser,
        private DependencyVisitor $dependencyVisitor,
        private DependencyGraphBuilder $graphBuilder,
    ) {}

    public function analyze(array $paths, AbsolutePath $projectRoot): DependencyGraphAnalysisResult
    {
        $projectRoot = $projectRoot->canonicalize();
        $files = iterator_to_array($this->fileDiscovery->discover($paths), false);
        $analyzedFiles = [];
        $failures = [];
        /** @var list<Dependency> $dependencies */
        $dependencies = [];
        /** @var array<string, LogicalClassPath> $logicalClassUniverse */
        $logicalClassUniverse = [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->dependencyVisitor);

        foreach ($files as $file) {
            $path = PathFactory::bestEffortRelative($file->getPathname(), $projectRoot);

            try {
                $ast = $this->fileParser->parse($file);
                $this->dependencyVisitor->setFile($path);
                $traverser->traverse($ast);
                array_push($dependencies, ...$this->dependencyVisitor->getDependencies());
                foreach (self::declaredLogicalClasses($ast) as $class) {
                    $logicalClassUniverse[$class->toCanonical()] = $class;
                }
                $analyzedFiles[] = $path;
            } catch (ParseException $e) {
                $failures[] = new AnalysisFailure($path, AnalysisFailureKind::Parse, $e->getMessage());
            } catch (Throwable $e) {
                $failures[] = new AnalysisFailure($path, AnalysisFailureKind::Processing, $e->getMessage());
            }
        }

        return new DependencyGraphAnalysisResult(
            $this->graphBuilder->build($dependencies, array_values($logicalClassUniverse)),
            new AnalysisCoverage($analyzedFiles, [], $failures),
        );
    }

    /**
     * Graph-only analysis has no metric repository, so it discovers the
     * declared logical class universe directly from the parsed AST.
     *
     * @param array<Node> $nodes
     *
     * @return list<LogicalClassPath>
     */
    private static function declaredLogicalClasses(array $nodes, string $namespace = ''): array
    {
        $classes = [];
        foreach ($nodes as $node) {
            if ($node instanceof Namespace_) {
                array_push($classes, ...self::declaredLogicalClasses(
                    $node->stmts,
                    $node->name?->toString() ?? '',
                ));

                continue;
            }

            $name = match (true) {
                $node instanceof Class_ && $node->name !== null => $node->name->toString(),
                $node instanceof Interface_ && $node->name !== null => $node->name->toString(),
                $node instanceof Trait_ && $node->name !== null => $node->name->toString(),
                $node instanceof Enum_ && $node->name !== null => $node->name->toString(),
                default => null,
            };

            if ($name !== null) {
                $classes[] = new LogicalClassPath(SymbolPath::forClass($namespace, $name));
            }
        }

        return $classes;
    }
}

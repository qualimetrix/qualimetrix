<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Pipeline;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailure;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DependencyGraphAnalysisResult;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DependencyGraphAnalyzerInterface;
use Qualimetrix\Core\Ast\FileParserInterface;
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
        private DependencyTraversalParticipantInterface $dependencyVisitor,
        private DependencyGraphBuilderInterface $graphBuilder,
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
                $this->dependencyVisitor->beginFile($path);
                $traverser->traverse($ast);
                array_push($dependencies, ...$this->dependencyVisitor->dependencies());
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

            if (!$node instanceof ClassLike || $node->name === null) {
                continue;
            }

            $classes[] = new LogicalClassPath(SymbolPath::forClass($namespace, $node->name->toString()));
        }

        return $classes;
    }
}

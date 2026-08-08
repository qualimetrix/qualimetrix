<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection\Dependency;

use PhpParser\NodeTraverser;
use Qualimetrix\Analysis\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Pipeline\AnalysisFailure;
use Qualimetrix\Analysis\Pipeline\AnalysisFailureKind;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Dependency\Dependency;
use Qualimetrix\Core\Exception\ParseException;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
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

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->dependencyVisitor);

        foreach ($files as $file) {
            $path = PathFactory::bestEffortRelative($file->getPathname(), $projectRoot);

            try {
                $ast = $this->fileParser->parse($file);
                $this->dependencyVisitor->setFile($path);
                $traverser->traverse($ast);
                array_push($dependencies, ...$this->dependencyVisitor->getDependencies());
                $analyzedFiles[] = $path;
            } catch (ParseException $e) {
                $failures[] = new AnalysisFailure($path, AnalysisFailureKind::Parse, $e->getMessage());
            } catch (Throwable $e) {
                $failures[] = new AnalysisFailure($path, AnalysisFailureKind::Processing, $e->getMessage());
            }
        }

        return new DependencyGraphAnalysisResult(
            $this->graphBuilder->build($dependencies),
            new AnalysisCoverage($analyzedFiles, [], $failures),
        );
    }
}

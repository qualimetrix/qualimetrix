<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Pipeline;

use PhpParser\NodeTraverser;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarInterface;
use Qualimetrix\Analysis\Run\Collection\SourceReader;
use Qualimetrix\Analysis\Run\Collection\UnreadableSource;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectFilesInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailure;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DependencyGraphAnalysisResult;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DependencyGraphAnalyzerInterface;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Ast\NameResolution;
use Qualimetrix\Core\Exception\ParseException;
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
    private SourceReader $sourceReader;

    public function __construct(
        private ProjectFilesInterface $projectFiles,
        private FileParserInterface $fileParser,
        private DependencyTraversalParticipantInterface $dependencyVisitor,
        private DependencyGraphBuilderInterface $graphBuilder,
        private DeclarationRegistrarFactory $declarationRegistrarFactory,
    ) {
        $this->sourceReader = new SourceReader();
    }

    public function analyze(RunConfiguration $configuration): DependencyGraphAnalysisResult
    {
        $projectRoot = $configuration->projectRoot->canonicalize();
        $discovery = $this->projectFiles->discover($configuration);
        $files = $discovery->eligibleFiles;
        $analyzedFiles = [];
        $failures = [];
        /** @var list<Dependency> $dependencies */
        $dependencies = [];
        /** @var list<ClassLikeDeclaration> $classLikeDeclarations */
        $classLikeDeclarations = [];

        foreach ($files as $file) {
            $path = PathFactory::published(PathFactory::fromCliArgument($file->getPathname(), $projectRoot), $projectRoot);

            $source = $this->sourceReader->read($file);
            if ($source instanceof UnreadableSource) {
                $failures[] = new AnalysisFailure($path, AnalysisFailureKind::UnreadableFile, $source->reason);

                continue;
            }

            try {
                $ast = $this->fileParser->parseContent($file, $source);
                NameResolution::resolve($ast);
                $traverser = new NodeTraverser();
                $registrar = $this->beginNumbering($traverser);
                $traverser->addVisitor($this->dependencyVisitor);
                $this->dependencyVisitor->beginFile($path, $registrar->index());
                $traverser->traverse($ast);
                array_push($dependencies, ...$this->dependencyVisitor->dependencies());
                array_push($classLikeDeclarations, ...$this->dependencyVisitor->classLikeDeclarations());
                $analyzedFiles[] = $path;
            } catch (ParseException $e) {
                $failures[] = new AnalysisFailure($path, AnalysisFailureKind::Parse, $e->getMessage());
            } catch (Throwable $e) {
                $failures[] = new AnalysisFailure($path, AnalysisFailureKind::Processing, $e->getMessage());
            }
        }

        $coverage = new AnalysisCoverage($analyzedFiles, $discovery->generatedExcludedFiles, $failures, $discovery->namedExcluded);

        // The graph export answers the same question about its own input as a
        // check run does: an entry discovery refused is a hole in the graph,
        // and `graph:export` already refuses to publish an incomplete one.
        foreach ($discovery->skippedEntries as $skip) {
            $coverage = $coverage->withSkipped(
                $skip->relativeTo($projectRoot),
                $skip->reason,
                $skip->detail,
            );
        }

        return new DependencyGraphAnalysisResult(
            $this->graphBuilder->build($dependencies, $classLikeDeclarations),
            $coverage,
        );
    }

    /**
     * Numbering belongs to one traversal of one file: the registrar and its
     * index are rebuilt per file, and the visitor shared with the check path
     * is rebound to this path's index. The registrar goes in first so a
     * producer asking about the node it is entering finds it registered.
     */
    private function beginNumbering(NodeTraverser $traverser): DeclarationRegistrarInterface
    {
        $registrar = $this->declarationRegistrarFactory->createForFile();
        $traverser->addVisitor($registrar);

        return $registrar;
    }

}

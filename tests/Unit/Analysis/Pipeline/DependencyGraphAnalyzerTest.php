<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Pipeline;

use PhpParser\Node;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Collection\Dependency\DependencyGraphBuilder;
use Qualimetrix\Analysis\Collection\Dependency\DependencyResolver;
use Qualimetrix\Analysis\Collection\Dependency\DependencyVisitor;
use Qualimetrix\Analysis\Discovery\FinderFileDiscovery;
use Qualimetrix\Analysis\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Pipeline\DependencyGraphAnalysisResult;
use Qualimetrix\Analysis\Pipeline\DependencyGraphAnalyzer;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Exception\ParseException;
use Qualimetrix\Core\Path\AbsolutePath;
use RuntimeException;
use SplFileInfo;
use Throwable;

#[CoversClass(DependencyGraphAnalyzer::class)]
#[CoversClass(DependencyGraphAnalysisResult::class)]
final class DependencyGraphAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx-graph-analyzer-' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*.php');
        foreach ($files !== false ? $files : [] as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    #[Test]
    public function itReturnsGraphAndCanonicalCoverageForMixedDependencies(): void
    {
        file_put_contents(
            $this->tempDir . '/Service.php',
            '<?php namespace App; use Domain\\Model; final class Service { public function load(Model $model): void {} }',
        );
        file_put_contents($this->tempDir . '/Model.php', '<?php namespace Domain; final class Model {}');

        $result = $this->createAnalyzer($this->parser())->analyze(
            [AbsolutePath::fromString($this->tempDir)],
            AbsolutePath::fromString($this->tempDir),
        );

        self::assertTrue($result->coverage->isComplete());
        self::assertSame(2, $result->coverage->discoveredFiles());
        self::assertSame(['Model.php', 'Service.php'], array_map(
            static fn($path): string => $path->value(),
            $result->coverage->analyzedFiles,
        ));
        self::assertCount(1, $result->graph->getAllDependencies());
        self::assertSame('class:App\\Service', $result->graph->getAllDependencies()[0]->source->toCanonical());
        self::assertSame('class:Domain\\Model', $result->graph->getAllDependencies()[0]->target->toCanonical());
    }

    #[Test]
    public function itClassifiesParseAndProcessingFailuresWithoutDiscardingSuccessfulFiles(): void
    {
        $good = $this->tempDir . '/Good.php';
        $parseFailure = $this->tempDir . '/ParseFailure.php';
        $processingFailure = $this->tempDir . '/ProcessingFailure.php';
        file_put_contents($good, '<?php namespace App; final class Good {}');
        file_put_contents($parseFailure, '<?php broken');
        file_put_contents($processingFailure, '<?php namespace App; final class ProcessingFailure {}');

        $delegate = $this->parser();
        $parser = new class ($delegate, $processingFailure) implements FileParserInterface {
            public function __construct(
                private readonly FileParserInterface $delegate,
                private readonly string $processingFailure,
            ) {}

            public function parse(SplFileInfo $file): array
            {
                if ($file->getPathname() === $this->processingFailure) {
                    throw new RuntimeException('Synthetic processing failure');
                }

                return $this->delegate->parse($file);
            }

            public function parseContent(SplFileInfo $file, string $content): array
            {
                if ($file->getPathname() === $this->processingFailure) {
                    throw new RuntimeException('Synthetic processing failure');
                }

                return $this->delegate->parseContent($file, $content);
            }
        };

        $result = $this->createAnalyzer($parser)->analyze(
            [AbsolutePath::fromString($this->tempDir)],
            AbsolutePath::fromString($this->tempDir),
        );

        self::assertFalse($result->coverage->isComplete());
        self::assertSame(3, $result->coverage->discoveredFiles());
        self::assertSame(1, $result->coverage->analyzedFilesCount());
        self::assertSame(2, $result->coverage->failedFilesCount());
        self::assertSame(AnalysisFailureKind::Parse, $result->coverage->failures[0]->kind);
        self::assertSame(AnalysisFailureKind::Processing, $result->coverage->failures[1]->kind);
    }

    private function createAnalyzer(FileParserInterface $parser): DependencyGraphAnalyzer
    {
        return new DependencyGraphAnalyzer(
            new FinderFileDiscovery([]),
            $parser,
            new DependencyVisitor(new DependencyResolver()),
            new DependencyGraphBuilder(),
        );
    }

    private function parser(): FileParserInterface
    {
        return new class implements FileParserInterface {
            /** @return list<Node> */
            public function parse(SplFileInfo $file): array
            {
                $content = file_get_contents($file->getPathname());
                if ($content === false) {
                    throw new RuntimeException('Unable to read test fixture');
                }

                return $this->parseContent($file, $content);
            }

            /** @return list<Node> */
            public function parseContent(SplFileInfo $file, string $content): array
            {
                try {
                    return array_values((new ParserFactory())->createForNewestSupportedVersion()->parse($content) ?? []);
                } catch (Throwable $e) {
                    throw new ParseException(
                        AbsolutePath::fromString($file->getPathname()),
                        $e->getMessage(),
                        $e,
                    );
                }
            }
        };
    }
}

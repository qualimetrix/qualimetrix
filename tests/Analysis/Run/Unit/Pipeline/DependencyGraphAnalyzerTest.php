<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Pipeline;

use PhpParser\Node;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyResolver;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyVisitor;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DependencyGraphAnalysisResult;
use Qualimetrix\Analysis\Run\Discovery\FinderFileDiscovery;
use Qualimetrix\Analysis\Run\Pipeline\DependencyGraphAnalyzer;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Core\Exception\ParseException;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;
use ReflectionClass;
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
        $this->tempDir = sys_get_temp_dir() . '/qmx-graph-analyzer-' . bin2hex(random_bytes(6));
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
        self::assertSame('declaration:class:App\\Service@Service.php', $result->graph->getAllDependencies()[0]->source->toCanonical());
        self::assertSame('class:Domain\\Model', $result->graph->getAllDependencies()[0]->targetLogical()->toCanonical());
    }

    #[Test]
    public function itBuildsTheCanonicalUniverseForEveryNamedClassLikeAndExternalTarget(): void
    {
        file_put_contents($this->tempDir . '/Kinds.php', <<<'PHP'
<?php
namespace { class GlobalType {} }
namespace App {
    use Vendor\External;
    class Service extends External { public function make(): object { return new class {}; } }
    interface Port {}
    trait Shared {}
    enum State {}
}
PHP);

        $result = $this->createAnalyzer($this->parser())->analyze(
            [AbsolutePath::fromString($this->tempDir)],
            AbsolutePath::fromString($this->tempDir),
        );
        $classes = array_map(
            static fn($path): string => $path->toCanonical(),
            $result->graph->getAllClasses(),
        );
        sort($classes);

        self::assertTrue($result->coverage->isComplete());
        self::assertSame([
            'class:App\Port',
            'class:App\Service',
            'class:App\Shared',
            'class:App\State',
            'class:GlobalType',
            'class:Vendor\External',
        ], $classes);
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

    #[Test]
    public function itUsesTheTraversalContractWithoutImportingDependencyModelInternals(): void
    {
        $constructor = (new ReflectionClass(DependencyGraphAnalyzer::class))->getConstructor();
        self::assertNotNull($constructor);
        $type = $constructor->getParameters()[2]->getType();

        self::assertSame(DependencyTraversalParticipantInterface::class, (string) $type);
        $source = file_get_contents(\dirname(__DIR__, 5) . '/src/Analysis/Run/Pipeline/DependencyGraphAnalyzer.php');
        self::assertIsString($source);
        self::assertStringNotContainsString('DependencyModel\\Extraction', $source);
    }

    private function createAnalyzer(FileParserInterface $parser): DependencyGraphAnalyzer
    {
        return new DependencyGraphAnalyzer(
            new FinderFileDiscovery([]),
            $parser,
            new DependencyVisitor(new DependencyResolver()),
            AdjacencyGraphBuilder::builder(),
            new DeclarationRegistrarFactory(),
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

<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Pipeline;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailure;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Core\Path\RelativePath;

#[CoversClass(AnalysisCoverage::class)]
#[CoversClass(AnalysisFailure::class)]
#[CoversClass(AnalysisFailureKind::class)]
final class AnalysisCoverageTest extends TestCase
{
    #[Test]
    public function itModelsEveryDiscoveredFileInExactlyOneTerminalState(): void
    {
        $coverage = new AnalysisCoverage(
            analyzedFiles: [RelativePath::fromString('src/B.php'), RelativePath::fromString('src/A.php')],
            generatedExcludedFiles: [RelativePath::fromString('var/Generated.php')],
            failures: [
                new AnalysisFailure(
                    RelativePath::fromString('src/Broken.php'),
                    AnalysisFailureKind::Parse,
                    'Unexpected token',
                ),
            ],
        );

        self::assertSame(4, $coverage->discoveredFiles());
        self::assertSame(2, $coverage->analyzedFilesCount());
        self::assertSame(1, $coverage->generatedExcludedFilesCount());
        self::assertSame(1, $coverage->failedFilesCount());
        self::assertFalse($coverage->isComplete());
        self::assertSame(['src/A.php', 'src/B.php'], self::pathValues($coverage->analyzedFiles));
    }

    #[Test]
    public function itTreatsGeneratedExclusionsAsCompleteCoverage(): void
    {
        $coverage = new AnalysisCoverage(
            analyzedFiles: [],
            generatedExcludedFiles: [RelativePath::fromString('Generated.php')],
            failures: [],
        );

        self::assertTrue($coverage->isComplete());
    }

    #[Test]
    public function itRejectsMoreThanOneTerminalStateForTheSamePath(): void
    {
        $path = RelativePath::fromString('src/Duplicate.php');

        $this->expectException(LogicException::class);

        new AnalysisCoverage(
            analyzedFiles: [$path],
            generatedExcludedFiles: [],
            failures: [new AnalysisFailure($path, AnalysisFailureKind::Processing, 'Worker crashed')],
        );
    }

    #[Test]
    public function itMergesCoverageDeterministically(): void
    {
        $left = new AnalysisCoverage(
            analyzedFiles: [RelativePath::fromString('src/Z.php')],
            generatedExcludedFiles: [],
            failures: [],
        );
        $right = new AnalysisCoverage(
            analyzedFiles: [RelativePath::fromString('src/A.php')],
            generatedExcludedFiles: [RelativePath::fromString('src/Generated.php')],
            failures: [],
        );

        $merged = $left->merge($right);

        self::assertSame(['src/A.php', 'src/Z.php'], self::pathValues($merged->analyzedFiles));
        self::assertSame(3, $merged->discoveredFiles());
    }

    /**
     * @param list<RelativePath> $paths
     *
     * @return list<string>
     */
    private static function pathValues(array $paths): array
    {
        return array_map(static fn(RelativePath $path): string => $path->value(), $paths);
    }
}

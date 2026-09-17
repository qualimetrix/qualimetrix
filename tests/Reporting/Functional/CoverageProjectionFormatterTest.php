<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Functional\Reporting;

use DOMDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Reporting\CoverageFailure;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\ReportBuilder;
use Qualimetrix\Reporting\ReportCoverage;

final class CoverageProjectionFormatterTest extends TestCase
{
    /** @return iterable<string, array{string, ReportCoverage}> */
    public static function matrix(): iterable
    {
        $formats = ['text', 'text-verbose', 'summary', 'health', 'json', 'metrics', 'sarif', 'gitlab', 'checkstyle', 'github', 'html'];
        $states = [
            'empty' => new ReportCoverage(0, 0, 0, 0),
            'complete' => new ReportCoverage(1, 1, 0, 0),
            'generated-only' => new ReportCoverage(1, 0, 1, 0),
            'partial-failure' => self::failedCoverage(2, 1),
            'all-failed' => self::failedCoverage(1, 0),
        ];

        foreach ($formats as $format) {
            foreach ($states as $state => $coverage) {
                yield $format . '-' . $state => [$format, $coverage];
            }
        }
    }

    #[Test]
    #[DataProvider('matrix')]
    public function itProjectsCoverageInEveryNativeFormat(string $format, ReportCoverage $coverage): void
    {
        $container = (new ContainerFactory())->create();
        /** @var FormatterRegistryInterface $registry */
        $registry = $container->get(FormatterRegistryInterface::class);
        $output = $registry->get($format)->format(
            ReportBuilder::create()
                ->filesAnalyzed($coverage->analyzed)
                ->filesSkipped($coverage->generatedExcluded + $coverage->failed)
                ->coverage($coverage)
                ->build(),
            new FormatterContext(useColor: false),
        );

        match ($format) {
            'json', 'metrics' => self::assertJsonCoverage($output, $coverage),
            'sarif' => self::assertSarifCoverage($output, $coverage),
            'gitlab' => self::assertGitlabCoverage($output, $coverage),
            'checkstyle' => self::assertCheckstyleCoverage($output, $coverage),
            'github' => self::assertGithubCoverage($output, $coverage),
            'html' => self::assertHtmlCoverage($output, $coverage),
            default => self::assertStringContainsString(
                $coverage->discovered === 0
                    ? 'No PHP files were discovered.'
                    : 'Analysis ' . ($coverage->isComplete() ? 'complete' : 'incomplete'),
                $output,
            ),
        };
    }

    private static function failedCoverage(int $discovered, int $analyzed): ReportCoverage
    {
        return new ReportCoverage(
            $discovered,
            $analyzed,
            0,
            1,
            [new CoverageFailure('src/Broken.php', 'parse', 'Unexpected token')],
        );
    }

    private static function assertJsonCoverage(string $output, ReportCoverage $coverage): void
    {
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame($coverage->isComplete(), $data['coverage']['complete']);
        self::assertSame($coverage->failed, $data['coverage']['failed']);
    }

    private static function assertSarifCoverage(string $output, ReportCoverage $coverage): void
    {
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame($coverage->isComplete(), $data['runs'][0]['invocations'][0]['executionSuccessful']);
        self::assertCount($coverage->failed, $data['runs'][0]['invocations'][0]['toolExecutionNotifications']);
    }

    private static function assertGitlabCoverage(string $output, ReportCoverage $coverage): void
    {
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsList($data);
        self::assertCount($coverage->failed, $data);
    }

    private static function assertCheckstyleCoverage(string $output, ReportCoverage $coverage): void
    {
        $xml = new DOMDocument();
        self::assertTrue($xml->loadXML($output));
        self::assertSame($coverage->failed, $xml->getElementsByTagName('error')->count());
    }

    private static function assertGithubCoverage(string $output, ReportCoverage $coverage): void
    {
        self::assertSame($coverage->failed, substr_count($output, '::error '));
    }

    private static function assertHtmlCoverage(string $output, ReportCoverage $coverage): void
    {
        $html = new DOMDocument();
        self::assertTrue(@$html->loadHTML($output));
        self::assertSame(!$coverage->isComplete(), str_contains($output, 'data-qmx-coverage="incomplete"'));
        self::assertStringContainsString('"coverage":', $output);
    }
}

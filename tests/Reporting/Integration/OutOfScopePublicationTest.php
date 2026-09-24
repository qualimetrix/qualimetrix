<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Reporting\DrillDown\OutOfScopeFindings;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\ReportBuilder;
use Qualimetrix\Reporting\ReportCoverage;
use Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub;

/**
 * A `--namespace`/`--class` selection narrows what a report lists, never what
 * decides the exit code. A machine consumer reading only the selection sees
 * "0 errors" from a run that exits 2 — so every structured format must say,
 * in its own diagnostic channel, what the selection left out, or have the
 * selection refused because it has no such channel.
 */
#[CoversClass(OutOfScopeFindings::class)]
final class OutOfScopePublicationTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function structuredFormats(): iterable
    {
        foreach (['json', 'metrics', 'sarif', 'github', 'html'] as $format) {
            yield $format => [$format];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function listFormats(): iterable
    {
        foreach (['sarif', 'github', 'html'] as $format) {
            yield $format => [$format];
        }
    }

    /**
     * The sharpest case: the selection is clean, the run is not.
     */
    #[Test]
    #[DataProvider('structuredFormats')]
    public function itPublishesWhatACleanSelectionLeftOut(string $format): void
    {
        $output = $this->format($format, [], new OutOfScopeFindings(7, 2, 1));

        match ($format) {
            'json' => self::assertSame(
                ['violationCount' => 10, 'errorCount' => 7, 'warningCount' => 2, 'infoCount' => 1],
                self::decode($output)['outOfScope'],
            ),
            'metrics' => self::assertSame(
                ['violations' => 10, 'errors' => 7, 'warnings' => 2, 'info' => 1],
                self::decode($output)['outOfScope'],
            ),
            'sarif' => self::assertSame(
                [['level' => 'note', 'message' => ['text' => self::sentence()], 'descriptor' => ['id' => 'QMX-DRILL-DOWN-OUT-OF-SCOPE']]],
                self::decode($output)['runs'][0]['invocations'][0]['toolExecutionNotifications'],
            ),
            'github' => self::assertSame('::notice title=drill-down.out-of-scope::' . self::sentence() . "\n", $output),
            'html' => self::assertStringContainsString(
                'data-qmx-drill-down="out-of-scope" style="padding:12px;background:#78350f;color:#fff">' . self::sentence() . '</div>',
                $output,
            ),
            default => self::fail('No trace is asserted for ' . $format),
        };
    }

    /**
     * The trace sits beside the selection's own findings, not instead of them.
     */
    #[Test]
    #[DataProvider('structuredFormats')]
    public function itKeepsTheSelectionBesideTheTrace(string $format): void
    {
        $output = $this->format($format, [self::finding()], new OutOfScopeFindings(3, 0, 0));

        match ($format) {
            'json' => self::assertSame(
                [1, 3],
                [self::decode($output)['summary']['errorCount'], self::decode($output)['outOfScope']['errorCount']],
            ),
            'metrics' => self::assertSame(
                [1, 3],
                [self::decode($output)['summary']['errors'], self::decode($output)['outOfScope']['errors']],
            ),
            default => self::assertSame(
                [1, 1],
                [substr_count($output, 'Kept is too complex'), substr_count($output, '3 finding(s) outside')],
            ),
        };
    }

    #[Test]
    public function itPublishesTheJsonKeyAsNullWithoutASelection(): void
    {
        $json = self::decode($this->format('json', [self::finding()], null));
        $metrics = self::decode($this->format('metrics', [self::finding()], null));

        self::assertArrayHasKey('outOfScope', $json);
        self::assertNull($json['outOfScope']);
        self::assertArrayHasKey('outOfScope', $metrics);
        self::assertNull($metrics['outOfScope']);
    }

    /**
     * A selection that left nothing out is still a selection: zero, not "no
     * selection".
     */
    #[Test]
    public function itPublishesZeroesWhenTheSelectionLeftNothingOut(): void
    {
        $json = self::decode($this->format('json', [self::finding()], new OutOfScopeFindings(0, 0, 0)));
        $metrics = self::decode($this->format('metrics', [self::finding()], new OutOfScopeFindings(0, 0, 0)));

        self::assertSame(['violationCount' => 0, 'errorCount' => 0, 'warningCount' => 0, 'infoCount' => 0], $json['outOfScope']);
        self::assertSame(['violations' => 0, 'errors' => 0, 'warnings' => 0, 'info' => 0], $metrics['outOfScope']);
    }

    /**
     * A list format has no summary to hold a zero, so it adds an entry only
     * when there is something to explain.
     */
    #[Test]
    #[DataProvider('listFormats')]
    public function itAddsNoEntryWhenNothingLiesOutside(string $format): void
    {
        foreach ([null, new OutOfScopeFindings(0, 0, 0)] as $outOfScope) {
            $output = $this->format($format, [self::finding()], $outOfScope);

            self::assertStringNotContainsString('drill-down', $output);
            self::assertStringNotContainsString('DRILL-DOWN', $output);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function formatsWithoutAPlace(): iterable
    {
        foreach (OutOfScopeFindings::FORMATS_WITHOUT_A_PLACE as $format) {
            yield $format => [$format];
        }
    }

    /**
     * Every entry of these formats is a finding to its consumer, so the
     * selection is refused before they run and they publish no note: a clean
     * selection stays zero issues, not one.
     */
    #[Test]
    #[DataProvider('formatsWithoutAPlace')]
    public function itPublishesNothingOfTheSelectionInAFormatWithoutAPlace(string $format): void
    {
        self::assertSame(
            $this->format($format, [self::finding()], null),
            $this->format($format, [self::finding()], new OutOfScopeFindings(7, 2, 1)),
        );
    }

    /** A name no formatter answers to would leave the refusal guarding nothing. */
    #[Test]
    #[DataProvider('formatsWithoutAPlace')]
    public function itNamesARegisteredFormat(string $format): void
    {
        /** @var FormatterRegistryInterface $registry */
        $registry = (new ContainerFactory())->create()->get(FormatterRegistryInterface::class);

        self::assertSame($format, $registry->get($format)->getName());
    }

    private static function sentence(): string
    {
        return '10 finding(s) outside the --namespace/--class selection (7 error(s), 2 warning(s), 1 info)'
            . ' are not listed in this report; the exit code is resolved over them as well.';
    }

    /**
     * @param list<Finding> $findings
     */
    private function format(string $format, array $findings, ?OutOfScopeFindings $outOfScope): string
    {
        $builder = ReportBuilder::create()
            ->metrics(new InMemoryMetricRepository())
            ->addFindings($findings)
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->coverage(new ReportCoverage(2, 2, 0, 0));
        if ($outOfScope !== null) {
            $builder->outOfScope($outOfScope);
        }

        /** @var FormatterRegistryInterface $registry */
        $registry = (new ContainerFactory())->create()->get(FormatterRegistryInterface::class);

        return $registry->get($format)->format($builder->build(), new FormatterContext(
            useColor: false,
            basePath: '/project',
            namespace: $outOfScope === null ? null : NamespacePatternStub::subtree('App'),
        ));
    }

    private static function finding(): Finding
    {
        $symbol = SymbolPath::forClass('App', 'Kept');
        $file = RelativePath::fromString('src/Kept.php');

        return new Finding(
            location: new Location($file, 3),
            subject: MetricSubject::declaration(DeclarationPath::of($symbol, $file, DeclarationOrdinal::fromRank(0))),
            symbolPath: $symbol,
            ruleName: 'complexity.ccn',
            code: 'complexity.ccn',
            message: 'Class Kept is too complex',
            severity: Severity::Error,
        );
    }

    /** @return array<mixed> */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

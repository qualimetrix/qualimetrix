<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Integration;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Reporting\FindingProjection\SuppressionComposition;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\ReportBuilder;
use Qualimetrix\Reporting\ReportCoverage;
use Qualimetrix\Reporting\ReportProjectScope;

/**
 * A run over a slice of the project silences the channels that judge only a
 * whole-project run; a run over a project without a readable manifest judges
 * them against the paths alone. Either is a fact about the report the stderr
 * warning does not carry past `-q` or a machine format, so every format with a
 * place for it publishes the state.
 */
#[CoversClass(ReportProjectScope::class)]
final class ProjectScopePublicationTest extends TestCase
{
    private const array CHANNELS = ['architecture.unreachable-layer', 'discovery.unmatched-exclude'];

    private const array UNKNOWN_CHANNELS = ['suppression.unmatched-namespace'];

    private const array UNKNOWN_VALUES = [['option' => 'suppress_namespaces', 'pattern' => 'subtree:Tests']];

    private const array SKIPPED_VALUES = [['option' => 'suppress_paths', 'pattern' => 'subtree:tests/Legacy']];

    /** @return iterable<string, array{string}> */
    public static function documentFormats(): iterable
    {
        foreach (['json', 'metrics', 'suppressed'] as $format) {
            yield $format => [$format];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function noticeFormats(): iterable
    {
        foreach (['sarif', 'github', 'html', 'text', 'text-verbose', 'summary', 'health'] as $format) {
            yield $format => [$format];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function formatsWithoutAPlace(): iterable
    {
        foreach (['gitlab', 'checkstyle'] as $format) {
            yield $format => [$format];
        }
    }

    /** The key is there in every state, with the same keys inside it. */
    #[Test]
    #[DataProvider('documentFormats')]
    public function itPublishesTheStateUnderOneKeyOfOneShape(string $format): void
    {
        $narrowed = self::decode($this->format($format, self::narrowed()))['projectScope'];

        self::assertSame(
            ['state' => 'narrowed', 'uncoveredAutoloadTargets' => ['lib/'], 'unjudgedChannels' => self::CHANNELS, 'unjudgedValues' => []],
            $narrowed,
        );
        self::assertSame(
            ['state' => 'covered', 'uncoveredAutoloadTargets' => [], 'unjudgedChannels' => [], 'unjudgedValues' => []],
            self::decode($this->format($format, ReportProjectScope::covered()))['projectScope'],
        );
        self::assertSame(
            [
                'state' => 'covered',
                'uncoveredAutoloadTargets' => [],
                'unjudgedChannels' => ['suppression.unmatched-path'],
                'unjudgedValues' => self::SKIPPED_VALUES,
            ],
            self::decode($this->format($format, self::coveredWithSkippedValues()))['projectScope'],
        );
        self::assertSame(
            [
                'state' => 'unknown',
                'uncoveredAutoloadTargets' => [],
                'unjudgedChannels' => self::UNKNOWN_CHANNELS,
                'unjudgedValues' => self::UNKNOWN_VALUES,
            ],
            self::decode($this->format($format, self::unknown()))['projectScope'],
        );
    }

    #[Test]
    #[DataProvider('noticeFormats')]
    public function itNamesANarrowedRunAndTheChannelsItDidNotJudge(string $format): void
    {
        $output = $this->format($format, self::narrowed());

        self::assertStringContainsString('Project scope narrowed', $output);
        self::assertStringContainsString('lib/', $output);
        self::assertStringContainsString('architecture.unreachable-layer, discovery.unmatched-exclude', $output);
    }

    /** The list formats publish under a name of their own, not under a coverage failure's. */
    #[Test]
    public function itPublishesUnderTheProjectScopeName(): void
    {
        $sentence = (string) self::unknown()->describe();

        self::assertSame(
            '::notice title=run.project-scope::' . $sentence . "\n",
            $this->format('github', self::unknown()),
        );
        self::assertSame(
            [['level' => 'note', 'message' => ['text' => $sentence], 'descriptor' => ['id' => 'QMX-RUN-PROJECT-SCOPE']]],
            self::decode($this->format('sarif', self::unknown()))['runs'][0]['invocations'][0]['toolExecutionNotifications'],
        );
        self::assertStringContainsString('data-qmx-project-scope="unknown"', $this->format('html', self::unknown()));
    }

    #[Test]
    #[DataProvider('noticeFormats')]
    public function itNamesARunWhosePathsWereTakenAsTheProject(string $format): void
    {
        $output = $this->format($format, self::unknown());

        self::assertStringContainsString('Project scope unknown', $output);
        self::assertStringContainsString('suppress_namespaces', $output);
        self::assertStringContainsString('subtree:Tests', $output);
    }

    /**
     * A covered run that skipped a value is not the ordinary one: without the
     * line it read exactly like a run that judged the value and found it bound.
     */
    #[Test]
    #[DataProvider('noticeFormats')]
    public function itNamesTheValuesACoveredRunSkipped(string $format): void
    {
        $output = $this->format($format, self::coveredWithSkippedValues());

        self::assertStringContainsString('Project scope covered', $output);
        self::assertStringContainsString('suppress_paths', $output);
        self::assertStringContainsString('subtree:tests/Legacy', $output);
    }

    /** A narrowed run judges no value, so it has none to skip, and a list beside its channels could only contradict them. */
    #[Test]
    public function itRefusesSkippedValuesOnANarrowedRun(): void
    {
        $this->expectException(LogicException::class);

        self::narrowed()->withUnjudgedValues([]);
    }

    /** A covered run is the ordinary one and adds no entry to a list or a line to prose. */
    #[Test]
    #[DataProvider('noticeFormats')]
    public function itAddsNothingForACoveredRun(string $format): void
    {
        self::assertStringNotContainsString('Project scope', $this->format($format, ReportProjectScope::covered()));
    }

    /**
     * Every entry of these formats is a finding to its consumer, and narrowing
     * the run is the caller's choice, not a defect to count.
     */
    #[Test]
    #[DataProvider('formatsWithoutAPlace')]
    public function itPublishesNothingOfTheStateInAFormatWithoutAPlace(string $format): void
    {
        $covered = $this->format($format, ReportProjectScope::covered());

        self::assertSame($covered, $this->format($format, self::narrowed()));
        self::assertSame($covered, $this->format($format, self::unknown()));
        self::assertSame($covered, $this->format($format, self::coveredWithSkippedValues()));
    }

    private static function unknown(): ReportProjectScope
    {
        return ReportProjectScope::unknown()->withUnjudgedValues([
            ['channel' => 'suppression.unmatched-namespace', ...self::UNKNOWN_VALUES[0]],
        ]);
    }

    private static function coveredWithSkippedValues(): ReportProjectScope
    {
        return ReportProjectScope::covered()->withUnjudgedValues([
            ['channel' => 'suppression.unmatched-path', ...self::SKIPPED_VALUES[0]],
        ]);
    }

    private static function narrowed(): ReportProjectScope
    {
        return ReportProjectScope::narrowed(['lib/'], self::CHANNELS);
    }

    private function format(string $format, ReportProjectScope $projectScope): string
    {
        $report = ReportBuilder::create()
            ->metrics(new InMemoryMetricRepository())
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->coverage(new ReportCoverage(2, 2, 0, 0))
            ->projectScope($projectScope)
            // Read only by `suppressed`, which refuses a report without it.
            ->suppressionComposition(new SuppressionComposition([]))
            ->build();

        /** @var FormatterRegistryInterface $registry */
        $registry = (new ContainerFactory())->create()->get(FormatterRegistryInterface::class);

        return $registry->get($format)->format($report, new FormatterContext(useColor: false, basePath: '/project'));
    }

    /** @return array<mixed> */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

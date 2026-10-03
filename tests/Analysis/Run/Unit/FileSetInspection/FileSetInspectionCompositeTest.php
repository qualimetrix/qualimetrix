<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\FileSetInspection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationOptions;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Run\Contract\FileSetInspectionParticipantInterface;
use Qualimetrix\Analysis\Run\FileSetInspection\FileSetInspectionComposite;
use Qualimetrix\Analysis\Run\FileSetInspection\RuleSelectorProducerGate;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;
use RuntimeException;
use SplFileInfo;

#[CoversClass(FileSetInspectionComposite::class)]
final class FileSetInspectionCompositeTest extends TestCase
{
    #[Test]
    public function itResetsAllParticipantsBeforeTheFirstSelectionCheck(): void
    {
        $events = [];
        $alpha = new AlphaParticipant($events);
        $beta = new BetaParticipant($events);

        $this->composite([$alpha, $beta], disabled: ['alpha.rule'])->inspect([], $this->root());

        self::assertSame(['alpha.reset', 'beta.reset', 'beta.inspect'], $events);
        self::assertSame($events, $alpha->events());
        self::assertSame($events, $beta->events());
    }

    #[Test]
    public function itSkipsDisabledParticipantsWithoutInspectingFiles(): void
    {
        $events = [];
        $participant = new AlphaParticipant($events);

        $this->composite([$participant], disabled: ['alpha.rule'])->inspect([new SplFileInfo(__FILE__)], $this->root());

        self::assertSame(['alpha.reset'], $events);
    }

    #[Test]
    public function itClearsPriorCapabilityStateOnAnEnabledThenDisabledRun(): void
    {
        $events = [];
        $participant = new AlphaParticipant($events);
        $registry = new RuleOptionsRegistry();
        $composite = $this->composite([$participant], registry: $registry);
        $composite->inspect([new SplFileInfo(__FILE__)], $this->root());
        self::assertTrue($participant->hasResult);

        $metadata = [new RuleMetadata('alpha.rule', CodeDuplicationOptions::class, '', [], false)];
        $document = ResolvedOptionsFixture::document([['source' => 'config', 'values' => [
            'disabled_rules' => ['alpha.rule'],
        ]]], $this->root(), $metadata);
        $registry->replace(ResolvedOptionsFixture::ready(FindingConfiguration::fromDocument($document), $metadata));
        $composite->inspect([], $this->root());

        self::assertFalse($participant->hasResult);
    }

    #[Test]
    public function itReplacesPriorCapabilityStateOnAnEnabledThenNoMatchRun(): void
    {
        $events = [];
        $participant = new AlphaParticipant($events);
        $composite = $this->composite([$participant]);
        $composite->inspect([new SplFileInfo(__FILE__)], $this->root());
        self::assertTrue($participant->hasResult);

        $composite->inspect([], $this->root());

        self::assertFalse($participant->hasResult);
    }

    #[Test]
    public function itAcceptsAnEmptyParticipantSet(): void
    {
        $this->composite([])->inspect([], $this->root());

        self::addToAssertionCount(1);
    }

    #[Test]
    public function itExecutesEnabledParticipantsInCompilerOrder(): void
    {
        $events = [];

        $this->composite([new AlphaParticipant($events), new BetaParticipant($events)])
            ->inspect([], $this->root());

        self::assertSame(['alpha.reset', 'beta.reset', 'alpha.inspect', 'beta.inspect'], $events);
    }

    #[Test]
    public function itStopsTheProfilerSpanWhenAParticipantThrows(): void
    {
        $profiler = $this->createMock(ProfilerInterface::class);
        $profiler->expects(self::once())->method('start')->with('file-set-inspection.throwing', 'pipeline');
        $profiler->expects(self::once())->method('stop')->with('file-set-inspection.throwing');
        $this->expectException(RuntimeException::class);
        $this->composite([new ThrowingParticipant()], $profiler)->inspect([], $this->root());
    }

    #[Test]
    public function itUsesTheGenericParticipantSpanWithoutALogger(): void
    {
        $events = [];
        $profiler = $this->createMock(ProfilerInterface::class);
        $profiler->expects(self::once())->method('start')->with('file-set-inspection.alpha', 'pipeline');
        $profiler->expects(self::once())->method('stop')->with('file-set-inspection.alpha');
        $this->composite([new AlphaParticipant($events)], $profiler)->inspect([], $this->root());
    }

    /**
     * The defect this pair fixes: the two spellings of switching a producer
     * off answered differently, and only the selector one skipped the work.
     *
     * @param array<string, mixed> $ruleOptions
     */
    #[Test]
    #[TestWith([['alpha.rule' => ['enabled' => false]]])]
    #[TestWith([['alpha.rule' => false]])]
    public function itSkipsAParticipantItsOwnOptionsSwitchedOff(array $ruleOptions): void
    {
        $events = [];

        $this->composite([new AlphaParticipant($events), new BetaParticipant($events)], ruleOptions: $ruleOptions)
            ->inspect([new SplFileInfo(__FILE__)], $this->root());

        self::assertSame(['alpha.reset', 'beta.reset', 'beta.inspect'], $events);
    }

    /**
     * Malformed enablement must be refused before participant state changes.
     * Lawful writes that do not disable this producer still run it, including
     * a write addressing a different producer.
     *
     * @param array<string, mixed> $ruleOptions
     */
    #[Test]
    #[TestWith([['alpha.rule' => ['enabled' => 'false']], true, 'string'])]
    #[TestWith([['alpha.rule' => ['enabled' => 0]], true, 'int'])]
    #[TestWith([['alpha.rule' => ['enabled' => true]], false, null])]
    #[TestWith([['alpha.rule' => ['min_lines' => 5]], false, null])]
    #[TestWith([['beta.rule' => ['enabled' => false]], false, null])]
    public function itResolvesWrittenEnablementBeforeInspectingParticipants(array $ruleOptions, bool $invalidEnabled, ?string $refusedType): void
    {
        $events = [];

        try {
            $this->composite([new AlphaParticipant($events)], ruleOptions: $ruleOptions)
                ->inspect([new SplFileInfo(__FILE__)], $this->root());
        } catch (ConfigurationRefusal $refusal) {
            if (!$invalidEnabled) {
                throw $refusal;
            }
            self::assertSame(['rules', 'alpha.rule', 'enabled'], $refusal->position()?->segments);
            self::assertStringContainsString('must be boolean, got ' . $refusedType . '.', $refusal->summary());
            self::assertSame([], $events);

            return;
        }

        self::assertFalse($invalidEnabled, 'Malformed enablement must be refused before inspection.');
        self::assertSame(['alpha.reset', 'alpha.inspect'], $events);
    }

    /**
     * @param list<FileSetInspectionParticipantInterface> $participants
     * @param list<string> $disabled
     * @param array<string, mixed> $ruleOptions
     */
    private function composite(
        array $participants,
        ?ProfilerInterface $profiler = null,
        array $disabled = [],
        array $ruleOptions = [],
        ?RuleOptionsRegistry $registry = null,
    ): FileSetInspectionComposite {
        $metadata = [
            new RuleMetadata('alpha.rule', CodeDuplicationOptions::class, '', [], false),
            new RuleMetadata('beta.rule', CodeDuplicationOptions::class, '', [], false),
            new RuleMetadata('throwing.rule', CodeDuplicationOptions::class, '', [], false),
        ];
        $document = ResolvedOptionsFixture::document([['source' => 'config', 'values' => [
            'rules' => $ruleOptions,
            'disabled_rules' => $disabled,
        ]]], $this->root(), $metadata);
        $registry ??= new RuleOptionsRegistry();
        $registry->replace(ResolvedOptionsFixture::ready(FindingConfiguration::fromDocument($document), $metadata));

        return new FileSetInspectionComposite(
            $participants,
            new RuleSelectorProducerGate($registry),
            $profiler ?? self::createStub(ProfilerInterface::class),
        );
    }

    private function root(): AbsolutePath
    {
        return AbsolutePath::fromString((string) getcwd());
    }
}

final class AlphaParticipant implements FileSetInspectionParticipantInterface
{
    public bool $hasResult = false;

    /** @param list<string> $events */
    public function __construct(array &$events)
    {
        $this->events = &$events;
    }

    /** @var list<string> */
    private array $events;

    public static function participantId(): string
    {
        return 'alpha';
    }

    public static function producerRuleName(): string
    {
        return 'alpha.rule';
    }

    public function resetForRun(): void
    {
        $this->hasResult = false;
        $this->events[] = 'alpha.reset';
    }

    public function inspect(array $eligibleFiles, AbsolutePath $projectRoot): void
    {
        $this->hasResult = $eligibleFiles !== [];
        $this->events[] = 'alpha.inspect';
    }

    /** @return list<string> */
    public function events(): array
    {
        return $this->events;
    }
}

final class BetaParticipant implements FileSetInspectionParticipantInterface
{
    /** @param list<string> $events */
    public function __construct(array &$events)
    {
        $this->events = &$events;
    }

    /** @var list<string> */
    private array $events;

    public static function participantId(): string
    {
        return 'beta';
    }

    public static function producerRuleName(): string
    {
        return 'beta.rule';
    }

    public function resetForRun(): void
    {
        $this->events[] = 'beta.reset';
    }

    public function inspect(array $eligibleFiles, AbsolutePath $projectRoot): void
    {
        $this->events[] = 'beta.inspect';
    }

    /** @return list<string> */
    public function events(): array
    {
        return $this->events;
    }
}

final class ThrowingParticipant implements FileSetInspectionParticipantInterface
{
    public static function participantId(): string
    {
        return 'throwing';
    }

    public static function producerRuleName(): string
    {
        return 'throwing.rule';
    }

    public function resetForRun(): void {}

    public function inspect(array $eligibleFiles, AbsolutePath $projectRoot): void
    {
        throw new RuntimeException('failure');
    }
}

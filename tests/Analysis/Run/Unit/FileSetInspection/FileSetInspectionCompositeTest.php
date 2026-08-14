<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\FileSetInspection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Rule\InMemoryRuleChannelRegistry;
use Qualimetrix\Analysis\Run\Contract\FileSetInspectionParticipantInterface;
use Qualimetrix\Analysis\Run\FileSetInspection\FileSetInspectionComposite;
use Qualimetrix\Analysis\Run\FileSetInspection\RuleSelectorProducerGate;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Profiler\ProfilerInterface;
use RuntimeException;
use SplFileInfo;

#[CoversClass(FileSetInspectionComposite::class)]
final class FileSetInspectionCompositeTest extends TestCase
{
    protected function tearDown(): void
    {
        ProfilerHolder::reset();
    }

    #[Test]
    public function itResetsAllParticipantsBeforeTheFirstSelectionCheck(): void
    {
        $events = [];
        $alpha = new AlphaParticipant($events);
        $beta = new BetaParticipant($events);

        $this->composite([$alpha, $beta])->inspect([], [], ['alpha.rule']);

        self::assertSame(['alpha.reset', 'beta.reset', 'beta.inspect'], $events);
        self::assertSame($events, $alpha->events());
        self::assertSame($events, $beta->events());
    }

    #[Test]
    public function itSkipsDisabledParticipantsWithoutInspectingFiles(): void
    {
        $events = [];
        $participant = new AlphaParticipant($events);

        $this->composite([$participant])->inspect([new SplFileInfo(__FILE__)], [], ['alpha.rule']);

        self::assertSame(['alpha.reset'], $events);
    }

    #[Test]
    public function itClearsPriorCapabilityStateOnAnEnabledThenDisabledRun(): void
    {
        $events = [];
        $participant = new AlphaParticipant($events);
        $composite = $this->composite([$participant]);
        $composite->inspect([new SplFileInfo(__FILE__)], [], []);
        self::assertTrue($participant->hasResult);

        $composite->inspect([], [], ['alpha.rule']);

        self::assertFalse($participant->hasResult);
    }

    #[Test]
    public function itReplacesPriorCapabilityStateOnAnEnabledThenNoMatchRun(): void
    {
        $events = [];
        $participant = new AlphaParticipant($events);
        $composite = $this->composite([$participant]);
        $composite->inspect([new SplFileInfo(__FILE__)], [], []);
        self::assertTrue($participant->hasResult);

        $composite->inspect([], [], []);

        self::assertFalse($participant->hasResult);
    }

    #[Test]
    public function itAcceptsAnEmptyParticipantSet(): void
    {
        $this->composite([])->inspect([], [], []);

        self::addToAssertionCount(1);
    }

    #[Test]
    public function itExecutesEnabledParticipantsInCompilerOrder(): void
    {
        $events = [];

        $this->composite([new AlphaParticipant($events), new BetaParticipant($events)])->inspect([], [], []);

        self::assertSame(['alpha.reset', 'beta.reset', 'alpha.inspect', 'beta.inspect'], $events);
    }

    #[Test]
    public function itStopsTheProfilerSpanWhenAParticipantThrows(): void
    {
        $profiler = $this->createMock(ProfilerInterface::class);
        $profiler->expects(self::once())->method('start')->with('file-set-inspection.throwing', 'pipeline');
        $profiler->expects(self::once())->method('stop')->with('file-set-inspection.throwing');
        ProfilerHolder::set($profiler);

        $this->expectException(RuntimeException::class);
        $this->composite([new ThrowingParticipant()], new ProfilerHolder())->inspect([], [], []);
    }

    #[Test]
    public function itUsesTheGenericParticipantSpanWithoutALogger(): void
    {
        $events = [];
        $profiler = $this->createMock(ProfilerInterface::class);
        $profiler->expects(self::once())->method('start')->with('file-set-inspection.alpha', 'pipeline');
        $profiler->expects(self::once())->method('stop')->with('file-set-inspection.alpha');
        ProfilerHolder::set($profiler);

        $this->composite([new AlphaParticipant($events)], new ProfilerHolder())->inspect([], [], []);
    }

    /** @param list<FileSetInspectionParticipantInterface> $participants */
    private function composite(array $participants, ?ProfilerHolder $holder = null): FileSetInspectionComposite
    {
        return new FileSetInspectionComposite(
            $participants,
            new RuleSelectorProducerGate(new RuleSelector(new InMemoryRuleChannelRegistry())),
            $holder,
        );
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

    public function inspect(array $eligibleFiles): void
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

    public function inspect(array $eligibleFiles): void
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

    public function inspect(array $eligibleFiles): void
    {
        throw new RuntimeException('failure');
    }
}

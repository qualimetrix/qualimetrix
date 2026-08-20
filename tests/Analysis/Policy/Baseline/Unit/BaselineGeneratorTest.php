<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineCapture;
use Qualimetrix\Analysis\Policy\Baseline\BaselineGenerator;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\UncapturedReason;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Time\ClockInterface;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use Qualimetrix\Tests\Analysis\Finding\Support\ViolationFactory;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\FixedClock;

#[CoversClass(BaselineGenerator::class)]
final class BaselineGeneratorTest extends TestCase
{
    private BaselineGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new BaselineGenerator(
            StubChannelDeclarationRegistry::withDefaults(),
            new FixedClock(),
        );
    }

    #[Test]
    public function itStampsTheFileFromTheInjectedClock(): void
    {
        $baseline = $this->capture([], []);

        self::assertSame('2026-08-05T12:00:00+03:00', $baseline->generated->format('c'));
    }

    #[Test]
    public function itReadsTheClockExactlyOnceAfterGrouping(): void
    {
        $clock = new class implements ClockInterface {
            public int $calls = 0;

            public function now(): DateTimeImmutable
            {
                ++$this->calls;

                return new DateTimeImmutable('2026-08-05T12:00:00+03:00');
            }
        };
        $generator = new BaselineGenerator(StubChannelDeclarationRegistry::withDefaults(), $clock);

        $generator->generate([
            ViolationFactory::occurrence(SymbolPath::forFile(RelativePath::fromString('src/A.php'))),
            ViolationFactory::occurrence(SymbolPath::forFile(RelativePath::fromString('src/A.php'))),
        ], ['src']);

        self::assertSame(1, $clock->calls);
    }

    #[Test]
    public function itQueriesTheRegistryOncePerIdentityGroupAndKeepsFirstGroupOrder(): void
    {
        $delegate = StubChannelDeclarationRegistry::withDefaults();
        $registry = new class ($delegate) implements ChannelDeclarationRegistryInterface {
            public int $queries = 0;

            public function __construct(private StubChannelDeclarationRegistry $delegate) {}

            public function declarationFor(ViolationChannel $channel): ?ChannelDeclaration
            {
                ++$this->queries;

                return $this->delegate->declarationFor($channel);
            }

            public function staticDeclarations(): array
            {
                return $this->delegate->staticDeclarations();
            }
        };
        $generator = new BaselineGenerator($registry, new FixedClock());
        $first = SymbolPath::forFile(RelativePath::fromString('src/First.php'));
        $second = SymbolPath::forFile(RelativePath::fromString('src/Second.php'));

        $baseline = $generator->generate([
            ViolationFactory::occurrence($first),
            ViolationFactory::occurrence($second),
            ViolationFactory::occurrence($first),
        ], ['src'])->baseline;

        self::assertSame(2, $registry->queries);
        self::assertSame(
            [$first->toCanonical(), $second->toCanonical()],
            array_map(static fn($entry): string => $entry->identity->subjectKey, $baseline->entries),
        );
        self::assertSame([2, 1], array_map(static fn($entry): int => $entry->count, $baseline->entries));
    }

    #[Test]
    public function itCapturesTheMagnitudeOfASingleFinding(): void
    {
        $baseline = $this->capture(
            [ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 25)],
            ['src'],
        );

        self::assertSame(1, $baseline->count());
        self::assertSame([25.0], $baseline->entries[0]->magnitudes);
        self::assertSame(1, $baseline->entries[0]->count);
    }

    #[Test]
    public function itCollectsEveryMemberOfAGroupIntoOneEntry(): void
    {
        $file = SymbolPath::forFile(RelativePath::fromString('src/Legacy/dup.php'));

        $baseline = $this->capture([
            ViolationFactory::magnitude($file, 100, 'duplication.code-duplication', 'duplication.code-duplication'),
            ViolationFactory::magnitude($file, 40, 'duplication.code-duplication', 'duplication.code-duplication'),
        ], ['src']);

        self::assertSame(1, $baseline->count());
        self::assertSame(
            BaselineIdentity::forViolation(ViolationFactory::magnitude(
                $file,
                100,
                'duplication.code-duplication',
                'duplication.code-duplication',
            ))->key(),
            $baseline->entries[0]->identity->key(),
        );
        self::assertSame($baseline->entries[0]->identity->selector()->value, $baseline->entries[0]->selector()->value);
        self::assertSame(2, $baseline->entries[0]->count);
        self::assertSame([40.0, 100.0], $baseline->entries[0]->magnitudes);
    }

    #[Test]
    public function itCountsAnOccurrenceGroupWithoutStoringItsReportedNumber(): void
    {
        $file = SymbolPath::forFile(RelativePath::fromString('src/Legacy/bootstrap.php'));

        $baseline = $this->capture([
            ViolationFactory::occurrence($file),
            ViolationFactory::occurrence($file),
            ViolationFactory::occurrence($file),
        ], ['src']);

        self::assertSame(1, $baseline->count());
        self::assertNull($baseline->entries[0]->magnitudes);
        self::assertSame(3, $baseline->entries[0]->count);
    }

    #[Test]
    public function itKeepsTwoEdgesOutOfOneClassApart(): void
    {
        $source = SymbolPath::forClass('App\Web', 'Controller');

        $baseline = $this->capture([
            ViolationFactory::edge($source, SymbolPath::forClass('App\Db', 'Connection')),
            ViolationFactory::edge($source, SymbolPath::forClass('App\Db', 'Statement')),
        ], ['src']);

        self::assertSame(2, $baseline->count());
    }

    #[Test]
    public function itKeepsSemanticOccurrencesOnTheSameSubjectAndChannelApart(): void
    {
        $subject = SymbolPath::forFile(RelativePath::fromString('src/Occurrence.php'));
        $first = $this->occurrenceWithKey($subject, OccurrenceKey::semantic('goto', ['slot' => 1]));
        $second = $this->occurrenceWithKey($subject, OccurrenceKey::semantic('goto', ['slot' => 2]));

        $baseline = $this->capture([$first, $second], ['src']);

        self::assertSame(
            [BaselineIdentity::forViolation($first)->key(), BaselineIdentity::forViolation($second)->key()],
            array_map(static fn($entry): string => $entry->identity->key(), $baseline->entries),
        );
        self::assertSame(
            [$first->occurrenceKey?->value, $second->occurrenceKey?->value],
            array_map(static fn($entry): ?string => $entry->identity->occurrenceKey, $baseline->entries),
        );
        self::assertSame(
            array_map(static fn($entry): string => $entry->identity->selector()->value, $baseline->entries),
            array_map(static fn($entry): string => $entry->selector()->value, $baseline->entries),
        );
        self::assertNotSame($baseline->entries[0]->selector()->value, $baseline->entries[1]->selector()->value);
    }

    #[Test]
    public function itKeepsTypedAndUntypedEdgesToTheSameTargetApart(): void
    {
        $source = SymbolPath::forClass('App', 'Source');
        $target = SymbolPath::forClass('Vendor', 'Target');
        $subject = MetricSubject::declaration(DeclarationPath::of($source, RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0)));
        $typed = ViolationFactory::edge($source, $target, DependencyType::New_, $subject);
        $untyped = $this->edgeWithType($source, $target, $subject, null);

        $baseline = $this->capture([$typed, $untyped], ['src']);

        self::assertSame(
            [BaselineIdentity::forViolation($typed)->key(), BaselineIdentity::forViolation($untyped)->key()],
            array_map(static fn($entry): string => $entry->identity->key(), $baseline->entries),
        );
        self::assertSame(
            [DependencyType::New_, null],
            array_map(static fn($entry): ?DependencyType => $entry->identity->edge?->type, $baseline->entries),
        );
        self::assertSame(
            [$target->toCanonical(), $target->toCanonical()],
            array_map(static fn($entry): ?string => $entry->identity->edge?->target, $baseline->entries),
        );
        self::assertNotSame($baseline->entries[0]->selector()->value, $baseline->entries[1]->selector()->value);
    }

    /**
     * An entry that could never be applied would be reported as inert on
     * every later run while suppressing nothing.
     */
    #[Test]
    public function itSkipsAChannelNoRuleDeclares(): void
    {
        $baseline = $this->capture([
            ViolationFactory::magnitude(
                SymbolPath::forMethod('App', 'Foo', 'bar'),
                5,
                'nobody.declares',
                'this.channel',
            ),
        ], ['src']);

        self::assertSame(0, $baseline->count());
    }

    /**
     * The skip above is sanctioned; the skip being *silent* was not. A group
     * that becomes no entry can never be reported as inert either — nothing
     * is written for it — so the capture has to hand the refusal back.
     */
    #[Test]
    public function itNamesTheGroupsItRefusedToCapture(): void
    {
        $first = ViolationFactory::magnitude(
            SymbolPath::forMethod('App', 'Foo', 'bar'),
            5,
            'nobody.declares',
            'this.channel',
        );
        $second = $this->violationWithoutMagnitude();

        $capture = $this->generator->generate([$first, $second, $first], ['src']);

        self::assertSame(0, $capture->baseline->count());
        self::assertCount(2, $capture->uncaptured);
        self::assertSame(
            [BaselineIdentity::forViolation($first)->key(), BaselineIdentity::forViolation($second)->key()],
            array_map(static fn($group): string => $group->identity->key(), $capture->uncaptured),
        );
        self::assertSame(
            [UncapturedReason::UndeclaredChannel, UncapturedReason::MagnitudeUnavailable],
            array_map(static fn($group): UncapturedReason => $group->reason, $capture->uncaptured),
        );
        self::assertSame([2, 1], array_map(static fn($group): int => $group->memberCount, $capture->uncaptured));
        self::assertSame(
            ['complexity.cyclomatic#complexity.cyclomatic.callable', 'nobody.declares#this.channel'],
            $capture->uncapturedChannels(),
        );
    }

    #[Test]
    public function itMaterializesRejectedGroupRecordsAtTheCaptureBoundary(): void
    {
        $baseline = $this->capture([], ['src']);
        $identity = new BaselineIdentity(
            'project:',
            new ViolationChannel('nobody.declares', 'this.channel'),
        );

        $empty = BaselineCapture::fromRejectedGroups($baseline, []);
        $capture = BaselineCapture::fromRejectedGroups($baseline, [[
            'identity' => $identity,
            'reason' => UncapturedReason::UndeclaredChannel,
            'memberCount' => 2,
        ]]);

        self::assertSame([], $empty->uncaptured);
        self::assertCount(1, $capture->uncaptured);
        self::assertSame($identity, $capture->uncaptured[0]->identity);
        self::assertSame(UncapturedReason::UndeclaredChannel, $capture->uncaptured[0]->reason);
        self::assertSame(2, $capture->uncaptured[0]->memberCount);
    }

    #[Test]
    public function itSkipsAMagnitudeGroupWhoseMemberReportsNoNumber(): void
    {
        $capture = $this->generator->generate([$this->violationWithoutMagnitude()], ['src']);

        self::assertSame(0, $capture->baseline->count());
        self::assertCount(1, $capture->uncaptured);
        self::assertSame(UncapturedReason::MagnitudeUnavailable, $capture->uncaptured[0]->reason);
    }

    #[Test]
    public function itRejectsANonFiniteMagnitudeWithoutLosingItsIdentity(): void
    {
        $violation = $this->violationWithMagnitude(\INF);

        $capture = $this->generator->generate([$violation], ['src']);

        self::assertSame([], $capture->baseline->entries);
        self::assertSame(
            [BaselineIdentity::forViolation($violation)->key()],
            array_map(static fn($group): string => $group->identity->key(), $capture->uncaptured),
        );
        self::assertSame(UncapturedReason::MagnitudeUnavailable, $capture->uncaptured[0]->reason);
    }

    private function violationWithoutMagnitude(): Violation
    {
        return new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 1),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forMethod('App', 'Foo', 'bar'), RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0))),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'no magnitude reported',
            severity: Severity::Warning,
        );
    }

    private function violationWithMagnitude(float $magnitude): Violation
    {
        return new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 1),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forMethod('App', 'Foo', 'bar'), RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0))),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'magnitude reported',
            severity: Severity::Warning,
            metricValue: $magnitude,
        );
    }

    private function occurrenceWithKey(SymbolPath $symbol, OccurrenceKey $occurrenceKey): Violation
    {
        return new Violation(
            location: new Location(RelativePath::fromString('src/Occurrence.php'), 7),
            subject: MetricSubject::aggregate($symbol),
            symbolPath: $symbol,
            ruleName: 'code-smell.goto',
            violationCode: 'code-smell.goto',
            message: 'occurrence finding',
            severity: Severity::Warning,
            metricValue: 1.0,
            occurrenceKey: $occurrenceKey,
        );
    }

    private function edgeWithType(
        SymbolPath $source,
        SymbolPath $target,
        MetricSubject $subject,
        ?DependencyType $type,
    ): Violation {
        return new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 11),
            subject: $subject,
            symbolPath: $source,
            ruleName: 'architecture.layer-violation',
            violationCode: 'architecture.layer-violation',
            message: 'forbidden dependency',
            severity: Severity::Error,
            dependencyTarget: $target,
            dependencyType: $type,
        );
    }

    #[Test]
    public function itSeparatesSameFqnFindingsWithDifferentDeclarationSubjects(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Duplicated', 'run');

        $baseline = $this->capture([
            ViolationFactory::magnitude($symbol, 12, subject: MetricSubject::declaration(DeclarationPath::of($symbol, RelativePath::fromString('src/a/Duplicated.php'), DeclarationOrdinal::fromRank(0)))),
            ViolationFactory::magnitude($symbol, 30, subject: MetricSubject::declaration(DeclarationPath::of($symbol, RelativePath::fromString('src/b/Duplicated.php'), DeclarationOrdinal::fromRank(0)))),
        ], ['src']);

        self::assertSame(2, $baseline->count());
    }

    #[Test]
    public function itRecordsTheScopeNormalized(): void
    {
        $baseline = $this->capture([], ['tests/', 'src', 'src/', 'src']);

        self::assertSame(['src', 'tests'], $baseline->scope);
    }

    #[Test]
    public function itKeepsTheFilesystemRootInScope(): void
    {
        self::assertSame(['/'], $this->capture([], ['/'])->scope);
    }

    #[Test]
    public function itProducesEntriesAddressableByTheIdentityOfTheirFindings(): void
    {
        $violation = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 25);

        $baseline = $this->capture([$violation], ['src']);

        self::assertSame(BaselineIdentity::forViolation($violation)->key(), $baseline->entries[0]->identity->key());
        self::assertSame(
            BaselineIdentity::forViolation($violation)->selector()->value,
            $baseline->entries[0]->selector()->value,
        );
    }

    /**
     * @param list<Violation> $violations
     * @param list<string> $scope
     */
    private function capture(array $violations, array $scope): Baseline
    {
        return $this->generator->generate($violations, $scope)->baseline;
    }
}

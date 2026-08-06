<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Baseline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineGenerator;
use Qualimetrix\Baseline\BaselineIdentity;
use Qualimetrix\Baseline\UncapturedReason;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Core\Violation\ViolationChannel;
use Qualimetrix\Tests\Support\Time\FixedClock;
use Qualimetrix\Tests\Support\Violation\StubChannelDeclarationRegistry;
use Qualimetrix\Tests\Support\Violation\ViolationFactory;

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
        $capture = $this->generator->generate([
            ViolationFactory::magnitude(
                SymbolPath::forMethod('App', 'Foo', 'bar'),
                5,
                'nobody.declares',
                'this.channel',
            ),
            ViolationFactory::magnitude(
                SymbolPath::forMethod('App', 'Foo', 'baz'),
                7,
                'nobody.declares',
                'this.channel',
            ),
        ], ['src']);

        self::assertSame(0, $capture->baseline->count());
        self::assertCount(2, $capture->uncaptured);
        self::assertSame(UncapturedReason::UndeclaredChannel, $capture->uncaptured[0]->reason);
        self::assertSame(1, $capture->uncaptured[0]->memberCount);
        self::assertSame(['nobody.declares#this.channel'], $capture->uncapturedChannels());
    }

    #[Test]
    public function itSkipsAMagnitudeGroupWhoseMemberReportsNoNumber(): void
    {
        $capture = $this->generator->generate([$this->violationWithoutMagnitude()], ['src']);

        self::assertSame(0, $capture->baseline->count());
        self::assertCount(1, $capture->uncaptured);
        self::assertSame(UncapturedReason::MagnitudeUnavailable, $capture->uncaptured[0]->reason);
    }

    private function violationWithoutMagnitude(): Violation
    {
        return new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 1),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
            message: 'no magnitude reported',
            severity: Severity::Warning,
        );
    }

    #[Test]
    public function itMergesFindingsOfOneIdentityReportedFromDifferentFiles(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Duplicated', 'run');

        $baseline = $this->capture([
            ViolationFactory::magnitude($symbol, 12),
            ViolationFactory::magnitude($symbol, 30),
        ], ['src']);

        self::assertSame(1, $baseline->count(), 'Two same-FQN declarations share one entry (§13.9).');
        self::assertSame([12.0, 30.0], $baseline->entries[0]->magnitudes);
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

        self::assertTrue($baseline->hasIdentity(BaselineIdentity::forViolation($violation)));
        self::assertTrue($baseline->hasIdentity(new BaselineIdentity(
            'method:App\Foo::bar',
            new ViolationChannel('complexity.cyclomatic', 'complexity.cyclomatic.method'),
        )));
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

<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Suppression\SuppressionFilter;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(SuppressionFilter::class)]
final class SuppressionFilterTest extends TestCase
{
    #[Test]
    public function itFileLevelSuppressesAllMatchingFindingsInFile(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 1, SuppressionType::File),
        ]);

        $finding1 = $this->createFinding('src/Foo.php', 10, 'complexity');
        $finding2 = $this->createFinding('src/Foo.php', 100, 'complexity');
        $finding3 = $this->createFinding('src/Foo.php', 50, 'coupling');

        self::assertFalse($filter->shouldInclude($finding1), 'File suppression should suppress matching violation at line 10');
        self::assertFalse($filter->shouldInclude($finding2), 'File suppression should suppress matching violation at line 100');
        self::assertTrue($filter->shouldInclude($finding3), 'File suppression should not suppress non-matching violation');
    }

    #[Test]
    public function itSymbolLevelSuppressesFindingsAtOrAfterSuppressionLine(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10, SuppressionType::Symbol, subject: $this->subject(), controlScope: ControlScope::Callable),
        ]);

        $findingBefore = $this->createFinding('src/Foo.php', 5, 'complexity');
        $findingAtLine = $this->createFinding('src/Foo.php', 10, 'complexity');
        $findingAfter = $this->createFinding('src/Foo.php', 42, 'complexity');

        self::assertFalse($filter->shouldInclude($findingBefore), 'Symbol suppression is exact-subject-bound, not line-bound');
        self::assertFalse($filter->shouldInclude($findingAtLine), 'Symbol suppression should suppress violations at suppression line');
        self::assertFalse($filter->shouldInclude($findingAfter), 'Symbol suppression should suppress violations after suppression line');
    }

    #[Test]
    public function itSymbolLevelDoesNotAffectFindingsBeforeSuppressionLine(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 20, SuppressionType::Symbol, subject: $this->subject(), controlScope: ControlScope::Callable),
        ]);

        $finding = $this->createFinding('src/Foo.php', 5, 'complexity');

        self::assertFalse($filter->shouldInclude($finding), 'Symbol suppression is exact-subject-bound, not line-bound');
    }

    #[Test]
    public function itNextLineSuppressesOnlySpecificNextLine(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10, SuppressionType::NextLine),
        ]);

        $findingOnNextLine = $this->createFinding('src/Foo.php', 11, 'complexity');
        $findingOnSameLine = $this->createFinding('src/Foo.php', 10, 'complexity');
        $findingOnLinePlus2 = $this->createFinding('src/Foo.php', 12, 'complexity');
        $findingBefore = $this->createFinding('src/Foo.php', 5, 'complexity');

        self::assertFalse($filter->shouldInclude($findingOnNextLine), 'NextLine suppression should suppress violation on line+1');
        self::assertTrue($filter->shouldInclude($findingOnSameLine), 'NextLine suppression should NOT suppress violation on same line');
        self::assertTrue($filter->shouldInclude($findingOnLinePlus2), 'NextLine suppression should NOT suppress violation on line+2');
        self::assertTrue($filter->shouldInclude($findingBefore), 'NextLine suppression should NOT suppress violation before suppression');
    }

    #[Test]
    public function itNextLineDoesNotSuppressLinePlus2(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10, SuppressionType::NextLine),
        ]);

        $finding = $this->createFinding('src/Foo.php', 12, 'complexity');

        self::assertTrue($filter->shouldInclude($finding), 'NextLine suppression must not affect line+2');
    }

    #[Test]
    public function itWildcardFileSuppressesAllRules(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('*', null, 1, SuppressionType::File),
        ]);

        $finding1 = $this->createFinding('src/Foo.php', 42, 'complexity');
        $finding2 = $this->createFinding('src/Foo.php', 50, 'coupling');

        self::assertFalse($filter->shouldInclude($finding1));
        self::assertFalse($filter->shouldInclude($finding2));
    }

    #[Test]
    public function itPassesThroughWhenNoSuppressions(): void
    {
        $filter = new SuppressionFilter();

        $finding = $this->createFinding('src/Foo.php', 42, 'complexity');

        self::assertTrue($filter->shouldInclude($finding), 'Violation should pass when no suppressions');
    }

    #[Test]
    public function itPassesThroughWhenDifferentFile(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10, SuppressionType::File),
        ]);

        $finding = $this->createFinding('src/Bar.php', 42, 'complexity');

        self::assertTrue($filter->shouldInclude($finding));
    }

    #[Test]
    public function itGetSuppressedFindings(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10, SuppressionType::Symbol, subject: $this->subject(), controlScope: ControlScope::Callable),
        ]);

        $finding1 = $this->createFinding('src/Foo.php', 42, 'complexity');
        $finding2 = $this->createFinding('src/Foo.php', 50, 'coupling');

        $suppressed = $filter->getSuppressedFindings([$finding1, $finding2]);

        self::assertCount(1, $suppressed);
        self::assertSame($finding1, $suppressed[0]);
    }

    #[Test]
    public function itSuppressionMatchesCodeWithAGroupSelector(): void
    {
        $filter = new SuppressionFilter();
        // Suppress 'complexity' — should match all complexity.* finding codes
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity.cyclomatic.*', null, 10, SuppressionType::Symbol, subject: $this->subject(), controlScope: ControlScope::Callable),
        ]);

        $finding1 = new Finding(
            location: new Location(RelativePath::fromString('src/Foo.php'), 42),
            subject: $this->subject(),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic.callable',
            message: 'Test message',
            severity: Severity::Warning,
        );

        $finding2 = new Finding(
            location: new Location(RelativePath::fromString('src/Foo.php'), 50),
            subject: $this->subject(),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'baz'),
            ruleName: 'coupling.distance',
            code: 'coupling.distance',
            message: 'Test message',
            severity: Severity::Error,
        );

        self::assertFalse($filter->shouldInclude($finding1), 'complexity.cyclomatic.callable should be suppressed by complexity.cyclomatic.*');
        self::assertTrue($filter->shouldInclude($finding2), 'coupling.distance should not be suppressed by complexity.cyclomatic.*');
    }

    #[Test]
    public function itHealthDimensionSuppressionLeavesSiblingChannelsActive(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('health.cohesion', 'Structurally inapplicable', 10, SuppressionType::Symbol, subject: $this->subject(), controlScope: ControlScope::Callable, endLine: 50),
        ]);

        $cohesion = new Finding(
            location: new Location(RelativePath::fromString('src/Foo.php'), 20),
            subject: $this->subject(),
            symbolPath: SymbolPath::forClass('App', 'Foo'),
            ruleName: 'computed.health',
            code: 'health.cohesion',
            message: 'Cohesion health is low',
            severity: Severity::Error,
        );
        $coupling = new Finding(
            location: new Location(RelativePath::fromString('src/Foo.php'), 20),
            subject: $this->subject(),
            symbolPath: SymbolPath::forClass('App', 'Foo'),
            ruleName: 'computed.health',
            code: 'health.coupling',
            message: 'Coupling health is low',
            severity: Severity::Error,
        );

        self::assertFalse($filter->shouldInclude($cohesion));
        self::assertTrue($filter->shouldInclude($coupling));
    }

    #[Test]
    public function itMultipleSuppressionsForSameFile(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10, SuppressionType::Symbol, subject: $this->subject(), controlScope: ControlScope::Callable),
            new Suppression('coupling', null, 20, SuppressionType::Symbol, subject: $this->subject(), controlScope: ControlScope::Callable),
        ]);

        $finding1 = $this->createFinding('src/Foo.php', 42, 'complexity');
        $finding2 = $this->createFinding('src/Foo.php', 50, 'coupling');
        $finding3 = $this->createFinding('src/Foo.php', 60, 'size');

        self::assertFalse($filter->shouldInclude($finding1));
        self::assertFalse($filter->shouldInclude($finding2));
        self::assertTrue($filter->shouldInclude($finding3));
    }

    #[Test]
    public function itPassesNonSuppressedFinding(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10, SuppressionType::Symbol, subject: $this->subject(), controlScope: ControlScope::Callable),
        ]);

        $finding = $this->createFinding('src/Foo.php', 42, 'coupling');

        self::assertTrue($filter->shouldInclude($finding), 'Non-suppressed violation should pass through');
    }

    #[Test]
    public function itClearSuppressionsResetsState(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 1, SuppressionType::File),
        ]);

        $finding = $this->createFinding('src/Foo.php', 10, 'complexity');
        self::assertFalse($filter->shouldInclude($finding), 'Violation should be suppressed before clear');

        $filter->clearSuppressions();

        self::assertTrue($filter->shouldInclude($finding), 'Violation should pass after clearSuppressions');
    }

    #[Test]
    public function itSuppressionsDoNotAccumulateAcrossMultipleLoads(): void
    {
        $filter = new SuppressionFilter();

        // First load: suppress complexity in Foo.php
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 1, SuppressionType::File),
        ]);

        $fooFinding = $this->createFinding('src/Foo.php', 10, 'complexity');
        self::assertFalse($filter->shouldInclude($fooFinding));

        // Second load: clear and load different suppressions
        $filter->clearSuppressions();
        $filter->setSuppressions('src/Bar.php', [
            new Suppression('coupling', null, 1, SuppressionType::File),
        ]);

        // Old suppression from Foo.php should no longer apply
        self::assertTrue($filter->shouldInclude($fooFinding), 'Old suppression for Foo.php should not persist after clear+reload');

        $barFinding = $this->createFinding('src/Bar.php', 10, 'coupling');
        self::assertFalse($filter->shouldInclude($barFinding), 'New suppression for Bar.php should work');
    }

    #[Test]
    public function itSymbolSuppressionDoesNotSuppressNullLineFinding(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('coupling', null, 10, SuppressionType::Symbol, subject: $this->subject(), controlScope: ControlScope::Callable),
        ]);

        // Symbol suppression matches the exact bound subject independently of presentation line.
        $finding = new Finding(
            location: new Location(RelativePath::fromString('src/Foo.php'), null),
            subject: $this->subject(),
            symbolPath: SymbolPath::forNamespace('App'),
            ruleName: 'coupling',
            code: 'coupling',
            message: 'Test',
            severity: Severity::Warning,
        );

        self::assertFalse($filter->shouldInclude($finding));
    }

    #[Test]
    public function itSymbolSuppressionDoesNotAffectFindingsAfterSymbolEndLine(): void
    {
        $filter = new SuppressionFilter();
        // Suppression on first class (lines 10-50), should NOT suppress second class (line 60)
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10, SuppressionType::Symbol, subject: $this->subject(), controlScope: ControlScope::Callable, endLine: 50),
        ]);

        $findingInFirstClass = $this->createFinding('src/Foo.php', 30, 'complexity');
        $findingInSecondClass = $this->createFinding('src/Foo.php', 60, 'complexity');
        $findingAtEndLine = $this->createFinding('src/Foo.php', 50, 'complexity');

        self::assertFalse($filter->shouldInclude($findingInFirstClass), 'Violation inside suppressed symbol should be suppressed');
        self::assertFalse($filter->shouldInclude($findingAtEndLine), 'Violation at symbol end line should be suppressed');
        self::assertFalse($filter->shouldInclude($findingInSecondClass), 'Symbol suppression is exact-subject-bound, not line-bound');
    }

    #[Test]
    public function itSymbolSuppressionWithoutEndLineActsUntilEndOfFile(): void
    {
        $filter = new SuppressionFilter();
        // Legacy behavior: no endLine means suppress until EOF
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10, SuppressionType::Symbol, subject: $this->subject(), controlScope: ControlScope::Callable, endLine: null),
        ]);

        $finding = $this->createFinding('src/Foo.php', 999, 'complexity');

        self::assertFalse($filter->shouldInclude($finding), 'Suppression without endLine should suppress until end of file');
    }

    #[Test]
    public function itResolvesTargetControlsIndependentlyOfThePresentationFileAndRebuildsTheIndex(): void
    {
        $filter = new SuppressionFilter();
        $targetDeclaration = DeclarationPath::of(SymbolPath::forMethod('App', 'Target', 'run'), RelativePath::fromString('src/Target.php'), DeclarationOrdinal::fromRank(0));
        $sourceDeclaration = DeclarationPath::of(SymbolPath::forMethod('App', 'Source', 'call'), RelativePath::fromString('src/Source.php'), DeclarationOrdinal::fromRank(0));
        $targetSubject = MetricSubject::declaration($targetDeclaration);
        $sourceSubject = MetricSubject::declaration($sourceDeclaration);
        $targetFinding = new Finding(
            location: new Location(RelativePath::fromString('src/Source.php'), 42),
            subject: $targetSubject,
            symbolPath: $targetDeclaration->logical,
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic',
            message: 'Target finding reported at its use site',
            severity: Severity::Warning,
        );
        $targetControl = new Suppression(
            'complexity.cyclomatic',
            null,
            10,
            SuppressionType::Symbol,
            subject: $targetSubject,
            controlScope: ControlScope::Callable,
        );
        $sourceControl = new Suppression(
            'complexity.cyclomatic',
            null,
            10,
            SuppressionType::Symbol,
            subject: $sourceSubject,
            controlScope: ControlScope::Callable,
        );

        $filter->setSuppressions('src/Target.php', [$targetControl]);
        $filter->setSuppressions('src/Source.php', [
            $sourceControl,
            new Suppression('physical.file', null, 1, SuppressionType::File),
            new Suppression('physical.next', null, 10, SuppressionType::NextLine),
        ]);
        self::assertFalse($filter->shouldInclude($targetFinding));

        $filter->setSuppressions('src/Target.php', []);
        self::assertTrue($filter->shouldInclude($targetFinding), 'A source control must not suppress a different target subject');

        $filter->setSuppressions('src/Target.php', [$targetControl]);
        self::assertFalse($filter->shouldInclude($targetFinding));
        self::assertFalse($filter->shouldInclude(new Finding(
            location: new Location(RelativePath::fromString('src/Source.php'), 42),
            subject: $targetSubject,
            symbolPath: $targetDeclaration->logical,
            ruleName: 'physical.file',
            code: 'physical.file',
            message: 'Physical file suppression',
            severity: Severity::Warning,
        )));
        self::assertFalse($filter->shouldInclude(new Finding(
            location: new Location(RelativePath::fromString('src/Source.php'), 11),
            subject: $targetSubject,
            symbolPath: $targetDeclaration->logical,
            ruleName: 'physical.next',
            code: 'physical.next',
            message: 'Physical next-line suppression',
            severity: Severity::Warning,
        )));

        $filter->clearSuppressions();
        self::assertTrue($filter->shouldInclude($targetFinding));
    }

    private function createFinding(string $file, int $line, string $code): Finding
    {
        return new Finding(
            location: new Location(RelativePath::fromString($file), $line),
            subject: $this->subject(),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: $code,
            code: $code,
            message: 'Test message',
            severity: Severity::Warning,
        );
    }

    private function subject(): MetricSubject
    {
        return MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('src/Foo.php')));
    }
}

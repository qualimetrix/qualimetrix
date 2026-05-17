<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Baseline\Suppression;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\Suppression\SuppressionFilter;
use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Suppression\SuppressionType;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;

#[CoversClass(SuppressionFilter::class)]
final class SuppressionFilterTest extends TestCase
{
    #[Test]
    public function itFileLevelSuppressesAllMatchingViolationsInFile(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 1, SuppressionType::File),
        ]);

        $violation1 = $this->createViolation('src/Foo.php', 10, 'complexity');
        $violation2 = $this->createViolation('src/Foo.php', 100, 'complexity');
        $violation3 = $this->createViolation('src/Foo.php', 50, 'coupling');

        self::assertFalse($filter->shouldInclude($violation1), 'File suppression should suppress matching violation at line 10');
        self::assertFalse($filter->shouldInclude($violation2), 'File suppression should suppress matching violation at line 100');
        self::assertTrue($filter->shouldInclude($violation3), 'File suppression should not suppress non-matching violation');
    }

    #[Test]
    public function itSymbolLevelSuppressesViolationsAtOrAfterSuppressionLine(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10, SuppressionType::Symbol),
        ]);

        $violationBefore = $this->createViolation('src/Foo.php', 5, 'complexity');
        $violationAtLine = $this->createViolation('src/Foo.php', 10, 'complexity');
        $violationAfter = $this->createViolation('src/Foo.php', 42, 'complexity');

        self::assertTrue($filter->shouldInclude($violationBefore), 'Symbol suppression should NOT affect violations before suppression line');
        self::assertFalse($filter->shouldInclude($violationAtLine), 'Symbol suppression should suppress violations at suppression line');
        self::assertFalse($filter->shouldInclude($violationAfter), 'Symbol suppression should suppress violations after suppression line');
    }

    #[Test]
    public function itSymbolLevelDoesNotAffectViolationsBeforeSuppressionLine(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 20, SuppressionType::Symbol),
        ]);

        $violation = $this->createViolation('src/Foo.php', 5, 'complexity');

        self::assertTrue($filter->shouldInclude($violation), 'Symbol suppression must not affect violations before its line');
    }

    #[Test]
    public function itNextLineSuppressesOnlySpecificNextLine(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10, SuppressionType::NextLine),
        ]);

        $violationOnNextLine = $this->createViolation('src/Foo.php', 11, 'complexity');
        $violationOnSameLine = $this->createViolation('src/Foo.php', 10, 'complexity');
        $violationOnLinePlus2 = $this->createViolation('src/Foo.php', 12, 'complexity');
        $violationBefore = $this->createViolation('src/Foo.php', 5, 'complexity');

        self::assertFalse($filter->shouldInclude($violationOnNextLine), 'NextLine suppression should suppress violation on line+1');
        self::assertTrue($filter->shouldInclude($violationOnSameLine), 'NextLine suppression should NOT suppress violation on same line');
        self::assertTrue($filter->shouldInclude($violationOnLinePlus2), 'NextLine suppression should NOT suppress violation on line+2');
        self::assertTrue($filter->shouldInclude($violationBefore), 'NextLine suppression should NOT suppress violation before suppression');
    }

    #[Test]
    public function itNextLineDoesNotSuppressLinePlus2(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10, SuppressionType::NextLine),
        ]);

        $violation = $this->createViolation('src/Foo.php', 12, 'complexity');

        self::assertTrue($filter->shouldInclude($violation), 'NextLine suppression must not affect line+2');
    }

    #[Test]
    public function itWildcardFileSuppressesAllRules(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('*', null, 1, SuppressionType::File),
        ]);

        $violation1 = $this->createViolation('src/Foo.php', 42, 'complexity');
        $violation2 = $this->createViolation('src/Foo.php', 50, 'coupling');

        self::assertFalse($filter->shouldInclude($violation1));
        self::assertFalse($filter->shouldInclude($violation2));
    }

    #[Test]
    public function itPassesThroughWhenNoSuppressions(): void
    {
        $filter = new SuppressionFilter();

        $violation = $this->createViolation('src/Foo.php', 42, 'complexity');

        self::assertTrue($filter->shouldInclude($violation), 'Violation should pass when no suppressions');
    }

    #[Test]
    public function itPassesThroughWhenDifferentFile(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10, SuppressionType::File),
        ]);

        $violation = $this->createViolation('src/Bar.php', 42, 'complexity');

        self::assertTrue($filter->shouldInclude($violation));
    }

    #[Test]
    public function itGetSuppressedViolations(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10, SuppressionType::Symbol),
        ]);

        $violation1 = $this->createViolation('src/Foo.php', 42, 'complexity');
        $violation2 = $this->createViolation('src/Foo.php', 50, 'coupling');

        $suppressed = $filter->getSuppressedViolations([$violation1, $violation2]);

        self::assertCount(1, $suppressed);
        self::assertSame($violation1, $suppressed[0]);
    }

    #[Test]
    public function itSuppressionMatchesViolationCodeWithPrefixMatching(): void
    {
        $filter = new SuppressionFilter();
        // Suppress 'complexity' — should match all complexity.* violation codes
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10, SuppressionType::Symbol),
        ]);

        $violation1 = new Violation(
            location: new Location('src/Foo.php', 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
            message: 'Test message',
            severity: Severity::Warning,
        );

        $violation2 = new Violation(
            location: new Location('src/Foo.php', 50),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'baz'),
            ruleName: 'coupling.distance',
            violationCode: 'coupling.distance',
            message: 'Test message',
            severity: Severity::Error,
        );

        self::assertFalse($filter->shouldInclude($violation1), 'complexity.cyclomatic.method should be suppressed by complexity');
        self::assertTrue($filter->shouldInclude($violation2), 'coupling.distance should not be suppressed by complexity');
    }

    #[Test]
    public function itMultipleSuppressionsForSameFile(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10, SuppressionType::Symbol),
            new Suppression('coupling', null, 20, SuppressionType::Symbol),
        ]);

        $violation1 = $this->createViolation('src/Foo.php', 42, 'complexity');
        $violation2 = $this->createViolation('src/Foo.php', 50, 'coupling');
        $violation3 = $this->createViolation('src/Foo.php', 60, 'size');

        self::assertFalse($filter->shouldInclude($violation1));
        self::assertFalse($filter->shouldInclude($violation2));
        self::assertTrue($filter->shouldInclude($violation3));
    }

    #[Test]
    public function itPassesNonSuppressedViolation(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10, SuppressionType::Symbol),
        ]);

        $violation = $this->createViolation('src/Foo.php', 42, 'coupling');

        self::assertTrue($filter->shouldInclude($violation), 'Non-suppressed violation should pass through');
    }

    #[Test]
    public function itClearSuppressionsResetsState(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 1, SuppressionType::File),
        ]);

        $violation = $this->createViolation('src/Foo.php', 10, 'complexity');
        self::assertFalse($filter->shouldInclude($violation), 'Violation should be suppressed before clear');

        $filter->clearSuppressions();

        self::assertTrue($filter->shouldInclude($violation), 'Violation should pass after clearSuppressions');
    }

    #[Test]
    public function itSuppressionsDoNotAccumulateAcrossMultipleLoads(): void
    {
        $filter = new SuppressionFilter();

        // First load: suppress complexity in Foo.php
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 1, SuppressionType::File),
        ]);

        $fooViolation = $this->createViolation('src/Foo.php', 10, 'complexity');
        self::assertFalse($filter->shouldInclude($fooViolation));

        // Second load: clear and load different suppressions
        $filter->clearSuppressions();
        $filter->setSuppressions('src/Bar.php', [
            new Suppression('coupling', null, 1, SuppressionType::File),
        ]);

        // Old suppression from Foo.php should no longer apply
        self::assertTrue($filter->shouldInclude($fooViolation), 'Old suppression for Foo.php should not persist after clear+reload');

        $barViolation = $this->createViolation('src/Bar.php', 10, 'coupling');
        self::assertFalse($filter->shouldInclude($barViolation), 'New suppression for Bar.php should work');
    }

    #[Test]
    public function itSymbolSuppressionDoesNotSuppressNullLineViolation(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('coupling', null, 10, SuppressionType::Symbol),
        ]);

        // Namespace/file-level violation with line=null should NOT be suppressed by symbol tag
        $violation = new Violation(
            location: new Location('src/Foo.php', null),
            symbolPath: SymbolPath::forNamespace('App'),
            ruleName: 'coupling',
            violationCode: 'coupling',
            message: 'Test',
            severity: Severity::Warning,
        );

        self::assertTrue($filter->shouldInclude($violation), 'Symbol-level suppression must not suppress violations with null line');
    }

    #[Test]
    public function itSymbolSuppressionDoesNotAffectViolationsAfterSymbolEndLine(): void
    {
        $filter = new SuppressionFilter();
        // Suppression on first class (lines 10-50), should NOT suppress second class (line 60)
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10, SuppressionType::Symbol, endLine: 50),
        ]);

        $violationInFirstClass = $this->createViolation('src/Foo.php', 30, 'complexity');
        $violationInSecondClass = $this->createViolation('src/Foo.php', 60, 'complexity');
        $violationAtEndLine = $this->createViolation('src/Foo.php', 50, 'complexity');

        self::assertFalse($filter->shouldInclude($violationInFirstClass), 'Violation inside suppressed symbol should be suppressed');
        self::assertFalse($filter->shouldInclude($violationAtEndLine), 'Violation at symbol end line should be suppressed');
        self::assertTrue($filter->shouldInclude($violationInSecondClass), 'Violation after symbol end line should NOT be suppressed');
    }

    #[Test]
    public function itSymbolSuppressionWithoutEndLineActsUntilEndOfFile(): void
    {
        $filter = new SuppressionFilter();
        // Legacy behavior: no endLine means suppress until EOF
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10, SuppressionType::Symbol, endLine: null),
        ]);

        $violation = $this->createViolation('src/Foo.php', 999, 'complexity');

        self::assertFalse($filter->shouldInclude($violation), 'Suppression without endLine should suppress until end of file');
    }

    private function createViolation(string $file, int $line, string $violationCode): Violation
    {
        return new Violation(
            location: new Location($file, $line),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: $violationCode,
            violationCode: $violationCode,
            message: 'Test message',
            severity: Severity::Warning,
        );
    }
}

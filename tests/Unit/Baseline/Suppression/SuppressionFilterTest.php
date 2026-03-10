<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Baseline\Suppression;

use AiMessDetector\Baseline\Suppression\SuppressionFilter;
use AiMessDetector\Core\Suppression\Suppression;
use AiMessDetector\Core\Suppression\SuppressionType;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SuppressionFilter::class)]
final class SuppressionFilterTest extends TestCase
{
    public function testFileLevelSuppressesAllMatchingViolationsInFile(): void
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

    public function testSymbolLevelSuppressesViolationsAtOrAfterSuppressionLine(): void
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

    public function testSymbolLevelDoesNotAffectViolationsBeforeSuppressionLine(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 20, SuppressionType::Symbol),
        ]);

        $violation = $this->createViolation('src/Foo.php', 5, 'complexity');

        self::assertTrue($filter->shouldInclude($violation), 'Symbol suppression must not affect violations before its line');
    }

    public function testNextLineSuppressesOnlySpecificNextLine(): void
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

    public function testNextLineDoesNotSuppressLinePlus2(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10, SuppressionType::NextLine),
        ]);

        $violation = $this->createViolation('src/Foo.php', 12, 'complexity');

        self::assertTrue($filter->shouldInclude($violation), 'NextLine suppression must not affect line+2');
    }

    public function testWildcardFileSuppressesAllRules(): void
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

    public function testPassesThroughWhenNoSuppressions(): void
    {
        $filter = new SuppressionFilter();

        $violation = $this->createViolation('src/Foo.php', 42, 'complexity');

        self::assertTrue($filter->shouldInclude($violation), 'Violation should pass when no suppressions');
    }

    public function testPassesThroughWhenDifferentFile(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10, SuppressionType::File),
        ]);

        $violation = $this->createViolation('src/Bar.php', 42, 'complexity');

        self::assertTrue($filter->shouldInclude($violation));
    }

    public function testGetSuppressedViolations(): void
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

    public function testSuppressionMatchesViolationCodeWithPrefixMatching(): void
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

    public function testMultipleSuppressionsForSameFile(): void
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

    public function testPassesNonSuppressedViolation(): void
    {
        $filter = new SuppressionFilter();
        $filter->setSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10, SuppressionType::Symbol),
        ]);

        $violation = $this->createViolation('src/Foo.php', 42, 'coupling');

        self::assertTrue($filter->shouldInclude($violation), 'Non-suppressed violation should pass through');
    }

    public function testClearSuppressionsResetsState(): void
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

    public function testSuppressionsDoNotAccumulateAcrossMultipleLoads(): void
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

    public function testSymbolSuppressionDoesNotSuppressNullLineViolation(): void
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

    public function testSymbolSuppressionDoesNotAffectViolationsAfterSymbolEndLine(): void
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

    public function testSymbolSuppressionWithoutEndLineActsUntilEndOfFile(): void
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

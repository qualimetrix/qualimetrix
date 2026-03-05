<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Baseline\Suppression;

use AiMessDetector\Baseline\Suppression\Suppression;
use AiMessDetector\Baseline\Suppression\SuppressionFilter;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Core\Violation\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SuppressionFilter::class)]
final class SuppressionFilterTest extends TestCase
{
    public function testFiltersOutSuppressedViolation(): void
    {
        $filter = new SuppressionFilter();
        $filter->addSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10),
        ]);

        $violation = new Violation(
            location: new Location('src/Foo.php', 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Test message',
            severity: Severity::Warning,
        );

        self::assertFalse($filter->shouldInclude($violation), 'Suppressed violation should be filtered out');
    }

    public function testPassesNonSuppressedViolation(): void
    {
        $filter = new SuppressionFilter();
        $filter->addSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10),
        ]);

        $violation = new Violation(
            location: new Location('src/Foo.php', 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'coupling',
            violationCode: 'coupling',
            message: 'Test message',
            severity: Severity::Warning,
        );

        self::assertTrue($filter->shouldInclude($violation), 'Non-suppressed violation should pass through');
    }

    public function testWildcardSuppressesAllRules(): void
    {
        $filter = new SuppressionFilter();
        $filter->addSuppressions('src/Foo.php', [
            new Suppression('*', null, 10),
        ]);

        $violation1 = new Violation(
            location: new Location('src/Foo.php', 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Test message',
            severity: Severity::Warning,
        );

        $violation2 = new Violation(
            location: new Location('src/Foo.php', 50),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'baz'),
            ruleName: 'coupling',
            violationCode: 'coupling',
            message: 'Test message',
            severity: Severity::Error,
        );

        self::assertFalse($filter->shouldInclude($violation1));
        self::assertFalse($filter->shouldInclude($violation2));
    }

    public function testPassesThroughWhenNoSuppressions(): void
    {
        $filter = new SuppressionFilter();

        $violation = new Violation(
            location: new Location('src/Foo.php', 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Test message',
            severity: Severity::Warning,
        );

        self::assertTrue($filter->shouldInclude($violation), 'Violation should pass when no suppressions');
    }

    public function testPassesThroughWhenDifferentFile(): void
    {
        $filter = new SuppressionFilter();
        $filter->addSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10),
        ]);

        $violation = new Violation(
            location: new Location('src/Bar.php', 42),
            symbolPath: SymbolPath::forMethod('App', 'Bar', 'baz'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Test message',
            severity: Severity::Warning,
        );

        self::assertTrue($filter->shouldInclude($violation));
    }

    public function testGetSuppressedViolations(): void
    {
        $filter = new SuppressionFilter();
        $filter->addSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10),
        ]);

        $violation1 = new Violation(
            location: new Location('src/Foo.php', 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Test message',
            severity: Severity::Warning,
        );

        $violation2 = new Violation(
            location: new Location('src/Foo.php', 50),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'baz'),
            ruleName: 'coupling',
            violationCode: 'coupling',
            message: 'Test message',
            severity: Severity::Error,
        );

        $suppressed = $filter->getSuppressedViolations([$violation1, $violation2]);

        self::assertCount(1, $suppressed);
        self::assertSame($violation1, $suppressed[0]);
    }

    public function testSuppressionMatchesViolationCodeWithPrefixMatching(): void
    {
        $filter = new SuppressionFilter();
        // Suppress 'complexity' — should match all complexity.* violation codes
        $filter->addSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10),
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
        $filter->addSuppressions('src/Foo.php', [
            new Suppression('complexity', null, 10),
            new Suppression('coupling', null, 20),
        ]);

        $violation1 = new Violation(
            location: new Location('src/Foo.php', 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Test message',
            severity: Severity::Warning,
        );

        $violation2 = new Violation(
            location: new Location('src/Foo.php', 50),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'baz'),
            ruleName: 'coupling',
            violationCode: 'coupling',
            message: 'Test message',
            severity: Severity::Error,
        );

        $violation3 = new Violation(
            location: new Location('src/Foo.php', 60),
            symbolPath: SymbolPath::forClass('App', 'Foo'),
            ruleName: 'size',
            violationCode: 'size',
            message: 'Test message',
            severity: Severity::Warning,
        );

        self::assertFalse($filter->shouldInclude($violation1));
        self::assertFalse($filter->shouldInclude($violation2));
        self::assertTrue($filter->shouldInclude($violation3));
    }
}

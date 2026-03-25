<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Baseline;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineEntry;
use Qualimetrix\Baseline\Filter\BaselineFilter;
use Qualimetrix\Baseline\ViolationHasher;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;

#[CoversClass(BaselineFilter::class)]
final class BaselineFilterTest extends TestCase
{
    private ViolationHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = new ViolationHasher();
    }

    public function testFiltersOutKnownViolation(): void
    {
        $violation = new Violation(
            location: new Location('src/Foo.php', 45),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Complexity 15 exceeds 10',
            severity: Severity::Warning,
        );

        $hash = $this->hasher->hash($violation);

        $baseline = new Baseline(
            version: 1,
            generated: new DateTimeImmutable(),
            entries: [
                'method:App\Foo::bar' => [
                    new BaselineEntry('complexity', $hash),
                ],
            ],
        );

        $filter = new BaselineFilter($baseline, $this->hasher);

        self::assertFalse($filter->shouldInclude($violation), 'Known violation should be filtered out');
    }

    public function testPassesNewViolation(): void
    {
        $violation = new Violation(
            location: new Location('src/Foo.php', 45),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Complexity 15 exceeds 10',
            severity: Severity::Warning,
        );

        $baseline = new Baseline(
            version: 1,
            generated: new DateTimeImmutable(),
            entries: [],
        );

        $filter = new BaselineFilter($baseline, $this->hasher);

        self::assertTrue($filter->shouldInclude($violation), 'New violation should pass through');
    }

    public function testPassesViolationFromDifferentFile(): void
    {
        $violation = new Violation(
            location: new Location('src/Bar.php', 45),
            symbolPath: SymbolPath::forMethod('App', 'Bar', 'baz'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Complexity 15 exceeds 10',
            severity: Severity::Warning,
        );

        $baseline = new Baseline(
            version: 1,
            generated: new DateTimeImmutable(),
            entries: [
                'method:App\Foo::bar' => [
                    new BaselineEntry('complexity', 'different'),
                ],
            ],
        );

        $filter = new BaselineFilter($baseline, $this->hasher);

        self::assertTrue($filter->shouldInclude($violation));
    }

    public function testDetectsResolvedViolations(): void
    {
        // Current run has only the first violation (second was fixed)
        $violation1 = new Violation(
            location: new Location('src/Foo.php', 45),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Complexity 15 exceeds 10',
            severity: Severity::Warning,
        );

        $violation2 = new Violation(
            location: new Location('src/Foo.php', 10),
            symbolPath: SymbolPath::forClass('App', 'Foo'),
            ruleName: 'size',
            violationCode: 'size',
            message: 'Class too large',
            severity: Severity::Warning,
        );

        // Generate real hashes
        $hash1 = $this->hasher->hash($violation1);
        $hash2 = $this->hasher->hash($violation2);

        $entry1 = new BaselineEntry('complexity', $hash1);
        $entry2 = new BaselineEntry('size', $hash2);

        $baseline = new Baseline(
            version: 1,
            generated: new DateTimeImmutable(),
            entries: [
                'method:App\Foo::bar' => [$entry1],
                'class:App\Foo' => [$entry2],
            ],
        );

        $filter = new BaselineFilter($baseline, $this->hasher);
        $resolved = $filter->getResolvedFromBaseline([$violation1]); // Only violation1, violation2 was fixed

        self::assertArrayHasKey('class:App\Foo', $resolved);
        self::assertCount(1, $resolved['class:App\Foo']);
        self::assertSame('size', $resolved['class:App\Foo'][0]->rule);
    }

    public function testReturnsEmptyWhenNothingResolved(): void
    {
        $violation = new Violation(
            location: new Location('src/Foo.php', 45),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Complexity 15 exceeds 10',
            severity: Severity::Warning,
        );

        $hash = $this->hasher->hash($violation);

        $baseline = new Baseline(
            version: 1,
            generated: new DateTimeImmutable(),
            entries: [
                'method:App\Foo::bar' => [
                    new BaselineEntry('complexity', $hash),
                ],
            ],
        );

        $filter = new BaselineFilter($baseline, $this->hasher);
        $resolved = $filter->getResolvedFromBaseline([$violation]);

        self::assertEmpty($resolved);
    }

    public function testHashStableAcrossLineChanges(): void
    {
        // Violation originally at line 45
        $violation1 = new Violation(
            location: new Location('src/Foo.php', 45),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Complexity 15 exceeds 10',
            severity: Severity::Warning,
        );

        $hash = $this->hasher->hash($violation1);

        $baseline = new Baseline(
            version: 1,
            generated: new DateTimeImmutable(),
            entries: [
                'method:App\Foo::bar' => [
                    new BaselineEntry('complexity', $hash),
                ],
            ],
        );

        // Same violation now at line 55 (line drift)
        $violation2 = new Violation(
            location: new Location('src/Foo.php', 55),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Complexity 15 exceeds 10',
            severity: Severity::Warning,
        );

        $filter = new BaselineFilter($baseline, $this->hasher);

        self::assertFalse(
            $filter->shouldInclude($violation2),
            'Violation should still be filtered despite line change',
        );
    }
}

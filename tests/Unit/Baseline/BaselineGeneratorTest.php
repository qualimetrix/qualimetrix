<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Baseline;

use AiMessDetector\Baseline\BaselineGenerator;
use AiMessDetector\Baseline\ViolationHasher;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BaselineGenerator::class)]
final class BaselineGeneratorTest extends TestCase
{
    private BaselineGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new BaselineGenerator(new ViolationHasher());
    }

    public function testGeneratesBaselineFromViolations(): void
    {
        $violations = [
            new Violation(
                location: new Location('src/Foo.php', 45),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity',
                violationCode: 'complexity',
                message: 'Complexity 15 exceeds 10',
                severity: Severity::Warning,
            ),
        ];

        $baseline = $this->generator->generate($violations);

        self::assertSame(4, $baseline->version);
        self::assertCount(1, $baseline->entries);
        self::assertArrayHasKey('method:App\Foo::bar', $baseline->entries);
        self::assertCount(1, $baseline->entries['method:App\Foo::bar']);
    }

    public function testGroupsViolationsByFile(): void
    {
        $violations = [
            new Violation(
                location: new Location('src/Foo.php', 45),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity',
                violationCode: 'complexity',
                message: 'Test message',
                severity: Severity::Warning,
            ),
            new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'size',
                violationCode: 'size',
                message: 'Test message',
                severity: Severity::Warning,
            ),
            new Violation(
                location: new Location('src/Bar.php', 20),
                symbolPath: SymbolPath::forMethod('App', 'Bar', 'baz'),
                ruleName: 'coupling',
                violationCode: 'coupling',
                message: 'Test message',
                severity: Severity::Error,
            ),
        ];

        $baseline = $this->generator->generate($violations);

        self::assertCount(3, $baseline->entries);
        self::assertCount(1, $baseline->entries['method:App\Foo::bar']);
        self::assertCount(1, $baseline->entries['class:App\Foo']);
        self::assertCount(1, $baseline->entries['method:App\Bar::baz']);
    }

    public function testSortsEntriesByRuleAndHash(): void
    {
        $violations = [
            new Violation(
                location: new Location('src/Foo.php', 50),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'third'),
                ruleName: 'complexity',
                violationCode: 'complexity',
                message: 'Test message',
                severity: Severity::Warning,
            ),
            new Violation(
                location: new Location('src/Foo.php', 50),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'third'),
                ruleName: 'coupling',
                violationCode: 'coupling',
                message: 'Test message',
                severity: Severity::Warning,
            ),
            new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'third'),
                ruleName: 'size',
                violationCode: 'size',
                message: 'Test message',
                severity: Severity::Warning,
            ),
        ];

        $baseline = $this->generator->generate($violations);

        // All violations are for the same method, so they're in the same key
        // Sorted by rule name: complexity, coupling, size
        $entries = $baseline->entries['method:App\Foo::third'];
        self::assertSame('complexity', $entries[0]->rule);
        self::assertSame('coupling', $entries[1]->rule);
        self::assertSame('size', $entries[2]->rule);
    }

    public function testGeneratesEmptyBaselineForNoViolations(): void
    {
        $baseline = $this->generator->generate([]);

        self::assertSame(0, $baseline->count());
        self::assertEmpty($baseline->entries);
    }

    public function testHandlesViolationsWithoutLineNumber(): void
    {
        $violations = [
            new Violation(
                location: new Location('src/Foo.php'),
                symbolPath: SymbolPath::forNamespace('App'),
                ruleName: 'namespace-size',
                violationCode: 'namespace-size',
                message: 'Test message',
                severity: Severity::Warning,
            ),
        ];

        $baseline = $this->generator->generate($violations);

        $entry = $baseline->entries['ns:App'][0];
        self::assertSame('namespace-size', $entry->rule);
    }
}

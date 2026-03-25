<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Baseline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\ViolationHasher;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;

#[CoversClass(ViolationHasher::class)]
final class ViolationHasherTest extends TestCase
{
    private ViolationHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = new ViolationHasher();
    }

    public function testGeneratesConsistentHash(): void
    {
        $violation = $this->createViolation(
            line: 42,
            message: 'Complexity 15 exceeds 10',
        );

        $hash1 = $this->hasher->hash($violation);
        $hash2 = $this->hasher->hash($violation);

        self::assertSame($hash1, $hash2, 'Hash should be consistent');
        self::assertSame(16, \strlen($hash1), 'Hash should be 16 characters long');
    }

    public function testHashStableAcrossLineChanges(): void
    {
        $violation1 = $this->createViolation(line: 45, message: 'Complexity 15 exceeds 10');
        $violation2 = $this->createViolation(line: 55, message: 'Complexity 15 exceeds 10');

        $hash1 = $this->hasher->hash($violation1);
        $hash2 = $this->hasher->hash($violation2);

        self::assertSame(
            $hash1,
            $hash2,
            'Hash should be stable when line changes (line drift)',
        );
    }

    public function testHashStableAcrossMessageChanges(): void
    {
        $violation1 = $this->createViolation(line: 42, message: 'Complexity 15 exceeds 10');
        $violation2 = $this->createViolation(line: 42, message: 'Completely different message text');

        $hash1 = $this->hasher->hash($violation1);
        $hash2 = $this->hasher->hash($violation2);

        self::assertSame(
            $hash1,
            $hash2,
            'Hash should be stable when message changes (only violationCode matters)',
        );
    }

    public function testHashChangesWhenViolationCodeChanges(): void
    {
        $violation1 = new Violation(
            location: new Location('src/Foo.php', 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity.method',
            message: 'Test message',
            severity: Severity::Warning,
        );

        $violation2 = new Violation(
            location: new Location('src/Foo.php', 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity.class',
            message: 'Test message',
            severity: Severity::Warning,
        );

        $hash1 = $this->hasher->hash($violation1);
        $hash2 = $this->hasher->hash($violation2);

        self::assertNotSame(
            $hash1,
            $hash2,
            'Hash should change when violationCode changes',
        );
    }

    public function testSameViolationCodeProducesSameHash(): void
    {
        $violation1 = new Violation(
            location: new Location('src/Foo.php', 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity.method',
            message: 'First message',
            severity: Severity::Warning,
        );

        $violation2 = new Violation(
            location: new Location('src/Foo.php', 99),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity.method',
            message: 'Completely different message',
            severity: Severity::Error,
        );

        $hash1 = $this->hasher->hash($violation1);
        $hash2 = $this->hasher->hash($violation2);

        self::assertSame(
            $hash1,
            $hash2,
            'Same violationCode with different message/severity/line should produce same hash',
        );
    }

    public function testHashStableAcrossSeverityChanges(): void
    {
        $violation1 = new Violation(
            location: new Location('src/Foo.php', 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Complexity 15 exceeds 10',
            severity: Severity::Warning,
        );

        $violation2 = new Violation(
            location: new Location('src/Foo.php', 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Complexity 15 exceeds 10',
            severity: Severity::Error,
        );

        $hash1 = $this->hasher->hash($violation1);
        $hash2 = $this->hasher->hash($violation2);

        self::assertSame(
            $hash1,
            $hash2,
            'Hash should be stable when severity changes',
        );
    }

    public function testHashChangesWhenRuleChanges(): void
    {
        $violation1 = new Violation(
            location: new Location('src/Foo.php', 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Test message',
            severity: Severity::Warning,
        );

        $violation2 = new Violation(
            location: new Location('src/Foo.php', 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'coupling',
            violationCode: 'coupling',
            message: 'Test message',
            severity: Severity::Warning,
        );

        $hash1 = $this->hasher->hash($violation1);
        $hash2 = $this->hasher->hash($violation2);

        self::assertNotSame(
            $hash1,
            $hash2,
            'Hash should change when rule changes',
        );
    }

    public function testHashChangesWhenMethodNameChanges(): void
    {
        $violation1 = new Violation(
            location: new Location('src/Foo.php', 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'calculate'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Test message',
            severity: Severity::Warning,
        );

        $violation2 = new Violation(
            location: new Location('src/Foo.php', 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'process'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Test message',
            severity: Severity::Warning,
        );

        $hash1 = $this->hasher->hash($violation1);
        $hash2 = $this->hasher->hash($violation2);

        self::assertNotSame(
            $hash1,
            $hash2,
            'Hash should change when method name changes',
        );
    }

    public function testHashChangesWhenClassChanges(): void
    {
        $violation1 = new Violation(
            location: new Location('src/Foo.php', 42),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Test message',
            severity: Severity::Warning,
        );

        $violation2 = new Violation(
            location: new Location('src/Baz.php', 42),
            symbolPath: SymbolPath::forMethod('App', 'Baz', 'bar'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Test message',
            severity: Severity::Warning,
        );

        $hash1 = $this->hasher->hash($violation1);
        $hash2 = $this->hasher->hash($violation2);

        self::assertNotSame(
            $hash1,
            $hash2,
            'Hash should change when class changes',
        );
    }

    public function testHashForClassLevelViolation(): void
    {
        $violation = new Violation(
            location: new Location('src/Service/UserService.php', 10),
            symbolPath: SymbolPath::forClass('App\Service', 'UserService'),
            ruleName: 'class-size',
            violationCode: 'class-size',
            message: 'Class has 150 lines',
            severity: Severity::Warning,
        );

        $hash = $this->hasher->hash($violation);

        self::assertSame(16, \strlen($hash));
    }

    public function testHashForNamespaceLevelViolation(): void
    {
        $violation = new Violation(
            location: new Location('src/Service/UserService.php'),
            symbolPath: SymbolPath::forNamespace('App\Service'),
            ruleName: 'namespace-size',
            violationCode: 'namespace-size',
            message: 'Namespace has 50 classes',
            severity: Severity::Warning,
        );

        $hash = $this->hasher->hash($violation);

        self::assertSame(16, \strlen($hash));
    }

    public function testHashForFileLevelViolation(): void
    {
        $violation = new Violation(
            location: new Location('src/bootstrap.php'),
            symbolPath: SymbolPath::forFile('src/bootstrap.php'),
            ruleName: 'file-length',
            violationCode: 'file-length',
            message: 'File has 500 lines',
            severity: Severity::Warning,
        );

        $hash = $this->hasher->hash($violation);

        self::assertSame(16, \strlen($hash));
    }

    private function createViolation(int $line, string $message): Violation
    {
        return new Violation(
            location: new Location('src/Service/UserService.php', $line),
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            ruleName: 'cyclomatic-complexity',
            violationCode: 'cyclomatic-complexity',
            message: $message,
            severity: Severity::Warning,
            metricValue: 15,
        );
    }
}

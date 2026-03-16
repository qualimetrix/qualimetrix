<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Core\Violation;

use AiMessDetector\Core\Rule\RuleLevel;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Violation::class)]
final class ViolationTest extends TestCase
{
    public function testGetFingerprintForMethod(): void
    {
        $violation = new Violation(
            location: new Location('src/Service/UserService.php', 42),
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            ruleName: 'cyclomatic-complexity',
            violationCode: 'cyclomatic-complexity',
            message: 'Method has complexity of 15',
            severity: Severity::Warning,
            metricValue: 15,
        );

        self::assertSame(
            'cyclomatic-complexity:method:App\Service\UserService::calculate',
            $violation->getFingerprint(),
        );
    }

    public function testGetFingerprintForClass(): void
    {
        $violation = new Violation(
            location: new Location('src/Service/UserService.php', 10),
            symbolPath: SymbolPath::forClass('App\Service', 'UserService'),
            ruleName: 'class-size',
            violationCode: 'class-size',
            message: 'Class is too large',
            severity: Severity::Error,
        );

        self::assertSame(
            'class-size:class:App\Service\UserService',
            $violation->getFingerprint(),
        );
    }

    public function testGetFingerprintForNamespace(): void
    {
        $violation = new Violation(
            location: new Location('src/Service/UserService.php'),
            symbolPath: SymbolPath::forNamespace('App\Service'),
            ruleName: 'namespace-size',
            violationCode: 'namespace-size',
            message: 'Namespace has too many classes',
            severity: Severity::Warning,
            metricValue: 50,
        );

        self::assertSame(
            'namespace-size:ns:App\Service',
            $violation->getFingerprint(),
        );
    }

    public function testGetFingerprintForFile(): void
    {
        $violation = new Violation(
            location: new Location('src/bootstrap.php'),
            symbolPath: SymbolPath::forFile('src/bootstrap.php'),
            ruleName: 'file-length',
            violationCode: 'file-length',
            message: 'File is too long',
            severity: Severity::Warning,
        );

        self::assertSame(
            'file-length:file:src/bootstrap.php',
            $violation->getFingerprint(),
        );
    }

    public function testGetFingerprintForGlobalFunction(): void
    {
        $violation = new Violation(
            location: new Location('src/functions.php', 5),
            symbolPath: SymbolPath::forGlobalFunction('', 'myFunction'),
            ruleName: 'cyclomatic-complexity',
            violationCode: 'cyclomatic-complexity',
            message: 'Function has high complexity',
            severity: Severity::Warning,
        );

        self::assertSame(
            'cyclomatic-complexity:func::myFunction',
            $violation->getFingerprint(),
        );
    }

    public function testViolationProperties(): void
    {
        $location = new Location('src/test.php', 42);
        $symbolPath = SymbolPath::forMethod('App', 'Test', 'method');

        $violation = new Violation(
            location: $location,
            symbolPath: $symbolPath,
            ruleName: 'test-rule',
            violationCode: 'test-rule',
            message: 'Test message',
            severity: Severity::Error,
            metricValue: 10,
        );

        self::assertSame($location, $violation->location);
        self::assertSame($symbolPath, $violation->symbolPath);
        self::assertSame('test-rule', $violation->ruleName);
        self::assertSame('Test message', $violation->message);
        self::assertSame(Severity::Error, $violation->severity);
        self::assertSame(10, $violation->metricValue);
    }

    public function testViolationWithNullMetricValue(): void
    {
        $violation = new Violation(
            location: new Location('src/test.php'),
            symbolPath: SymbolPath::forFile('src/test.php'),
            ruleName: 'test-rule',
            violationCode: 'test-rule',
            message: 'Test message',
            severity: Severity::Warning,
        );

        self::assertNull($violation->metricValue);
    }

    public function testViolationWithLevel(): void
    {
        $violation = new Violation(
            location: new Location('src/Service/UserService.php', 42),
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Method has complexity of 15',
            severity: Severity::Warning,
            metricValue: 15,
            level: RuleLevel::Method,
        );

        self::assertSame(RuleLevel::Method, $violation->level);
    }

    public function testViolationWithNullLevel(): void
    {
        $violation = new Violation(
            location: new Location('src/test.php'),
            symbolPath: SymbolPath::forFile('src/test.php'),
            ruleName: 'test-rule',
            violationCode: 'test-rule',
            message: 'Test message',
            severity: Severity::Warning,
        );

        self::assertNull($violation->level);
    }

    public function testGetDisplayMessageReturnsHumanMessageWhenAvailable(): void
    {
        $violation = new Violation(
            location: new Location('src/test.php', 10),
            symbolPath: SymbolPath::forClass('App', 'Foo'),
            ruleName: 'complexity',
            violationCode: 'complexity.method',
            message: 'Cyclomatic complexity is 15, exceeds threshold of 10',
            severity: Severity::Error,
            recommendation: 'Cyclomatic complexity: 15 (threshold: 10) — too many code paths',
        );

        self::assertSame(
            'Cyclomatic complexity: 15 (threshold: 10) — too many code paths',
            $violation->getDisplayMessage(),
        );
    }

    public function testGetDisplayMessageFallsBackToMessageWhenHumanMessageNull(): void
    {
        $violation = new Violation(
            location: new Location('src/test.php', 10),
            symbolPath: SymbolPath::forClass('App', 'Foo'),
            ruleName: 'complexity',
            violationCode: 'complexity.method',
            message: 'Cyclomatic complexity is 15, exceeds threshold of 10',
            severity: Severity::Error,
        );

        self::assertSame(
            'Cyclomatic complexity is 15, exceeds threshold of 10',
            $violation->getDisplayMessage(),
        );
    }
}

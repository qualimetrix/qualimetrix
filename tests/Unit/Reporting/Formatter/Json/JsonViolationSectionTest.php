<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Json;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Debt\RemediationTimeRegistry;
use Qualimetrix\Reporting\Formatter\Json\JsonSanitizer;
use Qualimetrix\Reporting\Formatter\Json\JsonViolationSection;
use Qualimetrix\Reporting\FormatterContext;

#[CoversClass(JsonViolationSection::class)]
final class JsonViolationSectionTest extends TestCase
{
    private JsonViolationSection $section;

    protected function setUp(): void
    {
        $this->section = new JsonViolationSection(
            new RemediationTimeRegistry(),
            new JsonSanitizer(),
        );
    }

    // --- format ---

    public function testFormatEmptyViolations(): void
    {
        $result = $this->section->format([], new FormatterContext());

        self::assertSame([], $result);
    }

    public function testFormatSingleViolation(): void
    {
        $violation = new Violation(
            location: new Location('src/Service/UserService.php', 42),
            symbolPath: SymbolPath::forMethod('App\\Service', 'UserService', 'process'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Cyclomatic complexity is 15, threshold is 10',
            severity: Severity::Warning,
            metricValue: 15,
            threshold: 10,
            recommendation: 'Consider splitting the method',
        );

        $context = new FormatterContext(basePath: '/project');
        $result = $this->section->format([$violation], $context);

        self::assertCount(1, $result);
        $item = $result[0];

        self::assertSame('src/Service/UserService.php', $item['file']);
        self::assertSame(42, $item['line']);
        self::assertStringContainsString('UserService', $item['symbol']);
        self::assertStringContainsString('process', $item['symbol']);
        self::assertSame('App\\Service', $item['namespace']);
        self::assertSame('complexity.cyclomatic', $item['rule']);
        self::assertSame('complexity.cyclomatic', $item['code']);
        self::assertSame('warning', $item['severity']);
        self::assertSame('Cyclomatic complexity is 15, threshold is 10', $item['message']);
        self::assertSame('Consider splitting the method', $item['recommendation']);
        self::assertSame(15, $item['metricValue']);
        self::assertSame(10, $item['threshold']);
        self::assertArrayHasKey('techDebtMinutes', $item);
    }

    public function testFormatViolationWithNoneLocation(): void
    {
        $violation = new Violation(
            location: Location::none(),
            symbolPath: SymbolPath::forNamespace('App\\Cycle'),
            ruleName: 'architecture.circular-dependency',
            violationCode: 'architecture.circular-dependency',
            message: 'Circular dependency detected',
            severity: Severity::Error,
        );

        $context = new FormatterContext();
        $result = $this->section->format([$violation], $context);

        self::assertCount(1, $result);
        self::assertNull($result[0]['file']);
    }

    public function testFormatViolationWithEmptyNamespace(): void
    {
        $violation = new Violation(
            location: new Location('src/helpers.php', 1),
            symbolPath: SymbolPath::forFile('src/helpers.php'),
            ruleName: 'size.loc',
            violationCode: 'size.loc',
            message: 'File too long',
            severity: Severity::Warning,
        );

        $context = new FormatterContext();
        $result = $this->section->format([$violation], $context);

        self::assertCount(1, $result);
        self::assertNull($result[0]['namespace']);
    }

    public function testFormatSanitizesNonFiniteMetricValues(): void
    {
        $violation = new Violation(
            location: new Location('src/Bad.php', 10),
            symbolPath: SymbolPath::forClass('App', 'Bad'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Bad value',
            severity: Severity::Warning,
            metricValue: \NAN,
            threshold: \INF,
        );

        $context = new FormatterContext();
        $result = $this->section->format([$violation], $context);

        self::assertNull($result[0]['metricValue']);
        self::assertNull($result[0]['threshold']);
    }

    // --- sort ---

    public function testSortErrorsBeforeWarnings(): void
    {
        $warning = new Violation(
            location: new Location('a.php', 1),
            symbolPath: SymbolPath::forFile('a.php'),
            ruleName: 'size.loc',
            violationCode: 'size.loc',
            message: 'warning',
            severity: Severity::Warning,
        );

        $error = new Violation(
            location: new Location('b.php', 1),
            symbolPath: SymbolPath::forFile('b.php'),
            ruleName: 'size.loc',
            violationCode: 'size.loc',
            message: 'error',
            severity: Severity::Error,
        );

        $sorted = $this->section->sort([$warning, $error]);

        self::assertSame(Severity::Error, $sorted[0]->severity);
        self::assertSame(Severity::Warning, $sorted[1]->severity);
    }

    public function testSortByExceedanceDescending(): void
    {
        $low = new Violation(
            location: new Location('a.php', 1),
            symbolPath: SymbolPath::forFile('a.php'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'low exceedance',
            severity: Severity::Warning,
            metricValue: 12,
            threshold: 10,
        );

        $high = new Violation(
            location: new Location('b.php', 1),
            symbolPath: SymbolPath::forFile('b.php'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'high exceedance',
            severity: Severity::Warning,
            metricValue: 50,
            threshold: 10,
        );

        $sorted = $this->section->sort([$low, $high]);

        self::assertSame('high exceedance', $sorted[0]->message);
        self::assertSame('low exceedance', $sorted[1]->message);
    }

    public function testSortByFileThenLineThenCode(): void
    {
        $v1 = new Violation(
            location: new Location('b.php', 10),
            symbolPath: SymbolPath::forFile('b.php'),
            ruleName: 'r',
            violationCode: 'r.a',
            message: 'v1',
            severity: Severity::Warning,
        );

        $v2 = new Violation(
            location: new Location('a.php', 20),
            symbolPath: SymbolPath::forFile('a.php'),
            ruleName: 'r',
            violationCode: 'r.a',
            message: 'v2',
            severity: Severity::Warning,
        );

        $v3 = new Violation(
            location: new Location('a.php', 10),
            symbolPath: SymbolPath::forFile('a.php'),
            ruleName: 'r',
            violationCode: 'r.a',
            message: 'v3',
            severity: Severity::Warning,
        );

        $sorted = $this->section->sort([$v1, $v2, $v3]);

        self::assertSame('v3', $sorted[0]->message); // a.php:10
        self::assertSame('v2', $sorted[1]->message); // a.php:20
        self::assertSame('v1', $sorted[2]->message); // b.php:10
    }

    public function testSortWithNonFiniteExceedancesTreatedAsZero(): void
    {
        $inf = new Violation(
            location: new Location('a.php', 1),
            symbolPath: SymbolPath::forFile('a.php'),
            ruleName: 'r',
            violationCode: 'r.a',
            message: 'inf',
            severity: Severity::Warning,
            metricValue: \INF,
            threshold: 10,
        );

        $normal = new Violation(
            location: new Location('b.php', 1),
            symbolPath: SymbolPath::forFile('b.php'),
            ruleName: 'r',
            violationCode: 'r.b',
            message: 'normal',
            severity: Severity::Warning,
            metricValue: 20,
            threshold: 10,
        );

        $sorted = $this->section->sort([$inf, $normal]);

        // INF exceedance is 0.0 (non-finite guard), so normal (exceedance=10) comes first
        self::assertSame('normal', $sorted[0]->message);
        self::assertSame('inf', $sorted[1]->message);
    }

    // --- countByRule ---

    public function testCountByRuleEmpty(): void
    {
        self::assertSame([], $this->section->countByRule([]));
    }

    public function testCountByRuleGroupsAndSortsDescending(): void
    {
        $makeViolation = static fn(string $rule): Violation => new Violation(
            location: new Location('f.php', 1),
            symbolPath: SymbolPath::forFile('f.php'),
            ruleName: $rule,
            violationCode: $rule,
            message: 'msg',
            severity: Severity::Warning,
        );

        $violations = [
            $makeViolation('size.loc'),
            $makeViolation('complexity.cyclomatic'),
            $makeViolation('size.loc'),
            $makeViolation('size.loc'),
            $makeViolation('complexity.cyclomatic'),
        ];

        $counts = $this->section->countByRule($violations);

        self::assertSame(['size.loc' => 3, 'complexity.cyclomatic' => 2], $counts);
    }
}

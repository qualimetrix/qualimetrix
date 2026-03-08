<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting;

use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\ViolationSorter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ViolationSorter::class)]
final class ViolationSorterTest extends TestCase
{
    public function testSortNoneGroupBySeverityThenFileThenLine(): void
    {
        $warningB5 = $this->violation('b.php', 5, Severity::Warning, 'complexity');
        $errorA10 = $this->violation('a.php', 10, Severity::Error, 'complexity');
        $errorA3 = $this->violation('a.php', 3, Severity::Error, 'complexity');

        $sorted = ViolationSorter::sort([$warningB5, $errorA10, $errorA3], GroupBy::None);

        self::assertSame([$errorA3, $errorA10, $warningB5], $sorted);
    }

    public function testSortFileGroupByFileThenSeverityThenLine(): void
    {
        $warningB5 = $this->violation('b.php', 5, Severity::Warning, 'complexity');
        $errorA10 = $this->violation('a.php', 10, Severity::Error, 'complexity');
        $errorA3 = $this->violation('a.php', 3, Severity::Error, 'complexity');

        $sorted = ViolationSorter::sort([$warningB5, $errorA10, $errorA3], GroupBy::File);

        self::assertSame([$errorA3, $errorA10, $warningB5], $sorted);
    }

    public function testSortRuleGroupByRuleThenFile(): void
    {
        $sizeB = $this->violation('b.php', 1, Severity::Error, 'size');
        $complexityA = $this->violation('a.php', 1, Severity::Error, 'complexity');
        $sizeA = $this->violation('a.php', 1, Severity::Error, 'size');

        $sorted = ViolationSorter::sort([$sizeB, $complexityA, $sizeA], GroupBy::Rule);

        self::assertSame([$complexityA, $sizeA, $sizeB], $sorted);
    }

    public function testSortEmptyArray(): void
    {
        $sorted = ViolationSorter::sort([], GroupBy::None);

        self::assertSame([], $sorted);
    }

    public function testGroupByFile(): void
    {
        $v1 = $this->violation('a.php', 1, Severity::Error, 'complexity');
        $v2 = $this->violation('a.php', 5, Severity::Warning, 'complexity');
        $v3 = $this->violation('b.php', 2, Severity::Error, 'complexity');

        $groups = ViolationSorter::group([$v1, $v2, $v3], GroupBy::File);

        self::assertCount(2, $groups);
        self::assertArrayHasKey('a.php', $groups);
        self::assertArrayHasKey('b.php', $groups);
        self::assertSame([$v1, $v2], $groups['a.php']);
        self::assertSame([$v3], $groups['b.php']);
    }

    public function testGroupByNoneReturnsSingleGroup(): void
    {
        $v1 = $this->violation('a.php', 1, Severity::Error, 'complexity');
        $v2 = $this->violation('b.php', 2, Severity::Warning, 'size');

        $groups = ViolationSorter::group([$v1, $v2], GroupBy::None);

        self::assertCount(1, $groups);
        self::assertArrayHasKey('', $groups);
        self::assertSame([$v1, $v2], $groups['']);
    }

    public function testGroupBySeverity(): void
    {
        $v1 = $this->violation('a.php', 1, Severity::Error, 'complexity');
        $v2 = $this->violation('b.php', 2, Severity::Warning, 'size');
        $v3 = $this->violation('c.php', 3, Severity::Error, 'lcom');

        $groups = ViolationSorter::group([$v1, $v2, $v3], GroupBy::Severity);

        self::assertCount(2, $groups);
        self::assertArrayHasKey('error', $groups);
        self::assertArrayHasKey('warning', $groups);
        self::assertSame([$v1, $v3], $groups['error']);
        self::assertSame([$v2], $groups['warning']);
    }

    private function violation(string $file, int $line, Severity $severity, string $ruleName): Violation
    {
        return new Violation(
            location: new Location($file, $line),
            symbolPath: SymbolPath::forClass('App', 'MyClass'),
            ruleName: $ruleName,
            violationCode: $ruleName . '.method',
            message: 'msg',
            severity: $severity,
        );
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Formatter\Support\ViolationSorter;
use Qualimetrix\Reporting\GroupBy;

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

    public function testSortClassNameGroupByClassThenSeverityThenLine(): void
    {
        $v1 = $this->violationWithSymbol('a.php', 5, Severity::Warning, 'complexity', 'App\B', 'ClassB');
        $v2 = $this->violationWithSymbol('b.php', 1, Severity::Error, 'complexity', 'App\A', 'ClassA');
        $v3 = $this->violationWithSymbol('a.php', 3, Severity::Error, 'complexity', 'App\A', 'ClassA');

        $sorted = ViolationSorter::sort([$v1, $v2, $v3], GroupBy::ClassName);

        // ClassA first (alphabetically), then ClassB
        self::assertSame([$v3, $v2, $v1], $sorted);
    }

    public function testSortNamespaceNameGroupByNamespaceThenSeverityThenLine(): void
    {
        $v1 = $this->violationWithSymbol('a.php', 5, Severity::Warning, 'complexity', 'App\Service', 'Foo');
        $v2 = $this->violationWithSymbol('b.php', 1, Severity::Error, 'complexity', 'App\Model', 'Bar');
        $v3 = $this->violationWithSymbol('a.php', 3, Severity::Error, 'complexity', 'App\Model', 'Baz');

        $sorted = ViolationSorter::sort([$v1, $v2, $v3], GroupBy::NamespaceName);

        // App\Model first, then App\Service
        self::assertSame([$v3, $v2, $v1], $sorted);
    }

    public function testGroupByClassName(): void
    {
        $v1 = $this->violationWithSymbol('a.php', 1, Severity::Error, 'complexity', 'App', 'ClassA');
        $v2 = $this->violationWithSymbol('a.php', 5, Severity::Warning, 'complexity', 'App', 'ClassA');
        $v3 = $this->violationWithSymbol('b.php', 2, Severity::Error, 'complexity', 'App', 'ClassB');

        $groups = ViolationSorter::group([$v1, $v2, $v3], GroupBy::ClassName);

        self::assertCount(2, $groups);
        self::assertArrayHasKey('App\ClassA', $groups);
        self::assertArrayHasKey('App\ClassB', $groups);
        self::assertSame([$v1, $v2], $groups['App\ClassA']);
        self::assertSame([$v3], $groups['App\ClassB']);
    }

    public function testGroupByClassNameFallsBackToFileForNamespaceLevelViolation(): void
    {
        $v1 = new Violation(
            location: new Location('src/Service.php', 1),
            symbolPath: SymbolPath::forNamespace('App\Service'),
            ruleName: 'size',
            violationCode: 'size.namespace',
            message: 'msg',
            severity: Severity::Error,
        );

        $groups = ViolationSorter::group([$v1], GroupBy::ClassName);

        // Namespace-level violation has no class — falls back to file path
        self::assertArrayHasKey('src/Service.php', $groups);
    }

    public function testGroupByNamespaceName(): void
    {
        $v1 = $this->violationWithSymbol('a.php', 1, Severity::Error, 'complexity', 'App\Service', 'Foo');
        $v2 = $this->violationWithSymbol('b.php', 2, Severity::Warning, 'complexity', 'App\Service', 'Bar');
        $v3 = $this->violationWithSymbol('c.php', 3, Severity::Error, 'complexity', 'App\Model', 'Baz');

        $groups = ViolationSorter::group([$v1, $v2, $v3], GroupBy::NamespaceName);

        self::assertCount(2, $groups);
        self::assertArrayHasKey('App\Service', $groups);
        self::assertArrayHasKey('App\Model', $groups);
        self::assertSame([$v1, $v2], $groups['App\Service']);
        self::assertSame([$v3], $groups['App\Model']);
    }

    public function testGroupByNamespaceNameUsesGlobalForEmptyNamespace(): void
    {
        $v1 = new Violation(
            location: new Location('a.php', 1),
            symbolPath: SymbolPath::forClass('', 'GlobalClass'),
            ruleName: 'test',
            violationCode: 'test',
            message: 'msg',
            severity: Severity::Warning,
        );

        $groups = ViolationSorter::group([$v1], GroupBy::NamespaceName);

        self::assertArrayHasKey('<global>', $groups);
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

    private function violationWithSymbol(
        string $file,
        int $line,
        Severity $severity,
        string $ruleName,
        string $namespace,
        string $class,
    ): Violation {
        return new Violation(
            location: new Location($file, $line),
            symbolPath: SymbolPath::forClass($namespace, $class),
            ruleName: $ruleName,
            violationCode: $ruleName . '.method',
            message: 'msg',
            severity: $severity,
        );
    }
}

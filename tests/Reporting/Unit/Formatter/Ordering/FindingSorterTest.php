<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit\Formatter\Ordering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Formatter\Ordering\FindingSorter;
use Qualimetrix\Reporting\GroupBy;

#[CoversClass(FindingSorter::class)]
final class FindingSorterTest extends TestCase
{
    #[Test]
    public function itSortsNoneGroupBySeverityThenFileThenLine(): void
    {
        $warningB5 = $this->finding('b.php', 5, Severity::Warning, 'complexity');
        $errorA10 = $this->finding('a.php', 10, Severity::Error, 'complexity');
        $errorA3 = $this->finding('a.php', 3, Severity::Error, 'complexity');

        $sorted = FindingSorter::sort([$warningB5, $errorA10, $errorA3], GroupBy::None, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null));

        self::assertSame([$errorA3, $errorA10, $warningB5], $sorted);
    }

    #[Test]
    public function itSortsFileGroupByFileThenSeverityThenLine(): void
    {
        $warningB5 = $this->finding('b.php', 5, Severity::Warning, 'complexity');
        $errorA10 = $this->finding('a.php', 10, Severity::Error, 'complexity');
        $errorA3 = $this->finding('a.php', 3, Severity::Error, 'complexity');

        $sorted = FindingSorter::sort([$warningB5, $errorA10, $errorA3], GroupBy::File, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null));

        self::assertSame([$errorA3, $errorA10, $warningB5], $sorted);
    }

    #[Test]
    public function itSortsRuleGroupByRuleThenFile(): void
    {
        $sizeB = $this->finding('b.php', 1, Severity::Error, 'size');
        $complexityA = $this->finding('a.php', 1, Severity::Error, 'complexity');
        $sizeA = $this->finding('a.php', 1, Severity::Error, 'size');

        $sorted = FindingSorter::sort([$sizeB, $complexityA, $sizeA], GroupBy::Rule, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null));

        self::assertSame([$complexityA, $sizeA, $sizeB], $sorted);
    }

    #[Test]
    public function itSortsEmptyArrayToEmptyArray(): void
    {
        $sorted = FindingSorter::sort([], GroupBy::None, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null));

        self::assertSame([], $sorted);
    }

    #[Test]
    public function itGroupsByFile(): void
    {
        $v1 = $this->finding('a.php', 1, Severity::Error, 'complexity');
        $v2 = $this->finding('a.php', 5, Severity::Warning, 'complexity');
        $v3 = $this->finding('b.php', 2, Severity::Error, 'complexity');

        $groups = FindingSorter::group([$v1, $v2, $v3], GroupBy::File, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null));

        self::assertCount(2, $groups);
        self::assertArrayHasKey('a.php', $groups);
        self::assertArrayHasKey('b.php', $groups);
        self::assertSame([$v1, $v2], $groups['a.php']);
        self::assertSame([$v3], $groups['b.php']);
    }

    #[Test]
    public function itGroupsByNoneReturningSingleGroup(): void
    {
        $v1 = $this->finding('a.php', 1, Severity::Error, 'complexity');
        $v2 = $this->finding('b.php', 2, Severity::Warning, 'size');

        $groups = FindingSorter::group([$v1, $v2], GroupBy::None, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null));

        self::assertCount(1, $groups);
        self::assertArrayHasKey('', $groups);
        self::assertSame([$v1, $v2], $groups['']);
    }

    #[Test]
    public function itGroupsBySeverity(): void
    {
        $v1 = $this->finding('a.php', 1, Severity::Error, 'complexity');
        $v2 = $this->finding('b.php', 2, Severity::Warning, 'size');
        $v3 = $this->finding('c.php', 3, Severity::Error, 'cohesion.lcom');

        $groups = FindingSorter::group([$v1, $v2, $v3], GroupBy::Severity, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null));

        self::assertCount(2, $groups);
        self::assertArrayHasKey('error', $groups);
        self::assertArrayHasKey('warning', $groups);
        self::assertSame([$v1, $v3], $groups['error']);
        self::assertSame([$v2], $groups['warning']);
    }

    #[Test]
    public function itSortsClassNameGroupByClassThenSeverityThenLine(): void
    {
        $v1 = $this->findingWithSymbol('a.php', 5, Severity::Warning, 'complexity', 'App\B', 'ClassB');
        $v2 = $this->findingWithSymbol('b.php', 1, Severity::Error, 'complexity', 'App\A', 'ClassA');
        $v3 = $this->findingWithSymbol('a.php', 3, Severity::Error, 'complexity', 'App\A', 'ClassA');

        $sorted = FindingSorter::sort([$v1, $v2, $v3], GroupBy::ClassName, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null));

        // ClassA first (alphabetically), then ClassB
        self::assertSame([$v3, $v2, $v1], $sorted);
    }

    #[Test]
    public function itSortsNamespaceNameGroupByNamespaceThenSeverityThenLine(): void
    {
        $v1 = $this->findingWithSymbol('a.php', 5, Severity::Warning, 'complexity', 'App\Service', 'Foo');
        $v2 = $this->findingWithSymbol('b.php', 1, Severity::Error, 'complexity', 'App\Model', 'Bar');
        $v3 = $this->findingWithSymbol('a.php', 3, Severity::Error, 'complexity', 'App\Model', 'Baz');

        $sorted = FindingSorter::sort([$v1, $v2, $v3], GroupBy::NamespaceName, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null));

        // App\Model first, then App\Service
        self::assertSame([$v3, $v2, $v1], $sorted);
    }

    #[Test]
    public function itGroupsByClassName(): void
    {
        $v1 = $this->findingWithSymbol('a.php', 1, Severity::Error, 'complexity', 'App', 'ClassA');
        $v2 = $this->findingWithSymbol('a.php', 5, Severity::Warning, 'complexity', 'App', 'ClassA');
        $v3 = $this->findingWithSymbol('b.php', 2, Severity::Error, 'complexity', 'App', 'ClassB');

        $groups = FindingSorter::group([$v1, $v2, $v3], GroupBy::ClassName, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null));

        self::assertCount(2, $groups);
        self::assertArrayHasKey('App\ClassA', $groups);
        self::assertArrayHasKey('App\ClassB', $groups);
        self::assertSame([$v1, $v2], $groups['App\ClassA']);
        self::assertSame([$v3], $groups['App\ClassB']);
    }

    #[Test]
    public function itGroupsByClassNameFallingBackToFileForNamespaceLevelFinding(): void
    {
        $v1 = self::buildFinding(
            location: new Location(RelativePath::fromString('src/Service.php'), 1),
            symbolPath: SymbolPath::forNamespace('App\Service'),
            ruleName: 'size',
            code: 'size.namespace',
            message: 'msg',
            severity: Severity::Error,
        );

        $groups = FindingSorter::group([$v1], GroupBy::ClassName, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null));

        // Namespace-level finding has no class — falls back to file path
        self::assertArrayHasKey('src/Service.php', $groups);
    }

    #[Test]
    public function itGroupsByNamespaceName(): void
    {
        $v1 = $this->findingWithSymbol('a.php', 1, Severity::Error, 'complexity', 'App\Service', 'Foo');
        $v2 = $this->findingWithSymbol('b.php', 2, Severity::Warning, 'complexity', 'App\Service', 'Bar');
        $v3 = $this->findingWithSymbol('c.php', 3, Severity::Error, 'complexity', 'App\Model', 'Baz');

        $groups = FindingSorter::group([$v1, $v2, $v3], GroupBy::NamespaceName, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null));

        self::assertCount(2, $groups);
        self::assertArrayHasKey('App\Service', $groups);
        self::assertArrayHasKey('App\Model', $groups);
        self::assertSame([$v1, $v2], $groups['App\Service']);
        self::assertSame([$v3], $groups['App\Model']);
    }

    #[Test]
    public function itGroupsByNamespaceNameUsingGlobalForEmptyNamespace(): void
    {
        $v1 = self::buildFinding(
            location: new Location(RelativePath::fromString('a.php'), 1),
            symbolPath: SymbolPath::forClass('', 'GlobalClass'),
            ruleName: 'test',
            code: 'test',
            message: 'msg',
            severity: Severity::Warning,
        );

        $groups = FindingSorter::group([$v1], GroupBy::NamespaceName, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null));

        self::assertArrayHasKey('(global)', $groups);
    }

    #[Test]
    public function itUsesPublishedImpactRankBeforePlaceEvenForEqualImpactScores(): void
    {
        $first = $this->finding('z.php', 10, Severity::Error, 'first');
        $second = $this->finding('a.php', 1, Severity::Error, 'second');
        $ranking = [
            new \Qualimetrix\Analysis\Evidence\Prioritization\Impact\RankedIssue($first, 20, null, 15, 3),
            new \Qualimetrix\Analysis\Evidence\Prioritization\Impact\RankedIssue($second, 20, null, 15, 3),
        ];
        self::assertSame([$first], FindingSorter::worstFirst([$second, $first], $ranking, 1));
        self::assertSame([$second], FindingSorter::worstFirst([$first, $second], [], 1));
    }

    private function finding(string $file, int $line, Severity $severity, string $ruleName): Finding
    {
        return self::buildFinding(
            location: new Location(RelativePath::fromString($file), $line),
            symbolPath: SymbolPath::forClass('App', 'MyClass'),
            ruleName: $ruleName,
            code: $ruleName . '.method',
            message: 'msg',
            severity: $severity,
        );
    }

    private function findingWithSymbol(
        string $file,
        int $line,
        Severity $severity,
        string $ruleName,
        string $namespace,
        string $class,
    ): Finding {
        return self::buildFinding(
            location: new Location(RelativePath::fromString($file), $line),
            symbolPath: SymbolPath::forClass($namespace, $class),
            ruleName: $ruleName,
            code: $ruleName . '.method',
            message: 'msg',
            severity: $severity,
        );
    }

    /** @param list<\Qualimetrix\Analysis\Finding\Contract\Location> $relatedLocations */
    private static function buildFinding(\Qualimetrix\Analysis\Finding\Contract\Location $location, \Qualimetrix\Core\Symbol\SymbolPath $symbolPath, string $ruleName, string $code, string $message, \Qualimetrix\Analysis\Finding\Contract\Severity $severity, int|float|null $metricValue = null, array $relatedLocations = [], ?string $recommendation = null, int|float|null $threshold = null, ?\Qualimetrix\Core\Symbol\SymbolPath $dependencyTarget = null, ?\Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType $dependencyType = null, ?\Qualimetrix\Analysis\Finding\Contract\AcceptedLevel $acceptedLevel = null, ?\Qualimetrix\Analysis\Finding\Contract\OccurrenceKey $occurrenceKey = null, ?\Qualimetrix\Core\Symbol\MetricSubject $subject = null): Finding
    {
        $subject ??= match ($symbolPath->getType()) {
            \Qualimetrix\Core\Symbol\SymbolType::File, \Qualimetrix\Core\Symbol\SymbolType::Namespace_, \Qualimetrix\Core\Symbol\SymbolType::Project => \Qualimetrix\Core\Symbol\MetricSubject::aggregate($symbolPath),
            default => \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of($symbolPath, $location->file ?? \Qualimetrix\Core\Path\RelativePath::fromString('tests/Reporting/fixture.php'), \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
        };
        return new Finding(location: $location, subject: $subject, symbolPath: $symbolPath, ruleName: $ruleName, code: $code, message: $message, severity: $severity, metricValue: $metricValue, relatedLocations: $relatedLocations, recommendation: $recommendation, threshold: $threshold, dependencyTarget: $dependencyTarget, dependencyType: $dependencyType, acceptedLevel: $acceptedLevel, occurrenceKey: $occurrenceKey);
    }

}

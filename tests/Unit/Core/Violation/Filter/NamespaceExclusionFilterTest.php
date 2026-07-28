<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Violation\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Rules\CircularDependencyRule;
use Qualimetrix\Architecture\Rules\LayerViolationRule;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Util\NamespaceMatcher;
use Qualimetrix\Core\Violation\Filter\NamespaceExclusionFilter;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;

#[CoversClass(NamespaceExclusionFilter::class)]
final class NamespaceExclusionFilterTest extends TestCase
{
    #[Test]
    public function itFiltersSuppressedNamespace(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher(['App\\Entity']));

        $violation = $this->createViolation('App\\Entity', 'complexity.cyclomatic');

        self::assertFalse($filter->shouldInclude($violation), 'Violation matching excluded namespace should be suppressed');
    }

    #[Test]
    public function itPassesNonMatchingNamespace(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher(['App\\Entity']));

        $violation = $this->createViolation('App\\Service', 'complexity.cyclomatic');

        self::assertTrue($filter->shouldInclude($violation), 'Violation not matching excluded namespace should pass through');
    }

    #[Test]
    public function itKeepsLayerViolationRuleInExcludedNamespace(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher(['App\\Entity']));

        $violation = $this->createViolation('App\\Entity', LayerViolationRule::NAME);

        self::assertTrue($filter->shouldInclude($violation), 'architecture.* rules must not be silenced by exclude_namespaces');
    }

    #[Test]
    public function itKeepsCircularDependencyRuleInExcludedNamespace(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher(['App\\Entity']));

        $violation = $this->createViolation('App\\Entity', CircularDependencyRule::NAME);

        self::assertTrue($filter->shouldInclude($violation), 'architecture.* rules must not be silenced by exclude_namespaces');
    }

    #[Test]
    public function itKeepsArchitectureCoverageDiagnosticEvenIfNamePrefixMatchesByCoincidence(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher(['App\\Entity']));

        // architecture.coverage and friends are project-level (empty namespace) diagnostics,
        // but the exemption is driven purely by the rule-name prefix — verify it still applies.
        $violation = $this->createViolation('App\\Entity', LayerViolationRule::COVERAGE_DIAGNOSTIC_NAME);

        self::assertTrue($filter->shouldInclude($violation));
    }

    #[Test]
    public function itPassesWhenNoPatterns(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher([]));

        $violation = $this->createViolation('App\\Entity', 'complexity.cyclomatic');

        self::assertTrue($filter->shouldInclude($violation), 'Empty NamespaceMatcher should not filter any violations');
    }

    #[Test]
    public function itPassesEmptyNamespace(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher(['App']));

        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/helpers.php'), 10),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('src/helpers.php')),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
            message: 'Test',
            severity: Severity::Warning,
        );

        self::assertTrue($filter->shouldInclude($violation), 'Violation with empty/null namespace should never be filtered');
    }

    private function createViolation(string $namespace, string $ruleName): Violation
    {
        return new Violation(
            location: new Location(RelativePath::fromString('src/Entity/User.php'), 10),
            symbolPath: SymbolPath::forClass($namespace, 'User'),
            ruleName: $ruleName,
            violationCode: $ruleName,
            message: 'Test',
            severity: Severity::Warning,
        );
    }
}

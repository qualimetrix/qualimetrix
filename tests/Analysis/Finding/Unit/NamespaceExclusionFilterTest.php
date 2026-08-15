<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyRule;
use Qualimetrix\Analysis\Finding\Contract\Filter\NamespaceExclusionFilter;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Util\NamespaceMatcher;

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
    public function itKeepsArchitectureRuleEvenWhenItIsAFileSymbolViolationInExcludedNamespace(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher(['App\\Entity']));

        // Occurrence-style violations carry a file symbol path; the architecture
        // exemption is decided before any namespace resolution, so it must hold
        // even for a file-symbol violation whose subject declares an excluded namespace.
        $violation = $this->createFileSymbolViolation('App\\Entity', LayerViolationRule::NAME);

        self::assertTrue($filter->shouldInclude($violation), 'architecture.* rules must not be silenced by exclude_namespaces');
    }

    #[Test]
    public function itPassesWhenNoPatterns(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher([]));

        $violation = $this->createViolation('App\\Entity', 'complexity.cyclomatic');

        self::assertTrue($filter->shouldInclude($violation), 'Empty NamespaceMatcher should not filter any violations');
    }

    #[Test]
    public function itFiltersFileSymbolViolationWhoseSubjectNamespaceMatches(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher(['App\\Entity']));

        $violation = $this->createFileSymbolViolation('App\\Entity', 'code-smell.eval');

        self::assertFalse($filter->shouldInclude($violation), 'File-symbol violation whose declaration namespace matches should be suppressed');
    }

    #[Test]
    public function itPassesFileSymbolViolationWhoseSubjectNamespaceDoesNotMatch(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher(['App\\Entity']));

        $violation = $this->createFileSymbolViolation('App\\Service', 'code-smell.eval');

        self::assertTrue($filter->shouldInclude($violation), 'File-symbol violation whose declaration namespace does not match should pass through');
    }

    #[Test]
    public function itPassesFileSymbolViolationWithoutDeclaringNamespace(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher(['App']));

        // A file-symbol violation with no declaration subject has no namespace
        // to resolve, so it cannot be matched by any namespace exclusion.
        $file = RelativePath::fromString('src/helpers.php');
        $violation = new Violation(
            location: new Location($file, 10),
            symbolPath: SymbolPath::forFile($file),
            subject: MetricSubject::aggregate(SymbolPath::forFile($file)),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'Test',
            severity: Severity::Warning,
        );

        self::assertTrue($filter->shouldInclude($violation), 'File-symbol violation without a declaring namespace should not be filtered');
    }

    private function createViolation(string $namespace, string $ruleName): Violation
    {
        return new Violation(
            location: new Location(RelativePath::fromString('src/Entity/User.php'), 10),
            symbolPath: SymbolPath::forClass($namespace, 'User'),
            subject: MetricSubject::declaration(new DeclarationPath(
                SymbolPath::forClass($namespace, 'User'),
                RelativePath::fromString('src/Entity/User.php'),
                10,
            )),
            ruleName: $ruleName,
            violationCode: $ruleName,
            message: 'Test',
            severity: Severity::Warning,
        );
    }

    private function createFileSymbolViolation(string $subjectNamespace, string $ruleName): Violation
    {
        $file = RelativePath::fromString('src/Entity/User.php');

        return new Violation(
            location: new Location($file, 10),
            symbolPath: SymbolPath::forFile($file),
            subject: MetricSubject::declaration(new DeclarationPath(
                SymbolPath::forClass($subjectNamespace, 'User'),
                $file,
                10,
            )),
            ruleName: $ruleName,
            violationCode: $ruleName,
            message: 'Test',
            severity: Severity::Warning,
        );
    }
}

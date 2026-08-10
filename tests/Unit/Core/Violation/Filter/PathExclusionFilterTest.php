<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Violation\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Architecture\Rules\CircularDependencyRule;
use Qualimetrix\Architecture\Rules\LayerViolationRule;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Util\PathMatcher;
use Qualimetrix\Core\Violation\Filter\PathExclusionFilter;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;

#[CoversClass(PathExclusionFilter::class)]
final class PathExclusionFilterTest extends TestCase
{
    #[Test]
    public function itFiltersSuppressedPath(): void
    {
        $filter = new PathExclusionFilter(new PathMatcher(['src/Entity']));

        $violation = $this->createViolation('src/Entity/User.php');

        self::assertFalse($filter->shouldInclude($violation), 'Violation matching exclusion prefix should be suppressed');
    }

    #[Test]
    public function itKeepsLayerViolationRuleInExcludedPath(): void
    {
        $filter = new PathExclusionFilter(new PathMatcher(['src/Entity']));

        $violation = $this->createViolation('src/Entity/User.php', LayerViolationRule::NAME);

        self::assertTrue($filter->shouldInclude($violation), 'architecture.* rules must not be silenced by exclude_paths');
    }

    #[Test]
    public function itKeepsCircularDependencyRuleInExcludedPath(): void
    {
        $filter = new PathExclusionFilter(new PathMatcher(['src/Entity']));

        $violation = $this->createViolation('src/Entity/User.php', CircularDependencyRule::NAME);

        self::assertTrue($filter->shouldInclude($violation), 'architecture.* rules must not be silenced by exclude_paths');
    }

    #[Test]
    public function itKeepsArchitectureProjectWideDiagnosticsRegardlessOfPathMatcher(): void
    {
        // architecture.unreachable-layer / .potential-shadow / .coverage / .empty-template
        // are project-level diagnostics with no file (Location::none()) — already passed
        // through by the `$file === null` branch. Verify the new architecture-exemption
        // check does not change that behavior.
        $filter = new PathExclusionFilter(new PathMatcher(['src']));

        foreach (
            [
                LayerViolationRule::UNREACHABLE_LAYER_DIAGNOSTIC_NAME,
                LayerViolationRule::POTENTIAL_SHADOW_DIAGNOSTIC_NAME,
                LayerViolationRule::COVERAGE_DIAGNOSTIC_NAME,
                LayerViolationRule::EMPTY_TEMPLATE_DIAGNOSTIC_NAME,
            ] as $ruleName
        ) {
            $violation = new Violation(
                location: Location::none(),
                symbolPath: SymbolPath::forNamespace(''),
                subject: MetricSubject::aggregate(SymbolPath::forProject()),
                ruleName: $ruleName,
                violationCode: $ruleName,
                message: 'Test',
                severity: Severity::Warning,
            );

            self::assertTrue(
                $filter->shouldInclude($violation),
                \sprintf('%s must remain a no-op for file-less architecture diagnostics', $ruleName),
            );
        }
    }

    #[Test]
    public function itPassesNonMatchingPath(): void
    {
        $filter = new PathExclusionFilter(new PathMatcher(['src/Entity']));

        $violation = $this->createViolation('src/Service/UserService.php');

        self::assertTrue($filter->shouldInclude($violation), 'Violation not matching exclusion prefix should pass through');
    }

    #[Test]
    public function itPassesEmptyFilePath(): void
    {
        $filter = new PathExclusionFilter(new PathMatcher(['src']));

        $violation = new Violation(
            location: Location::none(),
            symbolPath: SymbolPath::forNamespace('App\\Service'),
            subject: MetricSubject::aggregate(SymbolPath::forNamespace('App\\Service')),
            ruleName: 'test.rule',
            violationCode: 'test.rule',
            message: 'Test',
            severity: Severity::Warning,
        );

        self::assertTrue($filter->shouldInclude($violation), 'Violation with empty file path should never be filtered');
    }

    #[Test]
    public function itFiltersGlobPattern(): void
    {
        $filter = new PathExclusionFilter(new PathMatcher(['src/Metrics/*Visitor.php']));

        $violation = $this->createViolation('src/Metrics/CboVisitor.php');

        self::assertFalse($filter->shouldInclude($violation), 'Violation matching glob pattern should be suppressed');
    }

    #[Test]
    public function itPassesWhenNoPrefixes(): void
    {
        $filter = new PathExclusionFilter(new PathMatcher([]));

        $violation = $this->createViolation('src/Entity/User.php');

        self::assertTrue($filter->shouldInclude($violation), 'Empty PathMatcher should not filter any violations');
    }

    private function createViolation(string $file, string $ruleName = 'test.rule'): Violation
    {
        return new Violation(
            location: new Location(RelativePath::fromString($file), 10),
            symbolPath: SymbolPath::forClass('App\\Entity', 'User'),
            subject: MetricSubject::declaration(new DeclarationPath(
                SymbolPath::forClass('App\\Entity', 'User'),
                RelativePath::fromString($file),
                10,
            )),
            ruleName: $ruleName,
            violationCode: $ruleName,
            message: 'Test',
            severity: Severity::Warning,
            metricValue: 5,
        );
    }
}

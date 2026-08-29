<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyRule;
use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;
use Qualimetrix\Analysis\Finding\Contract\Filter\ChannelFileScope;
use Qualimetrix\Analysis\Finding\Contract\Filter\PathExclusionFilter;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerDeclarationValidator;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Util\PathMatcher;

#[CoversClass(PathExclusionFilter::class)]
final class PathExclusionFilterTest extends TestCase
{
    #[Test]
    public function itFiltersSuppressedPath(): void
    {
        $filter = new PathExclusionFilter(new PathMatcher(['src/Entity']), self::declaredFileScope());

        $finding = $this->createFinding('src/Entity/User.php');

        self::assertFalse($filter->shouldInclude($finding), 'Violation matching exclusion prefix should be suppressed');
    }

    #[Test]
    public function itKeepsLayerViolationRuleInExcludedPath(): void
    {
        $filter = new PathExclusionFilter(new PathMatcher(['src/Entity']), self::declaredFileScope());

        $finding = $this->createFinding('src/Entity/User.php', LayerViolationRule::NAME);

        self::assertTrue($filter->shouldInclude($finding), 'architecture.* rules must not be silenced by exclude_paths');
    }

    #[Test]
    public function itKeepsCircularDependencyRuleInExcludedPath(): void
    {
        $filter = new PathExclusionFilter(new PathMatcher(['src/Entity']), self::declaredFileScope());

        $finding = $this->createFinding('src/Entity/User.php', CircularDependencyRule::NAME);

        self::assertTrue($filter->shouldInclude($finding), 'architecture.* rules must not be silenced by exclude_paths');
    }

    #[Test]
    public function itKeepsArchitectureProjectWideDiagnosticsRegardlessOfPathMatcher(): void
    {
        // architecture.unreachable-layer / .potential-shadow / .coverage / .empty-template
        // are project-level diagnostics with no file (Location::none()) — already passed
        // through by the `$file === null` branch. Verify the new architecture-exemption
        // check does not change that behavior.
        $filter = new PathExclusionFilter(new PathMatcher(['src']), self::declaredFileScope());

        foreach (
            [
                LayerDeclarationValidator::UNREACHABLE_LAYER_DIAGNOSTIC_NAME,
                LayerDeclarationValidator::POTENTIAL_SHADOW_DIAGNOSTIC_NAME,
                LayerDeclarationValidator::COVERAGE_DIAGNOSTIC_NAME,
                LayerDeclarationValidator::EMPTY_TEMPLATE_DIAGNOSTIC_NAME,
            ] as $ruleName
        ) {
            $finding = new Finding(
                location: Location::none(),
                symbolPath: SymbolPath::forNamespace(''),
                subject: MetricSubject::aggregate(SymbolPath::forProject()),
                ruleName: $ruleName,
                code: $ruleName,
                message: 'Test',
                severity: Severity::Warning,
            );

            self::assertTrue(
                $filter->shouldInclude($finding),
                \sprintf('%s must remain a no-op for file-less architecture diagnostics', $ruleName),
            );
        }
    }

    #[Test]
    public function itPassesNonMatchingPath(): void
    {
        $filter = new PathExclusionFilter(new PathMatcher(['src/Entity']), self::declaredFileScope());

        $finding = $this->createFinding('src/Service/UserService.php');

        self::assertTrue($filter->shouldInclude($finding), 'Violation not matching exclusion prefix should pass through');
    }

    #[Test]
    public function itPassesEmptyFilePath(): void
    {
        $filter = new PathExclusionFilter(new PathMatcher(['src']), self::declaredFileScope());

        $finding = new Finding(
            location: Location::none(),
            symbolPath: SymbolPath::forNamespace('App\\Service'),
            subject: MetricSubject::aggregate(SymbolPath::forNamespace('App\\Service')),
            ruleName: 'test.rule',
            code: 'test.rule',
            message: 'Test',
            severity: Severity::Warning,
        );

        self::assertTrue($filter->shouldInclude($finding), 'Violation with empty file path should never be filtered');
    }

    #[Test]
    public function itFiltersGlobPattern(): void
    {
        $filter = new PathExclusionFilter(new PathMatcher(['src/Metrics/*Visitor.php']), self::declaredFileScope());

        $finding = $this->createFinding('src/Metrics/CboVisitor.php');

        self::assertFalse($filter->shouldInclude($finding), 'Violation matching glob pattern should be suppressed');
    }

    #[Test]
    public function itPassesWhenNoPrefixes(): void
    {
        $filter = new PathExclusionFilter(new PathMatcher([]), self::declaredFileScope());

        $finding = $this->createFinding('src/Entity/User.php');

        self::assertTrue($filter->shouldInclude($finding), 'Empty PathMatcher should not filter any violations');
    }

    private function createFinding(string $file, string $ruleName = 'test.rule'): Finding
    {
        return new Finding(
            location: new Location(RelativePath::fromString($file), 10),
            symbolPath: SymbolPath::forClass('App\\Entity', 'User'),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('App\\Entity', 'User'), RelativePath::fromString($file), DeclarationOrdinal::fromRank(0))),
            ruleName: $ruleName,
            code: $ruleName,
            message: 'Test',
            severity: Severity::Warning,
            metricValue: 5,
        );
    }

    /**
     * The six project-scoped channels, in one place, asserted through the
     * declaration rather than through the `architecture.` spelling.
     *
     * This is the regression for the immunity itself: an implementation that
     * decides file scope from the rule name's first segment fails here as soon
     * as name selectors stop matching on prefixes, and an implementation that
     * simply forgets to consult the declaration fails here immediately.
     */
    #[Test]
    public function itKeepsEveryDeclaredProjectScopedChannelInAnExcludedPath(): void
    {
        $filter = new PathExclusionFilter(new PathMatcher(['src/Entity']), self::declaredFileScope());

        foreach (self::declaredProjectScopedChannelKeys() as $key) {
            $channel = new FindingChannel($key);

            self::assertTrue(
                $filter->shouldInclude($this->createChannelFinding('src/Entity/User.php', $channel)),
                \sprintf('%s is declared project-scoped and must survive exclude_paths', $key),
            );
        }
    }

    #[Test]
    public function itFiltersAChannelNoCapabilityDeclaredProjectScoped(): void
    {
        $filter = new PathExclusionFilter(new PathMatcher(['src/Entity']), self::declaredFileScope());
        $undeclared = new FindingChannel('architecture.layer-violation.invented');

        self::assertFalse(
            $filter->shouldInclude($this->createChannelFinding('src/Entity/User.php', $undeclared)),
            'Immunity follows the declaration, not the spelling of the rule name',
        );
    }

    private function createChannelFinding(string $file, FindingChannel $channel): Finding
    {
        return new Finding(
            location: new Location(RelativePath::fromString($file), 10),
            symbolPath: SymbolPath::forClass('App\\Entity', 'User'),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('App\\Entity', 'User'), RelativePath::fromString($file), DeclarationOrdinal::fromRank(0))),
            ruleName: $channel->code,
            code: $channel->code,
            message: 'Test',
            severity: Severity::Warning,
        );
    }
    /**
     * The production declaration, read from the owning capabilities rather
     * than restated here: a test that hard-coded the immune set would keep
     * passing after a capability stopped declaring its channel.
     */
    private static function declaredFileScope(): ChannelFileScope
    {
        return new ChannelFileScope([
            ...LayerPolicyPreparationInterface::PROJECT_SCOPED_CHANNELS,
            ...CircularDependencyPreparationInterface::PROJECT_SCOPED_CHANNELS,
        ]);
    }

    /** @return list<string> */
    private static function declaredProjectScopedChannelKeys(): array
    {
        return [
            ...LayerPolicyPreparationInterface::PROJECT_SCOPED_CHANNELS,
            ...CircularDependencyPreparationInterface::PROJECT_SCOPED_CHANNELS,
        ];
    }
}

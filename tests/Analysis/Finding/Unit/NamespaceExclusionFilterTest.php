<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyRule;
use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;
use Qualimetrix\Analysis\Finding\Contract\Filter\ChannelFileScope;
use Qualimetrix\Analysis\Finding\Contract\Filter\NamespaceExclusionFilter;
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
use Qualimetrix\Core\Util\NamespaceMatcher;

#[CoversClass(NamespaceExclusionFilter::class)]
final class NamespaceExclusionFilterTest extends TestCase
{
    #[Test]
    public function itFiltersSuppressedNamespace(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher(['App\\Entity']), self::declaredFileScope());

        $finding = $this->createFinding('App\\Entity', 'complexity.cyclomatic');

        self::assertFalse($filter->shouldInclude($finding), 'Violation matching excluded namespace should be suppressed');
    }

    #[Test]
    public function itPassesNonMatchingNamespace(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher(['App\\Entity']), self::declaredFileScope());

        $finding = $this->createFinding('App\\Service', 'complexity.cyclomatic');

        self::assertTrue($filter->shouldInclude($finding), 'Violation not matching excluded namespace should pass through');
    }

    #[Test]
    public function itKeepsLayerViolationRuleInExcludedNamespace(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher(['App\\Entity']), self::declaredFileScope());

        $finding = $this->createFinding('App\\Entity', LayerViolationRule::NAME);

        self::assertTrue($filter->shouldInclude($finding), 'architecture.* rules must not be silenced by exclude_namespaces');
    }

    #[Test]
    public function itKeepsCircularDependencyRuleInExcludedNamespace(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher(['App\\Entity']), self::declaredFileScope());

        $finding = $this->createFinding('App\\Entity', CircularDependencyRule::NAME);

        self::assertTrue($filter->shouldInclude($finding), 'architecture.* rules must not be silenced by exclude_namespaces');
    }

    #[Test]
    public function itKeepsArchitectureCoverageDiagnosticEvenIfNamePrefixMatchesByCoincidence(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher(['App\\Entity']), self::declaredFileScope());

        // architecture.coverage and friends are project-level (empty namespace) diagnostics,
        // but the exemption is driven purely by the rule-name prefix — verify it still applies.
        $finding = $this->createFinding('App\\Entity', LayerDeclarationValidator::COVERAGE_DIAGNOSTIC_NAME);

        self::assertTrue($filter->shouldInclude($finding));
    }

    #[Test]
    public function itKeepsArchitectureRuleEvenWhenItIsAFileSymbolFindingInExcludedNamespace(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher(['App\\Entity']), self::declaredFileScope());

        // Occurrence-style findings carry a file symbol path; the architecture
        // exemption is decided before any namespace resolution, so it must hold
        // even for a file-symbol finding whose subject declares an excluded namespace.
        $finding = $this->createFileSymbolFinding('App\\Entity', LayerViolationRule::NAME);

        self::assertTrue($filter->shouldInclude($finding), 'architecture.* rules must not be silenced by exclude_namespaces');
    }

    #[Test]
    public function itPassesWhenNoPatterns(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher([]), self::declaredFileScope());

        $finding = $this->createFinding('App\\Entity', 'complexity.cyclomatic');

        self::assertTrue($filter->shouldInclude($finding), 'Empty NamespaceMatcher should not filter any violations');
    }

    #[Test]
    public function itFiltersFileSymbolFindingWhoseSubjectNamespaceMatches(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher(['App\\Entity']), self::declaredFileScope());

        $finding = $this->createFileSymbolFinding('App\\Entity', 'code-smell.eval');

        self::assertFalse($filter->shouldInclude($finding), 'File-symbol violation whose declaration namespace matches should be suppressed');
    }

    #[Test]
    public function itPassesFileSymbolFindingWhoseSubjectNamespaceDoesNotMatch(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher(['App\\Entity']), self::declaredFileScope());

        $finding = $this->createFileSymbolFinding('App\\Service', 'code-smell.eval');

        self::assertTrue($filter->shouldInclude($finding), 'File-symbol violation whose declaration namespace does not match should pass through');
    }

    #[Test]
    public function itPassesFileSymbolFindingWithoutDeclaringNamespace(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher(['App']), self::declaredFileScope());

        // A file-symbol finding with no declaration subject has no namespace
        // to resolve, so it cannot be matched by any namespace exclusion.
        $file = RelativePath::fromString('src/helpers.php');
        $finding = new Finding(
            location: new Location($file, 10),
            symbolPath: SymbolPath::forFile($file),
            subject: MetricSubject::aggregate(SymbolPath::forFile($file)),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic.callable',
            message: 'Test',
            severity: Severity::Warning,
        );

        self::assertTrue($filter->shouldInclude($finding), 'File-symbol violation without a declaring namespace should not be filtered');
    }

    private function createFinding(string $namespace, string $ruleName): Finding
    {
        return new Finding(
            location: new Location(RelativePath::fromString('src/Entity/User.php'), 10),
            symbolPath: SymbolPath::forClass($namespace, 'User'),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass($namespace, 'User'), RelativePath::fromString('src/Entity/User.php'), DeclarationOrdinal::fromRank(0))),
            ruleName: $ruleName,
            code: $ruleName,
            message: 'Test',
            severity: Severity::Warning,
        );
    }

    private function createFileSymbolFinding(string $subjectNamespace, string $ruleName): Finding
    {
        $file = RelativePath::fromString('src/Entity/User.php');

        return new Finding(
            location: new Location($file, 10),
            symbolPath: SymbolPath::forFile($file),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass($subjectNamespace, 'User'), $file, DeclarationOrdinal::fromRank(0))),
            ruleName: $ruleName,
            code: $ruleName,
            message: 'Test',
            severity: Severity::Warning,
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
    public function itKeepsEveryDeclaredProjectScopedChannelInAnExcludedNamespace(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher(['App\\Entity']), self::declaredFileScope());

        foreach (self::declaredProjectScopedChannelKeys() as $key) {
            $channel = FindingChannel::fromKey($key);

            self::assertTrue(
                $filter->shouldInclude($this->createChannelFinding($channel)),
                \sprintf('%s is declared project-scoped and must survive exclude_namespaces', $key),
            );
        }
    }

    #[Test]
    public function itFiltersAChannelNoCapabilityDeclaredProjectScoped(): void
    {
        $filter = new NamespaceExclusionFilter(new NamespaceMatcher(['App\\Entity']), self::declaredFileScope());
        $undeclared = new FindingChannel('architecture.layer-violation', 'architecture.layer-violation.invented');

        self::assertFalse(
            $filter->shouldInclude($this->createChannelFinding($undeclared)),
            'Immunity follows the declaration, not the spelling of the rule name',
        );
    }

    private function createChannelFinding(FindingChannel $channel): Finding
    {
        return new Finding(
            location: new Location(RelativePath::fromString('src/Entity/User.php'), 10),
            symbolPath: SymbolPath::forClass('App\\Entity', 'User'),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('App\\Entity', 'User'), RelativePath::fromString('src/Entity/User.php'), DeclarationOrdinal::fromRank(0))),
            ruleName: $channel->ruleName,
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

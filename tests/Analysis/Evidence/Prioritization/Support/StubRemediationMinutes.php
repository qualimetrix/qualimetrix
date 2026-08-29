<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Prioritization\Support;

/**
 * A `$minutesByRule` map a test states outright, mirroring
 * {@see \Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry}'s
 * role for declarations: {@see \Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry}
 * no longer keeps this fact itself — production wires it from every rule's
 * own `REMEDIATION_MINUTES` constant — so a test constructing the registry
 * directly (rather than through the container) must supply the map by hand.
 *
 * {@see withRealValues()} mirrors the values the rule classes themselves
 * declare, for a test whose assertions depend on the actual calibration
 * (scaling arithmetic, debt totals). It also covers the fixture rule names
 * (`test`, `r`, `cyclomatic-complexity`, …) formatter tests invent for
 * unrelated purposes — all at 15, the number those calls used to reach by
 * falling through the now-removed `DEFAULT_MINUTES`, so unrelated tests keep
 * their prior expected output rather than each declaring a name they don't
 * care about.
 */
final class StubRemediationMinutes
{
    private function __construct() {}

    /** @return array<string, int> */
    public static function withRealValues(): array
    {
        return [
            // Complexity
            'complexity.cyclomatic' => 30,
            'complexity.cognitive' => 30,
            'complexity.npath' => 30,
            'complexity.wmc' => 30,

            // Coupling
            'coupling.cbo' => 45,
            'coupling.class-rank' => 30,
            'coupling.instability' => 30,
            'coupling.distance' => 30,

            // Design
            'design.inheritance' => 30,
            'design.noc' => 20,
            'design.type-coverage.param' => 15,
            'design.type-coverage.return' => 15,
            'design.type-coverage.property' => 15,
            'cohesion.lcom' => 45,
            'design.data-class' => 30,
            'design.god-class' => 120,

            // Size
            'size.class-count' => 30,
            'size.method-count' => 20,
            'size.property-count' => 15,

            // Maintainability
            'maintainability.index' => 60,

            // Code smell
            'code-smell.constructor-overinjection' => 60,
            'code-smell.boolean-argument' => 10,
            'code-smell.debug-code' => 5,
            'code-smell.empty-catch' => 10,
            'code-smell.eval' => 15,
            'code-smell.exit' => 10,
            'code-smell.goto' => 15,
            'code-smell.superglobals' => 15,
            'code-smell.error-suppression' => 10,
            'code-smell.count-in-loop' => 10,
            'code-smell.long-parameter-list' => 20,
            'code-smell.unreachable-code' => 10,
            'code-smell.unused-private' => 15,
            'code-smell.identical-subexpression' => 15,

            // Security
            'security.hardcoded-credentials' => 30,
            'security.sql-injection' => 60,
            'security.xss' => 45,
            'security.command-injection' => 60,
            'security.sensitive-parameter' => 10,

            // Architecture / annotation / duplication / computed
            'architecture.circular-dependency' => 120,
            'architecture.layer-violation' => 15,
            'annotation.directive' => 15,
            'duplication.code-duplication' => 15,
            'computed' => 15,

            'architecture.unassigned-class' => 15,

            // Sub-diagnostic identities emitted by LayerDeclarationValidator
            // and UnusedDirectiveRule/InlineDirectivePolicy under their own
            // ruleName, distinct from the producing class's own NAME — see
            // ChannelDeclarationCompilerPass, which inherits the producer's
            // minutes for these at container-build time.
            'architecture.coverage' => 15,
            'architecture.unreachable-layer' => 15,
            'architecture.potential-shadow' => 15,
            'architecture.empty-template' => 15,
            'architecture.pending-layer-matched' => 15,
            'annotation.unresolved-directive' => 15,
            'annotation.unsupported-threshold' => 15,
            'annotation.invalid-threshold' => 15,
            'annotation.unused-directive' => 15,

            // Fixture-only rule names used by tests unrelated to debt calibration
            'architecture.circular' => 15,
            'code-smell' => 15,
            'complexity' => 15,
            'cyclomatic-complexity' => 15,
            'file-size' => 15,
            'fixture.rule' => 15,
            'lcom' => 15,
            'namespace-size' => 15,
            'r' => 15,
            'size.loc' => 15,
            'size.namespace' => 15,
            'test' => 15,
            'test-rule' => 15,
        ];
    }
}

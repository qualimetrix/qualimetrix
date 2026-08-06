<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Rule;

use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Violation\ChannelDeclaration;
use Qualimetrix\Core\Violation\ChannelDeclarationRegistryInterface;
use Qualimetrix\Core\Violation\ViolationChannel;

/**
 * Assembles the static per-rule declarations with the run-time
 * `computed.*` / `health.*` family into one lookup.
 *
 * The static half is handed in by
 * {@see \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ChannelDeclarationCompilerPass}
 * — this class never scans rule classes itself, mirroring
 * {@see RuleRegistry}, which likewise receives its rule class list from a
 * compiler pass rather than discovering it. `Core` may not depend on
 * `Rules`, so nothing under `Core` could have assembled this map either way.
 *
 * The run-time half is resolved on every lookup from
 * {@see ComputedMetricDefinitionHolder} — the same static holder
 * `ComputedMetricRuleOptions` already reads to receive config-resolved
 * definitions without DI wiring (see its README entry). A `computed.*` /
 * `health.*` channel's vocabulary is open-ended (users define computed
 * metrics in `qmx.yaml`), so no compile-time map could enumerate it; both
 * facts the ceiling needs — shape (always `magnitude`) and direction (from
 * the definition's `inverted` flag) — are only known once configuration has
 * resolved the definition.
 */
final readonly class ChannelDeclarationRegistry implements ChannelDeclarationRegistryInterface
{
    /**
     * @param array<string, ChannelDeclaration> $staticDeclarations keyed by
     *                                                              {@see ViolationChannel::toKey()}
     * @param string $computedMetricRuleName the
     *                                       **family discriminator** for the run-time half, not incidental
     *                                       configuration: every `computed.*` / `health.*` channel is
     *                                       emitted under this one `ruleName` (`ComputedMetricRule::NAME`,
     *                                       literally `'computed.health'`, covering both the six built-in
     *                                       `health.*` scores and any user-defined `computed.*` metric).
     *                                       {@see declarationFor()} compares an incoming channel's
     *                                       `ruleName` against this value to decide whether to resolve it
     *                                       from {@see ComputedMetricDefinitionHolder} instead of the
     *                                       static map. Injected rather than read from the constant
     *                                       directly so this class — `Infrastructure\Rule` — needs no
     *                                       `qmx.yaml` dependency edge onto `Rules` just to know one string;
     *                                       the compiler pass, which already depends on rule classes to
     *                                       wire the container, supplies it.
     */
    public function __construct(
        private array $staticDeclarations,
        private string $computedMetricRuleName,
    ) {}

    public function declarationFor(ViolationChannel $channel): ?ChannelDeclaration
    {
        if ($channel->ruleName === $this->computedMetricRuleName) {
            return $this->resolveComputedMetricDeclaration($channel);
        }

        return $this->staticDeclarations[$channel->toKey()] ?? null;
    }

    public function staticDeclarations(): array
    {
        return $this->staticDeclarations;
    }

    /**
     * Resolves a `computed.*` / `health.*` channel's declaration from the
     * matching configured definition, or `null` when no definition with
     * that name is currently configured (e.g. a stale entry left over after
     * a user removed a `computed_metrics:` entry — not baselineable, and
     * not an error, per the same governing invariant as any other unknown
     * channel).
     */
    private function resolveComputedMetricDeclaration(ViolationChannel $channel): ?ChannelDeclaration
    {
        foreach (ComputedMetricDefinitionHolder::getDefinitions() as $definition) {
            if ($definition->name === $channel->violationCode) {
                return ChannelDeclaration::magnitude(
                    $definition->inverted ? WorseDirection::Lower : WorseDirection::Higher,
                );
            }
        }

        return null;
    }
}

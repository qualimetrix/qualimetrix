<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CompilerPass;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use ReflectionClass;

/**
 * The build-time guard over the metric a channel declares it judges.
 *
 * Its own class rather than three more methods on
 * {@see ChannelDeclarationCompilerPass}: the pass composes the channel
 * registry, this decides whether one declaration is sayable at all, and only
 * this side needs to read the metric catalog.
 */
final class JudgedMetricDeclarationGuard
{
    /**
     * The two build-time properties of a declared judged metric (ADR 0046):
     * every key it names exists in the catalog, and only a `magnitude`
     * producer names any.
     *
     * **Checked here rather than in `ChannelDeclaration` because of who owns
     * what.** {@see MetricName} belongs to `Analysis.Evidence.Measurement` and
     * {@see ChannelDeclaration} to `Analysis.Finding`; asserting inside the
     * declaration would buy a build-time check with a permanent dependency
     * edge between two capabilities. The composition root already holds both sides.
     *
     * An aggregate spelling counts as existing: `size.class-count.sum` is what
     * {@see \Qualimetrix\Analysis\Evidence\Size\ClassCountRule} actually
     * reads, and {@see MetricName::base()} strips a suffix only when it is a
     * real {@see \Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy}
     * value — so `complexity.ccn.max` resolves and `complexity.ccn.mux` does
     * not. What this does **not** check is that the key is the one the rule
     * body actually reads: that is a property of an observed run, and only a
     * run comparing published magnitudes against the declaration can say it.
     *
     * **The channels this check says nothing about — six, named, so the set
     * cannot grow in silence.** Five are static channels that declare no
     * judged metric at all, and the sixth is a whole run-time family:
     *
     * 1. `architecture.circular-dependency` — magnitude, publishes a cycle's
     *    member count from the dependency graph.
     * 2. `architecture.unassigned-class` — magnitude, publishes a count of
     *    unassigned declarations.
     * 3. `duplication.code-duplication` — magnitude, publishes a duplicated
     *    block's line count from the duplication engine.
     * 4. `design.god-class` — magnitude, publishes how many of its criteria
     *    matched, which is a number about the rule and not about the code.
     * 5. `coupling.class-rank` — the one that is *not* covered although a
     *    catalog metric is exactly what it publishes: it is deliberately
     *    `occurrence` (ADR 0017 point 5), so this check stays silent over a
     *    live `coupling.class-rank` value. The trade is recorded, not
     *    overlooked.
     * 6. every channel of the computed-metric family — resolved at run time
     *    from configuration, so no build-time pass can see its keys at all.
     *
     * @param class-string $class
     */
    public static function assertDeclarable(
        string $key,
        string $class,
        string $producerRuleName,
        ChannelShape $declaredShape,
        ChannelDeclaration $declaration,
    ): void {
        $judges = $declaration->judges;

        if ($judges === null) {
            return;
        }

        if ($declaredShape !== ChannelShape::Magnitude) {
            throw new LogicException(\sprintf(
                'Channel "%s" declared by %s names a judged metric, but producer "%s" declares shape "%s".'
                . ' A judged metric is where a reported magnitude comes from, and an occurrence producer'
                . ' reports none.',
                $key,
                $class,
                $producerRuleName,
                $declaredShape->value,
            ));
        }

        foreach ($judges->keys as $metricKey) {
            if (self::catalogKeyExists($metricKey)) {
                continue;
            }

            throw new LogicException(\sprintf(
                'Channel "%s" declared by %s judges "%s", which is not a metric the catalog publishes.'
                . ' A judged key is the exact published spelling, aggregate strategy included, and it must'
                . ' be a MetricName constant value or an aggregate of one.',
                $key,
                $class,
                $metricKey,
            ));
        }
    }

    /**
     * Whether a key is a published metric name, directly or as an aggregate of
     * one.
     */
    private static function catalogKeyExists(string $metricKey): bool
    {
        $catalog = self::catalogKeys();

        if (isset($catalog[$metricKey])) {
            return true;
        }

        $base = MetricName::base($metricKey);

        return $base !== $metricKey && isset($catalog[$base]);
    }

    /**
     * The catalog's published keys, as a set, read off the constants of
     * {@see MetricName} rather than listed here — a second list would be a
     * second authority, and it is the first one that would go stale.
     *
     * @return array<string, true>
     */
    private static function catalogKeys(): array
    {
        static $keys = null;

        if ($keys === null) {
            $keys = [];

            /** @var mixed $value */
            foreach (new ReflectionClass(MetricName::class)->getConstants() as $value) {
                if (\is_string($value)) {
                    $keys[$value] = true;
                }
            }
        }

        /** @var array<string, true> $keys */
        return $keys;
    }
}

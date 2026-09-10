<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ExcludeSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Builds `architecture.unmatched-exclude`: an `exclude:` clause that removed
 * nothing from a layer whose own criteria caught something.
 *
 * The layer is then wider than its declaration asks for, and every verdict
 * about it — which edges it is allowed, what coverage it accounts for — is
 * drawn from that wider set. Which is why the channel exists: the wrong answer
 * is not refused anywhere, it is simply larger than the author asked for.
 *
 * **Why the rule publishes it and not {@see LayerDeclarationValidator}.** A
 * {@see \Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface}
 * fails the run regardless of `fail_on` and can never be accepted by a
 * baseline. An inert exclude clause is not that: the declaration parses, every
 * layer resolves, and the consequence is a wider layer — ordinary debt a
 * project may accept and pay down. So the finding goes through
 * {@see LayerViolationRule} and answers to `fail_on`, `--disable-rule` and the
 * baseline like any other.
 *
 * Extracted from that rule for the reason {@see LayerViolationFinding} and
 * {@see UnassignedClassSummary} were: the rule owns the decision to report,
 * and a finding's own text and severity are a separate subject that would
 * otherwise put the rule over its coupling ceiling.
 *
 * @internal Consumed by {@see LayerViolationRule}.
 */
final class UnmatchedExcludeDiagnostic
{
    /**
     * The severity the channel reports at, and deliberately not
     * {@see LayerViolationOptions::$severity}: that option is documented as
     * the severity of every reported `architecture.layer-violation`, and a
     * project that raises its forbidden edges to `error` has said nothing
     * about how loudly it wants to hear that one of its exclude clauses is
     * inert. Warning is what the signal is — the run's conclusions are wider
     * than asked for, worth stopping a strict pipeline over, but not a
     * configuration that cannot be honoured.
     */
    private const Severity SEVERITY = Severity::Warning;

    /**
     * One finding per layer whose `exclude:` clause never fired even though
     * the layer's positive criteria caught something.
     *
     * **Why "caught something" is part of the predicate.** `exclude:` is
     * evaluated only after the positive criteria succeed
     * ({@see LayerDefinition::matches()}), so a layer nothing matched has an
     * empty exclusion count for a reason that has nothing to do with the
     * clause. Reporting there would restate `architecture.unreachable-layer`
     * about the same layer in different words.
     *
     * A layer declared `pending: true` is skipped for the same reason that
     * diagnostic skips it: its author has said the code does not exist yet.
     *
     * Granularity is the clause, not the individual pattern: a `suffix:` entry
     * that fires inside a multi-kind clause under `mode: any` leaves the other
     * kinds unexamined, and this channel does not claim to see that.
     *
     * @param string $channelName The rule name the findings publish under, passed
     *                            in rather than read here so the channel's spelling
     *                            keeps its single home on the producer.
     *
     * @return list<Finding>
     */
    public static function forInertClauses(LayerEvidence $evidence, string $channelName): array
    {
        $matchedCounts = $evidence->matchedCounts();
        $excludedCounts = $evidence->excludedCounts();

        $findings = [];

        foreach ($evidence->architecture->registry()->definitions() as $definition) {
            $exclude = $definition->membership()->exclude;
            $layerName = $definition->name();

            if ($exclude === null || $definition->lifecycle->isPending()) {
                continue;
            }

            $matched = $matchedCounts[$layerName] ?? 0;
            if ($matched === 0 || ($excludedCounts[$layerName] ?? 0) > 0) {
                continue;
            }

            $findings[] = new Finding(
                location: Location::none(),
                subject: MetricSubject::aggregate(SymbolPath::forProject()),
                symbolPath: SymbolPath::forProject(),
                ruleName: $channelName,
                code: $channelName,
                message: \sprintf(
                    'The "exclude" clause of layer "%s" (%s) removed no class from it, while the layer\'s own criteria (%s) matched %d symbol(s). The layer is therefore wider than the declaration asks for, and every verdict about it is drawn from that wider set.',
                    $layerName,
                    self::describe($exclude),
                    $definition->membership()->describe(),
                    $matched,
                ),
                severity: self::SEVERITY,
                recommendation: \sprintf(
                    'Check the exclude criteria against the classes layer "%s" actually holds — "qmx debug:layer-assignment <class>" shows what a given class matched. Correct the criteria, or drop the "exclude" clause if the classes it was written for are gone.',
                    $layerName,
                ),
            );
        }

        return $findings;
    }

    /**
     * The exclude clause in the same shape
     * {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec::describe()}
     * renders the positive criteria, so the message reads the two side by
     * side. Written here rather than on {@see ExcludeSpec} because this is its
     * only caller.
     */
    private static function describe(ExcludeSpec $exclude): string
    {
        $segments = [];

        foreach ([
            'patterns' => $exclude->patterns,
            'suffix' => $exclude->suffix,
            'attributes' => $exclude->attributes,
            'implements' => $exclude->implements,
            'extends' => $exclude->extends,
        ] as $kind => $values) {
            if ($values === []) {
                continue;
            }

            $segments[] = $kind . ': ' . implode(', ', array_map(
                static fn(string $value): string => '"' . $value . '"',
                $values,
            ));
        }

        return implode('; ', $segments);
    }
}

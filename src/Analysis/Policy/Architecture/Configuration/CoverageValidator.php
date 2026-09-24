<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Configuration;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;

/**
 * Parses and validates the {@code architecture.coverage-gap} scalar.
 *
 * Accepts {@code null} (defaults to {@see CoverageMode::Ignore}) or a
 * case-insensitive string of {@code 'ignore'}, {@code 'warn'}, {@code 'error'}.
 *
 * The mode is also checked against the layers it would judge, because the two
 * halves are one contract: a mode that names a policy and a declaration that
 * carries none. Splitting them across two owners is what let the pair be
 * accepted in the first place.
 */
final class CoverageValidator
{
    private const array ACCEPTED = ['error', 'ignore', 'warn'];

    public function validate(mixed $coverageRaw): CoverageMode
    {
        if ($coverageRaw === null) {
            return CoverageMode::Ignore;
        }

        if (!\is_string($coverageRaw)) {
            throw ConfigurationRefusal::atResolvedKey(
                RefusedPosition::closed(['architecture', 'coverage-gap'], get_debug_type($coverageRaw), self::ACCEPTED),
                \sprintf(
                    "architecture.coverage-gap: must be one of 'ignore', 'warn', 'error' (got %s).",
                    get_debug_type($coverageRaw),
                ),
            );
        }

        try {
            return CoverageMode::fromString($coverageRaw);
        } catch (InvalidArgumentException $e) {
            throw ConfigurationRefusal::atResolvedKey(
                RefusedPosition::closed(['architecture', 'coverage-gap'], $coverageRaw, self::ACCEPTED),
                \sprintf(
                    "architecture.coverage-gap: must be one of 'ignore', 'warn', 'error' (got '%s').",
                    $coverageRaw,
                ),
                previous: $e,
            );
        }
    }

    /**
     * Refuses a non-ignore mode on a section that declares no layers at all.
     *
     * Asked separately by the factory rather than folded into
     * {@see validate()}, because it is a question about a PAIR: the scalar
     * parses fine on its own, and a default-empty second parameter would make
     * the check opt-out by omission — the shape this whole package is about.
     *
     * The pair is a declaration that cannot be violated: the mode says every
     * class outside a layer is a warning or an error, and with no layers every
     * class is outside one — yet the run reports nothing, because the evidence
     * walk short-circuits on an empty registry and the run exits 0. The state is
     * reachable without a typo: `layers:` is REPLACED rather than merged across
     * configuration contributions, so a contribution that did not load on this
     * run leaves the mode behind with nothing to enforce, and the loudest
     * setting this option has produces the quietest possible outcome.
     *
     * Refused rather than made to report: diagnosing an empty registry would
     * fail every project that has not written a `layers:` block yet, which is
     * not what the option's author asked for either.
     *
     * The `architecture.unassigned-class` half of this is NOT covered here. Its
     * mode is a rule option, invisible to the configuration factory, and the
     * same emptiness silences it the same way.
     *
     * @param list<\Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition|\Qualimetrix\Analysis\Policy\Architecture\Layer\TemplateLayerDefinition> $layerEntries
     */
    public function rejectModeWithNothingToJudge(CoverageMode $mode, array $layerEntries): void
    {
        if ($mode === CoverageMode::Ignore || $layerEntries !== []) {
            return;
        }

        throw ConfigurationRefusal::atResolvedKey(
            RefusedPosition::open(['architecture', 'coverage-gap'], 'architecture.coverage-gap'),
            \sprintf(
                'architecture.coverage-gap: "%s" requires at least one entry under "architecture.layers". '
                . 'With no layers declared every class is outside every layer, and the run reports none of them — '
                . 'so the strictest setting of this option is also the silent one. Declare the layers the mode is '
                . 'meant to enforce, or leave coverage-gap on "ignore".',
                $mode->value,
            ),
        );
    }
}

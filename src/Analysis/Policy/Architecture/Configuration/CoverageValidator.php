<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Configuration;

use InvalidArgumentException;
use LogicException;

/**
 * Parses and validates the {@code architecture.coverage-gap} scalar.
 *
 * Accepts a key not written (defaults to {@see CoverageMode::Ignore}) or a
 * case-insensitive string of {@code 'ignore'}, {@code 'warn'}, {@code 'error'};
 * the configuration engine has already refused any other form.
 *
 * The mode is also checked against the layers it would judge, because the two
 * halves are one contract: a mode that names a policy and a declaration that
 * carries none. Splitting them across two owners is what let the pair be
 * accepted in the first place.
 */
final class CoverageValidator
{
    private const array ACCEPTED = ['error', 'ignore', 'warn'];

    public function validate(SectionSpot $coverage): CoverageMode
    {
        $value = $coverage->value();
        if ($value === null) {
            return CoverageMode::Ignore;
        }

        if (!\is_string($value)) {
            throw new LogicException('The configuration engine admits only a string as architecture.coverage-gap.');
        }

        try {
            return CoverageMode::fromString($value);
        } catch (InvalidArgumentException) {
            throw $coverage->refusal(
                \sprintf("architecture.coverage-gap: must be one of 'ignore', 'warn', 'error' (got '%s').", $value),
                self::ACCEPTED,
                $value,
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
     * The refusal names the layers that wrote either half: the mode and an
     * empty `layers:` can come from different configuration files.
     *
     * The `architecture.unassigned-class` half of this is NOT covered here. Its
     * mode is a rule option, invisible to the configuration factory, and the
     * same emptiness silences it the same way.
     *
     * @param list<\Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition|\Qualimetrix\Analysis\Policy\Architecture\Layer\TemplateLayerDefinition> $layerEntries
     */
    public function rejectModeWithNothingToJudge(CoverageMode $mode, array $layerEntries, SectionSpot $coverage, SectionSpot $layers): void
    {
        if ($mode === CoverageMode::Ignore || $layerEntries !== []) {
            return;
        }

        throw SectionSpot::refusalAcross(
            [$layers, $coverage],
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

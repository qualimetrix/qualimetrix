<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection;

use Qualimetrix\Analysis\Finding\Contract\Finding;

/**
 * Names the `file:line` of the `@qmx-ignore` directive that silenced one
 * finding, split out of {@see SuppressionCompositionBuilder} as its own
 * subject: placement rules for a symbol directive versus a physical one are
 * two independent decisions the sibling class does not need to know about to
 * assemble the rest of the composition.
 *
 * Replicates the placement rules of the directive filter that owns this
 * decision (`Analysis\Policy\Inline`'s suppression filter — internal to that
 * capability, so named here rather than `@see`-linked) using only
 * `Suppression`'s public fields; see {@see SuppressionCompositionBuilder}'s
 * class docblock for why this is a recomputation rather than a read.
 *
 * **Two of that filter's three conditions are reproduced here, and the third
 * cannot be.** The channel ban — the two channels no directive may silence —
 * is asked there about the finding alone, and naming it here would make
 * Reporting depend on an internal of the capability whose values it holds as
 * `mixed`. Nothing is lost while the input stays what it is: this resolver is
 * asked only about findings that filter removed, and a finding on a banned
 * channel is precisely one it did not remove. Should another path ever put a
 * suppressed finding in front of this class, that is the assumption to
 * re-check first.
 *
 * The selector half is not reproduced at all: `matches()` is the directive's
 * own answer, so a directive the extractor refused — one whose tag no grammar
 * reads, or one written where nothing binds — is silently unable to be named
 * here as a suppressor, which is what it is.
 */
final readonly class DirectiveSuppressorResolver
{
    /**
     * @param array<string, list<mixed>> $suppressions Per-file `Suppression` VOs, read only through public fields
     */
    public function resolve(Finding $finding, array $suppressions): string
    {
        return $this->symbolDirectiveSuppressor($finding, $suppressions)
            ?? $this->physicalDirectiveSuppressor($finding, $suppressions)
            ?? '(unresolved directive)';
    }

    /**
     * @param array<string, list<mixed>> $suppressions
     */
    private function symbolDirectiveSuppressor(Finding $finding, array $suppressions): ?string
    {
        foreach ($suppressions as $declaredFile => $fileSuppressions) {
            foreach ($fileSuppressions as $suppression) {
                if ($this->isMatchingSymbolDirective($suppression, $finding)) {
                    return $declaredFile . ':' . $suppression->line;
                }
            }
        }

        return null;
    }

    private function isMatchingSymbolDirective(mixed $suppression, Finding $finding): bool
    {
        return $suppression->type->value === 'symbol'
            && $suppression->binding?->subject->toCanonical() === $finding->subject->toCanonical()
            && $suppression->matches($finding->code, $finding->level());
    }

    /**
     * @param array<string, list<mixed>> $suppressions
     */
    private function physicalDirectiveSuppressor(Finding $finding, array $suppressions): ?string
    {
        $file = $finding->location->pathString();

        foreach ($suppressions[$file] ?? [] as $suppression) {
            if ($suppression->type->value === 'symbol' || !$suppression->matches($finding->code, $finding->level())) {
                continue;
            }

            if ($suppression->type->value === 'file' || $finding->location->line === $suppression->line + 1) {
                return $file . ':' . $suppression->line;
            }
        }

        return null;
    }
}

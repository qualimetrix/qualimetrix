<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Configuration;

/**
 * Whether the code `composer.json` declares under `autoload-dev` is part of
 * the project a run is about — both what a run with no `paths` analyses and
 * what a run is judged against when asked whether it covered the project.
 */
enum AutoloadDevPolicy
{
    case Include;
    case Exclude;

    /**
     * The targets that make up the project under this policy: the
     * production ones, and the `autoload-dev` ones when the policy counts
     * them. Both halves of a run ask this one question — the default paths
     * and the scope denominator — so they cannot answer it differently.
     *
     * @param ?list<string> $production
     * @param ?list<string> $development
     *
     * @return ?list<string> `null` when no counted section declares anything
     */
    public function projectTargets(?array $production, ?array $development): ?array
    {
        if ($this === self::Exclude || $development === null) {
            return $production;
        }

        return array_values(array_unique([...$production ?? [], ...$development]));
    }
}

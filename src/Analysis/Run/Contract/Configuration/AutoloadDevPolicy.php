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
}

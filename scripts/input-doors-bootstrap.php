<?php

declare(strict_types=1);

/**
 * Shared boot of the product's console application for the input-door stand.
 *
 * The command map is the one `bin/qmx` installs; a command absent from it is
 * invisible to the grid, which is the named blind spot of the reflection
 * method (`01-oracle.md` §3, case "д").
 */

namespace Qualimetrix\InputDoors;

use Qualimetrix\Infrastructure\Console\Application\Application;
use Qualimetrix\Infrastructure\Console\Application\ConsoleDiagnostics;
use Qualimetrix\Infrastructure\Console\Application\ProductCommandLoader;
use Qualimetrix\Infrastructure\Console\Refusal\OutOfMemoryHint;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

/** @return array<string, class-string> */
function commandMap(): array
{
    return ProductCommandLoader::NAMES;
}

function application(): Application
{
    return Application::forProduct(
        static fn(ConsoleDiagnostics $diagnostics) => (new ContainerFactory())->create($diagnostics),
        OutOfMemoryHint::register(),
    );
}

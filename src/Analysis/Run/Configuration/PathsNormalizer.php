<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;

final class PathsNormalizer
{
    /**
     * @param list<string> $paths
     *
     * @return list<AbsolutePath>
     */
    public static function normalize(AbsolutePath $root, array $paths): array
    {
        return array_map(
            static fn(string $path): AbsolutePath => PathFactory::fromCliArgument($path, $root),
            $paths,
        );
    }
}

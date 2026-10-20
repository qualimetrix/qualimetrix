<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration;

use LogicException;

/** Canonical root keys and command-line paths in the configuration document. */
final class DocumentRoots
{
    /**
     * Every root the configuration document accepts, in its canonical key,
     * declared or not.
     *
     * @return list<string>
     */
    public static function known(): array
    {
        return self::canonical(ConfigSchema::allowedRootKeys());
    }

    /**
     * The document path of a flat configuration key — the key the command
     * line writes under — in canonical keys: `cache.dir` is `cache` → `dir`.
     *
     * @return list<string>
     */
    public static function pathOf(string $flatKey): array
    {
        foreach (ConfigSchema::ENTRIES as [$sourcePath, $resultKey]) {
            if ($resultKey === $flatKey) {
                return self::canonical(explode('.', $sourcePath));
            }
        }

        if (\in_array($flatKey, ConfigSchema::DOCUMENT_ROOTS, true)) {
            return self::canonical([$flatKey]);
        }

        throw new LogicException(\sprintf('Configuration key "%s" has no place in the document.', $flatKey));
    }

    /**
     * @param list<string> $camelKeys
     *
     * @return list<string>
     */
    private static function canonical(array $camelKeys): array
    {
        return array_map(static fn(string $camel): string => ConfigKeySpelling::rewriteLike($camel, '_'), $camelKeys);
    }
}

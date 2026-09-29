<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;

/**
 * Every root of the configuration document and who declares it: the roots
 * Configuration owns ({@see ConfigurationRoot}), the sections owners register,
 * and a stand-in for a known root nobody declared yet.
 *
 * The stand-in keeps the root dictionary closed while owners move onto the
 * engine one by one: a key that is no root at all is refused as unknown,
 * whichever roots are declared.
 */
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
     * @param list<DocumentSectionSchemaInterface> $ownerSections
     *
     * @return list<DocumentSectionSchemaInterface>
     */
    public static function completing(array $ownerSections): array
    {
        $sections = [...ConfigurationRoot::cases(), ...$ownerSections];
        $declared = array_map(static fn(DocumentSectionSchemaInterface $section): string => $section->declaration()->key, $sections);

        foreach (self::known() as $root) {
            if (!\in_array($root, $declared, true)) {
                $sections[] = new UndeclaredRoot($root);
            }
        }

        return $sections;
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

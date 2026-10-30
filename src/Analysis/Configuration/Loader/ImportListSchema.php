<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;

/**
 * The form of the `imports` list: local file paths, globs over one directory
 * level, and names of built-in presets. The import chain reads the list with this schema before
 * composition and hands the composer every layer without it.
 */
final class ImportListSchema
{
    public static function schema(): NodeSchema
    {
        return NodeSchema::list(
            NodeSchema::scalar(ScalarForm::String)->nonEmpty()->judgedInEachLayer(self::judge(...)),
        )->withWording(description: 'Configuration files this one builds on, lowest precedence first; this file overrides them all.'
            . ' Read only in the root configuration file. Each entry is resolved against the directory of the file that writes it'
            . ' (of its target, when that file is a symlink);'
            . ' every other path keeps the working directory as its base.');
    }

    /** @param list<string> $at */
    private static function judge(ResolvedValueInterface $entry, array $at): void
    {
        $path = (string) $entry->plain();
        $extensions = YamlConfigLoader::SUPPORTED_EXTENSIONS;
        $isPattern = ImportGlob::isPattern($path);
        $refusal = match (true) {
            preg_match('~^[a-z][a-z0-9+.-]*://~i', $path) === 1 => 'names a URL; an import is a local file path',
            $isPattern && ImportGlob::grammarProblem($path) !== null => ImportGlob::grammarProblem($path),
            !$isPattern && ImportEntries::namesPreset($path) => null,
            !\in_array(strtolower(pathinfo($path, \PATHINFO_EXTENSION)), $extensions, true) => \sprintf('is not a %s file', implode(' or ', array_map(static fn(string $extension): string => '.' . $extension, $extensions))),
            default => null,
        };
        if ($refusal !== null) {
            $entry->refuse(\sprintf('Import "%s" (%s) %s.', $path, Provenance::display($at), $refusal));
        }
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\SelectorYamlDecoder;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorKind;

/**
 * The form of the `imports` list: an entry is a file path, the name of a
 * built-in preset, or a one-entry `subtree` / `regex` selector mapping over
 * the importing file's directory. The import chain reads the list with this
 * schema before composition and hands the composer every layer without it.
 *
 * The element is carried per layer and judged by its owner because it is a
 * string or a mapping, a shape the engine's typed scalars and maps do not
 * express as one element.
 */
final class ImportListSchema
{
    private const string ELEMENT = 'A file path, the name of a built-in preset, or a selector over the files below this file\'s directory: {subtree: dir} or {regex: fragment}.';

    public static function schema(): NodeSchema
    {
        return NodeSchema::list(
            NodeSchema::opaque()->withWording(description: self::ELEMENT)->judgedInEachLayer(self::judge(...)),
        )->withWording(description: 'Configuration files this one builds on, lowest precedence first; this file overrides them all.'
            . ' An entry is a file path, the name of a built-in preset (strict, legacy, ci), or a {subtree: dir} / {regex: fragment} selector'
            . ' standing for every .yaml or .yml file below this file\'s directory whose path it matches, in byte order of the path.'
            . ' Imports nest: a file an entry names may import in turn, but a file given to --preset may not. Each entry is resolved against the directory of the file that writes it'
            . ' (of its target, when that file is a symlink);'
            . ' every other path keeps the working directory as its base.');
    }

    /** What the author wrote at an entry: a string, or a mapping when it is a selector. */
    public static function written(ResolvedValueInterface $entry): mixed
    {
        $contributions = $entry->plain();

        return \is_array($contributions) ? ($contributions[0] ?? null) : $contributions;
    }

    /** Whether the string, taken as written, names a built-in preset rather than a file. */
    public static function namesPreset(string $entry): bool
    {
        return !str_contains($entry, '/') && !str_contains($entry, '\\') && pathinfo($entry, \PATHINFO_EXTENSION) === '';
    }

    /** The selector a mapping entry writes; its refusals are positioned where it was written. */
    public static function selectorOf(ResolvedValueInterface $entry): PathPattern
    {
        $writer = $entry->contributors()[0];

        return (new SelectorYamlDecoder())->decodePath(
            self::written($entry),
            $writer->origin,
            $writer->path ?? throw new LogicException('An entry read from a file carries its position.'),
        );
    }

    /** @param list<string> $at */
    private static function judge(ResolvedValueInterface $entry, array $at): void
    {
        $written = self::written($entry);
        $refusal = match (true) {
            \is_string($written) => self::problemWithPath($written),
            \is_array($written) && !array_is_list($written) => self::problemWithSelector($entry, $written),
            default => 'is neither a file path nor a selector mapping',
        };
        if ($refusal !== null) {
            $entry->refuse(\sprintf('Import %s (%s) %s.', self::shown($written), Provenance::display($at), $refusal));
        }
    }

    private static function problemWithPath(string $path): ?string
    {
        return match (true) {
            $path === '' => 'is empty; name a file',
            preg_match('~^[a-z][a-z0-9+.-]*://~i', $path) === 1 => 'names a URL; an import is a local file path',
            self::namesPreset($path) => null,
            default => ImportSelection::nameProblem($path),
        };
    }

    /**
     * @param array<mixed, mixed> $written
     */
    private static function problemWithSelector(ResolvedValueInterface $entry, array $written): ?string
    {
        if (array_key_first($written) === SelectorKind::Exact->value && \count($written) === 1) {
            return 'names one file; write a file path as a plain string';
        }

        return ImportSelection::problem(self::selectorOf($entry));
    }

    /** The entry as a sentence names it: a quoted path, or a selector in flow style. */
    public static function shown(mixed $written): string
    {
        if (\is_string($written)) {
            return '"' . $written . '"';
        }
        $kind = \is_array($written) && \count($written) === 1 ? array_key_first($written) : null;

        return \is_string($kind) && \is_string($written[$kind]) ? \sprintf('{%s: %s}', $kind, $written[$kind]) : 'entry';
    }
}

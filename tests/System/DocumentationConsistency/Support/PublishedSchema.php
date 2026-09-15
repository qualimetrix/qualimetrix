<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\System\DocumentationConsistency\Support;

use RuntimeException;

/**
 * Reads the schemas `website/docs/usage/output-formats.md` publishes.
 *
 * The page says the same thing in two voices, and both are read here. Prose
 * names a node's keys in a sentence; the fenced example shows them in a
 * document a reader will copy. Neither is complete on its own — the JSON
 * example omits `coverage`, which only the prose lists, and the prose never
 * names the keys of a violation entry, which only the example shows. So a
 * node's published key set is the **union of what every source says about that
 * node**, and a key named by neither is what the guard is for.
 *
 * Binding the example matters beyond completeness: of the four schema defects
 * that motivated this guard, one (`$schema` pointing at a path that does not
 * exist) lived only inside a fenced block. Only key names and literal
 * constants are read from examples; illustrative values are not.
 */
final class PublishedSchema
{
    public const string PAGE_EN = 'website/docs/usage/output-formats.md';
    public const string PAGE_RU = 'website/docs/usage/output-formats.ru.md';

    /**
     * Every `## <format>` section of one language page, keyed by format name.
     *
     * @return array<string, string>
     */
    public static function sections(string $page): array
    {
        $text = self::read($page);
        $sections = [];

        $chunks = preg_split('/\n## /', $text);

        foreach ($chunks === false ? [] : $chunks as $chunk) {
            $name = strtok($chunk, "\n");

            if ($name === false) {
                continue;
            }

            // "summary (default)" and the Russian headings carry a trailer.
            $name = trim(explode(' ', trim($name))[0]);
            $sections[$name] = $chunk;
        }

        return $sections;
    }

    /**
     * The schema nodes a format's fenced JSON examples publish.
     *
     * `[...]` is an elision a reader understands and `json_decode` does not, so
     * it is normalised to an empty array before parsing. A block that still
     * will not parse is an error rather than a skip: silently ignoring it would
     * let a malformed example sit on the page unchecked.
     *
     * @return array<string, list<string>> node path => published keys
     */
    public static function nodesFromExamples(string $page, string $format): array
    {
        $section = self::sections($page)[$format] ?? '';
        preg_match_all('/```json\n(.*?)```/s', $section, $matches, \PREG_SET_ORDER);

        $nodes = [];

        foreach ($matches as $index => [, $block]) {
            $normalised = str_replace('[...]', '[]', $block);
            $decoded = json_decode($normalised, true);

            if (!\is_array($decoded)) {
                throw new RuntimeException(\sprintf(
                    'The %s example #%d in %s is not parseable JSON: %s',
                    $format,
                    $index,
                    $page,
                    json_last_error_msg(),
                ));
            }

            /** @var array<string, mixed> $object */
            $object = $decoded;

            foreach (SchemaNodeInventory::of($object) as $path => $halves) {
                $keys = array_merge($halves['required'], $halves['optional']);
                $nodes[$path] = array_values(array_unique(array_merge($nodes[$path] ?? [], $keys)));
            }
        }

        return array_map(self::sorted(...), $nodes);
    }

    /**
     * The key names a prose fragment publishes.
     *
     * Only backtick-quoted tokens count, and a token is kept only if it could
     * be a key: CLI flags, paths, placeholders and the format names themselves
     * are not. `{count, violations}` yields two keys, and `location.{path,lines.begin}`
     * yields the leaf names the sentence is actually naming.
     *
     * @return list<string>
     */
    public static function keysIn(string $fragment, string $node = ''): array
    {
        // Some sentences publish a node by showing it rather than listing it:
        // "a typed edge is `{"type": "new", "target": "..."}`". The keys are
        // then the literal's own, and reading them as prose tokens would also
        // pick up the example *values*.
        $literal = trim($fragment, " \t\n`");

        if (str_starts_with($literal, '{')) {
            $decoded = json_decode($literal, true);

            if (\is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return self::sorted(array_map(strval(...), array_keys($decoded)));
            }
        }

        // A parenthetical describes a nested node or the values of a key, not
        // the keys of the node the sentence is listing: "each `failures[]` item
        // has `path`, `kind` (`parse` or `processing`), and `message`" publishes
        // three keys, not five.
        $fragment = preg_replace('/\([^()]*\)/', ' ', $fragment) ?? $fragment;

        preg_match_all('/`([^`]+)`/', $fragment, $matches);

        $keys = [];

        foreach ($matches[1] as $token) {
            // `location.{path,lines.begin}` names one key of this node, not
            // three. Brace groups expand a *child's* shape, so they collapse
            // into the key they hang off, innermost first.
            do {
                $collapsed = preg_replace('/\.\{[^{}]*\}/', '', $token) ?? $token;
                $changed = $collapsed !== $token;
                $token = $collapsed;
            } while ($changed);

            $candidates = preg_split('/[^A-Za-z0-9_.$%\[\]-]+/', $token);

            foreach ($candidates === false ? [] : $candidates as $candidate) {
                $candidate = trim($candidate);

                if ($candidate === '' || str_starts_with($candidate, '-')) {
                    continue;
                }

                // A sentence names a node's key by a path: `message.text` and
                // `location.{path,lines.begin}` publish `message` and
                // `location`, while `runs[].invocations[0]` — written from the
                // document root — publishes `invocations` of `runs[]`. So the
                // node's own prefix is removed first, and what is published is
                // the first segment of what remains, never the last: taking
                // the leaf would read `message.text` as a key called `text`.
                $candidate = preg_replace('/\[\d*\]/', '', $candidate) ?? $candidate;
                $prefix = preg_replace('/\[\d*\]/', '', $node === '(root)' ? '' : $node) ?? '';

                if ($prefix !== '' && str_starts_with($candidate, $prefix . '.')) {
                    $candidate = substr($candidate, \strlen($prefix) + 1);
                }

                $head = explode('.', $candidate)[0];

                if ($head === '' || preg_match('/^[A-Za-z_$%][A-Za-z0-9_$%-]*$/', $head) !== 1) {
                    continue;
                }

                $keys[] = $head;
            }
        }

        return self::sorted(array_unique($keys));
    }

    /**
     * The nodes a prose fragment publishes, rooted at the node it is about.
     *
     * A sentence that publishes a node by showing it — "each rules entry is
     * `{"id": ..., "shortDescription": {"text": ...}}`" — publishes the
     * nested nodes too. Walking the literal is how those get bound without a
     * hand-written registry row per child, which is a list that rots.
     *
     * @return array<string, list<string>>
     */
    public static function nodesIn(string $fragment, string $node): array
    {
        $literal = trim($fragment, " \t\n`");

        if (str_starts_with($literal, '{')) {
            $decoded = json_decode($literal, true);

            if (\is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $nodes = [];

                foreach (SchemaNodeInventory::of($decoded) as $path => $halves) {
                    $keys = array_merge($halves['required'], $halves['optional']);
                    $rooted = $path === '(root)' ? $node : $node . '.' . $path;
                    $nodes[$rooted] = self::sorted($keys);
                }

                return $nodes;
            }
        }

        $nodes = [$node => self::keysIn($fragment, $node)];

        foreach (self::nestedPaths($fragment, $node) as $path => $keys) {
            $nodes[$path] = array_values(array_unique(array_merge($nodes[$path] ?? [], $keys)));
        }

        return array_map(self::sorted(...), $nodes);
    }

    /**
     * The nested nodes a dotted or braced path publishes.
     *
     * `message.text` says node `message` has a key `text`;
     * `location.{path,lines.begin}` says `location` has `path` and `lines`, and
     * `lines` has `begin`. Without this the notation collapsed to its head and
     * every name it exists to publish was held only by the fenced example —
     * one source covering for another, in the most expressive part of the prose.
     *
     * @return array<string, list<string>>
     */
    private static function nestedPaths(string $fragment, string $node): array
    {
        $fragment = preg_replace('/\\([^()]*\\)/', ' ', $fragment) ?? $fragment;
        preg_match_all('/`([^`]+)`/', $fragment, $matches);

        $prefix = $node === '(root)' ? '' : $node;
        $nodes = [];

        foreach ($matches[1] as $token) {
            // `invocations[0]` and `invocations[]` name the same node; the
            // index is an example, the brackets are the shape.
            $token = preg_replace('/\\[\\d+\\]/', '[]', trim($token)) ?? trim($token);

            if ($prefix !== '' && str_starts_with($token, $prefix . '.')) {
                $token = substr($token, \strlen($prefix) + 1);
            }

            self::expandPath($token, $node, $nodes);
        }

        return $nodes;
    }

    /**
     * @param array<string, list<string>> $nodes
     */
    private static function expandPath(string $path, string $parent, array &$nodes): void
    {
        $path = trim($path);
        $brace = strpos($path, '.{');

        if ($brace !== false && str_ends_with($path, '}')) {
            $head = substr($path, 0, $brace);
            $members = substr($path, $brace + 2, -1);
            $owner = self::walkChain($head, $parent, $nodes);

            if ($owner === null) {
                return;
            }

            foreach (self::splitTopLevel($members) as $member) {
                $member = trim($member);

                // A plain name inside the braces is a key of the node the
                // group hangs off; anything longer describes a node below it.
                if (preg_match('/^([A-Za-z_$%][A-Za-z0-9_$%-]*)(\\[\\])?$/', $member, $leaf) === 1) {
                    $nodes[$owner] = array_values(array_unique([...($nodes[$owner] ?? []), $leaf[1]]));

                    continue;
                }

                // A longer member still names a key here before describing
                // what hangs off it: `artifactLocation.{uri,uriBaseId}` gives
                // this node the key `artifactLocation`.
                if (preg_match('/^([A-Za-z_$%][A-Za-z0-9_$%-]*)/', $member, $head) === 1) {
                    $nodes[$owner] = array_values(array_unique([...($nodes[$owner] ?? []), $head[1]]));
                }

                self::expandPath($member, $owner, $nodes);
            }

            return;
        }

        self::walkChain($path, $parent, $nodes);
    }

    /**
     * Records each step of a dotted chain as "this node has that key" and
     * returns the node the chain ends at.
     *
     * @param array<string, list<string>> $nodes
     */
    private static function walkChain(string $path, string $parent, array &$nodes): ?string
    {
        $current = $parent;

        foreach (explode('.', $path) as $index => $segment) {
            if (preg_match('/^([A-Za-z_$%][A-Za-z0-9_$%-]*)(\\[\\])?$/', $segment, $parts) !== 1) {
                return null;
            }

            if ($index > 0) {
                // The key is the name; the brackets describe its value.
                $nodes[$current] = array_values(array_unique([...($nodes[$current] ?? []), $parts[1]]));
            }

            $current = $current === '(root)' ? $segment : $current . '.' . $segment;
        }

        return $current;
    }

    /**
     * Splits `a, b.{c,d}, e` on the commas that are not inside braces.
     *
     * @return list<string>
     */
    private static function splitTopLevel(string $members): array
    {
        $parts = [];
        $depth = 0;
        $buffer = '';

        foreach (str_split($members) as $character) {
            if ($character === '{') {
                $depth++;
            } elseif ($character === '}') {
                $depth--;
            }

            if ($character === ',' && $depth === 0) {
                $parts[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $character;
        }

        if (trim($buffer) !== '') {
            $parts[] = $buffer;
        }

        return $parts;
    }

    public static function read(string $relativePath): string
    {
        $content = file_get_contents(OutputFormatObservation::repositoryRoot() . '/' . $relativePath);

        if ($content === false) {
            throw new RuntimeException(\sprintf('Cannot read %s.', $relativePath));
        }

        return $content;
    }

    /**
     * @param array<array-key, string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}

<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The three declared maps: channel names, symbols (FQN or path) and metric keys.
 *
 * Direction is not symmetric in purpose. Forward (old -> new) is applied to the
 * REFERENCE tree's artifacts, because the reference predates the rename and its
 * output still speaks the old vocabulary. Reverse (new -> old) is applied to the
 * configuration and CLI arguments handed to that same reference binary, which
 * cannot be addressed in a vocabulary it does not know yet.
 *
 * Two properties make a map a proof of what a step renamed rather than a blanket
 * rewrite, and both are enforced here rather than promised in prose.
 *
 * A row translates a whole name, never a prefix of a longer one. The vocabulary
 * is full of prefix siblings — `complexity.cyclomatic` is a proper prefix of
 * `complexity.cyclomatic.callable`, and the level segments are exactly what the
 * plan's later steps rename — so a substring rewrite would let a step rename
 * three codes, declare one, and stay green. Boundaries are therefore literal:
 * everything a name can be built from (letters, digits, `_`, `-`, `.`, `/`, `\`)
 * continues a name, and only a character outside that set ends one.
 *
 * And substitution happens in one pass over the original text. Applied
 * sequentially, a row whose target contains a name another row renames would
 * cascade: `old#old.class -> mid#mid.class` followed by `mid.class -> new.code`
 * yields `mid#new.code`, an identity no row declares. The load-time checks below
 * reject the whole-name form of that chain; single-pass substitution closes the
 * containment form they cannot see.
 *
 * With every map empty both directions are the identity. The path is still live
 * code and covered by `--self-test`: the steps that populate the maps must not
 * be the ones that first discover whether it works.
 */
final class RenameMaps
{
    private const FILES = ['channels.tsv', 'symbols.tsv', 'metric-keys.tsv'];

    /** What continues a name, and therefore what may not end a match. */
    private const NAME_CHARS = 'A-Za-z0-9_.\\-\\\\/';

    /** @var list<array{old: string, new: string, source: string, row: string}> */
    private array $pairs;

    /** @var array<int, int> */
    private array $hits = [];

    /** @var array<string, list<array{0: string, 1: string, 2: int}>> */
    private array $substitutions = [];

    /** @var array<string, string> */
    private array $patterns = [];

    /** @param list<array{old: string, new: string, source: string, row?: string}> $pairs */
    private function __construct(array $pairs)
    {
        $unique = [];

        foreach ($pairs as $pair) {
            $pair['row'] ??= \sprintf('%s: "%s" -> "%s"', $pair['source'], $pair['old'], $pair['new']);
            $unique[$pair['old'] . "\0" . $pair['new']] = $pair;
        }

        $this->pairs = array_values($unique);
        $this->validate();
    }

    public static function load(string $mapsDirectory): self
    {
        $pairs = [];

        foreach (self::FILES as $file) {
            $path = $mapsDirectory . '/' . $file;

            foreach (self::rows($path) as [$old, $new]) {
                $expanded = $file === 'channels.tsv'
                    ? self::expandChannelRow($old, $new, $path)
                    : [[$old, $new]];
                $row = \sprintf('%s: "%s" -> "%s"', $file, $old, $new);

                foreach ($expanded as [$expandedOld, $expandedNew]) {
                    $pairs[] = ['old' => $expandedOld, 'new' => $expandedNew, 'source' => $file, 'row' => $row];
                }
            }
        }

        return new self($pairs);
    }

    /** @param list<array{old: string, new: string, source: string, row?: string}> $pairs */
    public static function fromPairs(array $pairs): self
    {
        return new self($pairs);
    }

    public function isIdentity(): bool
    {
        return $this->pairs === [];
    }

    /**
     * Every declared row, whatever it translated.
     *
     * @return list<string>
     */
    public function declaredRows(): array
    {
        return array_values(array_unique(array_column($this->pairs, 'row')));
    }

    /**
     * The declared rows that translated nothing in the whole run.
     *
     * A row that fired nowhere is a claim about a rename that did not happen —
     * the same lie as a normalization rule that redacted nothing, and it fails
     * the same way. Accounted per declared row, not per expanded pair: a channel
     * row that reaches the artifacts only through its halves did its job.
     *
     * @return list<string>
     */
    public function staleRows(): array
    {
        $fired = [];
        $declared = [];

        foreach ($this->pairs as $index => $pair) {
            $declared[$pair['row']] = $pair['row'];

            if (($this->hits[$index] ?? 0) > 0) {
                $fired[$pair['row']] = true;
            }
        }

        return array_values(array_diff($declared, array_keys($fired)));
    }

    /** Reference output, restated in the candidate's vocabulary. */
    public function forward(string $text): string
    {
        return $this->replace($text, old: 'old', new: 'new');
    }

    /** Candidate-side input, restated in the reference's vocabulary. */
    public function reverse(string $text): string
    {
        return $this->replace($text, old: 'new', new: 'old');
    }

    /**
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    public function reverseArguments(array $arguments): array
    {
        return array_map($this->reverse(...), $arguments);
    }

    private function replace(string $text, string $old, string $new): string
    {
        if ($this->pairs === []) {
            return $text;
        }

        $direction = $old . '>' . $new;
        $substitutions = $this->substitutions[$direction] ??= $this->buildSubstitutions($old, $new);
        $pattern = $this->patterns[$direction] ??= self::buildPattern($substitutions);
        $lookup = [];

        foreach ($substitutions as [$from, $to, $index]) {
            $lookup[$from] = [$to, $index];
        }

        $replaced = preg_replace_callback(
            $pattern,
            function (array $match) use ($lookup): string {
                [$to, $index] = $lookup[$match[0]];
                $this->hits[$index] = ($this->hits[$index] ?? 0) + 1;

                return $to;
            },
            $text,
        );

        if ($replaced === null) {
            throw new GateError('The declared maps do not compile into a substitution pattern.');
        }

        return $replaced;
    }

    /**
     * A backslash-bearing symbol reaches an artifact both raw (text surfaces)
     * and JSON-escaped, and a map row can only be written one way. Both forms
     * are substituted, and both count towards the row's own staleness.
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private function buildSubstitutions(string $old, string $new): array
    {
        $substitutions = [];

        foreach ($this->pairs as $index => $pair) {
            $from = $pair[$old];
            $to = $pair[$new];
            $substitutions[] = [$from, $to, $index];

            if (str_contains($from, '\\')) {
                $substitutions[] = [str_replace('\\', '\\\\', $from), str_replace('\\', '\\\\', $to), $index];
            }
        }

        // Longest first: PCRE takes the leftmost alternative that matches, and
        // the boundary assertions alone would already refuse a shorter prefix,
        // but an ordering that does not depend on that is one thing less to
        // reason about.
        usort($substitutions, static fn(array $a, array $b): int => \strlen($b[0]) <=> \strlen($a[0]));

        return $substitutions;
    }

    /** @param list<array{0: string, 1: string, 2: int}> $substitutions */
    private static function buildPattern(array $substitutions): string
    {
        $alternatives = array_map(
            static fn(array $substitution): string => preg_quote($substitution[0], '~'),
            $substitutions,
        );

        return \sprintf(
            '~(?<![%1$s])(?:%2$s)(?![%1$s])~',
            self::NAME_CHARS,
            implode('|', $alternatives),
        );
    }

    private function validate(): void
    {
        $targets = [];
        $sources = [];

        foreach ($this->pairs as $pair) {
            if ($pair['old'] === $pair['new']) {
                throw new GateError(\sprintf('%s renames nothing: the two sides are the same name.', $pair['row']));
            }

            if (isset($sources[$pair['old']])) {
                throw new GateError(\sprintf(
                    '%s and %s both rename "%s", so what the reference means by it is undecidable.',
                    $sources[$pair['old']],
                    $pair['row'],
                    $pair['old'],
                ));
            }

            if (isset($targets[$pair['new']])) {
                throw new GateError(\sprintf(
                    '%s and %s both produce "%s", so two reference names would collapse into one.',
                    $targets[$pair['new']],
                    $pair['row'],
                    $pair['new'],
                ));
            }

            $sources[$pair['old']] = $pair['row'];
            $targets[$pair['new']] = $pair['row'];
        }

        foreach ($this->pairs as $pair) {
            if (isset($sources[$pair['new']])) {
                throw new GateError(\sprintf(
                    '%s produces "%s", which %s renames again: a chain declares an identity no row states.',
                    $pair['row'],
                    $pair['new'],
                    $sources[$pair['new']],
                ));
            }
        }
    }

    /**
     * A channel key renamed by a step renames its halves with it: the same
     * rename shows up as a whole key in `channel`, and as each half in `rule`
     * and `code`.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function expandChannelRow(string $old, string $new, string $path): array
    {
        $oldHalves = explode('#', $old);
        $newHalves = explode('#', $new);

        if (\count($oldHalves) !== 2 || \count($newHalves) !== 2) {
            throw new GateError(\sprintf('%s: "%s" -> "%s" is not a pair of full "rule#code" keys.', $path, $old, $new));
        }

        $pairs = [[$old, $new]];

        foreach ([0, 1] as $half) {
            if ($oldHalves[$half] !== $newHalves[$half]) {
                $pairs[] = [$oldHalves[$half], $newHalves[$half]];
            }
        }

        return $pairs;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function rows(string $path): array
    {
        $rows = [];

        foreach (Tsv::rows($path, ['old', 'new', 'reason']) as $row) {
            $rows[] = [$row['old'], $row['new']];
        }

        return $rows;
    }
}

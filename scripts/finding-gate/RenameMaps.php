<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Declared correspondence for channels, symbols, metric keys, inputs and report values.
 *
 * Reference artifacts travel forwards; candidate inputs travel backwards in
 * their named role. Whole-name substitution is one pass over the original text,
 * so prefix neighbours and chained replacements receive no inferred license.
 * Equal pairs in several roles share credit, but each role keeps its direction
 * and surface restrictions. A row is live if it substituted an observed token
 * or explained a matched producer movement; an idle declaration is stale.
 *
 * Aggregation strategy rows bind both trees' complete suffix vocabularies and
 * check independent base keys from both trees. YAML keys and report enumeration
 * positions use finite structured forms owned by their respective translators.
 * Unsupported forms receive no guessed rewrite. Channel splits retain exact
 * per-record movement evidence through ChannelSplit rather than translating an
 * ambiguous producer half as text.
 *
 * @phpstan-type MapPair array{old: string, new: string, sources: list<string>, row: string, reversible: bool, ambiguous: bool, multivalued: bool}
 */
final class RenameMaps
{
    public const CHANNELS = 'channels.tsv';
    public const SYMBOLS = 'symbols.tsv';
    public const METRIC_KEYS = 'metric-keys.tsv';
    public const INPUTS = 'inputs.tsv';
    public const REPORT_VALUES = 'report-values.tsv';

    /**
     * Map file => the surface classes it may be applied to, or `null` for all.
     *
     * A row translates a whole name, and half the metric vocabulary is an
     * ordinary English word: `cognitive`, `distance`, `instability`,
     * `abstractness`. Applied to a surface that prints prose, a key map turns
     * "Maximum method cognitive complexity is 29" into "Maximum method
     * complexity.cognitive complexity is 29" — a rename leaking into text the
     * step never touched, and reported against the step as a mismatch.
     *
     * The restriction follows the measured publishing surface: metric keys
     * appear in `format:metrics` (282 spellings),
     * `format:json` (13) and the HTML report, which embeds the JSON payload.
     * The other nine formats, the baseline, `baseline:explain` and the `rules`
     * listing publish none. A key that later reaches one of those is therefore
     * NOT silently translated: it stands as an undeclared difference and the run
     * goes red, which is the direction this has to fail in.
     *
     * `REPORT_VALUES` is restricted the same way, and the measurement is
     * narrower still: `format:suppressed` is the only surface that publishes an
     * enumerable report field's string value at all, so the row has one surface
     * to be declared against rather than a short list.
     *
     * @var array<string, list<string>|null>
     */
    private const SURFACES = [
        self::METRIC_KEYS => ['format:json', 'format:metrics', 'format:html'],
        self::REPORT_VALUES => ['format:suppressed'],
    ];

    /** Map file => whether it may also be applied backwards. */
    private const FILES = [
        self::CHANNELS => false,
        self::SYMBOLS => true,
        self::METRIC_KEYS => false,
        self::INPUTS => true,
        self::REPORT_VALUES => false,
    ];

    /** What continues a name, and therefore what may not end a match. */
    private const NAME_CHARS = 'A-Za-z0-9_.\\-\\\\/';

    /**
     * Prefixes an artifact writes in front of a name, which the boundary
     * assertion would otherwise refuse to look past.
     *
     * Measured across all twelve formats plus the baseline file, `baseline:explain`
     * and the `bin/qmx rules` snapshot: `qmx.` in checkstyle's `source` attribute
     * is the only one. A dot continues a name, so `source="qmx.code-smell.eval"`
     * has no left boundary and the row would translate nothing there — a rename
     * leaking into an undeclared diff. Handled like the JSON-escaped backslash:
     * the prefixed spelling is another substitution of the SAME row, so it
     * counts towards that row's staleness and is never a second declaration.
     *
     * @var list<string>
     */
    private const PREFIXES = ['qmx.'];

    /**
     * Separates the several new tokens of one multivalued `inputs.tsv` row.
     *
     * Not a name character, so a row carrying it cannot be mistaken for a row
     * about a single token whose name happens to contain it.
     */
    public const IMAGE_SEPARATOR = '|';

    /** @var list<array{old: string, new: string, sources: list<string>, row: string, reversible: bool, ambiguous: bool, multivalued: bool}> */
    private array $pairs;

    /** @var array<int, int> */
    private array $hits = [];

    /** @var array<string, list<array{0: string, 1: string, 2: int, 3: bool}>> */
    private array $substitutions = [];

    /** @var array<string, string> */
    private array $patterns = [];

    /**
     * Old half => the several new halves declared for it, i.e. a split.
     *
     * @var array<string, list<string>>
     */
    private array $splits = [];

    /** @var array<string, string> old whole channel key => new whole channel key */
    private array $channelKeys = [];

    /** @var array<string, string> old whole channel key => the declared row that names it */
    private array $channelKeyRows = [];

    /**
     * Declared rows that explained a record, whatever they substituted.
     *
     * @var array<string, true>
     */
    private array $explainedRows = [];

    private ?MetricVocabulary $referenceVocabulary = null;

    /** @var list<array{old: string, new: string, sources: list<string>, row: string, reversible: bool, ambiguous: bool, multivalued: bool}> */
    private array $dropped = [];

    /**
     * @param list<array{old: string, new: string, source: string, row?: string}> $pairs
     */
    private function __construct(array $pairs, private readonly MetricVocabulary $vocabulary)
    {
        $this->pairs = self::normalize($pairs);
        $this->splits = $this->collectSplits();
        $dropped = array_values(array_filter(
            $this->pairs,
            fn(array $pair): bool => isset($this->splits[$pair['old']]) && $pair['ambiguous'],
        ));
        $this->dropped = $dropped;
        $this->pairs = array_values(array_filter(
            $this->pairs,
            fn(array $pair): bool => !isset($this->splits[$pair['old']]) || !$pair['ambiguous'],
        ));
        $this->validate($dropped);
        $this->strategyRenames();
        $enumerationAddresses = [];
        $inputAddresses = [];
        foreach ($this->pairs as $pair) {
            if (\in_array(self::METRIC_KEYS, $pair['sources'], true)
                && str_starts_with($pair['old'], 'strategy:') !== str_starts_with($pair['new'], 'strategy:')) {
                throw new GateError('A strategy declaration must name a strategy on both sides.');
            }
            if (\in_array(self::INPUTS, $pair['sources'], true) && !str_starts_with($pair['new'], '--')) {
                $address = json_encode(YamlInputMap::path($pair['new'], $pair['row']), \JSON_THROW_ON_ERROR);
                if (isset($inputAddresses[$address])) {
                    throw new GateError('Two input declarations address the same YAML key path.');
                }
                $inputAddresses[$address] = true;
            }
            if (\in_array(self::INPUTS, $pair['sources'], true) && str_starts_with($pair['old'], '[')) {
                $oldPath = self::yamlPath($pair['old'], $pair['row']);
                $newPath = self::yamlPath($pair['new'], $pair['row']);
                if (\array_slice($oldPath, 0, -1) !== \array_slice($newPath, 0, -1)) {
                    throw new GateError('A YAML key map must retain its parent path.');
                }
            }
            if (\in_array(self::REPORT_VALUES, $pair['sources'], true) && str_starts_with($pair['old'], '{')) {
                $oldEnum = self::enumeration($pair['old'], $pair['row']);
                $newEnum = self::enumeration($pair['new'], $pair['row']);
                $address = $oldEnum['surface'] . ':' . implode('.', $oldEnum['path']);
                if (isset($enumerationAddresses[$address])) {
                    throw new GateError('Two enumeration declarations address the same publication path.');
                }
                $enumerationAddresses[$address] = true;
                if ([$oldEnum['surface'], $oldEnum['path'], $oldEnum['kind']] !== [$newEnum['surface'], $newEnum['path'], $newEnum['kind']]
                    || \count($oldEnum['members']) !== \count($newEnum['members'])) {
                    throw new GateError('Enumeration correspondence must retain its address and member count.');
                }
            }
        }

        // Both directions are built here rather than on first use. The guard
        // against two declarations reaching one spelling lives in that build, and
        // a guard that fires only once some caller happens to substitute in that
        // direction is a guard whose moment is decided by the run — the reverse
        // direction is not built at all on a run whose reference needs no input
        // translated.
        $this->buildSubstitutions('old', 'new', reversibleOnly: false, surfaceClass: null);
        $this->buildSubstitutions('new', 'old', reversibleOnly: true, surfaceClass: null);
    }

    public static function load(string $mapsDirectory, MetricVocabulary $vocabulary): self
    {
        $pairs = [];

        foreach (array_keys(self::FILES) as $file) {
            $path = $mapsDirectory . '/' . $file;

            foreach (self::rows($path) as [$old, $new]) {
                $pairs[] = ['old' => $old, 'new' => $new, 'source' => $file];
            }
        }

        return new self($pairs, $vocabulary);
    }

    /**
     * Synthetic rows, for the self-test.
     *
     * The vocabulary defaults to none rather than to the product's: a shape
     * proved on synthetic rows must not depend on what the product happens to
     * declare today, and the cases that are about the suffix expansion carry
     * their own.
     *
     * @param list<array{old: string, new: string, source: string, row?: string}> $pairs
     */
    public static function fromPairs(array $pairs, ?MetricVocabulary $vocabulary = null): self
    {
        return new self($pairs, $vocabulary ?? MetricVocabulary::none());
    }

    public function isIdentity(): bool
    {
        return $this->pairs === [] && $this->splits === [];
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
     * What this instance translated, keyed by the row that did it.
     *
     * Exists because a case runs in a worker process with a `RenameMaps` of its
     * own ({@see \QmxFindingGate\CaseScheduler}), and a row whose only work is
     * on that case's *input* — a configuration key as the document writes it, a
     * name inside a case argument — fires there and nowhere else. Without a way
     * back, the parent judges staleness against hits it could never have seen
     * and reports such a row as translating nothing in the whole run. Measured:
     * `root-key-renamed`, whose entire subject is an input-only translation,
     * was failing for exactly that reason.
     *
     * Keyed by the row's own text rather than by index: both processes load the
     * same directory and would agree on order today, but an index is a fact
     * about one load and the row is the thing being credited.
     *
     * @return array<string, int>
     */
    public function firedRows(): array
    {
        $fired = [];

        foreach ($this->pairs as $index => $pair) {
            $hits = $this->hits[$index] ?? 0;

            if ($hits > 0) {
                $fired[$pair['row']] = ($fired[$pair['row']] ?? 0) + $hits;
            }
        }

        return $fired;
    }

    /**
     * Credits rows that fired in another process.
     *
     * A row this instance does not declare is refused rather than ignored: it
     * means the two processes loaded different maps, and silently dropping the
     * credit would report a live row as stale — the failure this method exists
     * to remove, arriving by a different door.
     *
     * @param array<string, int> $hitsByRow
     */
    public function creditRowsFiredElsewhere(array $hitsByRow): void
    {
        foreach ($hitsByRow as $row => $hits) {
            $matched = false;

            foreach ($this->pairs as $index => $pair) {
                if ($pair['row'] === $row) {
                    $this->hits[$index] = ($this->hits[$index] ?? 0) + $hits;
                    $matched = true;
                }
            }

            if (!$matched) {
                throw new GateError(\sprintf(
                    'A case worker credited the map row "%s", which this process does not declare. The two loaded'
                    . ' different maps, and crediting nothing would report a live row as stale.',
                    $row,
                ));
            }
        }
    }

    /**
     * The declared rows that translated nothing in the whole run.
     *
     * A row that fired nowhere is a claim about a rename that did not happen —
     * the same lie as a normalization rule that redacted nothing, and it fails
     * the same way. Accounted per declared row, not per expanded pair: a channel
     * row that reaches the artifacts only through its halves did its job.
     *
     * "Fired" means substituted **or** explained, and the second half exists for
     * one shape: a row that moves a producer and nothing else
     * (`computed.health#health.complexity -> health.complexity#health.complexity`)
     * has nothing to substitute anywhere. Its rule half is one side of a split
     * and is deliberately left untranslated, its code half is the same string on
     * both sides and expands into no pair, and no surface prints the whole
     * `rule#code` key the row is written as. Judged by substitution alone it is
     * idle, which would make the only shape a producer move can be declared in
     * unwritable. The credit is granted by {@see creditExplanation()} per row and
     * per matched record, so a row of a live split that explained nothing itself
     * stays stale.
     *
     * A multivalued row is the deliberate exception, and this is the place where
     * a guard turns into a rubber stamp if the decision is taken by default.
     * Such a row is not one rename but one per image, so **every** image has to
     * have translated something; "one of three fired" would let a step declare
     * three new names, exercise one, and keep the other two as a standing
     * excuse. The cost is named: the corpus has to address each new name, which
     * is the same pressure the coverage rule already applies to channels. The
     * idle images are named in the returned line, because the row itself is not
     * the thing that is idle.
     *
     * @return list<string>
     */
    public function staleRows(): array
    {
        $declared = [];
        $fired = [];
        $idle = [];

        foreach ($this->pairs as $index => $pair) {
            $row = $pair['row'];
            $declared[$row] = $row;
            $hit = ($this->hits[$index] ?? 0) > 0;

            if ($hit) {
                $fired[$row] = true;
            }

            if ($pair['multivalued'] && !$hit) {
                $idle[$row][] = $pair['new'];
            }
        }

        $rows = [];

        foreach ($declared as $row) {
            if (isset($idle[$row])) {
                $rows[] = \sprintf('%s [translated nothing into: %s]', $row, implode(', ', $idle[$row]));

                continue;
            }

            if (!isset($fired[$row]) && !isset($this->explainedRows[$row])) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The halves a declared rename splits, and what it splits them into.
     *
     * A half is translated textually only when the whole set of rows agrees on
     * one target for it. Where they disagree the translation is undecidable, so
     * the half survives untranslated and the record it sits in is explained
     * instead — see ChannelSplit.
     *
     * @return array<string, list<string>>
     */
    public function splits(): array
    {
        return $this->splits;
    }

    /** @return array<string, string> old whole channel key => new whole channel key */
    public function channelKeys(): array
    {
        return $this->channelKeys;
    }

    /**
     * Credits the row that declares this channel key with having explained a
     * record that moved.
     *
     * The key is the unit of attribution rather than the row text, because the
     * caller holding the explanation is holding a key. Attribution is what keeps
     * the relaxation from becoming "any row of a live split is live": the pairs
     * a split makes ambiguous are dropped from the substitution list with the
     * rest reindexed, and the hit counter is keyed by pair index, so without
     * this the only fact reaching staleness would be that the split as a whole
     * had translated something.
     *
     * Whether a record moved is the caller's judgement, because only the caller
     * holds the record — see {@see ChannelSplit::unexplained()}, which credits a
     * row only where its declared target differs from what the record it names
     * already publishes.
     *
     * A key no row declares is refused. Stated precisely, because the previous
     * spelling read as a guard on a path that does not exist: `ChannelSplit`
     * passes a key it has just read out of {@see channelKeys()}, so the refusal
     * is a contract on this public method rather than a branch the gate can take
     * today. It is worth stating as one because credit travels by NAME — a
     * caller naming a key nothing declares would keep some row alive by a name
     * that is not in it, and staleness would then report nothing at all.
     */
    public function creditExplanation(string $oldChannelKey): void
    {
        $row = $this->channelKeyRows[$oldChannelKey] ?? throw new GateError(\sprintf(
            'No declared row names the channel key "%s", so nothing can be credited with explaining a record of it.',
            $oldChannelKey,
        ));

        $this->explainedRows[$row] = true;
    }

    /**
     * Reference output, restated in the candidate's vocabulary.
     *
     * The surface class decides which maps apply — see {@see SURFACES}. It is
     * required rather than optional: a default would let a new call site keep
     * the unrestricted behaviour by saying nothing.
     */
    public function forward(string $text, string $surfaceClass): string
    {
        $text = $this->forwardStrategies($text, $surfaceClass);
        $text = $this->forwardEnumerations($text, $surfaceClass);

        return $this->replace($text, old: 'old', new: 'new', reversibleOnly: false, surfaceClass: $surfaceClass);
    }

    public function acceptReferenceVocabulary(MetricVocabulary $reference): void
    {
        $strategies = $this->strategyRenames();
        $translated = [];
        foreach ($reference->suffixes as $suffix) {
            $translated[] = $strategies[$suffix] ?? $suffix;
        }
        sort($translated);
        $candidate = $this->vocabulary->suffixes;
        sort($candidate);
        if ($translated !== $candidate || \count(array_unique($translated)) !== \count($translated)) {
            throw new GateError('The aggregation suffix vocabularies do not agree after exactly the declared strategy renames.');
        }
        foreach ($strategies as $old => $new) {
            if (!\in_array($old, $reference->suffixes, true) || !\in_array($new, $candidate, true)) {
                throw new GateError('A declared strategy rename is absent from its tree vocabulary.');
            }
        }
        $this->referenceVocabulary = $reference;
        $this->assertNoSuffixOverlap($this->dropped);
        $baseKeys = array_unique([...$reference->baseKeys, ...$this->vocabulary->baseKeys]);
        foreach ($baseKeys as $key) {
            foreach ($strategies as $old => $new) {
                if (\in_array($key . '.' . $old, $baseKeys, true) || \in_array($key . '.' . $new, $baseKeys, true)) {
                    throw new GateError('A strategy expansion collides with an independent metric base key.');
                }
            }
        }
        $this->substitutions = [];
        $this->patterns = [];
    }

    /** Candidate-side input, restated in the reference's vocabulary. */
    public function reverse(string $text): string
    {
        return $this->replace($text, old: 'new', new: 'old', reversibleOnly: true, surfaceClass: null);
    }

    /**
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    public function reverseArguments(array $arguments): array
    {
        $translated = [];
        $hits = [];
        $pathValue = false;
        foreach ($arguments as $argument) {
            if ($pathValue) {
                $this->assertArgumentRoleUntouched($argument, [self::SYMBOLS]);
                $translated[] = $argument;
                $pathValue = false;
                continue;
            }
            $parts = explode('=', $argument, 2);
            $option = $parts[0];
            $value = $parts[1] ?? null;
            [$option, $indices] = $this->reverseInputCell($option);
            foreach ($indices as $index) {
                $hits[$index] = ($hits[$index] ?? 0) + 1;
            }
            if ($value !== null && $option === '--rule-opt') {
                $parts = explode('=', $value, 2);
                $cell = $parts[0];
                $setting = $parts[1] ?? null;
                if ($setting === null || !str_contains($cell, ':')) {
                    throw new GateError('A mapped rule option requires an exact rule:option=value cell.');
                }
                [$cell, $indices] = $this->reverseInputCell($cell);
                foreach ($indices as $index) {
                    $hits[$index] = ($hits[$index] ?? 0) + 1;
                }
                if ($indices === []) {
                    $this->assertArgumentRoleUntouched($cell, [self::CHANNELS, self::SYMBOLS]);
                }
                $value = $cell . '=' . $setting;
            } elseif ($value !== null && \in_array($option, ['--only-rules', '--disable-rule', '--enable-rule'], true)) {
                $selectors = [];
                foreach (explode(',', $value) as $selector) {
                    [$selector, $indices] = $this->reverseInputCell($selector);
                    foreach ($indices as $index) {
                        $hits[$index] = ($hits[$index] ?? 0) + 1;
                    }
                    if ($indices === []) {
                        $this->assertArgumentRoleUntouched($selector, [self::CHANNELS, self::SYMBOLS], selectors: true);
                    }
                    $selectors[] = $selector;
                }
                $value = implode(',', $selectors);
            } elseif (\array_key_exists($option, CaseDefinition::INPUT_OPTIONS) || $option === '-c') {
                if ($value === null) {
                    $pathValue = true;
                } else {
                    $this->assertArgumentRoleUntouched($value, [self::SYMBOLS]);
                }
            } else {
                $this->assertArgumentRoleUntouched($argument, [self::CHANNELS, self::SYMBOLS]);
            }
            $translated[] = $option . ($value === null ? '' : '=' . $value);
        }
        $this->mergeHits($hits);
        return $translated;
    }

    /** @param list<string> $paths */
    public function assertSymbolPathsUntouched(array $paths): void
    {
        foreach ($paths as $path) {
            $this->assertArgumentRoleUntouched($path, [self::SYMBOLS]);
        }
    }

    /** An input without a named translator cannot consume or credit a mapped token. */
    public function assertUnsupportedInputUntouched(string $text): void
    {
        foreach ($this->pairs as $pair) {
            $targets = [$pair['new']];
            if (str_starts_with($pair['new'], '[')) {
                $path = YamlInputMap::canonicalPath($pair['new'], $pair['row']);
                $targets = [$path[\count($path) - 1]];
            } elseif (str_starts_with($pair['new'], '{')) {
                $targets = self::enumeration($pair['new'], $pair['row'])['members'];
            } elseif (str_starts_with($pair['new'], 'strategy:')) {
                $targets = array_map(static fn(string $base): string => $base . '.' . substr($pair['new'], 9), $this->vocabulary->baseKeys);
            }
            foreach ($targets as $target) {
                if (self::containsName($text, $target) || self::containsName($text, str_replace('\\', '\\\\', $target))) {
                    throw new GateError('A mapped token occurs in an input without a supported named role.');
                }
            }
        }
    }

    /** @return array{string, list<int>} */
    private function reverseInputCell(string $cell): array
    {
        $images = [];
        $indices = [];
        foreach ($this->pairs as $index => $pair) {
            if (\in_array(self::INPUTS, $pair['sources'], true) && $pair['new'] === $cell) {
                $images[$pair['old']] = true;
                $indices[] = $index;
            }
        }
        if (\count($images) > 1) {
            throw new GateError('An input cell has several declared inverse targets.');
        }
        return [$images === [] ? $cell : (string) array_key_first($images), $indices];
    }

    /** @param list<string> $sources */
    private function assertArgumentRoleUntouched(string $text, array $sources, bool $selectors = false): void
    {
        foreach ($this->pairs as $pair) {
            if (array_intersect($sources, $pair['sources']) === []) {
                continue;
            }
            $targets = explode('#', $pair['new']);
            if (\in_array(self::SYMBOLS, $pair['sources'], true)) {
                $targets[] = str_replace('\\', '/', $pair['new']);
                $separator = strrpos($pair['new'], '\\');
                $leaf = $separator === false ? $pair['new'] : substr($pair['new'], $separator + 1);
                if (preg_match('~(?<![A-Za-z0-9_])' . preg_quote($leaf, '~') . '(?![A-Za-z0-9_])~', $text) === 1) {
                    throw new GateError('A mapped CLI symbol path requires physical path correspondence.');
                }
            }
            foreach ($targets as $target) {
                if (self::containsName($text, $target) || ($selectors && fnmatch($text, $target))) {
                    throw new GateError('A mapped CLI selector or symbol path requires supported reach or physical path correspondence.');
                }
            }
        }
    }

    private static function containsName(string $text, string $name): bool
    {
        return preg_match('~(?<![' . self::NAME_CHARS . '])' . preg_quote($name, '~') . '(?![' . self::NAME_CHARS . '])~', $text) === 1;
    }

    public function reverseSymbol(string $symbol): string
    {
        $lookup = [];
        foreach ($this->pairs as $index => $pair) {
            if (!\in_array(self::SYMBOLS, $pair['sources'], true)) {
                continue;
            }
            $lookup[$pair['new']] = [$pair['old'], $index];
        }
        if ($lookup === []) {
            return $symbol;
        }
        return preg_replace_callback('~(?<![' . self::NAME_CHARS . '])(?:' . implode('|', array_map(static fn(string $key): string => preg_quote($key, '~'), array_keys($lookup))) . ')(?![' . self::NAME_CHARS . '])~', function (array $match) use ($lookup): string {
            [$old, $index] = $lookup[$match[0]];
            $this->hits[$index] = ($this->hits[$index] ?? 0) + 1;
            return $old;
        }, $symbol) ?? throw new GateError('A symbol translation could not be read.');
    }

    public function reverseChannelMap(string $text): string
    {
        if ($this->isIdentity()) {
            return $text;
        }
        $lines = explode("\n", $text);
        if (trim($lines[0]) !== "old\tnew\treason") {
            throw new GateError('A product channel rename map must have the old/new/reason header.');
        }
        $hits = [];
        foreach ($lines as $number => &$line) {
            if ($number === 0 || trim($line) === '' || str_starts_with($line, '#')) {
                continue;
            }
            $ending = str_ends_with($line, "\r") ? "\r" : '';
            $fields = explode("\t", rtrim($line, "\r"));
            if (\count($fields) !== 3) {
                throw new GateError('A product channel rename map row must have exactly three fields.');
            }
            foreach ([0, 1] as $position) {
                $field = $fields[$position];
                $images = [];
                foreach ($this->pairs as $index => $pair) {
                    if (\in_array(self::CHANNELS, $pair['sources'], true) && $pair['new'] === $field) {
                        $images[$pair['old']] = $index;
                    }
                }
                if (\count($images) > 1) {
                    throw new GateError('A collapsed channel cannot be inverted in a product rename map.');
                }
                if ($images !== []) {
                    $field = (string) array_key_first($images);
                    $index = $images[$field];
                    $hits[$index] = ($hits[$index] ?? 0) + 1;
                    $fields[$position] = $field;
                }
            }
            $line = implode("\t", $fields) . $ending;
        }
        unset($line);
        $this->mergeHits($hits);
        return implode("\n", $lines);
    }

    public function reverseComposer(string $text): string
    {
        [$text, $hits] = ComposerInputMap::reverse($text, $this->pairs);
        $this->mergeHits($hits);
        return $text;
    }

    public function reversePhp(string $text): string
    {
        [$text, $hits] = PhpInputMap::reverse($text, $this->pairs);
        $this->mergeHits($hits);
        return $text;
    }

    public function reverseYaml(string $text): string
    {
        [$text, $hits] = YamlInputMap::reverse($text, $this->pairs);
        $this->mergeHits($hits);
        return $text;
    }

    private static function structured(string $token): bool
    {
        return str_starts_with($token, '[') || str_starts_with($token, '{') || str_starts_with($token, 'strategy:');
    }

    /** @return list<string> */
    private static function yamlPath(string $text, string $row): array
    {
        return YamlInputMap::canonicalPath($text, $row);
    }

    /** @return array<string, string> */
    private function strategyRenames(): array
    {
        return AggregationRenames::of($this->pairs);
    }

    private function forwardStrategies(string $text, string $surface): string
    {
        [$text, $hits] = AggregationRenames::translate($text, $surface, $this->referenceVocabulary ?? $this->vocabulary, $this->pairs);
        $this->mergeHits($hits);
        return $text;
    }

    /** @return array{surface: string, path: list<string>, kind: string, members: list<string>} */
    private static function enumeration(string $text, string $row): array
    {
        return ReportEnumerationMap::descriptor($text, $row);
    }

    private function forwardEnumerations(string $text, string $surface): string
    {
        [$text, $hits] = ReportEnumerationMap::translate($text, $surface, $this->pairs);
        $this->mergeHits($hits);
        return $text;
    }

    /** @param array<int, int> $hits */
    private function mergeHits(array $hits): void
    {
        foreach ($hits as $index => $count) {
            $this->hits[$index] = ($this->hits[$index] ?? 0) + $count;
        }
    }

    private function replace(
        string $text,
        string $old,
        string $new,
        bool $reversibleOnly,
        ?string $surfaceClass,
    ): string {
        if ($this->pairs === []) {
            return $text;
        }

        $direction = $old . '>' . $new . '@' . ($surfaceClass ?? '*');
        $substitutions = $this->substitutions[$direction] ??= $this->buildSubstitutions(
            $old,
            $new,
            $reversibleOnly,
            $surfaceClass,
        );

        if ($substitutions === []) {
            return $text;
        }

        $pattern = $this->patterns[$direction] ??= self::buildPattern($substitutions);
        $lookup = [];

        foreach ($substitutions as [$from, $to, $index, $refusal]) {
            $lookup[$from] = [$to, $index, $refusal];
        }

        $replaced = preg_replace_callback(
            $pattern,
            function (array $match) use ($lookup): string {
                [$to, $index, $refusal] = $lookup[$match[0]];

                if ($refusal) {
                    throw new GateError(\sprintf(
                        '%s declares several new tokens for "%s", so there is no forward translation of it — and this'
                        . ' text carries it: "%s". Taking the first image would publish a rename the row never'
                        . ' declared. Either the surface belongs in a declared delta, or the input that reaches it'
                        . ' needs a row of its own naming one token.',
                        $this->pairs[$index]['row'],
                        $match[0],
                        implode(', ', $this->imagesOf($this->pairs[$index]['row'])),
                    ));
                }

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
     * The new tokens one declared row names.
     *
     * @return list<string>
     */
    private function imagesOf(string $row): array
    {
        $images = [];

        foreach ($this->pairs as $pair) {
            if ($pair['row'] === $row) {
                $images[] = $pair['new'];
            }
        }

        return $images;
    }

    /**
     * Whether a declaration reaches a surface at all.
     *
     * A declaration carrying several roles reaches a surface if ANY of its maps
     * does: the roles are the same translation, and the one that publishes there
     * is the one that matters.
     *
     * @param list<string> $sources
     */
    private static function appliesToSurface(array $sources, ?string $surfaceClass): bool
    {
        if ($surfaceClass === null) {
            return true;
        }

        foreach ($sources as $source) {
            $surfaces = self::SURFACES[$source] ?? null;

            if ($surfaces === null || \in_array($surfaceClass, $surfaces, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A name reaches an artifact in more spellings than a map row can be written
     * in: a backslash-bearing symbol appears raw on text surfaces and
     * JSON-escaped in JSON, checkstyle prefixes a channel code with `qmx.`, and
     * SARIF publishes a channel code title-cased as the rule's display name.
     * Every spelling is substituted, and every one counts towards the row's own
     * staleness.
     *
     * A multivalued row has no forward direction, and the substitution it
     * contributes there is a refusal rather than a rewrite: taking the first of
     * its images would publish a translation the row never declared. One refusal
     * per row, not per image, because all its images share the one old spelling.
     *
     * @return list<array{0: string, 1: string, 2: int, 3: bool}>
     */
    private function buildSubstitutions(
        string $old,
        string $new,
        bool $reversibleOnly,
        ?string $surfaceClass,
    ): array {
        $substitutions = [];
        $refused = [];
        $claimed = [];

        foreach ($this->pairs as $index => $pair) {
            if (self::structured($pair['old']) || self::structured($pair['new'])) {
                continue;
            }
            if (\in_array(self::REPORT_VALUES, $pair['sources'], true)
                || (\in_array(self::INPUTS, $pair['sources'], true) && str_ends_with($pair['old'], ':'))) {
                continue;
            }
            if ($reversibleOnly && !$pair['reversible']) {
                continue;
            }

            if (!self::appliesToSurface($pair['sources'], $surfaceClass)) {
                continue;
            }

            $forward = $old === 'old';
            $refusal = $pair['multivalued'] && $forward;

            if ($refusal && isset($refused[$pair['row']])) {
                continue;
            }

            $refused[$pair['row']] = $refusal;
            $from = $forward ? $pair['old'] : $pair['new'];
            $to = $forward ? $pair['new'] : $pair['old'];

            // A spelling belongs to the ROLE that publishes it, and travels only
            // in the directions that role is applied in. A declaration in two
            // roles is reversible if either of them is, and without this split a
            // forward-only role's own spellings would ride the other role's
            // backwards direction — substituting, on the input, a spelling only
            // an artifact ever carries.
            $keyRole = self::applies(self::METRIC_KEYS, $pair['sources'], $forward);
            $reportValueRole = self::applies(self::REPORT_VALUES, $pair['sources'], $forward);
            $otherRoles = array_values(array_filter(
                $pair['sources'],
                static fn(string $source): bool => $source !== self::METRIC_KEYS && $source !== self::REPORT_VALUES,
            ));

            $spellings = [];

            // Every role but the key one names a rule, a channel, a symbol or a
            // token of the input, and each of those appears bare in an artifact.
            if ($otherRoles !== [] && self::appliesToAny($otherRoles, $pair['sources'], $forward)) {
                $spellings[] = [$from, $to];

                if (str_contains($from, '\\')) {
                    $spellings[] = [str_replace('\\', '\\\\', $from), str_replace('\\', '\\\\', $to)];
                }

                foreach (self::PREFIXES as $prefix) {
                    $spellings[] = [$prefix . $from, $prefix . $to];
                }
            }

            // A metric key travels only where it is a WHOLE string, quotes
            // included. Half the vocabulary is an ordinary English word —
            // `cognitive`, `distance`, `instability`, `abstractness` — and the
            // surfaces that publish keys publish messages beside them: measured
            // 2026-08-28, the reference's `format:json` came back with "Maximum
            // method cognitive complexity is 29" rewritten into "Maximum method
            // complexity.cognitive complexity is 29". A key is published as a
            // JSON string and nothing else, so the quotes are the boundary the
            // name-character rule cannot supply.
            //
            // The aggregated spellings are the same row's, and only the suffix
            // moves with the key, and only at the end of the name: the strategy
            // list is closed, so `ccn.avg` is translated and `ccn.average` is
            // not, and neither is `ccn.avg.avg` — a doubled suffix is a spelling
            // the product does not publish, and inventing a translation for it
            // would be the substring rewrite the whole-name rule refuses.
            if ($keyRole) {
                $spellings[] = ['"' . $from . '"', '"' . $to . '"'];

                foreach ($this->vocabulary->suffixes as $suffix) {
                    $spellings[] = ['"' . $from . '.' . $suffix . '"', '"' . $to . '.' . $suffix . '"'];
                }
            }

            // A report value travels the same way, for the same reason and with
            // no suffix to expand: the vocabulary is plain kebab-case, so a bare
            // occurrence would be indistinguishable from prose the same surface
            // may print beside it — measured on `format:suppressed`'s own `note`
            // field, which is prose.
            if ($reportValueRole) {
                $spellings[] = ['"' . $from . '"', '"' . $to . '"'];
            }

            // The title-cased spelling travels only as a WHOLE quoted value and
            // only on the surface that publishes it, for the same reason the
            // key role above is quoted: the quotes are the boundary the
            // name-character rule cannot supply, and here the surface is a
            // second boundary of the same kind.
            //
            // Title-casing a channel code produces live English prose.
            // `titleCase('maintainability.index')` is "Maintainability Index",
            // which is what the product calls the metric in a finding's own
            // message, in the rule's description and as a label in the HTML
            // report. Measured 2026-09-08 against `ec1597d6`: the bare spelling
            // rewrote the reference's "Maintainability Index is 47.4, below
            // threshold" into "Maintainability Mi is 47.4, ...", and 24 of the
            // run's 33 refusals were that one sentence on nine surfaces.
            // Quoting alone is not enough either: SARIF's own
            // `"text": "Checks Maintainability Index (...)"` stops matching once
            // quoted, but the HTML report's `"label": "Maintainability Index"`
            // is a whole quoted value and would still be rewritten. Only
            // `format:sarif` publishes a channel code title-cased as a rule
            // name, so only there is the spelling a name rather than prose.
            $titled = ['"' . self::titleCase($from) . '"', '"' . self::titleCase($to) . '"'];

            // `null` is the surface-less build the constructor makes for the
            // conflict guard alone ({@see __construct()}); dropping the spelling
            // there would take it out of the one check that refuses two rows
            // reaching one spelling.
            if (($surfaceClass === null || $surfaceClass === 'format:sarif')
                && self::applies(self::CHANNELS, $pair['sources'], $forward)
                && !str_contains($pair['old'] . $pair['new'], '#')
                && [self::titleCase($from), self::titleCase($to)] !== [$from, $to]
            ) {
                $spellings[] = $titled;
            }

            foreach ($spellings as [$spelledFrom, $spelledTo]) {
                // One spelling, one row. Substitution is a single pass driven by
                // a lookup keyed by the matched text, so two declarations
                // claiming one spelling would leave one of them silently
                // unapplied — and, because staleness is counted per matched
                // pair, reported stale as well. Whichever of the two the
                // ordering happened to drop, the run would be measuring a
                // translation nobody declared.
                $conflict = $claimed[$spelledFrom] ?? null;

                if ($conflict !== null && $conflict !== $index) {
                    throw new GateError(\sprintf(
                        '%s and %s both reach the spelling "%s", so which of the two translates it is decided by the'
                        . ' order they were loaded in. Declare one row for that spelling.',
                        $this->pairs[$conflict]['row'],
                        $pair['row'],
                        $spelledFrom,
                    ));
                }

                $claimed[$spelledFrom] = $index;
                $substitutions[] = [$spelledFrom, $spelledTo, $index, $refusal];
            }
        }

        // Longest first: PCRE takes the leftmost alternative that matches, and
        // the boundary assertions alone would already refuse a shorter prefix,
        // but an ordering that does not depend on that is one thing less to
        // reason about.
        usort($substitutions, static fn(array $a, array $b): int => \strlen($b[0]) <=> \strlen($a[0]));

        return $substitutions;
    }

    /**
     * Whether any of a declaration's non-key roles applies in this direction.
     *
     * @param list<string> $roles
     * @param list<string> $sources
     */
    private static function appliesToAny(array $roles, array $sources, bool $forward): bool
    {
        foreach ($roles as $role) {
            if (self::applies($role, $sources, $forward)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a role's own spellings travel in this direction.
     *
     * Forward, every declared role applies. Backwards, only a role whose map is
     * reversible does — see the docblock above {@see buildSubstitutions()}.
     *
     * @param list<string> $sources
     */
    private static function applies(string $source, array $sources, bool $forward): bool
    {
        return \in_array($source, $sources, true) && ($forward || self::FILES[$source]);
    }

    /**
     * A channel code as SARIF publishes it: the display name of a rule, each
     * dot- or dash-separated word capitalised.
     *
     * Measured, not guessed — a control renaming a channel code left exactly one
     * surface differing, `rules[].name` on SARIF, and no row could be written
     * for a spelling with spaces in it. Only a channel row gets this spelling,
     * and only where neither of its sides is a whole `rule#code` key: title-
     * casing a key or a class FQN produces a phrase no artifact contains, and a
     * substitution nothing can match is exactly the rubber stamp the map rules
     * refuse elsewhere.
     *
     * @see \Qualimetrix\Reporting\Formatter\Sarif\SarifRuleCollector::formatRuleName()
     */
    private static function titleCase(string $name): string
    {
        $words = preg_split('~[-.]~', $name);

        return implode(' ', array_map(ucfirst(...), $words === false ? [$name] : $words));
    }

    /** @param list<array{0: string, 1: string, 2: int}> $substitutions */
    private static function buildPattern(array $substitutions): string
    {
        // Ordering is not done here: buildSubstitutions() already sorts the list
        // longest-first, and sorting the same list twice would read as if one of
        // the two places were the authority.
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

    /**
     * Expands the declared rows into the pairs that are actually substituted.
     *
     * A channels row is a whole `rule#code` key, and the same rename shows up in
     * the artifacts three ways: as the whole key, and as each differing half.
     * Halves are marked ambiguous because several rows may legitimately disagree
     * about one half — that is a split, not a contradiction.
     *
     * The same pair declared in two maps is grouped FIRST, before anything is
     * checked or expanded, because it is one declaration in two roles and every
     * later rule is about declarations: the shape rules of both roles apply to
     * it, its directions are the union of theirs, and staleness credits it once.
     * Grouping by (old, new) rather than by (old, new, file) is what makes that
     * true — keyed by file, the two entries would be two rows renaming one name,
     * which is refused, and the only way to declare a name that is both a
     * channel identity and a configuration token would be not to declare one of
     * them.
     *
     * @param list<array{old: string, new: string, source: string, row?: string}> $pairs
     *
     * @return list<array{old: string, new: string, sources: list<string>, row: string, reversible: bool, ambiguous: bool, multivalued: bool}>
     */
    private static function normalize(array $pairs): array
    {
        /** @var array<string, array{old: string, new: string, sources: list<string>, row: string|null}> $declared */
        $declared = [];

        foreach ($pairs as $pair) {
            $source = $pair['source'];

            if (!\array_key_exists($source, self::FILES)) {
                throw new GateError(\sprintf('"%s" is not one of the declared maps.', $source));
            }

            $key = $pair['old'] . "\0" . $pair['new'];
            $declaration = $declared[$key] ?? ['old' => $pair['old'], 'new' => $pair['new'], 'sources' => [], 'row' => null];

            if (!\in_array($source, $declaration['sources'], true)) {
                $declaration['sources'][] = $source;
            }

            $declaration['row'] ??= $pair['row'] ?? null;
            $declared[$key] = $declaration;
        }

        // Grouping a pair declared by two maps into one declaration is
        // legitimate — it is what lets one name serve two roles at once, per
        // the docblock above. What is refused here is REPORT_VALUES sharing
        // that grouping specifically: every other role publishes bare, so
        // merging sources leaves the combined declaration unrestricted
        // (appliesToSurface() passes if ANY source is), and REPORT_VALUES then
        // inherits a spelling and a surface it never declared for itself — a
        // quoted-only, format:suppressed-only value would travel unquoted,
        // everywhere, exactly as codex-01 measured. Refused at load time
        // instead of letting REPORT_VALUES lose the one restriction that is
        // its whole point.
        foreach ($declared as $declaration) {
            $sources = $declaration['sources'];

            if (\in_array(self::REPORT_VALUES, $sources, true) && \count($sources) > 1) {
                throw new GateError(\sprintf(
                    'Report value "%s" -> "%s" is also declared by %s. A report-values row must not share its'
                    . ' (old, new) spelling with another map: the roles would merge and the value would stop'
                    . ' being quoted-only and format:suppressed-only. Give the report value its own spelling.',
                    $declaration['old'],
                    $declaration['new'],
                    implode(', ', array_values(array_diff($sources, [self::REPORT_VALUES]))),
                ));
            }
        }

        $unique = [];

        foreach ($declared as $declaration) {
            $old = $declaration['old'];
            $new = $declaration['new'];
            $sources = $declaration['sources'];

            $row = $declaration['row'] ?? \sprintf('%s: "%s" -> "%s"', implode('+', $sources), $old, $new);
            $images = self::images($old, $new, $sources, $row);

            if (\in_array(self::INPUTS, $sources, true)) {
                self::assertWholeInputToken($old, $row);

                foreach ($images as $image) {
                    self::assertWholeInputToken($image, $row);
                }
            }

            if (\in_array(self::REPORT_VALUES, $sources, true)) {
                self::assertReportValueSpelling($old, $row);

                foreach ($images as $image) {
                    self::assertReportValueSpelling($image, $row);
                }
            }

            if (\in_array(self::METRIC_KEYS, $sources, true)) {
                self::assertPlainMetricKey($old, $row);

                foreach ($images as $image) {
                    self::assertPlainMetricKey($image, $row);
                }
            }

            $expanded = \in_array(self::CHANNELS, $sources, true)
                ? self::expandChannelRow($old, $new, self::CHANNELS)
                : array_map(static fn(string $image): array => [$old, $image, false], $images);
            $multivalued = \count($images) > 1;
            $reversible = array_reduce(
                $sources,
                static fn(bool $carry, string $source): bool => $carry || self::FILES[$source],
                false,
            );

            foreach ($expanded as [$expandedOld, $expandedNew, $ambiguous]) {
                // A half is a channel spelling and nothing else. It exists only
                // for a `rule#code` row, and such a row cannot carry another
                // role: `#` is refused by both the input-token shape and the
                // metric-key shape, so the restriction below is a theorem about
                // the checks above rather than a precaution.
                //
                // Halves are keyed apart from declared pairs, and that is not
                // tidiness. Merging by (old, new) is what lets one declaration
                // hold two roles — but a half is not a declaration, so a half
                // that coincides with some other map's row would take that row's
                // slot or lose its own to it: one of the two would keep neither
                // its roles nor its credit, and would be reported stale for a
                // spelling the other one translated. Kept apart, the two reach
                // one spelling and the guard in buildSubstitutions() says so out
                // loud.
                $slot = $expandedOld . "\0" . $expandedNew . "\0" . ($ambiguous ? 'half' : 'declared');
                $unique[$slot] = [
                    'old' => $expandedOld,
                    'new' => $expandedNew,
                    'sources' => $ambiguous ? [self::CHANNELS] : $sources,
                    'row' => $row,
                    'reversible' => $ambiguous ? self::FILES[self::CHANNELS] : $reversible,
                    'ambiguous' => $ambiguous,
                    'multivalued' => $multivalued,
                ];
            }
        }

        return array_values($unique);
    }

    /**
     * The new tokens one row declares: one, or several for a split input.
     *
     * Only `inputs.tsv` may carry several, and only on the new side. The old
     * side is the reference's vocabulary, where a split producer is one name by
     * definition; several old tokens onto one new one would make the BACKWARDS
     * direction the undecidable one, and backwards is the direction this map
     * exists for — so that shape is refused with its reason rather than half
     * supported. A channels row expresses the same collapse without needing it,
     * because it is applied forwards only.
     *
     * @param list<string> $sources
     *
     * @return list<string>
     */
    private static function images(string $old, string $new, array $sources, string $row): array
    {
        if (!str_contains($old . $new, self::IMAGE_SEPARATOR)) {
            return [$new];
        }

        if ($sources !== [self::INPUTS]) {
            throw new GateError(\sprintf(
                '%s: only an %s row may name several tokens with "%s". A channel map is applied forwards only, so a'
                . ' collapse is already two ordinary rows and a split is derived from them.',
                $row,
                self::INPUTS,
                self::IMAGE_SEPARATOR,
            ));
        }

        if (str_contains($old, self::IMAGE_SEPARATOR)) {
            throw new GateError(\sprintf(
                '%s: the several tokens belong on the new side. The old side is the reference\'s vocabulary, where a'
                . ' split producer is one name; several old tokens onto one new one would make the backwards'
                . ' direction — the one this map exists for — the undecidable one.',
                $row,
            ));
        }

        $images = explode(self::IMAGE_SEPARATOR, $new);

        if (\count($images) !== \count(array_unique($images)) || \in_array('', $images, true)) {
            throw new GateError(\sprintf(
                '%s: a multivalued row names each new token once and none of them empty.',
                $row,
            ));
        }

        return $images;
    }

    /**
     * A `metric-keys.tsv` row names a plain metric key.
     *
     * `#` and `:` are refused because the row is expanded over the aggregation
     * suffixes: `<key>.<strategy>` is a spelling the product publishes, while
     * `rule#code.avg` and `rule:option.avg` are spellings nothing publishes, and
     * a substitution nothing can match is the rubber stamp these rules refuse
     * everywhere else. It is also what makes a half a channel spelling and
     * nothing else — see {@see normalize()}.
     */
    private static function assertPlainMetricKey(string $key, string $row): void
    {
        if (preg_match('~^strategy:[a-z][a-z0-9_-]*$~D', $key) === 1) {
            return;
        }
        if (preg_match('~^[A-Za-z0-9][A-Za-z0-9_.-]*$~', $key) === 1) {
            return;
        }

        throw new GateError(\sprintf(
            '%s: "%s" is not a plain metric key. A metric-keys row is expanded over the aggregation suffixes, and'
            . ' "<that>.<strategy>" is a spelling no surface carries.',
            $row,
            $key,
        ));
    }

    /**
     * A `report-values.tsv` row names a plain kebab-case report value.
     *
     * `#`, `:`, a doubled dash and a dot are all refused, none of them because a
     * particular surface happens to use them as a separator — the enumerable
     * report values this map is for are, as a matter of the product's own
     * vocabulary, single kebab-case words (`{@see
     * \Qualimetrix\Reporting\FindingProjection\SuppressionMechanism}`), and a
     * spelling outside that shape is a spelling no surface can publish. A
     * substitution nothing can match is the same rubber stamp
     * {@see assertPlainMetricKey()} refuses.
     */
    private static function assertReportValueSpelling(string $value, string $row): void
    {
        if (str_starts_with($value, '{')) {
            self::enumeration($value, $row);
            return;
        }
        if (preg_match('~^[a-z0-9]+(?:-[a-z0-9]+)*$~', $value) === 1) {
            return;
        }

        throw new GateError(\sprintf(
            '%s: "%s" is not a plain kebab-case report value. A report-values row translates a single value of an'
            . ' enumerable report field, and the product\'s own vocabulary for those is lowercase words joined by a'
            . ' single dash — a spelling outside that shape is one no surface can publish.',
            $row,
            $value,
        ));
    }

    /**
     * An `inputs.tsv` row names a whole token, never a name inside one.
     *
     * Four shapes are whole tokens on the input: `rule:option-key` as it is
     * written inside `--rule-opt=`, a flag together with its two dashes, a
     * dotted producer name as a selector writes it (`--disable-rule=`,
     * `only_rules:`), and a configuration key as a YAML document writes it, with
     * its trailing colon. A bare undotted word is refused, because "the option
     * key without its rule" would also translate the same key on some other
     * rule.
     *
     * The trailing-colon form addresses a root YAML key. A nested YAML key
     * requires a canonical JSON path retaining its parent; the translator edits
     * only the addressed key span and preserves comments and scalar values.
     *
     * It also never matches inside `--rule-opt=rule:option=value`: that shape
     * carries the option name between a colon and an `=`, never followed by a
     * colon of its own, so the whole-token text this shape declares — ending in
     * `:` — does not occur there at all.
     */
    private static function assertWholeInputToken(string $token, string $row): void
    {
        if (str_starts_with($token, '[')) {
            self::yamlPath($token, $row);
            return;
        }
        $dottedName = '[A-Za-z0-9][A-Za-z0-9_-]*(?:\.[A-Za-z0-9][A-Za-z0-9_-]*)+';
        $shapes = [
            'a rule and its option key' => '~^' . $dottedName . ':[A-Za-z0-9][A-Za-z0-9._-]*$~',
            'a flag with its two dashes' => '~^--[A-Za-z0-9][A-Za-z0-9._-]*$~',
            'a dotted producer name as a selector writes it' => '~^' . $dottedName . '$~',
            'a configuration key as a document writes it' => '~^[A-Za-z0-9][A-Za-z0-9_-]*:$~',
        ];

        foreach ($shapes as $pattern) {
            if (preg_match($pattern, $token) === 1) {
                return;
            }
        }

        throw new GateError(\sprintf(
            '%s: "%s" is not a whole input token. An %s row translates %s — never a name inside a token, because'
            . ' "the option key without its rule" would translate the same key on another rule too.',
            $row,
            $token,
            self::INPUTS,
            implode(', or ', array_keys($shapes)),
        ));
    }

    /**
     * The halves several rows disagree about, i.e. what a split renames.
     *
     * @return array<string, list<string>>
     */
    private function collectSplits(): array
    {
        $targets = [];

        foreach ($this->pairs as $pair) {
            if ($pair['ambiguous']) {
                $targets[$pair['old']][$pair['new']] = $pair['new'];
            }
        }

        $splits = [];

        foreach ($targets as $old => $news) {
            if (\count($news) > 1) {
                $sorted = array_values($news);
                sort($sorted);
                $splits[$old] = $sorted;
            }
        }

        return $splits;
    }

    /**
     * @param list<array{old: string, new: string, sources: list<string>, row: string, reversible: bool, ambiguous: bool, multivalued: bool}> $dropped
     *                                                                                                                                                 the split halves the constructor removed from substitution
     */
    private function validate(array $dropped): void
    {
        $targets = [];
        $sources = [];
        $reversibleTargets = [];

        foreach ($this->pairs as $pair) {
            if ($pair['old'] === $pair['new']) {
                throw new GateError(\sprintf('%s renames nothing: the two sides are the same name.', $pair['row']));
            }

            // Two DIFFERENT rows renaming one name leaves the reference's meaning
            // undecidable and is refused. The several images of ONE multivalued
            // row are the declared exception: they are undecidable forwards too,
            // which is why that row is never applied forwards — see images().
            if (isset($sources[$pair['old']]) && !($pair['multivalued'] && $sources[$pair['old']] === $pair['row'])) {
                throw new GateError(\sprintf(
                    '%s and %s both rename "%s", so what the reference means by it is undecidable.',
                    $sources[$pair['old']],
                    $pair['row'],
                    $pair['old'],
                ));
            }

            // Two rows onto one name is a collapse, and forwards a collapse is
            // correct: the two reference names really do become one. It only
            // breaks the backwards direction, so it is refused exactly there —
            // and "there" means BOTH rows travel backwards. A reversible row
            // sharing its target with a forward-only one is still a function
            // backwards, because the forward-only row is not consulted in that
            // direction at all. This arrangement is normal when a metric key
            // and the channel checking it deliberately share one name,
            // so `typeCoverage.param` (forward-only) and
            // `design.param-type-coverage` (reversible, the corpus addresses it
            // in --rule-opt) both arrive at `design.type-coverage.param`.
            if ($pair['reversible'] && isset($reversibleTargets[$pair['new']])) {
                throw new GateError(\sprintf(
                    '%s and %s both produce "%s". A map applied backwards must be injective in both directions,'
                    . ' and a collapse cannot be inverted.',
                    $reversibleTargets[$pair['new']],
                    $pair['row'],
                    $pair['new'],
                ));
            }

            $sources[$pair['old']] = $pair['row'];
            $targets[$pair['new']] = $pair;

            // Remembered separately from `$targets`, and that separation is the
            // whole check. Written into one map, a forward-only row landing
            // between two reversible ones overwrites the first and the second
            // sees a target that "is not reversible" — the maps load in file
            // order, so a merged channels+inputs row, a metric-keys row and a
            // plain inputs row on one target is exactly that sequence.
            if ($pair['reversible']) {
                $reversibleTargets[$pair['new']] = $pair['row'];
            }
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

        foreach ($this->pairs as $pair) {
            if (\in_array(self::CHANNELS, $pair['sources'], true) && str_contains($pair['old'], '#')) {
                $this->channelKeys[$pair['old']] = $pair['new'];
                $this->channelKeyRows[$pair['old']] = $pair['row'];
            }
        }

        $this->assertNoSuffixOverlap($dropped);
    }

    /**
     * Nothing else already carries the aggregated spelling of a declared metric
     * key.
     *
     * The expansion is what makes this checkable rather than hopeful. If some key
     * `A` is declared and `A.sum` is a name in its own right, then one spelling
     * has two meanings and which of them applies is decided by nothing. Three
     * populations can carry it, and all three are looked at:
     *
     * - **another declared name**, on either side of any row;
     * - **a half the split dropped.** Those halves are deliberately left
     *   untranslated so the records under them can be explained instead
     *   ({@see ChannelSplit}), and they are removed from the pair list before
     *   this check — so without naming them here, an expansion would translate
     *   exactly the spelling the split says is undecidable. Measured on synthetic
     *   rows: it did;
     * - **a base key the product declares.** That population is partial and the
     *   docblock of {@see MetricVocabulary} says how: `MetricName`'s constants
     *   are 71 of the 82 published keys, and the other eleven are collector-owned
     *   literals no single file declares.
     *
     * What this cannot see is therefore worth stating: a key that only the
     * REFERENCE publishes, shaped like an aggregation of a declared one, and moved
     * by the step without a row of its own. Every other arrangement of that shape
     * ends in a surface diff rather than in silence — the candidate publishes
     * something the translated reference does not — so what is left is the one
     * case where the step's undeclared rename happens to be exactly the
     * translation a declared row produces. Measured 2026-08-26 across all 83
     * published base keys: no key of either tree has the shape at all.
     *
     * @param list<array{old: string, new: string, sources: list<string>, row: string, reversible: bool, ambiguous: bool, multivalued: bool}> $dropped
     */
    private function assertNoSuffixOverlap(array $dropped): void
    {
        $declaredBy = [];

        foreach ([...$this->vocabulary->baseKeys, ...($this->referenceVocabulary === null ? [] : $this->referenceVocabulary->baseKeys)] as $key) {
            $declaredBy[$key] = 'the product\'s own metric-key declaration';
        }

        foreach ($dropped as $pair) {
            $declaredBy[$pair['old']] = \sprintf('%s, as a half the split leaves untranslated', $pair['row']);
        }

        foreach ($this->pairs as $pair) {
            $declaredBy[$pair['old']] ??= $pair['row'];
            $declaredBy[$pair['new']] ??= $pair['row'];
        }

        foreach ($this->pairs as $pair) {
            if (!\in_array(self::METRIC_KEYS, $pair['sources'], true)) {
                continue;
            }
            if (str_starts_with($pair['old'], 'strategy:')) {
                continue;
            }

            foreach ([$pair['old'], $pair['new']] as $key) {
                foreach (array_unique([...$this->vocabulary->suffixes, ...($this->referenceVocabulary === null ? [] : $this->referenceVocabulary->suffixes)]) as $suffix) {
                    $aggregated = $key . '.' . $suffix;
                    $other = $declaredBy[$aggregated] ?? null;

                    if ($other !== null) {
                        throw new GateError(\sprintf(
                            '%s names the metric key "%s", whose aggregated spelling "%s" is already a name in its own'
                            . ' right — %s. A key row translates its own "<key>.<strategy>" spellings, so the two'
                            . ' reach one name and neither of them decides it.',
                            $pair['row'],
                            $key,
                            $aggregated,
                            $other,
                        ));
                    }
                }
            }
        }
    }

    /**
     * A channel key renamed by a step renames its halves with it: the same
     * rename shows up as a whole key in `channel`, and as each half in `rule`
     * and `code`.
     *
     * Three shapes, because a channel key is not a pair for ever. `rule#code ->
     * rule#code` is the pair-to-pair rename and expands into its halves as
     * above. `name -> name` is a rename of a channel that is already one name,
     * and has no halves to expand. `rule#code -> name` is the collapse of the
     * pair into a single identity, and it expands into the whole key **only**:
     * the rule survives the collapse as its own published field, so translating
     * the rule half would rewrite a field the step does not move, and any rename
     * of the code half is a rename of a name in its own right and needs its own
     * row rather than being inferred from this one.
     *
     * `name -> rule#code` is refused. Nothing in the plan goes that way, and a
     * half semantics invented for a direction no step takes is a claim nothing
     * checks.
     *
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    private static function expandChannelRow(string $old, string $new, string $path): array
    {
        $oldHalves = explode('#', $old);
        $newHalves = explode('#', $new);

        if (\count($oldHalves) > 2 || \count($newHalves) > 2) {
            throw new GateError(\sprintf(
                '%s: "%s" -> "%s" is not a channel name or a full "rule#code" key on each side.',
                $path,
                $old,
                $new,
            ));
        }

        if (\count($oldHalves) === 1 && \count($newHalves) === 2) {
            throw new GateError(\sprintf(
                '%s: "%s" -> "%s" turns one channel name back into a "rule#code" pair. No step does that, and the'
                . ' halves of the new key would be a translation no row declares.',
                $path,
                $old,
                $new,
            ));
        }

        $pairs = [[$old, $new, false]];

        if (\count($oldHalves) !== 2 || \count($newHalves) !== 2) {
            return $pairs;
        }

        foreach ([0, 1] as $half) {
            if ($oldHalves[$half] !== $newHalves[$half]) {
                $pairs[] = [$oldHalves[$half], $newHalves[$half], true];
            }
        }

        return $pairs;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function rows(string $path): array
    {
        $rows = [];

        foreach (Tsv::rows($path, ['old', 'new', 'reason']) as $row) {
            if ($row['reason'] === '?') {
                throw new GateError($path . ': a generated proposal needs an explicit reason before it can authorize a rename.');
            }
            $rows[] = [$row['old'], $row['new']];
        }

        return $rows;
    }
}

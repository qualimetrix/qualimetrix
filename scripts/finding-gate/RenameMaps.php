<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The four declared maps: channel names, symbols (FQN or path), metric keys and
 * inputs (option keys, flag aliases, names inside selectors).
 *
 * Direction is declared per map, not assumed. Forward (old -> new) is applied to
 * the REFERENCE tree's artifacts, because the reference predates the rename and
 * its output still speaks the old vocabulary. Reverse (new -> old) is applied to
 * the configuration and CLI arguments handed to that same reference binary, which
 * cannot be addressed in a vocabulary it does not know yet.
 *
 * A map may be applied backwards if and only if it is injective in both
 * directions, and that is checked here rather than promised. The one shape that
 * is allowed to break it does so in one direction only, and the asymmetry is
 * what makes it admissible: an `inputs.tsv` row may name SEVERAL new tokens for
 * one old one, because a split producer is addressed in the candidate's
 * vocabulary by several names and in the reference's by one. Backwards — the
 * direction that map exists for — the several candidate names all restate as the
 * one name the reference knows, which is a function. Forwards there is no
 * function to apply, so the row is not applied forwards at all: an occurrence of
 * the old token on the way out stops the run instead of silently taking the
 * first image. Measured 2026-08-24: after Ш4b `design.type-coverage` is three
 * producers (`design.param-type-coverage`, `design.property-type-coverage`,
 * `design.return-type-coverage`), so a case addressing the old name through a
 * selector had no writable row at all and was `reference-input-untranslated`
 * for good.
 *
 * `channels.tsv` is
 * forward-only: after a collapse two rows share one target, and after a split one
 * old half has several, so neither can be inverted. It also must not be inverted
 * even where it looks invertible — a collapsed channel name is textually the same
 * string as the unchanged producer name the corpus writes into its own input, so
 * an inverted channel map would rewrite a legitimate argument. An input that does
 * need the translation says so with an `inputs.tsv` row; one that needs it and has
 * no row makes the reference run fail loudly on an unknown name, which is what
 * Gate's `reference-input-untranslated` reports.
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
    public const CHANNELS = 'channels.tsv';
    public const SYMBOLS = 'symbols.tsv';
    public const METRIC_KEYS = 'metric-keys.tsv';
    public const INPUTS = 'inputs.tsv';

    /** Map file => whether it may also be applied backwards. */
    private const FILES = [
        self::CHANNELS => false,
        self::SYMBOLS => true,
        self::METRIC_KEYS => true,
        self::INPUTS => true,
    ];

    /** What continues a name, and therefore what may not end a match. */
    private const NAME_CHARS = 'A-Za-z0-9_.\\-\\\\/';

    /**
     * Prefixes an artifact writes in front of a name, which the boundary
     * assertion would otherwise refuse to look past.
     *
     * Measured across all eleven formats plus the baseline file, `baseline:explain`
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

    /** @var list<array{old: string, new: string, source: string, row: string, reversible: bool, ambiguous: bool, multivalued: bool}> */
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

    /** @param list<array{old: string, new: string, source: string, row?: string}> $pairs */
    private function __construct(array $pairs)
    {
        $this->pairs = self::normalize($pairs);
        $this->splits = $this->collectSplits();
        $this->pairs = array_values(array_filter(
            $this->pairs,
            fn(array $pair): bool => !isset($this->splits[$pair['old']]) || !$pair['ambiguous'],
        ));
        $this->validate();
    }

    public static function load(string $mapsDirectory): self
    {
        $pairs = [];

        foreach (array_keys(self::FILES) as $file) {
            $path = $mapsDirectory . '/' . $file;

            foreach (self::rows($path) as [$old, $new]) {
                $pairs[] = ['old' => $old, 'new' => $new, 'source' => $file];
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
     * record.
     *
     * The key is the unit of attribution rather than the row text, because the
     * caller holding the explanation is holding a key. Attribution is what keeps
     * the relaxation from becoming "any row of a live split is live": the pairs
     * a split makes ambiguous are dropped from the substitution list with the
     * rest reindexed, and the hit counter is keyed by pair index, so without
     * this the only fact reaching staleness would be that the split as a whole
     * had translated something.
     *
     * An undeclared key is refused rather than ignored. A credit nothing
     * declared would keep a row alive by a name that is not in it, which is the
     * failure this whole check exists to report.
     */
    public function creditExplanation(string $oldChannelKey): void
    {
        $row = $this->channelKeyRows[$oldChannelKey] ?? throw new GateError(\sprintf(
            'No declared row names the channel key "%s", so nothing can be credited with explaining a record of it.',
            $oldChannelKey,
        ));

        $this->explainedRows[$row] = true;
    }

    /** Reference output, restated in the candidate's vocabulary. */
    public function forward(string $text): string
    {
        return $this->replace($text, old: 'old', new: 'new', reversibleOnly: false);
    }

    /** Candidate-side input, restated in the reference's vocabulary. */
    public function reverse(string $text): string
    {
        return $this->replace($text, old: 'new', new: 'old', reversibleOnly: true);
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

    private function replace(string $text, string $old, string $new, bool $reversibleOnly): string
    {
        if ($this->pairs === []) {
            return $text;
        }

        $direction = $old . '>' . $new;
        $substitutions = $this->substitutions[$direction] ??= $this->buildSubstitutions($old, $new, $reversibleOnly);

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
    private function buildSubstitutions(string $old, string $new, bool $reversibleOnly): array
    {
        $substitutions = [];
        $refused = [];

        foreach ($this->pairs as $index => $pair) {
            if ($reversibleOnly && !$pair['reversible']) {
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
            $spellings = [[$from, $to]];

            if (str_contains($from, '\\')) {
                $spellings[] = [str_replace('\\', '\\\\', $from), str_replace('\\', '\\\\', $to)];
            }

            foreach (self::PREFIXES as $prefix) {
                $spellings[] = [$prefix . $from, $prefix . $to];
            }

            $titled = [self::titleCase($from), self::titleCase($to)];

            if ($pair['source'] === self::CHANNELS
                && !str_contains($pair['old'] . $pair['new'], '#')
                && $titled !== [$from, $to]
            ) {
                $spellings[] = $titled;
            }

            foreach ($spellings as [$spelledFrom, $spelledTo]) {
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
     * @param list<array{old: string, new: string, source: string, row?: string}> $pairs
     *
     * @return list<array{old: string, new: string, source: string, row: string, reversible: bool, ambiguous: bool, multivalued: bool}>
     */
    private static function normalize(array $pairs): array
    {
        $unique = [];

        foreach ($pairs as $pair) {
            $source = $pair['source'];

            if (!\array_key_exists($source, self::FILES)) {
                throw new GateError(\sprintf('"%s" is not one of the declared maps.', $source));
            }

            $row = $pair['row'] ?? \sprintf('%s: "%s" -> "%s"', $source, $pair['old'], $pair['new']);
            $images = self::images($pair['old'], $pair['new'], $source, $row);

            if ($source === self::INPUTS) {
                self::assertWholeInputToken($pair['old'], $row);

                foreach ($images as $image) {
                    self::assertWholeInputToken($image, $row);
                }
            }

            $expanded = $source === self::CHANNELS
                ? self::expandChannelRow($pair['old'], $pair['new'], $source)
                : array_map(static fn(string $image): array => [$pair['old'], $image, false], $images);
            $multivalued = \count($images) > 1;

            foreach ($expanded as [$old, $new, $ambiguous]) {
                $unique[$old . "\0" . $new . "\0" . $source] = [
                    'old' => $old,
                    'new' => $new,
                    'source' => $source,
                    'row' => $row,
                    'reversible' => self::FILES[$source],
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
     * @return list<string>
     */
    private static function images(string $old, string $new, string $source, string $row): array
    {
        if (!str_contains($old . $new, self::IMAGE_SEPARATOR)) {
            return [$new];
        }

        if ($source !== self::INPUTS) {
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
     * An `inputs.tsv` row names a whole token, never a name inside one.
     *
     * Three shapes are whole tokens on the input: `rule:option-key` as it is
     * written inside `--rule-opt=`, a flag together with its two dashes, and a
     * dotted producer name as a selector writes it (`--disable-rule=`,
     * `only_rules:`). A bare undotted word is refused, because "the option key
     * without its rule" would also translate the same key on some other rule.
     */
    private static function assertWholeInputToken(string $token, string $row): void
    {
        $dottedName = '[A-Za-z0-9][A-Za-z0-9_-]*(?:\.[A-Za-z0-9][A-Za-z0-9_-]*)+';
        $shapes = [
            'a rule and its option key' => '~^' . $dottedName . ':[A-Za-z0-9][A-Za-z0-9._-]*$~',
            'a flag with its two dashes' => '~^--[A-Za-z0-9][A-Za-z0-9._-]*$~',
            'a dotted producer name as a selector writes it' => '~^' . $dottedName . '$~',
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

    private function validate(): void
    {
        $targets = [];
        $sources = [];

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
            // breaks the backwards direction, so it is refused exactly there.
            if (isset($targets[$pair['new']]) && ($pair['reversible'] || $targets[$pair['new']]['reversible'])) {
                throw new GateError(\sprintf(
                    '%s and %s both produce "%s". A map applied backwards must be injective in both directions,'
                    . ' and a collapse cannot be inverted.',
                    $targets[$pair['new']]['row'],
                    $pair['row'],
                    $pair['new'],
                ));
            }

            $sources[$pair['old']] = $pair['row'];
            $targets[$pair['new']] = $pair;
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
            if ($pair['source'] === self::CHANNELS && str_contains($pair['old'], '#')) {
                $this->channelKeys[$pair['old']] = $pair['new'];
                $this->channelKeyRows[$pair['old']] = $pair['row'];
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
            $rows[] = [$row['old'], $row['new']];
        }

        return $rows;
    }
}

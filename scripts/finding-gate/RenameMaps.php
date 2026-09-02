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
 * `metric-keys.tsv` is forward-only too, and the two reasons are the same two,
 * measured 2026-08-26. Nothing on the reference's input is spelled as a metric
 * key: no case argument carries one, and the corpus' only user-defined formula
 * reads no metric at all, so there is nothing for a backwards direction to
 * translate. And an inverted key map would rewrite arguments the step never
 * touched — after the vocabulary rename the new key names are textually the rule
 * names the corpus writes into its own `--rule-opt` tokens (`coupling.class-rank`
 * in all fourteen cases, `size.class-count` and its two siblings in `design`,
 * `complexity.cognitive` and `complexity.npath` in two more), and a reverse pass
 * would hand the reference `classRank` and `classCount` as rules it does not
 * have. A metric key that ever does need translating on the input says so with
 * an `inputs.tsv` row, exactly as a channel does.
 *
 * A metric key is published bare AND once per aggregation strategy declared for
 * it, so a `metric-keys.tsv` row also translates its own `<key>.<strategy>`
 * spellings. The strategies are a closed list read from both trees
 * ({@see AggregationSuffixes}), the suffix is matched only at the end of the
 * name, and the expansion is granted to that one map: measured, 212 of the
 * corpus' 295 published spellings are `base.<strategy>` against 83 base keys, so
 * a row per spelling is a list no step can keep complete, while a row per key
 * with an open suffix would be the substring rewrite the whole-name rule
 * refuses. The suffixed spellings are spellings of the SAME row — like the
 * `qmx.` prefix below — so they count towards that row's staleness and are never
 * a second declaration.
 *
 * One name may hold two roles: `computed.branch_load` is a channel identity and
 * a token the corpus writes into its own configuration, so the step that renames
 * it declares the same pair in `channels.tsv` and in `inputs.tsv`. Two rows
 * naming one name are otherwise refused as undecidable, and rightly — but two
 * rows stating the SAME translation decide nothing differently. Such a pair is
 * therefore ONE declaration carrying both roles: it is applied in the union of
 * their directions, held to the shape rules of each, and credited once. Each
 * role's own spellings still travel only in the directions that role is applied
 * in — a forward-only role does not get a backwards pass because a sibling role
 * has one.
 *
 * Crediting it once is a decision with a stated reach. Roles that apply in the
 * same direction substitute the same string in the same artifacts, so which of
 * them an occurrence belonged to is not a measurable question. What is NOT
 * claimed is per-direction accounting: like every row here, a declaration is
 * live once it fired anywhere, so a two-role declaration whose forward side
 * never appeared in an artifact is not distinguished from one that did. That is
 * the same latitude a symbols row has had all along — it may fire on artifacts
 * and never on input — and narrowing it for merged rows alone would be a rule
 * about bookkeeping rather than about renames.
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
     * The restriction is a measurement, not a convenience: Ш5e3 measured which
     * surfaces publish a metric key at all — `format:metrics` (282 spellings),
     * `format:json` (13) and the HTML report, which embeds the JSON payload.
     * The other nine formats, the baseline, `baseline:explain` and the `rules`
     * listing publish none. A key that later reaches one of those is therefore
     * NOT silently translated: it stands as an undeclared difference and the run
     * goes red, which is the direction this has to fail in.
     *
     * @var array<string, list<string>|null>
     */
    private const SURFACES = [
        self::METRIC_KEYS => ['format:json', 'format:metrics', 'format:html'],
    ];

    /** Map file => whether it may also be applied backwards. */
    private const FILES = [
        self::CHANNELS => false,
        self::SYMBOLS => true,
        self::METRIC_KEYS => false,
        self::INPUTS => true,
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
        $this->pairs = array_values(array_filter(
            $this->pairs,
            fn(array $pair): bool => !isset($this->splits[$pair['old']]) || !$pair['ambiguous'],
        ));
        $this->validate($dropped);

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
        return $this->replace($text, old: 'old', new: 'new', reversibleOnly: false, surfaceClass: $surfaceClass);
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
        return array_map($this->reverse(...), $arguments);
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
            $otherRoles = array_values(array_filter(
                $pair['sources'],
                static fn(string $source): bool => $source !== self::METRIC_KEYS,
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

            $titled = [self::titleCase($from), self::titleCase($to)];

            if (self::applies(self::CHANNELS, $pair['sources'], $forward)
                && !str_contains($pair['old'] . $pair['new'], '#')
                && $titled !== [$from, $to]
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
            // direction at all. Ш5e3 makes that arrangement the normal case: a
            // metric key and the channel checking it are one name on purpose,
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

        foreach ($this->vocabulary->baseKeys as $key) {
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

            foreach ([$pair['old'], $pair['new']] as $key) {
                foreach ($this->vocabulary->suffixes as $suffix) {
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
            $rows[] = [$row['old'], $row['new']];
        }

        return $rows;
    }
}

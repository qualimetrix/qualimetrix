<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The gate's own mechanics, checked without running the corpus.
 *
 * The map path is the reason this exists. At Ш1 all three maps are empty, so a
 * comparison run exercises the identity case only — and the steps that populate
 * the maps must not be the ones that first discover whether mapping works. The
 * same applies to normalization: a kind with no tracked row today is still the
 * kind the deriver will emit tomorrow.
 */
final class SelfTest
{
    /** @var list<string> */
    private array $failures = [];

    public function __construct(private readonly string $candidateRoot) {}

    /** @return list<string> */
    public function run(): array
    {
        $this->maps();
        $this->ambiguities();
        $this->declaredDelta();
        $this->normalization();
        $this->deriver();
        $this->tuple();
        $this->fingerprints();
        $this->verdicts();
        $this->surfaces();
        $this->removal();

        return $this->failures;
    }

    private function maps(): void
    {
        $empty = RenameMaps::fromPairs([]);
        $this->assert($empty->isIdentity(), 'an empty map set is the identity');
        $this->same('code-smell.eval', $empty->forward('code-smell.eval'), 'identity forward');
        $this->same('code-smell.eval', $empty->reverse('code-smell.eval'), 'identity reverse');

        // What the tracked declaration of THIS step is, asserted rather than
        // assumed: loading already refuses chains, duplicate sources and
        // duplicate targets, so what is left to check is that the step's own
        // shape survived the load. Ш4c (ADR 0031 — shape moves off the channel
        // onto the producer) publishes nothing, so its tracked maps are the
        // identity and derive no split — Ш4b's own assertion here (a
        // `design.type-coverage` split) belonged to that step's tracked state
        // and is retired with it, not carried forward.
        $maps = RenameMaps::load($this->candidateRoot . '/finding-gate/maps');
        $this->assert($maps->isIdentity(), 'this step declares no renames, so the tracked maps are the identity');
        $this->same(
            [],
            array_keys($maps->splits()),
            'the tracked rows derive no split — this step\'s maps are empty',
        );

        // One row, and it is the whole key: the halves are expanded from it, so
        // the two spellings of one rename can never be declared out of step.
        $channel = RenameMaps::fromPairs([
            ['old' => 'design.type-coverage#design.type-coverage.param', 'new' => 'design.param-typing#design.param-typing', 'source' => 'channels.tsv'],
        ]);
        $this->same(
            'design.param-typing#design.param-typing',
            $channel->forward('design.type-coverage#design.type-coverage.param'),
            'a whole channel key maps before its halves do',
        );
        $this->same(
            '"rule": "design.param-typing"',
            $channel->forward('"rule": "design.type-coverage"'),
            'the rule half of a renamed channel maps too',
        );
        // Forward-only, and the reason is measured: after a collapse the target
        // is textually the unchanged producer name the corpus writes into its own
        // arguments, so an inverted channel map would rewrite a legitimate input.
        $this->same(
            '--only-rule=design.param-typing',
            $channel->reverse('--only-rule=design.param-typing'),
            'the channel map is not applied backwards',
        );
        $this->same(
            ['--only-rule=design.param-typing'],
            $channel->reverseArguments(['--only-rule=design.param-typing']),
            'nor when it is handed a list of arguments',
        );

        // checkstyle prints `source="qmx.<code>"`, and a dot continues a name, so
        // without the prefixed spelling the boundary assertion refuses the match
        // and the row translates nothing there — while still counting as fired
        // everywhere else, which is how a rename leaks into an undeclared diff.
        $this->same(
            'source="qmx.design.param-typing"',
            $channel->forward('source="qmx.design.type-coverage.param"'),
            'a prefixed spelling of the code is translated too',
        );
        $prefixOnly = RenameMaps::fromPairs([
            ['old' => 'code-smell.eval#code-smell.eval', 'new' => 'code-smell.eval#smell.eval', 'source' => 'channels.tsv'],
        ]);
        $prefixOnly->forward('source="qmx.code-smell.eval"');
        $this->same([], $prefixOnly->staleRows(), 'a row whose only match was the prefixed spelling is not stale');

        $inputs = RenameMaps::fromPairs([
            ['old' => 'design.type-coverage:param_warning', 'new' => 'design.param-typing:warning', 'source' => 'inputs.tsv'],
            ['old' => '--type-coverage-param-warning', 'new' => '--param-typing-warning', 'source' => 'inputs.tsv'],
        ]);
        $this->same(
            '--rule-opt=design.type-coverage:param_warning=-1',
            $inputs->reverse('--rule-opt=design.param-typing:warning=-1'),
            'an input row restates candidate input in the reference vocabulary',
        );
        $this->same(
            ['--type-coverage-param-warning=2'],
            $inputs->reverseArguments(['--param-typing-warning=2']),
            'arguments are reversed one by one',
        );
        $this->same(
            '--param-typing-warning (--rule-opt=design.param-typing:warning=…)',
            $inputs->forward('--type-coverage-param-warning (--rule-opt=design.type-coverage:param_warning=…)'),
            'an input row also applies forward, because the rules snapshot prints the same tokens',
        );

        // A row naming a name inside a token would translate the same option key
        // on some other rule as well, so the shape is checked when it loads.
        foreach (['param_warning', 'type-coverage-param-warning'] as $partial) {
            $this->assert(
                self::throws(static fn(): mixed => RenameMaps::fromPairs([
                    ['old' => $partial, 'new' => 'whatever.else', 'source' => 'inputs.tsv'],
                ])),
                \sprintf('an input row on "%s" is refused: that is a name inside a token, not a token', $partial),
            );
        }

        $this->assert(
            !self::throws(static fn(): mixed => RenameMaps::fromPairs([
                ['old' => 'design.type-coverage', 'new' => 'design.param-typing', 'source' => 'inputs.tsv'],
            ])),
            'a dotted producer name is a whole token, because that is how a selector writes it',
        );

        // Measured counterexample, 2026-08-23: with the row above as the only
        // map row, a substring rewrite turned the undeclared prefix sibling
        // `design.type-coverage.property` into `design.param-typing.property`,
        // so a step could rename three level codes, declare one, and stay green.
        // The code half must survive untranslated — then the surface comparison
        // is what reports the undeclared rename.
        $this->same(
            '"channel":"design.param-typing#design.type-coverage.property"',
            $channel->forward('"channel":"design.type-coverage#design.type-coverage.property"'),
            'a row does not translate a longer name that merely starts with it',
        );

        $levels = RenameMaps::fromPairs([
            ['old' => 'complexity.cyclomatic', 'new' => 'complexity.ccn', 'source' => 'metric-keys.tsv'],
        ]);
        $this->same(
            '"complexity.cyclomatic.callable" and "complexity.ccn"',
            $levels->forward('"complexity.cyclomatic.callable" and "complexity.cyclomatic"'),
            'a level segment keeps a prefix row away from the whole name',
        );

        // The other measured counterexample: rows applied one after another
        // cascade. Row 1 produces `new.rule#a.code`, and row 2 renames the name
        // `a.code` that target contains — sequentially that yields
        // `new.rule#z.code`, an identity no row declares. Row 2's source equals
        // no row's target, so the load-time chain check cannot see it; one pass
        // over the original text can.
        $cascade = RenameMaps::fromPairs([
            ['old' => 'old.rule#a.code', 'new' => 'new.rule#a.code', 'source' => 'channels.tsv'],
            ['old' => 'a.code', 'new' => 'z.code', 'source' => 'metric-keys.tsv'],
        ]);
        $this->same(
            'new.rule#a.code',
            $cascade->forward('old.rule#a.code'),
            'substitution is one pass over the original text, so rows cannot cascade',
        );

        $rejected = [
            'a chain' => [
                ['old' => 'a.one#a.one', 'new' => 'b.one#b.one', 'source' => 'channels.tsv'],
                ['old' => 'b.one#b.one', 'new' => 'c.one#c.one', 'source' => 'channels.tsv'],
            ],
            'two rows renaming one whole key' => [
                ['old' => 'a.one#a.one', 'new' => 'b.one#b.one', 'source' => 'channels.tsv'],
                ['old' => 'a.one#a.one', 'new' => 'c.one#c.one', 'source' => 'channels.tsv'],
            ],
            'two reversible rows onto one target' => [
                ['old' => 'a.one', 'new' => 'z.one', 'source' => 'metric-keys.tsv'],
                ['old' => 'b.one', 'new' => 'z.one', 'source' => 'metric-keys.tsv'],
            ],
            'a row that renames nothing' => [
                ['old' => 'a.one', 'new' => 'a.one', 'source' => 'metric-keys.tsv'],
            ],
        ];

        foreach ($rejected as $description => $pairs) {
            $this->assert(
                self::throws(static fn(): mixed => RenameMaps::fromPairs($pairs)),
                $description . ' is refused when the map loads',
            );
        }

        $stale = RenameMaps::fromPairs([
            ['old' => 'never.observed', 'new' => 'nor.published', 'source' => 'metric-keys.tsv'],
        ]);
        $stale->forward('nothing this row can translate');
        $this->same(
            ['metric-keys.tsv: "never.observed" -> "nor.published"'],
            $stale->staleRows(),
            'a row that translated nothing is reported stale',
        );
        $this->same([], $levels->staleRows(), 'a row that did translate something is not');

        $symbols = RenameMaps::fromPairs([
            ['old' => 'Qualimetrix\\Analysis\\Finding\\Contract\\Violation', 'new' => 'Qualimetrix\\Analysis\\Finding\\Contract\\Finding', 'source' => 'symbols.tsv'],
        ]);
        $this->same(
            '"subject": "declaration:class:Qualimetrix\\\\Analysis\\\\Finding\\\\Contract\\\\Finding@src/x.php"',
            $symbols->forward('"subject": "declaration:class:Qualimetrix\\\\Analysis\\\\Finding\\\\Contract\\\\Violation@src/x.php"'),
            'a symbol row maps its JSON-escaped form as well as its raw form',
        );
    }

    /**
     * The two ambiguities a channel map cannot be a function through, and what
     * the gate does with each.
     *
     * A collapse is correct forwards: two reference names really do become one,
     * and the map has no backwards direction to lose. A split is not: one old
     * half has several new names, so no translation of that half is right, and
     * the record it sits in is explained instead.
     */
    private function ambiguities(): void
    {
        $collapse = RenameMaps::fromPairs([
            ['old' => 'complexity.cyclomatic#complexity.cyclomatic.callable', 'new' => 'complexity.cyclomatic#complexity.cyclomatic', 'source' => 'channels.tsv'],
            ['old' => 'complexity.cyclomatic#complexity.cyclomatic.class', 'new' => 'complexity.cyclomatic#complexity.cyclomatic', 'source' => 'channels.tsv'],
        ]);
        $this->same(
            '"complexity.cyclomatic" and "complexity.cyclomatic"',
            $collapse->forward('"complexity.cyclomatic.callable" and "complexity.cyclomatic.class"'),
            'a collapse is allowed forwards, and both codes reach the one new name',
        );
        $this->same([], $collapse->splits(), 'a collapse is not a split');

        $split = RenameMaps::fromPairs([
            ['old' => 'design.type-coverage#design.type-coverage.param', 'new' => 'design.param-typing#design.param-typing', 'source' => 'channels.tsv'],
            ['old' => 'design.type-coverage#design.type-coverage.return', 'new' => 'design.return-typing#design.return-typing', 'source' => 'channels.tsv'],
        ]);
        $this->same(
            ['design.type-coverage' => ['design.param-typing', 'design.return-typing']],
            $split->splits(),
            'one old half with two new names is reported as a split',
        );
        $this->same(
            '"rule": "design.type-coverage"',
            $split->forward('"rule": "design.type-coverage"'),
            'the split half is not translated, because no translation of it is right',
        );
        $this->same(
            '"channel": "design.param-typing#design.param-typing"',
            $split->forward('"channel": "design.type-coverage#design.type-coverage.param"'),
            'the whole key still maps: only the ambiguous half is left alone',
        );

        $explanation = ChannelSplit::of($split);
        $reference = [
            ['subject' => 'declaration:class:A@a.php', 'rule' => 'design.type-coverage', 'code' => 'design.type-coverage.param', 'channel' => 'design.type-coverage#design.type-coverage.param'],
        ];
        $explained = [
            ['subject' => 'declaration:class:A@a.php', 'rule' => 'design.param-typing', 'code' => 'design.param-typing', 'channel' => 'design.param-typing#design.param-typing'],
        ];
        $this->same([], $explanation->unexplained($reference, $explained), 'an occurrence the declared row accounts for is explained');
        $this->assert(
            $explanation->allowsMove('rule', 'design.type-coverage', 'design.param-typing'),
            'an explained record lets a delta show the move it performed',
        );
        $this->assert(
            $explanation->allowsMove('rule', 'design.param-typing', 'design.type-coverage'),
            'in either order, because the token order inside one line is the formatter\'s',
        );
        $this->assert(
            !$explanation->allowsMove('rule', 'design.type-coverage', 'design.god-class'),
            'and no move to somewhere the split never went',
        );
        // The hole the value-set version left: both of these values are carried
        // by explained records, so a set-membership check accepted a move
        // between them on any record. No explained record ever paired them.
        $bothHalves = ChannelSplit::of(RenameMaps::fromPairs([
            ['old' => 'design.type-coverage#design.type-coverage.param', 'new' => 'design.param-typing#design.param-typing', 'source' => 'channels.tsv'],
            ['old' => 'design.type-coverage#design.type-coverage.return', 'new' => 'design.return-typing#design.return-typing', 'source' => 'channels.tsv'],
        ]));
        $this->same([], $bothHalves->unexplained(
            [
                ['subject' => 'declaration:class:A@a.php', 'rule' => 'design.type-coverage', 'code' => 'design.type-coverage.param', 'channel' => 'design.type-coverage#design.type-coverage.param'],
                ['subject' => 'declaration:class:A@a.php', 'rule' => 'design.type-coverage', 'code' => 'design.type-coverage.return', 'channel' => 'design.type-coverage#design.type-coverage.return'],
            ],
            [
                ['subject' => 'declaration:class:A@a.php', 'rule' => 'design.param-typing', 'code' => 'design.param-typing', 'channel' => 'design.param-typing#design.param-typing'],
                ['subject' => 'declaration:class:A@a.php', 'rule' => 'design.return-typing', 'code' => 'design.return-typing', 'channel' => 'design.return-typing#design.return-typing'],
            ],
        ), 'two halves of one split, both explained');
        $this->assert(
            !$bothHalves->allowsMove('rule', 'design.param-typing', 'design.return-typing'),
            'a move between two targets of the same split is not a move the split performed',
        );

        $this->same(
            1,
            \count(ChannelSplit::of($split)->unexplained($reference, [])),
            'a declared split whose new key the candidate never publishes is split-unmapped',
        );

        $undeclaredHalf = [
            ['subject' => 'declaration:class:B@b.php', 'rule' => 'design.type-coverage', 'code' => 'design.type-coverage.property', 'channel' => 'design.type-coverage#design.type-coverage.property'],
        ];
        $this->same(
            1,
            \count(ChannelSplit::of($split)->unexplained($undeclaredHalf, $explained)),
            'the third code of a split rule, left undeclared, is split-unmapped rather than absorbed',
        );
    }

    /**
     * Two properties the reverse direction rests on, neither asserted before.
     *
     * One: a shorter row that is a prefix of a longer one does not shadow it.
     * `:` and `=` are not name characters, so a channels-derived half is a
     * legitimate prefix of an inputs token — exactly the pair this step
     * declares — and PCRE alternation is leftmost-first rather than
     * longest-match. `buildSubstitutions()` sorts longest-first for that reason;
     * this pins the outcome rather than the sort, so a future refactor that
     * loses the ordering fails here.
     *
     * Two: the channels map is **not** applied backwards. That is what keeps a
     * collapse's target — textually the unchanged producer name a corpus writes
     * into its own arguments — from being rewritten on the way in. It is stated
     * in a constant and was asserted nowhere.
     */
    private function prefixShadowing(): void
    {
        $maps = RenameMaps::fromPairs([
            ['old' => 'design.type-coverage#design.type-coverage.param', 'new' => 'design.param-type-coverage#design.param-type-coverage', 'source' => 'channels.tsv'],
            ['old' => 'design.type-coverage:param_warning', 'new' => 'design.param-type-coverage:warning', 'source' => 'inputs.tsv'],
        ]);

        $this->same(
            '--rule-opt=design.type-coverage:param_warning=-1',
            $maps->reverse('--rule-opt=design.param-type-coverage:warning=-1'),
            'the input token maps back whole, not as a shorter prefix row',
        );
        $this->same(
            '--rule-opt=design.param-type-coverage:warning=-1',
            $maps->forward('--rule-opt=design.type-coverage:param_warning=-1'),
            'and forward the same way',
        );
        // Forward-only, asserted rather than trusted to the constant: a channels
        // row must not translate anything on the way in.
        $channelsOnly = RenameMaps::fromPairs([
            ['old' => 'design.type-coverage#design.type-coverage.param', 'new' => 'design.param-type-coverage#design.param-type-coverage', 'source' => 'channels.tsv'],
        ]);
        $this->assert(!$channelsOnly->isIdentity(), 'a channels row is a rename');
        $this->same(
            '--only-rule=design.param-type-coverage',
            $channelsOnly->reverse('--only-rule=design.param-type-coverage'),
            'and it is never applied backwards, so an input naming the new name survives',
        );
        $this->same(
            'design.param-type-coverage#design.param-type-coverage',
            $channelsOnly->forward('design.type-coverage#design.type-coverage.param'),
            'while forward it translates the whole key',
        );
    }

    /**
     * The keys the HTML report's embedded payload publishes compared fields
     * under, pinned against the one place that writes them.
     *
     * `Gate::spellingsOf()` carries three aliases because the payload spells
     * `rule`, `code` and `symbol` as `ruleName`, `violationCode` and
     * `symbolPath`. A table like that rots silently: if the payload renames a
     * key, `delta-overreach` stops reading that field on the HTML surface and
     * nothing says so. So the alias is asserted to occur in the partitioner,
     * and the tuple's own spelling is asserted **not** to — the point of the
     * alias is that the payload does not use the tuple's name.
     */
    private function htmlPayloadVocabulary(): void
    {
        $partitioner = $this->candidateRoot . '/src/Reporting/Formatter/Html/HtmlViolationPartitioner.php';
        $source = Fs::read($partitioner);

        foreach (['rule' => 'ruleName', 'code' => 'violationCode', 'symbol' => 'symbolPath'] as $field => $alias) {
            $this->assert(
                str_contains($source, "'" . $alias . "' =>"),
                'the HTML payload still publishes ' . $field . ' as ' . $alias,
            );
            $this->assert(
                !str_contains($source, "'" . $field . "' =>"),
                'and still does not publish it under the tuple spelling ' . $field,
            );
        }
    }

    /**
     * The reason this diff has hunks at all, which is the repair Ш4b made to
     * its own instrument and the one nothing covered.
     *
     * Two changes at opposite ends of an artifact used to be reported as one
     * hunk spanning everything between them, so `delta-too-large` counted
     * hundreds of identical lines as changed and refused a declaration that had
     * nothing left to declare. Every case here is written as the pair
     * "what the diff says" and "what it must not say".
     */
    private function multiHunkDiff(): void
    {
        $left = "head\n" . implode("\n", array_map(static fn(int $i): string => 'same ' . $i, range(1, 40))) . "\ntail\n";
        $right = str_replace(["head\n", "\ntail\n"], ["HEAD\n", "\nTAIL\n"], $left);

        $diff = ExactDiff::between($left, $right, 'candidate', 'reference (mapped)');

        $this->same(2, substr_count($diff->render(), "\n@@ "), 'two changes far apart are two hunks, not one span');
        $this->same(4, $diff->changedLineCount(), 'the identical lines between two hunks are not counted as changed');
        $this->assert(
            !str_contains($diff->render(), 'same 20'),
            'the padding between two hunks is not emitted at all',
        );
        $this->same(
            [['head', 'HEAD'], ['tail', 'TAIL']],
            $diff->pairs(),
            'pairs() pairs inside each hunk, which is what delta-overreach reads',
        );
        $this->assert(
            str_contains($diff->render(), '@@ -1,1 +1,1 @@') && str_contains($diff->render(), '@@ -42,1 +42,1 @@'),
            'each hunk carries the line it starts at on both sides',
        );

        // The anchor floor, stated: a shared run shorter than it stays inside
        // its hunk and IS counted, which is why the class docblock no longer
        // claims that nothing identical is counted.
        $short = ExactDiff::between("a\nx\nb\nc\ny\nd\n", "A\nx\nb\nc\nY\nd\n", 'l', 'r');
        $this->same(1, substr_count($short->render(), "\n@@ "), 'a shared run below the anchor is not split on');
        $this->same(10, $short->changedLineCount(), 'and it is counted on both sides, padding included');

        // The budget is a refusal, not a silent downgrade to one hunk. Both
        // edges have to move: the span the search works on is what is left
        // after the shared head and tail are trimmed, so a change at one end
        // alone leaves nothing to spend a budget on.
        $wide = static fn(string $first, string $last): string => $first . "\n"
            . implode("\n", array_map(static fn(int $i): string => 'line ' . $i, range(1, 12100)))
            . "\n" . $last . "\n";
        $refused = false;

        try {
            ExactDiff::between($wide('first', 'last'), $wide('FIRST', 'LAST'), 'l', 'r');
        } catch (BudgetExceeded $error) {
            $refused = str_contains($error->getMessage(), 'refused rather than silently downgraded');
        }

        $this->assert($refused, 'a span past the search budget is refused, not emitted as one padded hunk');
    }

    /**
     * The declared delta's own mechanics: an exact diff, and the staleness that
     * keeps a declaration honest.
     */
    private function declaredDelta(): void
    {
        $diff = ExactDiff::between("a\nb\nc\n", "a\nB\nc\n", 'candidate', 'reference (mapped)');
        $this->same(2, $diff->changedLineCount(), 'an exact diff counts both sides of the change');
        $this->same(
            "--- candidate\n+++ reference (mapped)\n@@ -2,1 +2,1 @@\n-b\n+B\n",
            $diff->render(),
            'the exact diff is the whole change, with no context and no clipping',
        );
        $this->assert(
            ExactDiff::between("a\n", "a\n", 'l', 'r')->isEmpty(),
            'two equal artifacts have no exact diff',
        );

        $long = str_repeat('x', 600);
        $detail = ExactDiff::between('{"a":"' . $long . '1"}', '{"a":"' . $long . '2"}', 'l', 'r')->tokenDetail();
        $this->assert($detail !== [], 'a line too long to read as a line also gets a token diff');

        $unclipped = ExactDiff::between($long . "1\n", $long . "2\n", 'l', 'r')->render();
        $this->assert(str_contains($unclipped, $long . '1'), 'a long line is declared whole, never clipped');

        $this->prefixShadowing();
        $this->multiHunkDiff();
        $this->htmlPayloadVocabulary();

        // Loading refuses a row whose reason is still "?", so a loaded index is
        // already an explained one. What the self-test adds is that every
        // declared surface carries a diff to compare against: an index row
        // pointing at an empty file would make `delta-mismatch` unreachable for
        // that surface. Ш4c declares no delta — see the note on the tracked
        // maps above — so the tracked index is empty and there is no surface
        // left to check a diff file for.
        $delta = DeclaredDelta::load($this->candidateRoot . '/finding-gate');
        $this->assert($delta->isEmpty(), 'this step declares no delta, so the tracked index is empty');
        $this->same([], $delta->surfaces(), 'an empty declared delta claims no surface');

        foreach ($delta->surfaces() as $surface) {
            $this->assert(
                ($delta->claim($surface) ?? '') !== '',
                'the declared delta of ' . $surface . ' is a diff, not an empty file',
            );
        }
        $this->same(null, $delta->claim('case:smells|format:json'), 'a surface nothing declares claims nothing');
    }

    private function normalization(): void
    {
        $rules = [
            new NormalizationRule('format:json', 'meta.timestamp', NormalizationRule::KIND_JSON_PATH, 'test'),
            new NormalizationRule('format:json', 'violations.*.seenAt', NormalizationRule::KIND_JSON_PATH, 'test'),
            new NormalizationRule('format:json', 'meta.absent', NormalizationRule::KIND_JSON_PATH, 'test'),
            new NormalizationRule('format:html', 'project.generatedAt', NormalizationRule::KIND_HTML_REPORT_DATA_PATH, 'test'),
            new NormalizationRule('format:summary', '~^(Analysed in ).*()$~m', NormalizationRule::KIND_LINE_REGEX, 'test'),
        ];
        $normalization = Normalization::fromRules($rules);

        $json = $normalization->normalize('format:json', '{"meta":{"timestamp":"now","version":"1"},"violations":[{"seenAt":"a"},{"seenAt":"b"}]}');
        $this->assert(str_contains($json, '"timestamp": "' . Normalization::REDACTED . '"'), 'a json-path rule redacts its field');
        $this->assert(str_contains($json, '"version": "1"'), 'a json-path rule redacts nothing else');
        $this->same(2, substr_count($json, '"seenAt": "' . Normalization::REDACTED . '"'), 'a `*` segment covers every element of a list');

        $html = $normalization->normalize(
            'format:html',
            '<script type="application/json" id="report-data">{"project":{"generatedAt":"then","name":"x"}}</script>',
        );
        $this->assert(str_contains($html, Normalization::REDACTED), 'an html rule reaches into the embedded report data');
        $this->assert(str_contains($html, '"name": "x"'), 'an html rule leaves its siblings alone');

        $summary = $normalization->normalize('format:summary', "Analysed in 1.2s\nAnalysed nothing else\n");
        $this->same("Analysed in " . Normalization::REDACTED . "\nAnalysed nothing else\n", $summary, 'a line-regex rule redacts only the varying part');

        $stale = array_map(static fn(NormalizationRule $rule): string => $rule->locator, $normalization->staleRules());
        $this->same(['meta.absent'], $stale, 'a rule that matched nothing is reported stale, and one that matched is not');
    }

    private function deriver(): void
    {
        $rules = NormalizationDeriver::derive([
            ['case:x|format:json' => '{"meta":{"timestamp":"a","version":"1"}}'],
            ['case:x|format:json' => '{"meta":{"timestamp":"b","version":"1"}}'],
        ]);
        $this->same(1, \count($rules), 'the deriver emits one row per diverging field');
        $this->same('meta.timestamp', $rules[0]->locator, 'the row names the field by path');
        $this->same(NormalizationRule::KIND_JSON_PATH, $rules[0]->kind, 'a JSON surface derives a json-path row');

        $lines = NormalizationDeriver::derive([
            ['case:x|format:summary' => "Analysed in 1.2s\n"],
            ['case:x|format:summary' => "Analysed in 1.3s\n"],
        ]);
        $this->same(1, \count($lines), 'a text surface derives one row per diverging line');
        $this->assert(
            preg_match($lines[0]->locator, 'Analysed in 9.9s') === 1,
            'the derived locator matches the same line with another value',
        );
        $this->assert(
            preg_match($lines[0]->locator, 'Elapsed 1.2s') !== 1,
            'the derived locator is anchored on the field label, not on a substring',
        );

        $this->assert(
            self::throws(static fn(): mixed => NormalizationDeriver::derive([
                ['case:x|format:json' => '{"violations":[{"a":1}]}'],
                ['case:x|format:json' => '{"violations":[{"a":1},{"a":2}]}'],
            ])),
            'a structural divergence is refused rather than normalized away',
        );
    }

    private function tuple(): void
    {
        $derived = EquivalenceTuple::derive($this->candidateRoot);
        $tracked = EquivalenceTuple::load($this->candidateRoot . '/finding-gate/equivalence-tuple.tsv');
        $this->assert($derived->fields !== [], 'the tuple derivation reads fields out of the publishing code');
        $this->assert($tracked->equals($derived), 'the tracked tuple is what the publishing code publishes');
    }

    private function fingerprints(): void
    {
        $this->same(
            ['channel:subject'],
            Fingerprints::expected([['channel' => 'channel', 'subject' => 'subject', 'occurrence' => null, 'edge' => null]]),
            'a plain finding fingerprints as channel:subject',
        );
        $this->same(
            ['channel:subject:occ'],
            Fingerprints::expected([['channel' => 'channel', 'subject' => 'subject', 'occurrence' => 'occ', 'edge' => null]]),
            'an occurrence key joins the fingerprint',
        );
        $this->same(
            ['channel:subject:extends:Target'],
            Fingerprints::expected([['channel' => 'channel', 'subject' => 'subject', 'edge' => ['type' => 'extends', 'target' => 'Target']]]),
            'a typed edge joins as type:target',
        );
        $this->same(
            ['channel:subject:untyped-edge:6:Target'],
            Fingerprints::expected([['channel' => 'channel', 'subject' => 'subject', 'edge' => ['target' => 'Target']]]),
            'an untyped edge carries its target length, exactly as Violation::getFingerprint() composes it',
        );
        $this->same(
            [md5('channel:subject')],
            Fingerprints::md5Of(['channel:subject']),
            'the GitLab fingerprint is the md5 of the same string',
        );
    }

    private function verdicts(): void
    {
        $green = new GateReport();
        $this->same(GateReport::VERDICT_GREEN, $green->verdict(), 'an unrestricted run with no failure is green');
        $this->same(GateReport::EXIT_GREEN, $green->exitCode(), 'green exits 0');

        $partial = new GateReport();
        $partial->limit('the corpus was restricted');
        $this->same(GateReport::VERDICT_PARTIAL, $partial->verdict(), 'a restricted run is partial, not green');
        $this->same(GateReport::EXIT_PARTIAL, $partial->exitCode(), 'partial has its own exit code');
        $rendered = $partial->render();
        $this->assert(str_contains($rendered, 'PARTIAL'), 'a partial run says so');
        $this->assert(
            !str_contains($rendered, 'finding-equivalent under the declared maps'),
            'a partial run does not print the full-equivalence verdict',
        );

        $red = new GateReport();
        $red->limit('the corpus was restricted');
        $red->fail(FailureClass::RUN_FAILED, 'scope', 'detail');
        $this->same(GateReport::VERDICT_RED, $red->verdict(), 'a failure outranks a restriction');
        $this->same(GateReport::EXIT_RED, $red->exitCode(), 'red exits 1');
    }

    private function surfaces(): void
    {
        $this->same('format:json', Surfaces::surfaceClass('case:smells|format:json'), 'a format is its own surface class');
        $this->same('explain', Surfaces::surfaceClass('case:smells|explain:declaration:class:A@a.php'), 'every explained subject shares one surface class');
        $this->same('stderr', Surfaces::surfaceClass('case:smells|stderr:format:json'), 'every stream of errors shares one surface class');
        $this->same(\count(FailureClass::ALL), \count(array_unique(FailureClass::ALL)), 'the failure vocabulary has no duplicate');
    }

    /**
     * The gate deletes reference worktrees and scratch directories, so a delete
     * that walks a symlink deletes outside the tree it was given.
     */
    private function removal(): void
    {
        $root = Fs::temporaryDirectory('self-test-removal-');
        $outside = $root . '/outside';
        mkdir($outside);
        Fs::write($outside . '/keep.txt', 'keep');
        $victim = $root . '/victim';
        mkdir($victim);
        symlink($outside, $victim . '/link');

        Fs::removeRecursively($victim);

        $this->assert(!is_dir($victim), 'a directory handed to the removal is gone');
        $this->assert(is_file($outside . '/keep.txt'), 'a symlinked directory is unlinked, not walked into and emptied');

        Fs::removeRecursively($root);
    }

    private static function throws(callable $callback): bool
    {
        try {
            $callback();

            return false;
        } catch (GateError) {
            return true;
        }
    }

    private function assert(bool $condition, string $description): void
    {
        if (!$condition) {
            $this->failures[] = $description;
        }
    }

    private function same(mixed $expected, mixed $actual, string $description): void
    {
        if ($expected !== $actual) {
            $this->failures[] = \sprintf(
                '%s (expected %s, got %s)',
                $description,
                json_encode($expected, \JSON_UNESCAPED_SLASHES),
                json_encode($actual, \JSON_UNESCAPED_SLASHES),
            );
        }
    }
}

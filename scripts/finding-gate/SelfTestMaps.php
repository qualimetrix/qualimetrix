<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The declared maps: what a row may look like, what it translates and where, and the ambiguities a
 * set of rows is refused for.
 */
final class SelfTestMaps extends SelfTestGroup
{
    public function maps(): void
    {
        $empty = RenameMaps::fromPairs([]);
        $this->assert($empty->isIdentity(), 'an empty map set is the identity');
        $this->same('code-smell.eval', $empty->forward('code-smell.eval', 'format:json'), 'identity forward');
        $this->same('code-smell.eval', $empty->reverse('code-smell.eval'), 'identity reverse');

        // About the tracked map files this self-test asserts ONE thing: that they
        // load. Nothing about how many rows they hold, what those rows rename,
        // or what shapes they derive. Loading is a real assertion — it is where
        // a chain, a duplicated source, a duplicated target and a row renaming
        // nothing are refused — and it is the only one that stays true whoever
        // edits the file next.
        //
        // Several stronger-sounding spellings failed on later valid inputs
        // rather than on defects: a named row, then "the tracked maps are not
        // the identity" despite four header-only files, then "the tracked rows
        // derive no split" despite a producer move that derives a split by
        // construction — which is what stops the half
        // `computed.health` being substituted textually over every reference
        // mention of it). The pattern is one mistake wearing three faces: what a
        // step happens to declare is a fact about that step, and a self-test on
        // the machinery is entitled to no opinion about it. A fourth face would
        // be any assertion here that reads the file's contents at all.
        //
        // Every shape a row can have is therefore proved on synthetic pairs,
        // where the input cannot go stale because the case carries it: chains,
        // duplicate sources, duplicate targets and rows renaming nothing below
        // in this method; collapse and split in {@see ambiguities()}; the
        // producer move, its staleness credit and the collapse that moves
        // nothing in {@see producerMoves()}.
        $refusal = null;

        try {
            RenameMaps::load(
                $this->candidateRoot . '/finding-gate/maps',
                MetricVocabulary::ofTree($this->candidateRoot),
            );
        } catch (GateError $error) {
            $refusal = $error->getMessage();
        }

        $this->assert($refusal === null, 'the tracked maps do not load: ' . ($refusal ?? ''));

        // One row, and it is the whole key: the halves are expanded from it, so
        // the two spellings of one rename can never be declared out of step.
        $channel = RenameMaps::fromPairs([
            ['old' => 'design.type-coverage#design.type-coverage.param', 'new' => 'design.param-typing#design.param-typing', 'source' => 'channels.tsv'],
        ]);
        $this->same(
            'design.param-typing#design.param-typing',
            $channel->forward('design.type-coverage#design.type-coverage.param', 'format:json'),
            'a whole channel key maps before its halves do',
        );
        $this->same(
            '"rule": "design.param-typing"',
            $channel->forward('"rule": "design.type-coverage"', 'format:json'),
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
            $channel->forward('source="qmx.design.type-coverage.param"', 'format:json'),
            'a prefixed spelling of the code is translated too',
        );
        $prefixOnly = RenameMaps::fromPairs([
            ['old' => 'code-smell.eval#code-smell.eval', 'new' => 'code-smell.eval#smell.eval', 'source' => 'channels.tsv'],
        ]);
        $prefixOnly->forward('source="qmx.code-smell.eval"', 'format:json');
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
            $inputs->forward('--type-coverage-param-warning (--rule-opt=design.type-coverage:param_warning=…)', 'format:json'),
            'an input row also applies forward, because the rules snapshot prints the same tokens',
        );

        $this->multivaluedInput();

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
            $channel->forward('"channel":"design.type-coverage#design.type-coverage.property"', 'format:json'),
            'a row does not translate a longer name that merely starts with it',
        );

        $levels = RenameMaps::fromPairs([
            ['old' => 'complexity.cyclomatic', 'new' => 'complexity.ccn', 'source' => 'metric-keys.tsv'],
        ]);
        $this->same(
            '"complexity.cyclomatic.callable" and "complexity.ccn"',
            $levels->forward('"complexity.cyclomatic.callable" and "complexity.cyclomatic"', 'format:json'),
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
            $cascade->forward('old.rule#a.code', 'format:json'),
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
                ['old' => 'a.one', 'new' => 'z.one', 'source' => 'symbols.tsv'],
                ['old' => 'b.one', 'new' => 'z.one', 'source' => 'symbols.tsv'],
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
        $stale->forward('nothing this row can translate', 'format:json');
        $this->same(
            ['metric-keys.tsv: "never.observed" -> "nor.published"'],
            $stale->staleRows(),
            'a row that translated nothing is reported stale',
        );
        $this->same([], $levels->staleRows(), 'a row that did translate something is not');

        // A case runs in a worker with a RenameMaps of its own, and a row whose
        // only work is on that case's input fires there and nowhere else. The
        // parent judges staleness, so the credit has to travel; without it
        // `root-key-renamed` — a control whose whole subject is an input-only
        // translation — reported its own row stale and failed for years.
        $inWorker = RenameMaps::fromPairs([
            ['old' => 'suppress_namespaces:', 'new' => 'suppress_ns:', 'source' => 'inputs.tsv'],
        ]);
        $inWorker->reverse("suppress_ns:\n  - Corpus\\X\n");
        $this->same(
            ['inputs.tsv: "suppress_namespaces:" -> "suppress_ns:"' => 1],
            $inWorker->firedRows(),
            'a map reports what it translated, keyed by the row that did it',
        );

        $inParent = RenameMaps::fromPairs([
            ['old' => 'suppress_namespaces:', 'new' => 'suppress_ns:', 'source' => 'inputs.tsv'],
        ]);
        $this->same(
            ['inputs.tsv: "suppress_namespaces:" -> "suppress_ns:"'],
            $inParent->staleRows(),
            'a row that fired only in another process is stale until it is credited',
        );
        $inParent->creditRowsFiredElsewhere($inWorker->firedRows());
        $this->same([], $inParent->staleRows(), 'and is not stale once the worker\'s credit arrives');

        // Silently dropping a credit for a row this process does not declare
        // would report a live row as stale — the failure the credit exists to
        // remove, arriving by a different door.
        $refused = false;

        try {
            $inParent->creditRowsFiredElsewhere(['inputs.tsv: "never" -> "declared"' => 1]);
        } catch (GateError) {
            $refused = true;
        }

        $this->same(true, $refused, 'crediting a row this process does not declare is refused');

        $symbols = RenameMaps::fromPairs([
            ['old' => 'Qualimetrix\\Analysis\\Finding\\Contract\\Violation', 'new' => 'Qualimetrix\\Analysis\\Finding\\Contract\\Finding', 'source' => 'symbols.tsv'],
        ]);
        $this->same(
            '"subject": "declaration:class:Qualimetrix\\\\Analysis\\\\Finding\\\\Contract\\\\Finding@src/x.php"',
            $symbols->forward('"subject": "declaration:class:Qualimetrix\\\\Analysis\\\\Finding\\\\Contract\\\\Violation@src/x.php"', 'format:json'),
            'a symbol row maps its JSON-escaped form as well as its raw form',
        );
    }

    /**
     * The fourth `inputs.tsv` shape: a configuration key exactly as a YAML
     * document writes it, colon included.
     *
     * The other three shapes all require a dot, so a bare configuration key like
     * `suppress_namespaces` matched none of them — the row that would state such
     * a rename had no shape to be written in at all.
     */
    public function documentKeyInputForm(): void
    {
        $this->assert(
            !self::throws(static fn(): mixed => RenameMaps::fromPairs([
                ['old' => 'suppress_namespaces:', 'new' => 'suppress_ns:', 'source' => RenameMaps::INPUTS],
            ])),
            'a configuration key as the document writes it, with its trailing colon, is a whole input token',
        );
        $this->assert(
            self::throws(static fn(): mixed => RenameMaps::fromPairs([
                ['old' => 'suppress_namespaces', 'new' => 'suppress_ns', 'source' => RenameMaps::INPUTS],
            ])),
            'the same key without its colon is still refused: an undotted bare word is not a whole token',
        );
        $this->assert(
            !self::throws(static fn(): mixed => RenameMaps::fromPairs([
                ['old' => 'design.type-coverage:param_warning', 'new' => 'design.param-typing:warning', 'source' => RenameMaps::INPUTS],
            ])),
            'a rule and its option key still matches the first shape, not the fourth',
        );

        $documentKey = RenameMaps::fromPairs([
            ['old' => 'suppress_namespaces:', 'new' => 'suppress_ns:', 'source' => RenameMaps::INPUTS],
        ]);

        // The form is deliberately blind to indent: a root key and a per-rule
        // key of the same spelling are one token in the document's own grammar,
        // and this is what lets one row address both levels of
        // finding-gate/cases/rule-exclusion-ledger/qmx.yaml at once.
        $this->same(
            "suppress_namespaces:\n  - App\nrules:\n  some.rule:\n    suppress_namespaces:\n      - App\\Sub",
            $documentKey->reverse("suppress_ns:\n  - App\nrules:\n  some.rule:\n    suppress_ns:\n      - App\\Sub"),
            'the row translates the key at root indent and at per-rule indent alike',
        );

        // The counterexample the plan requires: --rule-opt writes a per-rule
        // option as "rule:option=value", with an "=" after the option name, never
        // a second ":" — so the whole-token text this shape declares, ending in
        // ":", is not a substring of it at all.
        $this->same(
            '--rule-opt=some.rule:suppress_ns=value',
            $documentKey->reverse('--rule-opt=some.rule:suppress_ns=value'),
            'the row does not fire inside a --rule-opt argument, where the same name is followed by "=" rather than ":"',
        );

        // The known counterexample on the forward side: FindingFilterOrchestrator
        // prints the old per-rule vocabulary in one stderr sentence, and the
        // writing "suppress_paths:" is right there in it — but a "/" precedes it,
        // and "/" continues a name, so the left boundary refuses the match. This
        // is not something A1 fixes; A3 covers the surface with a declared delta.
        $pathsKey = RenameMaps::fromPairs([
            ['old' => 'suppress_paths:', 'new' => 'suppress-paths:', 'source' => RenameMaps::INPUTS],
        ]);
        $stderrLine = '<info>3 violation(s) suppressed by per-rule suppress_namespaces/suppress_namespace_channels/suppress_paths:</info>';
        $this->same(
            $stderrLine,
            $pathsKey->forward($stderrLine, 'stderr'),
            'the writing "suppress_paths:" is present in this stderr artifact, and forward substitution does not'
            . ' touch it: "/" precedes it and continues a name',
        );
    }

    /**
     * The fifth map: a string value of an enumerable report field.
     *
     * `format:suppressed` is the one surface {@see RenameMaps::SURFACES}
     * declares for it, the substitution is quoted only, and there is no bare
     * spelling — {@see RenameMaps} excludes `REPORT_VALUES` from the role every
     * other map gets, exactly as it excludes `METRIC_KEYS`.
     */
    public function reportValues(): void
    {
        $values = RenameMaps::fromPairs([
            ['old' => 'namespace-suppression', 'new' => 'namespace-block', 'source' => RenameMaps::REPORT_VALUES],
        ]);

        $this->same(
            '"mechanism": "namespace-block"',
            $values->forward('"mechanism": "namespace-suppression"', 'format:suppressed'),
            'a report-values row translates the quoted value',
        );
        $this->same(
            'namespace-suppression is a report value',
            $values->forward('namespace-suppression is a report value', 'format:suppressed'),
            'and there is no bare substitution: the same word unquoted, as prose beside it might carry, is left alone',
        );
        $this->same(
            '"mechanism": "namespace-suppression"',
            $values->forward('"mechanism": "namespace-suppression"', 'format:json'),
            'the row reaches only the surface it is declared for: format:json publishes no report value',
        );
        $this->same(
            '"mechanism": "namespace-block"',
            $values->reverse('"mechanism": "namespace-block"'),
            'the map is forward-only: reverse leaves the new spelling exactly as it found it',
        );

        $this->assert(
            self::throws(static fn(): mixed => RenameMaps::fromPairs([
                ['old' => 'Namespace-Suppression', 'new' => 'namespace-block', 'source' => RenameMaps::REPORT_VALUES],
            ])),
            'an uppercase report value is refused: the product\'s own vocabulary is plain kebab-case',
        );

        foreach (['a#b', 'a:b', 'a--b', 'a.b', '--a', 'a-'] as $invalid) {
            $this->assert(
                self::throws(static fn(): mixed => RenameMaps::fromPairs([
                    ['old' => $invalid, 'new' => 'ok-value', 'source' => RenameMaps::REPORT_VALUES],
                ])),
                \sprintf('"%s" is refused: not a plain kebab-case report value', $invalid),
            );
        }

        // The tracked file is read through the gate's own loader, exactly as the
        // other four maps are proved to load in maps() above.
        $refusal = null;

        try {
            RenameMaps::load(
                $this->candidateRoot . '/finding-gate/maps',
                MetricVocabulary::ofTree($this->candidateRoot),
            );
        } catch (GateError $error) {
            $refusal = $error->getMessage();
        }

        $this->assert($refusal === null, 'the tracked report-values map does not load: ' . ($refusal ?? ''));

        $idle = RenameMaps::fromPairs([
            ['old' => 'never-observed', 'new' => 'nor-published', 'source' => RenameMaps::REPORT_VALUES],
        ]);
        $idle->forward('nothing this row can translate', 'format:suppressed');
        $this->same(
            [RenameMaps::REPORT_VALUES . ': "never-observed" -> "nor-published"'],
            $idle->staleRows(),
            'a report-value row that translated nothing is reported stale',
        );
        $this->same([], $values->staleRows(), 'and one that fired is not');

        $this->assert(
            self::throws(static fn(): mixed => RenameMaps::fromPairs([
                ['old' => 'a-value', 'new' => 'b-value', 'source' => RenameMaps::REPORT_VALUES],
                ['old' => 'a-value', 'new' => 'c-value', 'source' => RenameMaps::REPORT_VALUES],
            ])),
            'two report-value rows renaming one value stay refused, exactly as for the other maps',
        );

        // codex-01: a report-values row colliding on (old, new) with another
        // map's row must not silently merge into one declaration — that would
        // hand REPORT_VALUES the other role's unrestricted surface and bare
        // spelling.
        $this->assert(
            self::throws(static fn(): mixed => RenameMaps::fromPairs([
                ['old' => 'shared-spelling', 'new' => 'shared-target', 'source' => RenameMaps::REPORT_VALUES],
                ['old' => 'shared-spelling', 'new' => 'shared-target', 'source' => RenameMaps::CHANNELS],
            ])),
            'a report-value row colliding with a channels row on the same (old, new) spelling is refused'
            . ' at load, not merged into a bare, every-surface substitution',
        );
    }

    /**
     * The input row a split producer needs, and the three obligations it keeps.
     *
     * One old token, several new ones: backwards — the direction `inputs.tsv`
     * exists for — the candidate's several names all restate as the one name the
     * reference knows, so the row is a function exactly where it is applied.
     * Forwards there is nothing to apply and the row refuses out loud. Without
     * it a case addressing a split producer by name has no writable row at all:
     * one old name and three new ones.
     */
    private function multivaluedInput(): void
    {
        $images = 'design.param-type-coverage|design.property-type-coverage|design.return-type-coverage';
        $split = RenameMaps::fromPairs([
            ['old' => 'design.type-coverage', 'new' => $images, 'source' => 'inputs.tsv'],
        ]);

        foreach (['param', 'property', 'return'] as $aspect) {
            $this->same(
                '--disable-rule=design.type-coverage',
                $split->reverse('--disable-rule=design.' . $aspect . '-type-coverage'),
                'each new name of a split producer restates as the one name the reference knows (' . $aspect . ')',
            );
        }

        $this->same(
            ['--disable-rule=design.type-coverage'],
            $split->reverseArguments(['--disable-rule=design.return-type-coverage']),
            'and so does an argument list',
        );
        $this->same(
            'design.type-coverage-of-something',
            $split->forward('design.type-coverage-of-something', 'format:json'),
            'the row still translates a whole token only, so a longer name it merely starts is left alone',
        );

        // The refusal is the point: with three images there is no forward
        // translation, and picking the first would publish a rename no row
        // declared.
        $this->assert(
            self::throws(static fn(): mixed => $split->forward('"rule": "design.type-coverage"', 'format:json')),
            'the forward direction of a multivalued row refuses out loud instead of taking the first image',
        );
        $this->assert(
            self::throws(static fn(): mixed => $split->forward('source="qmx.design.type-coverage"', 'format:json')),
            'and refuses the prefixed spelling too, rather than silently not seeing it',
        );

        $rejected = [
            'an image that is not a whole token' => ['design.type-coverage', 'design.param-type-coverage|warning'],
            'a repeated image' => ['design.type-coverage', 'design.param-type-coverage|design.param-type-coverage'],
            'an empty image' => ['design.type-coverage', 'design.param-type-coverage|'],
            'several tokens on the old side' => ['design.a-coverage|design.b-coverage', 'design.type-coverage'],
        ];

        foreach ($rejected as $description => [$old, $new]) {
            $this->assert(
                self::throws(static fn(): mixed => RenameMaps::fromPairs([
                    ['old' => $old, 'new' => $new, 'source' => 'inputs.tsv'],
                ])),
                $description . ' is refused when the map loads',
            );
        }

        $this->assert(
            self::throws(static fn(): mixed => RenameMaps::fromPairs([
                ['old' => 'a.one#a.one', 'new' => 'b.one#b.one|c.one#c.one', 'source' => 'channels.tsv'],
            ])),
            'only an input row may be multivalued: a channel map is forward-only, so it has no use for the shape',
        );
        $this->assert(
            self::throws(static fn(): mixed => RenameMaps::fromPairs([
                ['old' => 'design.type-coverage', 'new' => $images, 'source' => 'inputs.tsv'],
                ['old' => 'design.param-type-coverage', 'new' => 'design.param-typing', 'source' => 'inputs.tsv'],
            ])),
            'a chain through one image of a multivalued row is refused like any other chain',
        );

        // Staleness, and the decision inside it: every image has to have
        // translated something. One image out of three would leave the other two
        // as a standing excuse — which is exactly what map-stale exists to
        // prevent.
        $partial = RenameMaps::fromPairs([
            ['old' => 'design.type-coverage', 'new' => $images, 'source' => 'inputs.tsv'],
        ]);
        $partial->reverse('--disable-rule=design.param-type-coverage');
        $stale = $partial->staleRows();
        $this->same(1, \count($stale), 'a multivalued row with two idle images is stale, not satisfied by the third');
        $this->assert(
            str_contains($stale[0] ?? '', 'design.property-type-coverage')
            && str_contains($stale[0] ?? '', 'design.return-type-coverage')
            && !str_contains($stale[0] ?? '', 'translated nothing into: design.param-type-coverage'),
            'and it names the images that translated nothing, not the row as a whole',
        );

        $whole = RenameMaps::fromPairs([
            ['old' => 'design.type-coverage', 'new' => $images, 'source' => 'inputs.tsv'],
        ]);

        foreach (['param', 'property', 'return'] as $aspect) {
            $whole->reverse('--disable-rule=design.' . $aspect . '-type-coverage');
        }

        $this->same([], $whole->staleRows(), 'a multivalued row every image of which fired is not stale');
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
    public function ambiguities(): void
    {
        $collapse = RenameMaps::fromPairs([
            ['old' => 'complexity.cyclomatic#complexity.cyclomatic.callable', 'new' => 'complexity.cyclomatic#complexity.cyclomatic', 'source' => 'channels.tsv'],
            ['old' => 'complexity.cyclomatic#complexity.cyclomatic.class', 'new' => 'complexity.cyclomatic#complexity.cyclomatic', 'source' => 'channels.tsv'],
        ]);
        $this->same(
            '"complexity.cyclomatic" and "complexity.cyclomatic"',
            $collapse->forward('"complexity.cyclomatic.callable" and "complexity.cyclomatic.class"', 'format:json'),
            'a collapse is allowed forwards, and both codes reach the one new name',
        );
        $this->same([], $collapse->splits(), 'a collapse is not a split');

        // A collapse is refused only where both halves travel backwards. One
        // reversible row and one forward-only row reaching the same name is the
        // deliberate arrangement where a metric key and the channel checking
        // it share one name; backwards only the reversible row is
        // consulted, so the translation is still a function.
        $mixed = RenameMaps::fromPairs([
            ['old' => 'typeCoverage.param', 'new' => 'design.type-coverage.param', 'source' => 'metric-keys.tsv'],
            ['old' => 'design.param-type-coverage', 'new' => 'design.type-coverage.param', 'source' => 'inputs.tsv'],
        ]);
        $this->same(
            '"design.type-coverage.param" and "design.type-coverage.param"',
            $mixed->forward('"typeCoverage.param" and "design.param-type-coverage"', 'format:json'),
            'forwards, both old names reach the one new name',
        );
        $this->same(
            '--rule-opt=design.param-type-coverage:warning=1',
            $mixed->reverse('--rule-opt=design.type-coverage.param:warning=1'),
            'backwards, only the reversible row is consulted, so the input is a function',
        );

        $this->assert(
            self::throws(static fn(): RenameMaps => RenameMaps::fromPairs([
                ['old' => 'design.param-type-coverage', 'new' => 'design.type-coverage.param', 'source' => 'inputs.tsv'],
                ['old' => 'design.param-typing', 'new' => 'design.type-coverage.param', 'source' => 'inputs.tsv'],
            ])),
            'two reversible rows onto one name are still refused: backwards there is no function',
        );

        // And still refused when a forward-only row reaches the same name —
        // the ordinary arrangement where a metric key and the channel checking
        // it share one name. What this pins is that the refusal
        // does not depend on the order the rows happen to load in: the check
        // remembers the last REVERSIBLE row per target rather than the last row,
        // so an intervening forward-only row cannot answer "not reversible" on
        // a reversible row's behalf. Measured 2026-08-28: with the weaker form
        // no input reached the hole either, because normalization does not
        // produce the interleaving it needs — this is the check saying what it
        // means, not a closed exploit.
        $this->assert(
            self::throws(static fn(): RenameMaps => RenameMaps::fromPairs([
                ['old' => 'design.param-type-coverage', 'new' => 'design.type-coverage.param', 'source' => RenameMaps::CHANNELS],
                ['old' => 'design.param-type-coverage', 'new' => 'design.type-coverage.param', 'source' => RenameMaps::INPUTS],
                ['old' => 'typeCoverage.param', 'new' => 'design.type-coverage.param', 'source' => RenameMaps::METRIC_KEYS],
                ['old' => 'design.param-typing', 'new' => 'design.type-coverage.param', 'source' => RenameMaps::INPUTS],
            ])),
            'a forward-only row between two reversible ones does not hide the collapse',
        );

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
            $split->forward('"rule": "design.type-coverage"', 'format:json'),
            'the split half is not translated, because no translation of it is right',
        );
        $this->same(
            '"channel": "design.param-typing#design.param-typing"',
            $split->forward('"channel": "design.type-coverage#design.type-coverage.param"', 'format:json'),
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
     * A row that moves a producer and nothing else: it substitutes nothing, and
     * what keeps it honest is the record it explains.
     *
     * `computed.health#health.complexity -> health.complexity#health.complexity`
     * moves the `rule` field and leaves the `code` field alone. Its halves
     * therefore give it nothing to substitute — the rule half is one side of a
     * split and is left untranslated on purpose, the code half is the same
     * string on both sides and expands into no pair at all — and no surface
     * prints the whole `rule#code` key the row is written as. Judged by
     * substitution alone such a row is idle, and `map-stale` would refuse the
     * only shape a producer move can be declared in.
     *
     * So a row is credited by what it explained as well as by what it
     * substituted, and the credit is per row rather than per split: a row is
     * live because a record of ITS key was explained, never because a sibling
     * of its split was. The two cases below that keep a row stale are what says
     * the relaxation did not become "any row of a live split is live".
     */
    public function producerMoves(): void
    {
        $rows = static fn(): RenameMaps => RenameMaps::fromPairs([
            ['old' => 'computed.health#health.complexity', 'new' => 'health.complexity#health.complexity', 'source' => 'channels.tsv'],
            ['old' => 'computed.health#health.cohesion', 'new' => 'health.cohesion#health.cohesion', 'source' => 'channels.tsv'],
        ]);
        $cohesionRow = 'channels.tsv: "computed.health#health.cohesion" -> "health.cohesion#health.cohesion"';
        $reference = [
            ['subject' => 'namespace:App', 'rule' => 'computed.health', 'code' => 'health.complexity', 'channel' => 'health.complexity'],
            ['subject' => 'namespace:App', 'rule' => 'computed.health', 'code' => 'health.cohesion', 'channel' => 'health.cohesion'],
        ];
        $candidate = [
            ['subject' => 'namespace:App', 'rule' => 'health.complexity', 'code' => 'health.complexity', 'channel' => 'health.complexity'],
            ['subject' => 'namespace:App', 'rule' => 'health.cohesion', 'code' => 'health.cohesion', 'channel' => 'health.cohesion'],
        ];

        $explaining = $rows();
        $this->same([], ChannelSplit::of($explaining)->unexplained($reference, $candidate), 'a producer move is explained record by record');
        $this->same(
            [],
            $explaining->staleRows(),
            'a row that substituted nothing and explained a record is not idle',
        );

        // Fail-closed, stated as its own case: the relaxation credits explaining,
        // not declaring. With the same two rows and nothing for them to explain,
        // both are as stale as they were before the relaxation existed.
        $idle = $rows();
        ChannelSplit::of($idle)->unexplained($reference, []);
        $this->same(
            2,
            \count($idle->staleRows()),
            'a row that substituted nothing and explained nothing is still stale',
        );

        // The candidate publishes one of the two pairs, so one record is
        // explained and the other is not. Credit follows the record, so the row
        // whose record went unmatched stays stale even though its split partner
        // is live.
        $halfMatched = $rows();
        $this->same(
            1,
            \count(ChannelSplit::of($halfMatched)->unexplained($reference, [$candidate[0]])),
            'the pair the candidate never published is split-unmapped',
        );
        $this->same(
            [$cohesionRow],
            $halfMatched->staleRows(),
            'and its row is stale: a declared row is credited by a record it matched, not by being declared',
        );

        // The reference carries one of the two pairs at all, which is the shape
        // the DoD names: two rows of one split, one of them explaining.
        $halfPresent = $rows();
        ChannelSplit::of($halfPresent)->unexplained([$reference[0]], $candidate);
        $this->same(
            [$cohesionRow],
            $halfPresent->staleRows(),
            'the sibling of an explaining row is stale on its own account',
        );

        // Credit is for a movement, not for a match. A row reading
        // `rule#code -> code` collapses the pair into one name and constrains
        // the code only — the rule survives as its own published field — so
        // where that code is what the record already publishes, the row claims
        // nothing and a candidate record matches it without anything having
        // moved. Crediting the match kept such a row out of `map-stale`, which
        // is the state it was in before the credit existed.
        $typingRow = 'channels.tsv: "computed.health#health.typing" -> "health.typing"';
        $withStandstill = RenameMaps::fromPairs([
            ['old' => 'computed.health#health.complexity', 'new' => 'health.complexity#health.complexity', 'source' => 'channels.tsv'],
            ['old' => 'computed.health#health.cohesion', 'new' => 'health.cohesion#health.cohesion', 'source' => 'channels.tsv'],
            ['old' => 'computed.health#health.typing', 'new' => 'health.typing', 'source' => 'channels.tsv'],
        ]);
        $standstill = ['subject' => 'namespace:App', 'rule' => 'computed.health', 'code' => 'health.typing', 'channel' => 'health.typing'];
        $this->same(
            [],
            ChannelSplit::of($withStandstill)->unexplained(
                [...$reference, $standstill],
                [...$candidate, $standstill],
            ),
            'a record whose declared target it already publishes is explained all the same',
        );
        $this->same(
            [$typingRow],
            $withStandstill->staleRows(),
            'but its row is credited with nothing: what earns the credit is a movement, not a match',
        );

        // Credit travels by name, so a name nothing declares is refused rather
        // than quietly keeping some row alive. `ChannelSplit` passes a key it
        // has just read out of the declared ones, so this is a contract on the
        // method and not a branch the gate takes — asserted here because that
        // is the only place it can be.
        $this->assert(
            self::throws(static function () use ($rows): void {
                $rows()->creditExplanation('computed.health#health.never-declared');
            }),
            'a credit named by no declared row is refused',
        );
    }
}

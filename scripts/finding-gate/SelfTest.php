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
        $this->metricKeys();
        $this->channelRowShapes();
        $this->claims();
        $this->coverage();
        $this->levelVocabulary();
        $this->ambiguities();
        $this->producerMoves();
        $this->writesNeverFollowHardlinks();
        $this->declaredDelta();
        $this->declaredFieldMoves();
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
        $this->same('code-smell.eval', $empty->forward('code-smell.eval', 'format:json'), 'identity forward');
        $this->same('code-smell.eval', $empty->reverse('code-smell.eval'), 'identity reverse');

        // About the tracked map files this self-test asserts ONE thing: that they
        // load. Nothing about how many rows they hold, what those rows rename,
        // or what shapes they derive. Loading is a real assertion — it is where
        // a chain, a duplicated source, a duplicated target and a row renaming
        // nothing are refused — and it is the only one that stays true whoever
        // edits the file next.
        //
        // Three spellings have now claimed more than that, and all three died on
        // the NEXT step rather than on a defect: a named Ш5b row, then "the
        // tracked maps are not the identity" (falsified by the repair after Ш5c,
        // which renamed nothing and tracked four header-only files), then "the
        // tracked rows derive no split" (falsified by Ш5d, whose producer move
        // derives a split by construction — that is what stops the half
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
     * The unit of a case's claim: a channel AND the level it fired at.
     *
     * Every shape a subject reaches the comparison in is levelled here, and an
     * unknown one stops the run rather than being defaulted — a subject nothing
     * can level is a subject whose level the claim would quietly stop checking.
     * The tracked claims are read through the corpus loader too, so a case.json
     * left in the old shape fails here rather than in a full run.
     */
    /**
     * The three shapes a channels row may take, and the spelling SARIF adds.
     *
     * Ш5b collapses `rule#code` into one name, so the row that declares it has a
     * single name on its new side. The map that could not express that shape
     * refused the row at load time, which would have made the step's own
     * declaration unwritable.
     */
    private function channelRowShapes(): void
    {
        $row = static fn(string $old, string $new): array => [
            'old' => $old,
            'new' => $new,
            'source' => RenameMaps::CHANNELS,
        ];
        $collapse = RenameMaps::fromPairs([$row('cohesion.lcom#cohesion.lcom', 'cohesion.lcom')]);

        $this->same(
            '"cohesion.lcom" and "cohesion.lcom"',
            $collapse->forward('"cohesion.lcom#cohesion.lcom" and "cohesion.lcom"', 'format:json'),
            'a collapse row translates the whole key and leaves the surviving rule name alone',
        );

        $renamed = RenameMaps::fromPairs([$row('cohesion.lcom', 'cohesion.lcom4')]);
        $this->same(
            '"cohesion.lcom4"',
            $renamed->forward('"cohesion.lcom"', 'format:json'),
            'a channel that is already one name is renamed by an ordinary row',
        );

        $this->assert(
            self::throws(static fn(): mixed => RenameMaps::fromPairs([$row('cohesion.lcom', 'cohesion.lcom#cohesion.lcom')])),
            'a row turning one channel name back into a pair is refused',
        );

        $half = RenameMaps::fromPairs([
            $row('complexity.cyclomatic#complexity.cyclomatic.callable', 'complexity.cyclomatic#complexity.cyclomatic.call'),
        ]);
        $this->same(
            '"name": "Complexity Cyclomatic Call"',
            $half->forward('"name": "Complexity Cyclomatic Callable"', 'format:json'),
            'a renamed code half is translated in the title-cased spelling SARIF publishes as a rule name',
        );

        $symbols = RenameMaps::fromPairs([[
            'old' => 'src/old.php',
            'new' => 'src/new.php',
            'source' => RenameMaps::SYMBOLS,
        ]]);
        $this->same(
            'Src/old Php',
            $symbols->forward('Src/old Php', 'format:json'),
            'the title-cased spelling belongs to channel rows only, so a symbol row does not invent one',
        );
    }

    private function claims(): void
    {
        $shapes = [
            'declaration:callable:Corpus\\A::b@src/A.php' => 'callable',
            'declaration:class:Corpus\\A@src/A.php' => 'class',
            'declaration:class:Corpus\\A@src/A.php#2' => 'class',
            'declaration:func:Corpus\\A::helper@src/A.php' => 'callable',
            'class:Corpus\\A' => 'class',
            'file:src/A.php' => 'file',
            'ns:Corpus\\A' => 'namespace',
            'project:' => 'project',
        ];

        foreach ($shapes as $subject => $level) {
            $this->same($level, SubjectLevel::of($subject), 'the level of "' . $subject . '"');
        }

        $this->assert(
            self::throws(static fn(): mixed => SubjectLevel::of('member:Corpus\\A::$b')),
            'a subject shape the gate cannot level stops the run instead of claiming a level for it',
        );
        $this->same(
            'a.rule#a.code@class',
            SubjectLevel::claim('a.rule#a.code', 'class'),
            'a claim entry is the channel and the level, separated by a character no name may contain',
        );
        $this->same(
            'a.rule#a.code',
            SubjectLevel::channelOf('a.rule#a.code@class'),
            'and the channel is readable back out of it, which is what coverage counts',
        );
        $this->assert(
            self::throws(static function (): void {
                SubjectLevel::assertClaim('a.rule#a.code', 'case.json');
            }),
            'a bare channel name is refused as a claim: the old shape claims less than it looks like it claims',
        );
        $this->assert(
            self::throws(static function (): void {
                SubjectLevel::assertClaim('a.rule#a.code@klass', 'case.json');
            }),
            'and so is a level outside the product\'s own vocabulary',
        );

        $this->claimShapeOnLoad();

        // The tracked corpus is loaded, not described: every case's claim has to
        // be in the pair shape already, or no run of this step can be green.
        $corpus = Corpus::load($this->candidateRoot, []);
        $this->assert($corpus->cases !== [], 'the tracked corpus loads');

        foreach ($corpus->cases as $case) {
            foreach ($case->channels as $entry) {
                SubjectLevel::assertClaim($entry, 'case:' . $case->id);
            }
        }
    }

    /**
     * Coverage has a declared side of its own, and it counts pairs.
     *
     * The universe below is the one the old accounting was blind to, and both
     * verdicts are asserted from it: as pairs it is a shortfall, as the names
     * behind those same pairs it is green. That green is not a curiosity — it is
     * what every run before this check reported for a declared pair that fires
     * in no case and is claimed in no case, so it is kept as the reason the pair
     * accounting exists rather than deleted once it went red.
     *
     * Exercised on a synthetic universe because the alternative is a full
     * comparison run: the pairs a real corpus fires need two trees, and a check
     * that can only be tried by the thing it is supposed to certify is not
     * checked at all.
     */
    private function coverage(): void
    {
        $declared = ['a.rule#a.code@class', 'a.rule#a.code@callable'];
        $observed = ['a.rule#a.code@class'];

        $shortfall = new GateReport();
        ChannelCoverage::check($shortfall, $declared, $observed, incompleteCorpus: false);
        $this->same(
            [FailureClass::COVERAGE_SHORTFALL],
            $shortfall->failureClasses(),
            'a declared pair that fires in no case and is claimed in no case is a coverage shortfall',
        );

        $names = static fn(array $pairs): array => array_values(array_unique(array_map(SubjectLevel::channelOf(...), $pairs)));
        $byName = new GateReport();
        ChannelCoverage::check($byName, $names($declared), $names($observed), incompleteCorpus: false);
        $this->same(
            GateReport::VERDICT_GREEN,
            $byName->verdict(),
            'and the same universe counted by channel name is green, which is what the name accounting reported',
        );

        $downgraded = new GateReport();
        ChannelCoverage::check($downgraded, $declared, $observed, incompleteCorpus: true);
        $this->same(
            GateReport::VERDICT_PARTIAL,
            $downgraded->verdict(),
            '--incomplete-corpus downgrades a pair shortfall exactly as it downgraded a name shortfall',
        );

        $surplus = new GateReport();
        ChannelCoverage::check($surplus, $observed, $declared, incompleteCorpus: false);
        $this->same(
            [FailureClass::COVERAGE_SURPLUS],
            $surplus->failureClasses(),
            'a level a declared channel does not declare is a coverage surplus, not a silent pass',
        );

        // The declared side is derived from two witnesses, so their disagreement
        // is its own answer: a fixture whose levels column drifts from the
        // declaration in code must not read as a corpus that lost a fixture.
        $disagreement = new GateReport();
        ChannelWitness::checkAgreement($disagreement, ['a.rule#a.code@class'], ['a.rule#a.code@callable']);
        $this->same(
            [FailureClass::WITNESS_DISAGREEMENT],
            $disagreement->failureClasses(),
            'two witnesses differing on a level is its own failure, distinct from a coverage shortfall',
        );

        $agreed = new GateReport();
        ChannelWitness::checkAgreement($agreed, ['a.rule#a.code@class'], ['a.rule#a.code@class']);
        $this->same(GateReport::VERDICT_GREEN, $agreed->verdict(), 'and two witnesses agreeing is not a failure');
    }

    /**
     * The gate holds one copy of the level vocabulary, and the product holds the
     * original.
     *
     * Asked of the candidate tree itself, so the check runs wherever
     * `--self-test` runs — including inside `composer check`, which is the only
     * place it can run without a reference tree. The synthetic half proves the
     * comparison bites; the real half proves the tracked copy is current.
     */
    private function levelVocabulary(): void
    {
        $drifted = new GateReport();
        ChannelWitness::checkLevelVocabulary($drifted, ['callable', 'klass', 'file', 'namespace', 'project']);
        $this->same(
            [FailureClass::LEVEL_VOCABULARY_DRIFT],
            $drifted->failureClasses(),
            'a level the product does not spell that way is drift, not a matter of taste',
        );

        $current = new GateReport();
        ChannelWitness::checkLevelVocabulary($current, (new ChannelWitness($this->candidateRoot))->productLevels());
        $this->same(
            GateReport::VERDICT_GREEN,
            $current->verdict(),
            'the gate\'s tag map spells every level the way this tree\'s SymbolLevel does',
        );
    }

    /**
     * What `case.json` may claim, checked where a case is loaded.
     *
     * A repeated pair is refused rather than tolerated: the observed set is
     * deduplicated by pair, so a claim listing one twice can never be satisfied,
     * and a claim nothing can satisfy is the shape a half-done migration leaves
     * behind. Exercised on a written case rather than on the loader's argument,
     * because loading is where the tracked corpus meets it.
     */
    private function claimShapeOnLoad(): void
    {
        $root = Fs::temporaryDirectory('self-test-claim-');
        $directory = $root . '/probe';
        mkdir($directory);
        Fs::write($directory . '/qmx.yaml', "suppress_paths: []\n");

        $write = static function (array $channels) use ($directory): void {
            Fs::write($directory . '/case.json', (string) json_encode([
                'id' => 'probe',
                'description' => 'a written case, so the claim shape is checked where a case is loaded',
                'paths' => ['src'],
                'config' => 'qmx.yaml',
                'channels' => $channels,
            ]));
        };

        $write(['a.code@class', 'a.code@callable']);
        $this->assert(
            !self::throws(static fn(): mixed => CaseDefinition::load($directory)),
            'a case claiming one channel at two levels loads: that is the pair the level segment leaves behind',
        );

        $write(['a.code@class', 'a.code@class']);
        $this->assert(
            self::throws(static fn(): mixed => CaseDefinition::load($directory)),
            'a case claiming one pair twice is refused: the observed set could never satisfy it',
        );

        $write(['a.code']);
        $this->assert(
            self::throws(static fn(): mixed => CaseDefinition::load($directory)),
            'and a case claiming a channel with no level is refused when it loads',
        );

        $write(['a.rule#a.code@class']);
        $this->assert(
            self::throws(static fn(): mixed => CaseDefinition::load($directory)),
            'a claim still written as a "rule#code" pair is refused: no channel carries that name',
        );

        Fs::removeRecursively($root);
    }

    /**
     * A metric key is published bare and once per aggregation strategy, and one
     * row has to reach all of those spellings.
     *
     * Everything here is synthetic except the vocabulary's shape: the point is
     * the mechanism, and a row that happens to be tracked tomorrow is a fact
     * about that step. The strategies are named literally so that a case reads
     * as its own input — {@see MetricVocabulary} is what holds the run to the
     * product's real list, and it is proved separately below.
     */
    private function metricKeys(): void
    {
        $strategies = ['avg', 'count', 'max', 'min', 'p5', 'p95', 'sum'];
        $vocabulary = MetricVocabulary::of($strategies);
        $keys = RenameMaps::fromPairs([
            ['old' => 'ccn', 'new' => 'complexity.ccn', 'source' => RenameMaps::METRIC_KEYS],
        ], $vocabulary);

        $this->same(
            '"complexity.ccn"',
            $keys->forward('"ccn"', 'format:json'),
            'a key row translates the bare spelling',
        );

        foreach ($strategies as $strategy) {
            $this->same(
                '"complexity.ccn.' . $strategy . '"',
                $keys->forward('"ccn.' . $strategy . '"', 'format:json'),
                'and the aggregated spelling for ' . $strategy,
            );
        }

        // The closed list is the whole point. An open suffix would make the row
        // a substring rewrite over every longer name starting with the key,
        // which is what a step renaming three keys and declaring one needs to
        // stay green.
        $this->same(
            '"ccn.average" and "ccn.avg.avg" and "ccnx"',
            $keys->forward('"ccn.average" and "ccn.avg.avg" and "ccnx"', 'format:json'),
            'an unknown suffix, a doubled one and a longer name are not translated',
        );

        $idle = RenameMaps::fromPairs([
            ['old' => 'ccn', 'new' => 'complexity.ccn', 'source' => RenameMaps::METRIC_KEYS],
        ], $vocabulary);
        $idle->forward('nothing this row can translate', 'format:json');
        $this->same(
            [RenameMaps::METRIC_KEYS . ': "ccn" -> "complexity.ccn"'],
            $idle->staleRows(),
            'a key row that translated nothing at all is stale',
        );

        $suffixOnly = RenameMaps::fromPairs([
            ['old' => 'ccn', 'new' => 'complexity.ccn', 'source' => RenameMaps::METRIC_KEYS],
        ], $vocabulary);
        $suffixOnly->forward('"ccn.p5"', 'format:json');
        $this->same(
            [],
            $suffixOnly->staleRows(),
            'a key row whose only match was an aggregated spelling is not stale',
        );

        $this->assert(
            self::throws(static fn(): mixed => RenameMaps::fromPairs([
                ['old' => 'ccn', 'new' => 'complexity.ccn', 'source' => RenameMaps::METRIC_KEYS],
                ['old' => 'ccn.sum', 'new' => 'complexity.total-ccn', 'source' => RenameMaps::METRIC_KEYS],
            ], $vocabulary)),
            'a key whose aggregated spelling is another declared key is refused when the map loads',
        );

        $this->assert(
            self::throws(static fn(): mixed => RenameMaps::fromPairs([
                ['old' => 'complexity.cyclomatic#complexity.cyclomatic', 'new' => 'ccn#ccn', 'source' => RenameMaps::METRIC_KEYS],
            ], $vocabulary)),
            'a key row carrying a whole rule#code key is refused: nothing publishes "that.avg"',
        );

        // Measured 2026-08-26, and the reason `metric-keys.tsv` is forward-only:
        // after the vocabulary rename the new key names ARE the rule names the
        // corpus writes into its own arguments, so a reverse pass would hand the
        // reference rules it does not have. `coupling.class-rank` is in all
        // fourteen cases' `--rule-opt` tokens.
        $this->same(
            '--rule-opt=coupling.class-rank:warning=2',
            RenameMaps::fromPairs([
                ['old' => 'classRank', 'new' => 'coupling.class-rank', 'source' => RenameMaps::METRIC_KEYS],
            ], $vocabulary)->reverse('--rule-opt=coupling.class-rank:warning=2'),
            'a key map is not applied backwards, so an argument spelled like a new key is left alone',
        );

        // One name, two roles. `computed.branch_load` is a channel identity and a
        // token the corpus writes into its own configuration, so the step that
        // renames it declares the same pair twice — and the reference cannot be
        // handed the new spelling, which is what the input role is for.
        $roles = RenameMaps::fromPairs([
            ['old' => 'computed.branch_load', 'new' => 'computed.branch-load', 'source' => RenameMaps::CHANNELS],
            ['old' => 'computed.branch_load', 'new' => 'computed.branch-load', 'source' => RenameMaps::INPUTS],
        ], $vocabulary);
        $this->same(
            1,
            \count($roles->declaredRows()),
            'a pair declared in two maps is one declaration, not two rows renaming one name',
        );
        $this->same(
            'computed.branch_load:',
            $roles->reverse('computed.branch-load:'),
            'and it is applied backwards, because one of its roles is an input',
        );
        $this->same(
            [],
            $roles->staleRows(),
            'firing once is what a declaration owes, whichever of its roles the occurrence belonged to',
        );

        $this->assert(
            self::throws(static fn(): mixed => RenameMaps::fromPairs([
                ['old' => 'computed.branch_load', 'new' => 'computed.branch-load', 'source' => RenameMaps::CHANNELS],
                ['old' => 'computed.branch_load', 'new' => 'computed.other-name', 'source' => RenameMaps::INPUTS],
            ], $vocabulary)),
            'two maps disagreeing about one name stay refused: that decides nothing',
        );

        // Two declarations reaching one spelling. Neither renames the other's
        // name, so none of the load-time checks on names can see it: what they
        // collide on is a SPELLING one of them only reaches through the `qmx.`
        // prefix checkstyle writes. The refusal has to be at load too — a guard
        // that fires when some caller happens to substitute in that direction has
        // its moment decided by the run, and the reverse direction is not built
        // at all on a run whose reference needs no input translated.
        $this->assert(
            self::throws(static fn(): mixed => RenameMaps::fromPairs([
                ['old' => 'qmx.foo', 'new' => 'other.bar', 'source' => RenameMaps::SYMBOLS],
                ['old' => 'foo', 'new' => 'baz', 'source' => RenameMaps::CHANNELS],
            ], $vocabulary)),
            'two declarations reaching one spelling are refused when the map loads',
        );

        // The half of a live split is deliberately left untranslated so the
        // records under it can be explained instead. An expansion that reached it
        // would translate exactly the spelling the split calls undecidable —
        // measured, it did, so the overlap check is given the dropped halves too.
        $this->assert(
            self::throws(static fn(): mixed => RenameMaps::fromPairs([
                ['old' => 'a.sum#x', 'new' => 'left#x', 'source' => RenameMaps::CHANNELS],
                ['old' => 'a.sum#y', 'new' => 'right#y', 'source' => RenameMaps::CHANNELS],
                ['old' => 'a', 'new' => 'renamed.a', 'source' => RenameMaps::METRIC_KEYS],
            ], $vocabulary)),
            'a key whose aggregated spelling is a half the split drops is refused',
        );

        // The third population that can already carry an aggregated spelling: a
        // base key the product itself declares. A row whose expansion reaches one
        // would translate a key nobody named.
        $this->assert(
            self::throws(static fn(): mixed => RenameMaps::fromPairs([
                ['old' => 'a', 'new' => 'renamed.a', 'source' => RenameMaps::METRIC_KEYS],
            ], MetricVocabulary::of(['sum'], ['a', 'a.sum']))),
            'a key whose aggregated spelling is a base key of the product is refused',
        );

        // A channel half that coincides with another map's row. It is not a
        // second declaration of the same rename — a half is a spelling of a
        // channel row — so merging the two would leave one of them without its
        // roles and without its credit, reported stale for a spelling the other
        // translated. Kept apart, they collide loudly instead.
        $this->assert(
            self::throws(static fn(): mixed => RenameMaps::fromPairs([
                ['old' => 'old.rule#a.code', 'new' => 'new.rule#z.code', 'source' => RenameMaps::CHANNELS],
                ['old' => 'a.code', 'new' => 'z.code', 'source' => RenameMaps::METRIC_KEYS],
            ], $vocabulary)),
            'a channel half is not silently merged with another map\'s row on the same pair',
        );

        // A key that is also an input token: the merged declaration is reversible
        // through its input role, and the aggregated spellings belong to the key
        // role — which is forward-only, so they must not ride that reversibility
        // onto the input.
        $both = RenameMaps::fromPairs([
            ['old' => 'coupling.cbo', 'new' => 'coupling.class-coupling', 'source' => RenameMaps::METRIC_KEYS],
            ['old' => 'coupling.cbo', 'new' => 'coupling.class-coupling', 'source' => RenameMaps::INPUTS],
        ], $vocabulary);
        $this->same(
            1,
            \count($both->declaredRows()),
            'a key that is also an input token is one declaration',
        );
        $this->same(
            '--disable-rule=coupling.cbo',
            $both->reverse('--disable-rule=coupling.class-coupling'),
            'and its input role restates the token for the reference',
        );
        $this->same(
            '"coupling.class-coupling.avg"',
            $both->reverse('"coupling.class-coupling.avg"'),
            'while its aggregated spelling stays put: that spelling belongs to the forward-only key role',
        );
        $this->same(
            '"coupling.class-coupling.avg"',
            $both->forward('"coupling.cbo.avg"', 'format:json'),
            'and is translated forwards, where the key role does apply',
        );

        $this->aggregationVocabulary();
        $this->keysTravelOnlyWhereKeysArePublished();
        $this->htmlIsComparedThroughItsPayload();
    }

    /**
     * The HTML surface is its payload, and a report without one is loud.
     *
     * Three cases, because the narrowing is only safe if all three hold: the
     * bundle may move without the surface moving, a metric key inside the
     * payload may NOT move without it, and an artifact the payload cannot be
     * read out of is a failure of its own rather than a surface that compares
     * as nothing. The third is the one the narrowing could have bought
     * silently — two unreadable reports reduce to two empty strings, which are
     * equal.
     */
    private function htmlIsComparedThroughItsPayload(): void
    {
        $report = static fn(string $bundle, string $payload): string => '<html><head><style>a{}</style></head><body>'
            . '<script>' . $bundle . '</script>'
            . '<script type="application/json" id="report-data">' . $payload . '</script>'
            . '</body></html>';

        $payload = '{"project":{"metrics":{"complexity.ccn":5}}}';

        $this->same(
            ReportPayload::of($report('var A=1', $payload), 'case:x|format:html', 'candidate'),
            ReportPayload::of($report('var B=2', $payload), 'case:x|format:html', 'reference'),
            'a rebuilt bundle does not move the compared surface',
        );

        $this->assert(
            ReportPayload::of($report('var A=1', $payload), 'case:x|format:html', 'candidate')
            !== ReportPayload::of($report('var A=1', '{"project":{"metrics":{"ccn":5}}}'), 'case:x|format:html', 'reference'),
            'a metric key inside the payload still moves it',
        );

        $this->assert(
            self::throws(static fn(): string => ReportPayload::of('<html><body>no payload</body></html>', 'case:x|format:html', 'candidate')),
            'a report with no payload is refused rather than compared as nothing',
        );
        $this->assert(
            self::throws(static fn(): string => ReportPayload::of($report('var A=1', '{oops'), 'case:x|format:html', 'candidate')),
            'a payload that is not JSON is refused too',
        );

        // Normalization addresses the payload, and by the time it runs the
        // payload has already been reduced out of the report — so the rule has
        // to find it there too, or the surface would be compared unnormalized
        // and its clock field would diverge on every run.
        $normalization = Normalization::fromRules([
            new NormalizationRule('format:html', 'project.generatedAt', NormalizationRule::KIND_HTML_REPORT_DATA_PATH, 'test'),
        ]);
        $payload = ReportPayload::of(
            $report('var A=1', '{"project":{"generatedAt":"2026-01-01T00:00:00+00:00","metrics":{"complexity.ccn":5}}}'),
            'case:x|format:html',
            'candidate',
        );
        $this->assert(
            !str_contains($normalization->normalize('format:html', $payload), '2026-01-01'),
            'a rule for the report payload still finds it once the payload is the whole surface',
        );
    }

    /**
     * A key map reaches the surfaces that publish keys, and no others.
     *
     * Half the metric vocabulary is an English word, so on a prose surface the
     * whole-name rule is no protection at all: `cognitive` in "Maximum method
     * cognitive complexity is 29" has the same boundaries as the key. Measured
     * on the corpus 2026-08-28, before the restriction existed: the reference's
     * `format:text`, `format:checkstyle`, `format:gitlab`, `format:github`,
     * `format:summary`, `format:sarif` and the `rules` listing all came back
     * rewritten, and the run reported the corruption against the step.
     *
     * Both halves are checked, because only the pair says the restriction is a
     * restriction rather than an omission: the key is translated where keys are
     * published, and a channel row — which every surface publishes — still
     * travels everywhere.
     */
    private function keysTravelOnlyWhereKeysArePublished(): void
    {
        $maps = RenameMaps::fromPairs([
            ['old' => 'cognitive', 'new' => 'complexity.cognitive', 'source' => RenameMaps::METRIC_KEYS],
            ['old' => 'design.param-type-coverage', 'new' => 'design.type-coverage.param', 'source' => RenameMaps::CHANNELS],
        ], MetricVocabulary::of(['avg'], ['cognitive']));

        $prose = 'error[design.param-type-coverage]: Maximum method cognitive complexity is 29';

        $this->same(
            'error[design.type-coverage.param]: Maximum method cognitive complexity is 29',
            $maps->forward($prose, 'format:text'),
            'on a surface that publishes no metric key, the key map is not applied and prose survives',
        );
        $this->same(
            '{"cognitive": 29}',
            $maps->forward('{"cognitive": 29}', 'format:text'),
            'and the key itself is left alone there, so a key reaching a prose surface goes red instead of silent',
        );
        $this->same(
            '{"message": "Maximum method cognitive complexity is 29"}',
            $maps->forward('{"message": "Maximum method cognitive complexity is 29"}', 'format:json'),
            'even where keys are published, a key inside a longer string is prose and is left alone',
        );
        $this->same(
            '{"complexity.cognitive": 29}',
            $maps->forward('{"cognitive": 29}', 'format:metrics'),
            'on a surface that publishes keys, the key map is applied',
        );
        $this->same(
            '{"complexity.cognitive.avg": 29}',
            $maps->forward('{"cognitive.avg": 29}', 'format:json'),
            'and so are the aggregated spellings of that same row',
        );
    }

    /**
     * The vocabulary is read from the product, and the suffix list from BOTH
     * trees.
     *
     * Forward translation runs over the reference's artifacts, so a strategy the
     * step removed would stop being expanded while the reference is still
     * publishing it — a rename leaking into an undeclared diff, and silently.
     * The comparison itself is a condition of obtaining a reference tree
     * ({@see ReferenceTree::create()}), which is why what is proved here is the
     * refusal rather than the wiring.
     */
    private function aggregationVocabulary(): void
    {
        $product = MetricVocabulary::ofTree($this->candidateRoot);
        $this->same(
            ['avg', 'count', 'max', 'min', 'p5', 'p95', 'sum'],
            $product->suffixes,
            'the aggregation vocabulary is read out of the product',
        );
        $this->assert(
            \in_array('complexity.ccn', $product->baseKeys, true) && \count($product->baseKeys) > 50,
            'and so are the base keys the product declares in one place',
        );

        $root = Fs::temporaryDirectory('finding-gate-vocabulary-');

        try {
            $this->assert(
                self::throws(static fn(): mixed => MetricVocabulary::ofTree($root)),
                'a tree with no strategy enum is refused rather than expanded over nothing',
            );

            Fs::write(
                $root . '/src/Analysis/Evidence/Measurement/Contract/AggregationStrategy.php',
                "<?php\n\nenum AggregationStrategy: string\n{\n    case Sum = 'sum';\n}\n",
            );
            $this->assert(
                self::throws(static fn(): mixed => MetricVocabulary::ofTree($root)),
                'and one whose metric keys are missing is refused too, not read as an empty list',
            );

            Fs::write(
                $root . '/src/Analysis/Evidence/Measurement/Contract/MetricName.php',
                "<?php\n\nfinal class MetricName\n{\n    public const string CCN = 'ccn';\n}\n",
            );
            $vocabulary = MetricVocabulary::ofTree($root);
            $this->same(['sum'], $vocabulary->suffixes, 'a tree that declares one strategy declares one');
            $this->same(['ccn'], $vocabulary->baseKeys, 'and its keys are the constants it names');
            $this->assert(
                self::throws(static function () use ($vocabulary): void {
                    $vocabulary->assertSuffixesAgreeWith(MetricVocabulary::of(['avg', 'sum']));
                }),
                'two trees disagreeing about the vocabulary stop the run',
            );
        } finally {
            Fs::removeRecursively($root);
        }
    }

    /**
     * The input row a split producer needs, and the three obligations it keeps.
     *
     * One old token, several new ones: backwards — the direction `inputs.tsv`
     * exists for — the candidate's several names all restate as the one name the
     * reference knows, so the row is a function exactly where it is applied.
     * Forwards there is nothing to apply and the row refuses out loud. Without
     * it a case addressing a split producer by name has no writable row at all:
     * measured after Ш4b, one old name, three new ones.
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
    private function ambiguities(): void
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
        // arrangement Ш5e3 creates on purpose — a metric key and the channel
        // checking it are one name — and backwards only the reversible row is
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
        // the arrangement Ш5e3 makes ordinary, since a metric key and the
        // channel checking it are one name. What this pins is that the refusal
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
    private function producerMoves(): void
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
            $maps->forward('--rule-opt=design.type-coverage:param_warning=-1', 'format:json'),
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
            $channelsOnly->forward('design.type-coverage#design.type-coverage.param', 'format:json'),
            'while forward it translates the whole key',
        );
    }

    /**
     * The keys each surface publishes a compared field under, pinned against
     * the one place that writes them — and the list of surfaces itself.
     *
     * A table like this rots silently: if a formatter renames a key,
     * `delta-overreach` stops reading that field on that surface and nothing
     * says so. Worse, the *absence* of a surface from the table rots without
     * ever having been written down. That is what happened: the table named the
     * HTML payload's three aliases and stopped, so `sarif` (`text`) and `gitlab`
     * (`description`) published `message` under a key no reader knew, and eight
     * of nine declared deltas of one step were accepted by a reader that could
     * not reach them.
     *
     * So three things are asserted. Every alias occurs in its formatter; every
     * format of {@see Surfaces::FORMATS} is classified as readable or as
     * unreadable-with-a-reason, so a new format cannot be silently unread; and
     * the reader really picks the value out of a line in each surface's own
     * syntax, because a pinned name proves nothing about the regex that looks
     * for it.
     */
    private function publicationVocabulary(): void
    {
        $formatters = [
            'format:html' => 'src/Reporting/Formatter/Html/HtmlFindingPartitioner.php',
            'format:sarif' => 'src/Reporting/Formatter/Sarif/SarifFormatter.php',
            'format:gitlab' => 'src/Reporting/Formatter/GitLabCodeQualityFormatter.php',
            'format:checkstyle' => 'src/Reporting/Formatter/CheckstyleFormatter.php',
            'format:suppressed' => 'src/Reporting/Formatter/Suppressed/SuppressedFormatter.php',
            'baseline-file' => 'src/Analysis/Policy/Baseline/BaselineEntry.php',
        ];

        foreach ($formatters as $surface => $relative) {
            $source = Fs::read($this->candidateRoot . '/' . $relative);
            $keys = PublishedVocabulary::keysOf($surface);

            $this->assert($keys !== [], $surface . ' declares which compared fields it publishes');

            foreach ($keys as $field => $key) {
                $this->assert(
                    str_contains($source, "'" . $key . "'"),
                    $surface . ' still publishes ' . $field . ' as ' . $key,
                );
            }
        }

        // The HTML payload's own point: it does not use the tuple's spelling at
        // all, so reading the tuple spelling there read nothing.
        $partitioner = Fs::read($this->candidateRoot . '/src/Reporting/Formatter/Html/HtmlFindingPartitioner.php');

        foreach (array_keys(PublishedVocabulary::keysOf('format:html')) as $field) {
            $this->assert(
                !str_contains($partitioner, "'" . $field . "' =>"),
                'and the HTML payload still does not publish it under the tuple spelling ' . $field,
            );
        }

        $classified = PublishedVocabulary::readableSurfaces();

        foreach (Surfaces::FORMATS as $format) {
            $this->assert(
                \in_array('format:' . $format, $classified, true) || isset(PublishedVocabulary::UNREADABLE[$format]),
                'the ' . $format . ' surface is classified as readable or as marking no field, with a reason',
            );
        }

        // The reader, not the table. One line per syntax, each carrying the same
        // message under that surface's own key.
        $message = 'Suppression addresses no channel.';
        $this->same(
            [$message],
            PublishedVocabulary::valuesOn('format:json', '            "message": "' . $message . '",', 'message'),
            'the JSON member syntax is read under the tuple spelling',
        );
        $this->same(
            [$message],
            PublishedVocabulary::valuesOn('format:sarif', '                    "text": "' . $message . '"', 'message'),
            'and SARIF publishes the same field as "text"',
        );
        $this->same(
            [$message],
            PublishedVocabulary::valuesOn('format:gitlab', '        "description": "' . $message . '",', 'message'),
            'and GitLab as "description"',
        );
        $this->same(
            [$message],
            PublishedVocabulary::valuesOn('format:checkstyle', '    <error line="35" message="' . $message . '" source="qmx.a.b"/>', 'message'),
            'and checkstyle marks it as an XML attribute rather than a JSON member',
        );
        $this->same(
            [],
            PublishedVocabulary::valuesOn('format:text', 'src/A.php:35: error[a.b]: ' . $message, 'message'),
            'a prose surface yields nothing, which is why it is enumerated as unreadable rather than assumed read',
        );

        // Exhaustiveness, both ways round. A field SARIF does not carry is not
        // hunted for under its tuple spelling, and the JSON report's own
        // spelling still covers every field it publishes.
        $this->same(
            [],
            PublishedVocabulary::valuesOn('format:sarif', '                    "threshold": "5"', 'threshold'),
            'a field SARIF does not publish is not read there under its tuple spelling',
        );
        $this->same(
            ['5'],
            PublishedVocabulary::valuesOn('format:json', '            "threshold": "5",', 'threshold'),
            'while the JSON report publishes every compared field under its own name',
        );
        $this->same(
            [],
            PublishedVocabulary::valuesOn('format:suppressed', '        "channel": "a.b",', 'channel'),
            'and the suppressed surface spells the tuple\'s code as "channel", so its "channel" key is not the tuple\'s',
        );
        $this->same(
            ['a.b'],
            PublishedVocabulary::valuesOn('format:suppressed', '        "channel": "a.b",', 'code'),
            'it is the tuple\'s code',
        );

        // The container key of a nested object is not a value. SARIF spells the
        // member `"message": {`, and reading the tuple spelling as well would
        // have paired that brace against the reference's brace as if it were
        // the compared field.
        $this->same(
            [],
            PublishedVocabulary::valuesOn('format:sarif', '                    "message": {', 'message'),
            'and the SARIF container key is not read as a value of the field it wraps',
        );
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
        $this->publicationVocabulary();

        // Loading refuses a row whose reason is still "?", so a loaded index is
        // already an explained one. What the self-test adds is that every
        // declared surface carries a diff to compare against: an index row
        // pointing at an empty file would make `delta-mismatch` unreachable for
        // that surface.
        //
        // Both halves are asserted against something that cannot go stale. The
        // empty case reads a root with no index at all rather than the tracked
        // one: the previous spelling asserted "the tracked index is empty",
        // which was a fact about the step that wrote it (Ш4c declared no delta)
        // dressed up as the property, and it went red the moment a later step
        // declared one — saying nothing about the mechanism either way.
        $this->same(
            [],
            DeclaredDelta::load(sys_get_temp_dir() . '/qmx-gate-no-declared-delta')->surfaces(),
            'an empty declared delta claims no surface',
        );

        $delta = DeclaredDelta::load($this->candidateRoot . '/finding-gate');

        foreach ($delta->surfaces() as $surface) {
            $this->assert(
                $delta->claim($surface) !== null && $delta->claim($surface) !== '',
                \sprintf('the declared delta of %s carries a diff to compare against', $surface),
            );
        }

        foreach ($delta->surfaces() as $surface) {
            $this->assert(
                ($delta->claim($surface) ?? '') !== '',
                'the declared delta of ' . $surface . ' is a diff, not an empty file',
            );
        }
        $this->same(null, $delta->claim('case:smells|format:json'), 'a surface nothing declares claims nothing');
        $this->declaredDeltaWrite();
    }

    /**
     * The write half of the declaration, exercised end to end on a synthetic
     * root.
     *
     * Everything asserted about `DeclaredDelta` until now was about *loading* —
     * refusals, staleness, an empty index. The path that produces the file was
     * covered by nothing at all, so gutting it went unnoticed by every check.
     * The one property a run cannot supply is `reason`, so the carry-over rule
     * is asserted in both directions here: kept while the diff it explains is
     * the same diff, dropped to "?" the moment that diff moves.
     */
    private function declaredDeltaWrite(): void
    {
        $root = Fs::temporaryDirectory('self-test-delta-write-');
        $kept = "--- candidate\n+++ reference (mapped)\n@@ -1,1 +1,1 @@\n-a\n+A\n";
        $moved = "--- candidate\n+++ reference (mapped)\n@@ -2,1 +2,1 @@\n-b\n+B\n";

        Fs::write($root . '/' . DeclaredDelta::DIRECTORY . '/case-x-format-json.diff', $kept);
        Fs::write($root . '/' . DeclaredDelta::DIRECTORY . '/case-y-format-json.diff', $kept);
        Fs::write($root . '/' . DeclaredDelta::INDEX, Tsv::render(DeclaredDelta::COLUMNS, [
            ['case:x|format:json', DeclaredDelta::DIRECTORY . '/case-x-format-json.diff', 'the sentence written for x'],
            ['case:y|format:json', DeclaredDelta::DIRECTORY . '/case-y-format-json.diff', 'the sentence written for y'],
        ]));

        $written = DeclaredDelta::load($root)->rewrite([
            'case:y|format:json' => $moved,
            'case:x|format:json' => $kept,
            'case:z|format:json' => $kept,
        ]);

        $this->same(
            [
                DeclaredDelta::DIRECTORY . '/case-x-format-json.diff',
                DeclaredDelta::DIRECTORY . '/case-y-format-json.diff',
                DeclaredDelta::DIRECTORY . '/case-z-format-json.diff',
                DeclaredDelta::INDEX,
            ],
            $written,
            'a derivation writes one file per differing surface plus the index, and says which',
        );

        foreach ($written as $file) {
            $this->assert(is_file($root . '/' . $file), $file . ' is on disk after the write, not only in the return value');
        }

        $newFile = $root . '/' . DeclaredDelta::DIRECTORY . '/case-z-format-json.diff';
        $this->same($kept, is_file($newFile) ? Fs::read($newFile) : 'nothing was written', 'and holds the measured diff');

        $reasons = [];

        foreach (is_file($root . '/' . DeclaredDelta::INDEX) ? Tsv::rows($root . '/' . DeclaredDelta::INDEX, DeclaredDelta::COLUMNS) : [] as $row) {
            $reasons[$row['surface']] = $row['reason'];
        }

        $this->same(
            [
                'case:x|format:json' => 'the sentence written for x',
                'case:y|format:json' => '?',
                'case:z|format:json' => '?',
            ],
            $reasons,
            'a reason survives only the surface whose diff did not move; a moved one and a new one need writing again',
        );

        Fs::removeRecursively($root);
    }

    /**
     * Every tracked declaration this gate writes is written through
     * {@see Fs::write()}, and the controls harness clones the working tree by
     * **hardlinking** its content. A write in place therefore lands in the
     * developer's own repository: measured on 2026-09-04, one control run left
     * this repository's `declared-delta.tsv` holding thirteen rows derived from
     * a mutated clone.
     */
    private function writesNeverFollowHardlinks(): void
    {
        $root = Fs::temporaryDirectory('self-test-hardlink-');
        Fs::write($root . '/original', "before\n");
        $this->assert(link($root . '/original', $root . '/clone'), 'the hardlink case can be set up at all');
        Fs::write($root . '/clone', "after\n");
        $this->same("before\n", Fs::read($root . '/original'), 'writing a hardlinked file does not write through the link');
    }

    /**
     * The second source of permission `delta-overreach` consults, and the four
     * properties that keep it from being `normalization` under another name.
     *
     * Every case here is written against a synthetic index rather than the
     * tracked one, for the reason {@see maps()} spells out at length: what a
     * step happens to license is a fact about that step, and the mechanism is
     * entitled to no opinion about it. The tracked file is asserted to *load*,
     * and nothing more.
     */
    private function declaredFieldMoves(): void
    {
        $refusal = null;

        try {
            DeclaredFieldMoves::load($this->candidateRoot . '/finding-gate');
        } catch (GateError $error) {
            $refusal = $error->getMessage();
        }

        $this->assert($refusal === null, 'the tracked field moves do not load: ' . ($refusal ?? ''));

        $this->same(
            0,
            DeclaredFieldMoves::load(sys_get_temp_dir() . '/qmx-gate-no-declared-field-moves')->count(),
            'a tree with no index licenses no move',
        );

        $surface = 'case:annotations|format:json';
        $moves = self::fieldMoves([[$surface, 'message', 'said A and B', 'said A', 'B stopped being suggested']]);

        // Equality, and every column of the key is part of it. A substring, a
        // prefix, a neighbouring surface or a neighbouring field must all miss:
        // the harness next door has already paid once for a licence that fired
        // on containment.
        $this->assert($moves->allows($surface, 'message', 'said A and B', 'said A'), 'the declared pair is licensed');
        $this->assert(
            !$moves->allows($surface, 'message', 'said A and B', 'said A too'),
            'a value the declared one is a prefix of is not licensed',
        );
        $this->assert(
            !$moves->allows($surface, 'message', 'and B', 'said A'),
            'a value that is a substring of the declared one is not licensed',
        );
        $this->assert(
            !$moves->allows('case:annotations|format:sarif', 'message', 'said A and B', 'said A'),
            'the same move on another surface is not licensed',
        );
        $this->assert(
            !$moves->allows($surface, 'recommendation', 'said A and B', 'said A'),
            'the same move of another field is not licensed',
        );
        $this->assert(
            !$moves->allows($surface, 'message', 'said A', 'said A and B'),
            'the licence is directional: the reverse move is a move of its own',
        );

        // Staleness is measured on what fired, exactly as a map row's is.
        $this->same([], $moves->staleMoves(), 'a row a diff line used is not stale');

        $unused = self::fieldMoves([
            [$surface, 'message', 'from', 'to', 'used'],
            [$surface, 'message', 'never', 'happened', 'unused'],
        ]);
        $unused->allows($surface, 'message', 'from', 'to');
        $this->same(
            [['surface' => $surface, 'move' => '"message" ("never" -> "happened")']],
            $unused->staleMoves(),
            'a row nothing fired is stale, and is reported against the surface it names',
        );

        $this->same(
            'declares the same move of "message" on "' . $surface . '" twice',
            self::refusalOfFieldMoves([
                [$surface, 'message', 'from', 'to', 'first'],
                [$surface, 'message', 'from', 'to', 'second'],
            ]),
            'a duplicated key is refused, so two rows can never disagree about one licence',
        );
        $this->same(
            'licenses a move of "message" on "' . $surface . '" with no reason',
            self::refusalOfFieldMoves([[$surface, 'message', 'from', 'to', '']]),
            'a row with no reason is refused',
        );
        $this->same(
            'licenses a move of "message" on "' . $surface . '" with no reason',
            self::refusalOfFieldMoves([[$surface, 'message', 'from', 'to', '?']]),
            'and so is one still carrying the derived placeholder',
        );
        $this->same(
            'has a row naming no field',
            self::refusalOfFieldMoves([[$surface, '', 'from', 'to', 'why']]),
            'a row naming no field is refused, since it would license whatever a diff contains',
        );
        $this->same(
            'has a row naming no surface',
            self::refusalOfFieldMoves([['', 'message', 'from', 'to', 'why']]),
            'and so is one naming no surface',
        );
        $this->same(
            'moving from a value to itself',
            self::refusalOfFieldMoves([[$surface, 'message', 'same', 'same', 'why']]),
            'a row declaring no movement is refused',
        );

        // A licence nothing can consult is refused where it is still readable,
        // rather than surfacing a whole gate run later as staleness — which
        // names the wrong defect for a typo.
        $this->same(
            'where nothing can read that field',
            self::refusalOfFieldMoves([['case:annotations|format:sarrif', 'message', 'from', 'to', 'why']]),
            'a row naming a surface no run produces is refused',
        );
        $this->same(
            'where nothing can read that field',
            self::refusalOfFieldMoves([['case:annotations|format:text', 'message', 'from', 'to', 'why']]),
            'and so is one on a surface that marks no field at all',
        );
        $this->same(
            'where nothing can read that field',
            self::refusalOfFieldMoves([['case:annotations|format:sarif', 'threshold', 'from', 'to', 'why']]),
            'and one naming a field that surface does not publish',
        );
        $this->same(
            'has whitespace around the "from" field',
            self::refusalOfFieldMoves([[$surface, 'message', 'trailing space ', 'to', 'why']]),
            'a value with an invisible edge is refused rather than silently firing nowhere',
        );
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string, 4: string}> $rows
     */
    private static function fieldMoves(array $rows): DeclaredFieldMoves
    {
        $root = Fs::temporaryDirectory('self-test-field-moves-');
        Fs::write(
            $root . '/' . DeclaredFieldMoves::INDEX,
            Tsv::render(DeclaredFieldMoves::COLUMNS, $rows),
        );

        return DeclaredFieldMoves::load($root);
    }

    /**
     * The refusal such an index gets, reduced to the fragment that names the
     * defect — the sentence around it is a message, not a contract.
     *
     * @param list<array{0: string, 1: string, 2: string, 3: string, 4: string}> $rows
     */
    private static function refusalOfFieldMoves(array $rows): string
    {
        try {
            self::fieldMoves($rows);
        } catch (GateError $error) {
            foreach ([
                'declares the same move of "message" on "case:annotations|format:json" twice',
                'licenses a move of "message" on "case:annotations|format:json" with no reason',
                'has a row naming no field',
                'has a row naming no surface',
                'moving from a value to itself',
                'where nothing can read that field',
                'has whitespace around the "from" field',
            ] as $fragment) {
                if (str_contains($error->getMessage(), $fragment)) {
                    return $fragment;
                }
            }

            return 'refused, but for none of the reasons this self-test knows: ' . $error->getMessage();
        }

        return 'not refused at all';
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
        $tracked = EquivalenceTuple::load($this->candidateRoot);
        $this->assert($derived->fields !== [], 'the tuple derivation reads fields out of the publishing code');
        $this->assert($tracked->equals($derived), 'the tracked tuple is what the publishing code publishes');
        $this->tupleProvenanceOnLoad();
    }

    /**
     * The `source` column named a deleted publisher for a whole step, and every
     * run stayed green, because nothing resolved it. Written as a probe on a
     * fabricated tree rather than on the tracked file, so the refusal is proved
     * without a rename having to happen first.
     */
    private function tupleProvenanceOnLoad(): void
    {
        $root = Fs::temporaryDirectory('self-test-tuple-');
        $write = static function (string $source) use ($root): void {
            Fs::write(
                $root . '/' . EquivalenceTuple::TRACKED_PATH,
                Tsv::render(EquivalenceTuple::COLUMNS, [['channel', $source]]),
            );
        };
        Fs::write($root . '/src/Publisher.php', "<?php\n\nprivate function formatFinding(): array\n{\n}\n");

        $write('src/Publisher.php::formatFinding');
        $this->assert(
            !self::throws(static fn(): mixed => EquivalenceTuple::load($root)),
            'a tuple whose source names a file and a method that exist loads',
        );

        $write('src/Gone.php::formatFinding');
        $this->assert(
            self::throws(static fn(): mixed => EquivalenceTuple::load($root)),
            'a tuple whose source names a file the tree no longer has is refused',
        );

        $write('src/Publisher.php::formatViolation');
        $this->assert(
            self::throws(static fn(): mixed => EquivalenceTuple::load($root)),
            'a tuple whose source names a method the publisher no longer declares is refused',
        );

        $write('src/Publisher.php');
        $this->assert(
            self::throws(static fn(): mixed => EquivalenceTuple::load($root)),
            'a source cell that is not "<file>::<method>" is refused rather than read as a caption',
        );

        Fs::removeRecursively($root);
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
            'an untyped edge carries its target length, exactly as Finding::getFingerprint() composes it',
        );
        $this->same(
            [md5('channel:subject')],
            Fingerprints::md5Of(['channel:subject']),
            'the GitLab fingerprint is the md5 of the same string',
        );

        $this->same(
            [md5('channel:subject') => 'channel:subject'],
            Fingerprints::preimagesByHash([['channel' => 'channel', 'subject' => 'subject']]),
            'each published hash is paired with the identity it hashes',
        );

        $this->fingerprintSubstitution();
        $this->collapsedIdentityNeedsNoDelta();

        $this->assert(
            array_diff(Fingerprints::INPUT_FIELDS, EquivalenceTuple::load($this->candidateRoot)->fields) === [],
            'every field the fingerprint is composed from is one the tracked tuple compares',
        );
    }

    /**
     * The substitution, and the two ways it is allowed to be incomplete.
     */
    private function fingerprintSubstitution(): void
    {
        $identity = 'rule#code:declaration:class:App\\Thing@src/Thing.php';
        $pairs = [md5($identity) => $identity];
        $document = \sprintf('[{"check_name":"code","fingerprint":"%s"}]', md5($identity));
        $substituted = Fingerprints::substitute($document, $pairs, 1);

        $this->assert($substituted->isComplete(), 'a published hash is replaced by the identity it hashes');
        $this->same(1, $substituted->replaced, 'the replacement is counted');
        $this->same(
            '[{"check_name":"code","fingerprint":"rule#code:declaration:class:App\\\\Thing@src\\/Thing.php"}]',
            $substituted->text,
            'the identity goes in JSON-escaped, so the artifact stays a JSON document',
        );

        // The analysis-failure issues GitLab also carries hash a path and a
        // failure kind. Nothing verified them, so nothing substitutes them, and
        // they must survive untouched rather than be swept along.
        $withAnalysis = \sprintf(
            '[{"check_name":"analysis.parse-error","fingerprint":"%s"},{"check_name":"code","fingerprint":"%s"}]',
            md5('some/path:parse-error'),
            md5($identity),
        );
        $result = Fingerprints::substitute($withAnalysis, $pairs, 1);
        $this->assert($result->isComplete(), 'a hash nothing verified does not make the substitution incomplete');
        $this->assert(
            str_contains($result->text, md5('some/path:parse-error')),
            'an unverified hash is left exactly as it is',
        );

        $missing = Fingerprints::substitute('[{"fingerprint":"deadbeef"}]', $pairs, 1);
        $this->assert(!$missing->isComplete(), 'a verified hash the surface does not carry is a shortfall');
        $this->same([md5($identity)], $missing->missing, 'the shortfall names the hash it could not find');

        $short = Fingerprints::substitute($document, $pairs, 2);
        $this->assert(
            !$short->isComplete(),
            'replacing fewer values than the surface published is a shortfall even when every pair was found',
        );
    }

    /**
     * The step's own reason for existing, on a synthetic pair.
     *
     * The collapse of `rule#code` into `code` moves every fingerprint of every
     * finding. Compared as published bytes, the GitLab surface differs — and a
     * declared delta of nothing but hashes is exactly the blob `delta-too-large`
     * exists to refuse. Substituted, the same surface carries the identity as a
     * name, the declared row translates it, and the surfaces agree.
     *
     * The third assertion is the guarantee that must not be lost: with no row
     * declaring the collapse, the substituted surfaces still differ. A
     * fingerprint that moved is only ever absorbed by a declaration, never by
     * the substitution.
     */
    private function collapsedIdentityNeedsNoDelta(): void
    {
        $old = 'cohesion.lcom#cohesion.lcom:declaration:class:App\\Thing@src/Thing.php';
        $new = 'cohesion.lcom:declaration:class:App\\Thing@src/Thing.php';
        $document = static fn(string $identity): string => \sprintf(
            '[{"check_name":"cohesion.lcom","fingerprint":"%s"}]',
            md5($identity),
        );
        $reference = $document($old);
        $candidate = $document($new);
        $maps = RenameMaps::fromPairs([[
            'old' => 'cohesion.lcom#cohesion.lcom',
            'new' => 'cohesion.lcom',
            'source' => RenameMaps::CHANNELS,
        ]]);

        $this->assert(
            $maps->forward($reference, 'format:json') !== $candidate,
            'before substituting, the collapse moves the published GitLab bytes and would need a declared delta',
        );

        $substitute = static fn(string $text, string $identity): string => Fingerprints::substitute(
            $text,
            [md5($identity) => $identity],
            1,
        )->text;

        $this->same(
            $substitute($candidate, $new),
            $maps->forward($substitute($reference, $old), 'format:gitlab'),
            'substituted and then translated, the collapsed identity needs no declared delta',
        );

        $this->assert(
            $substitute($candidate, $new) !== RenameMaps::fromPairs([])->forward($substitute($reference, $old), 'format:gitlab'),
            'with no row declaring the collapse, the substituted surfaces still differ',
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

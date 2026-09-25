<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Coverage and the case claims it is built from: row shapes, claimed channel-and-level pairs, the
 * level vocabulary, and the corpus those claims come from — which cases a run selects and what a case
 * may point at.
 */
final class SelfTestCoverage extends SelfTestGroup
{
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
     * A `rule#code` collapse has one name on its new side, so its declaration row has a
     * single name on its new side. The map that could not express that shape
     * refused the row at load time, which would have made the step's own
     * declaration unwritable.
     */
    public function channelRowShapes(): void
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
            $half->forward('"name": "Complexity Cyclomatic Callable"', 'format:sarif'),
            'a renamed code half is translated in the title-cased spelling SARIF publishes as a rule name',
        );

        // The two boundaries the spelling needs, each pinned on its own. Both
        // were measured as damage rather than argued: the surface one on the
        // HTML report's `"label": "Maintainability Index"`, a whole quoted value
        // that is a display label and not a rule name, and the quoting one on
        // the same phrase inside a finding's message and a rule's description,
        // which is the English the product uses for the metric.
        $this->same(
            '"name": "Complexity Cyclomatic Callable"',
            $half->forward('"name": "Complexity Cyclomatic Callable"', 'format:json'),
            'the title-cased spelling belongs to SARIF, so another surface publishing it as a whole value keeps it',
        );
        $this->same(
            'Checks Complexity Cyclomatic Callable (paths per method)',
            $half->forward('Checks Complexity Cyclomatic Callable (paths per method)', 'format:sarif'),
            'the title-cased spelling travels as a whole quoted value, so the same phrase inside prose keeps it',
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

    public function claims(): void
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
    public function coverage(): void
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
    public function levelVocabulary(): void
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
        mkdir($directory . '/src', 0o777, true);
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
     * The corpus is external and self-contained, and a restricted run says
     * which cases it ran.
     *
     * Both halves are exercised on a written corpus rather than on the loader's
     * arguments, for the reason {@see claimShapeOnLoad()} gives. Every refusal
     * below loaded before its check existed: `paths` was the only value held to
     * the case directory, and a misspelt `--cases` name beside a real one was
     * dropped while the limit line still named it.
     */
    public function corpusBoundaries(): void
    {
        $root = Fs::temporaryDirectory('self-test-corpus-');
        $directory = $root . '/finding-gate/cases/probe';
        mkdir($directory . '/src', 0o777, true);
        Fs::write($directory . '/qmx.yaml', "suppress_paths: []\n");
        Fs::write($directory . '/preset.yaml', "rules: {}\n");
        Fs::write(\dirname($directory) . '/qmx.yaml', "suppress_paths: []\n");

        $loads = static function (string $config, array $args) use ($root, $directory): bool {
            Fs::write($directory . '/case.json', (string) json_encode([
                'id' => 'probe',
                'description' => 'a written case, so what it may point at is checked where a case is loaded',
                'paths' => ['src'],
                'config' => $config,
                'args' => $args,
                'channels' => ['a.code@class'],
            ]));

            return !self::throws(static fn(): mixed => Corpus::load($root, []));
        };

        $this->assert(
            $loads('qmx.yaml', ['--preset=strict', '--preset=preset.yaml', '-c', 'qmx.yaml', '--rule-opt=a.b:c=1']),
            'a case whose config, presets and path arguments stay in its directory loads',
        );
        $this->assert(!$loads('../qmx.yaml', []), 'a config reached through ".." outside the directory is refused');
        $this->assert($loads('../probe/qmx.yaml', []), 'while one whose ".." leads back inside loads');
        $this->assert(!$loads('missing.yaml', []), 'and a config that does not exist is refused');

        foreach ([
            'an absolute config as a separated -c' => ['-c', '/elsewhere/qmx.yaml'],
            'an attached -c' => ['-c/elsewhere/qmx.yaml'],
            'a -c inside a short-option cluster' => ['-qc/elsewhere/qmx.yaml'],
            'an attached --config' => ['--config=/elsewhere/qmx.yaml'],
            'a separated --config' => ['--config', '../../qmx.yaml'],
            'a preset path' => ['--preset=../../../preset.yaml'],
            'a preset hidden in a comma list' => ['--preset=strict,/elsewhere/preset.yaml'],
            'a baseline' => ['--baseline=/elsewhere/baseline.json'],
            'a working directory' => ['--working-dir=..'],
        ] as $what => $args) {
            $this->assert(!$loads('qmx.yaml', $args), \sprintf('%s outside the case directory is refused', $what));
        }

        foreach ([
            'a separated -o' => ['-o', 'report.json'],
            'a -o inside a short-option cluster' => ['-qoreport.json'],
            'an attached --output' => ['--output=report.json'],
            'a log file' => ['--log-file=qmx.log'],
            'a cache directory' => ['--cache-dir=cache'],
            'a profile without a file' => ['--profile'],
        ] as $what => $args) {
            $this->assert(!$loads('qmx.yaml', $args), \sprintf('%s is refused wherever it writes', $what));
        }

        $this->assert(
            !$loads('qmx.yaml', ['--format', 'json']),
            'a bare token that is no path option\'s value is refused: whether it names a path cannot be known',
        );
        $this->assert(!$loads('qmx.yaml', ['--', 'src']), 'and so is ending option parsing, after which every token is one');

        $loads('qmx.yaml', []);
        $unmatched = null;

        try {
            Corpus::load($root, ['probe', 'nope']);
        } catch (GateError $error) {
            $unmatched = $error->getMessage();
        }

        $this->assert(
            $unmatched !== null && str_contains($unmatched, 'nope'),
            'a --cases name that selects no case is refused by name even beside one that does',
        );
        $this->assert(
            !self::throws(static fn(): mixed => Corpus::load($root, ['probe'])),
            'while a --cases list whose every name selects a case loads',
        );

        Fs::removeRecursively($root);
    }
}

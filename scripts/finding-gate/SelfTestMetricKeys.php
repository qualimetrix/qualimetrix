<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Metric keys as a map translates them: the product's own vocabulary, aggregated spellings, and the
 * surfaces a key travels on.
 */
final class SelfTestMetricKeys extends SelfTestGroup
{
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
    public function metricKeys(): void
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
}

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

        $maps = RenameMaps::load($this->candidateRoot . '/finding-gate/maps');
        $this->assert($maps->isIdentity(), 'the tracked maps are empty at this step');

        $channel = RenameMaps::fromPairs([
            ['old' => 'design.type-coverage#design.type-coverage.param', 'new' => 'design.param-typing#design.param-typing', 'source' => 'channels.tsv'],
            ['old' => 'design.type-coverage', 'new' => 'design.param-typing', 'source' => 'channels.tsv'],
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
        $this->same(
            '--rule-opt=design.type-coverage:threshold=1',
            $channel->reverse('--rule-opt=design.param-typing:threshold=1'),
            'reverse restates candidate input in the reference vocabulary',
        );
        $this->same(
            ['--only-rule=design.type-coverage'],
            $channel->reverseArguments(['--only-rule=design.param-typing']),
            'arguments are reversed one by one',
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
            ['old' => 'complexity.cyclomatic', 'new' => 'complexity.ccn', 'source' => 'channels.tsv'],
        ]);
        $this->same(
            '"complexity.cyclomatic.callable" and "complexity.ccn"',
            $levels->forward('"complexity.cyclomatic.callable" and "complexity.cyclomatic"'),
            'a level segment keeps a prefix row away from the whole name',
        );

        // The other measured counterexample: rows applied one after another
        // cascade. Row 1 produces `mid.rule#mid.rule.class`, and row 2 renames
        // `mid.rule.class` — sequentially that yields `mid.rule#new.code`, an
        // identity no row declares. Neither row is a whole-name chain, so the
        // load-time checks cannot see it; one pass over the original text can.
        $cascade = RenameMaps::fromPairs([
            ['old' => 'old.rule#old.rule.class', 'new' => 'mid.rule#mid.rule.class', 'source' => 'channels.tsv'],
            ['old' => 'mid.rule.class', 'new' => 'new.code', 'source' => 'channels.tsv'],
        ]);
        $this->same(
            'mid.rule#mid.rule.class',
            $cascade->forward('old.rule#old.rule.class'),
            'substitution is one pass over the original text, so rows cannot cascade',
        );

        $rejected = [
            'a chain' => [
                ['old' => 'a.one', 'new' => 'b.one', 'source' => 'channels.tsv'],
                ['old' => 'b.one', 'new' => 'c.one', 'source' => 'channels.tsv'],
            ],
            'two rows renaming one name' => [
                ['old' => 'a.one', 'new' => 'b.one', 'source' => 'channels.tsv'],
                ['old' => 'a.one', 'new' => 'c.one', 'source' => 'channels.tsv'],
            ],
            'two rows onto one target' => [
                ['old' => 'a.one', 'new' => 'z.one', 'source' => 'channels.tsv'],
                ['old' => 'b.one', 'new' => 'z.one', 'source' => 'channels.tsv'],
            ],
            'a row that renames nothing' => [
                ['old' => 'a.one', 'new' => 'a.one', 'source' => 'channels.tsv'],
            ],
        ];

        foreach ($rejected as $description => $pairs) {
            $this->assert(
                self::throws(static fn(): mixed => RenameMaps::fromPairs($pairs)),
                $description . ' is refused when the map loads',
            );
        }

        $stale = RenameMaps::fromPairs([
            ['old' => 'never.observed', 'new' => 'nor.published', 'source' => 'channels.tsv'],
        ]);
        $stale->forward('nothing this row can translate');
        $this->same(
            ['channels.tsv: "never.observed" -> "nor.published"'],
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

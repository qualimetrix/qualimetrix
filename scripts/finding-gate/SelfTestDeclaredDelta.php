<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The declared delta and its licensed field moves: how a diff is declared, compared, written and refused.
 */
final class SelfTestDeclaredDelta extends SelfTestGroup
{
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

        // Exhaustiveness in both directions. A field SARIF does not carry is not
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
     * Produces a real hunk so the instrument's own diff path is covered.
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
    public function declaredDelta(): void
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
        // one: asserting "the tracked index is empty" confuses one observed
        // input with the property and fails as soon as a valid delta is
        // declared, saying nothing about the mechanism either way.
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
        // A corpus surface would go stale the day a step declares it; no case is named this.
        $undeclared = 'case:self-test-undeclared|format:json';
        $this->assert(!\in_array($undeclared, $delta->surfaces(), true), 'the undeclared probe surface is not declared');
        $this->same(null, $delta->claim($undeclared), 'a surface nothing declares claims nothing');
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
     * The second source of permission `delta-overreach` consults, and the four
     * properties that keep it from being `normalization` under another name.
     *
     * Every case here is written against a synthetic index rather than the
     * tracked one, for the reason {@see SelfTestMaps::maps()} spells out at length: what a
     * step happens to license is a fact about that step, and the mechanism is
     * entitled to no opinion about it. The tracked file is asserted to *load*,
     * and nothing more.
     */
    public function declaredFieldMoves(): void
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
}

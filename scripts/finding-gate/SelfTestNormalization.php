<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The normalization list and the deriver that measures it.
 */
final class SelfTestNormalization extends SelfTestGroup
{
    public function normalization(): void
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
        $this->same(
            '{"meta":{"timestamp":"' . Normalization::REDACTED . '","version":"1"},"violations":[{"seenAt":"'
            . Normalization::REDACTED . '"},{"seenAt":"' . Normalization::REDACTED . '"}]}',
            $json,
            'a json-path rule redacts its field, a `*` segment every element of a list, and nothing else is rewritten',
        );

        // What a JSON surface publishes is its bytes: the redaction must leave
        // layout, escaping and number spelling as they were, or a formatter that
        // changed them would compare equal.
        foreach ([
            'layout' => ["{\n    \"meta\": {\"timestamp\": \"x\"},\n    \"a\": \"b/c\"\n}", '{"meta":{"timestamp":"y"},"a":"b/c"}'],
            'escaping' => ['{"meta":{"timestamp":"x"},"a":"b/c"}', '{"meta":{"timestamp":"y"},"a":"b\\/c"}'],
            'number spelling' => ['{"meta":{"timestamp":"x"},"a":1.5}', '{"meta":{"timestamp":"y"},"a":1.50}'],
            'repeated key' => ['{"meta":{"timestamp":"x"},"a":1}', '{"meta":{"timestamp":"y"},"a":0,"a":1}'],
        ] as $difference => [$published, $republished]) {
            $this->same(
                [str_replace('"x"', '"' . Normalization::REDACTED . '"', $published), str_replace('"y"', '"' . Normalization::REDACTED . '"', $republished)],
                [$normalization->normalize('format:json', $published), $normalization->normalize('format:json', $republished)],
                'normalization preserves exact JSON byte spans for ' . $difference,
            );
            $this->assert(
                $normalization->normalize('format:json', $published) !== $normalization->normalize('format:json', $republished),
                'a JSON surface that differs only in ' . $difference . ' still differs once normalized',
            );
        }

        $this->same(
            $normalization->normalize('format:json', "{\n    \"meta\": {\"timestamp\": \"x\"}\n}\n"),
            $normalization->normalize('format:json', "{\n    \"meta\": {\"timestamp\": 17}\n}\n"),
            'two documents that differ only in a redacted value are equal once normalized',
        );

        $html = $normalization->normalize(
            'format:html',
            '<script type="application/json" id="report-data">{"project":{"generatedAt":"then","name":"x"}}</script>',
        );
        $this->assert(str_contains($html, Normalization::REDACTED), 'an html rule reaches into the embedded report data');
        $this->assert(str_contains($html, '"name":"x"'), 'an html rule leaves its siblings alone');

        $summary = $normalization->normalize('format:summary', "Analysed in 1.2s\nAnalysed nothing else\n");
        $this->same("Analysed in " . Normalization::REDACTED . "\nAnalysed nothing else\n", $summary, 'a line-regex rule redacts only the varying part');

        $tracked = Normalization::load($this->candidateRoot . '/finding-gate/normalization.tsv');
        $namespaceSummary = <<<'SUMMARY'
            Qualimetrix dev-main — 6 files analyzed [namespace: subtree:Shop], 0.2s

            Analysis complete: 6 analyzed, 0 generated file(s) excluded.

            Health: insufficient data


            Top issues by impact
              1. [ERR] 7.50  src/Cart.php:8  [15min]
                     annotation.unresolved-directive: Suppression "no.such.rule" addresses no channel. No declared name is close to it. Prose belongs after "--".
              2. [ERR] 7.50  src/Cart.php:14  [15min]
                     code-smell.eval: eval() usage detected - security risk (Cart::run)
                     Recommendation: Replace eval() with a safer alternative (closures, reflection, or template engine).
              3. [WRN] 2.50  src/Cart.php:17  [15min]
                     duplication.clone: Duplicated code block (29 code lines, 3 occurrences): "public function summarise(array $rows, string $mode, int $limit): array..." — also at src/Copy.php:9-38, src/Multi.php:8-37
                     Recommendation: Extract duplicated code into a shared method or class.
              4. [WRN] 2.50  src/Cart.php:19  [15min]
                     duplication.clone: Duplicated code block (26 code lines, 4 occurrences): "$result = []; $index = 0; foreach ($rows as $key => $row) {" — also at src/Copy.php:11-37, src/Multi.php:10-36, src/NoDeclarations.php:5-31
                     Recommendation: Extract duplicated code into a shared method or class.
              5. [WRN] 2.50  src/Copy.php:8  [15min]
                     duplication.clone: Duplicated code block (31 code lines, 2 occurrences): "public function summarise(array $rows, string $mode, int $limit): array..." — also at src/Multi.php:7-38
                     Recommendation: Extract duplicated code into a shared method or class.
              6. [WRN] 2.50  src/Copy.php:9  [15min]
                     duplication.clone: Duplicated code block (29 code lines, 3 occurrences): "public function summarise(array $rows, string $mode, int $limit): array..." — also at src/Cart.php:17-46, src/Multi.php:8-37
                     Recommendation: Extract duplicated code into a shared method or class.
              7. [WRN] 2.50  src/Copy.php:11  [15min]
                     duplication.clone: Duplicated code block (26 code lines, 4 occurrences): "$result = []; $index = 0; foreach ($rows as $key => $row) {" — also at src/Cart.php:19-45, src/Multi.php:10-36, src/NoDeclarations.php:5-31
                     Recommendation: Extract duplicated code into a shared method or class.
              8. [WRN] 2.50  src/Multi.php:7  [15min]
                     duplication.clone: Duplicated code block (31 code lines, 2 occurrences): "public function summarise(array $rows, string $mode, int $limit): array..." — also at src/Copy.php:8-39
                     Recommendation: Extract duplicated code into a shared method or class.
              9. [WRN] 2.50  src/Multi.php:8  [15min]
                     duplication.clone: Duplicated code block (29 code lines, 3 occurrences): "public function summarise(array $rows, string $mode, int $limit): array..." — also at src/Cart.php:17-46, src/Copy.php:9-38
                     Recommendation: Extract duplicated code into a shared method or class.
              10. [WRN] 2.50  src/Multi.php:10  [15min]
                      duplication.clone: Duplicated code block (26 code lines, 4 occurrences): "$result = []; $index = 0; foreach ($rows as $key => $row) {" — also at src/Cart.php:19-45, src/Copy.php:11-37, src/NoDeclarations.php:5-31
                      Recommendation: Extract duplicated code into a shared method or class.
            10 violations in this scope (2 errors, 8 warnings) | Tech debt: 2h 30min | 2 outside it (1 error, 1 warning) decide the exit code

            Hints: --format=html -o report.html for full report
            Docs: https://qualimetrix.dev · AI agents: https://qualimetrix.dev/llms.txt

            Violations
            src/Cart.php (4 violations)
              ERROR at line 8
                Suppression "no.such.rule" addresses no channel. No declared name is close to it. Prose belongs after "--".  [annotation.unresolved-directive]

              ERROR at line 14  Cart::run
                eval() usage detected - security risk  [code-smell.eval]
                Recommendation: Replace eval() with a safer alternative (closures, reflection, or template engine).

              WARN at line 17
                Duplicated code block (29 code lines, 3 occurrences): "public function summarise(array $rows, string $mode, int $limit): array..." — also at src/Copy.php:9-38, src/Multi.php:8-37  [duplication.clone]
                Recommendation: Extract duplicated code into a shared method or class.

              WARN at line 19
                Duplicated code block (26 code lines, 4 occurrences): "$result = []; $index = 0; foreach ($rows as $key => $row) {" — also at src/Copy.php:11-37, src/Multi.php:10-36, src/NoDeclarations.php:5-31  [duplication.clone]
                Recommendation: Extract duplicated code into a shared method or class.

            src/Copy.php (3 violations)
              WARN at line 8
                Duplicated code block (31 code lines, 2 occurrences): "public function summarise(array $rows, string $mode, int $limit): array..." — also at src/Multi.php:7-38  [duplication.clone]
                Recommendation: Extract duplicated code into a shared method or class.

              WARN at line 9
                Duplicated code block (29 code lines, 3 occurrences): "public function summarise(array $rows, string $mode, int $limit): array..." — also at src/Cart.php:17-46, src/Multi.php:8-37  [duplication.clone]
                Recommendation: Extract duplicated code into a shared method or class.

              WARN at line 11
                Duplicated code block (26 code lines, 4 occurrences): "$result = []; $index = 0; foreach ($rows as $key => $row) {" — also at src/Cart.php:19-45, src/Multi.php:10-36, src/NoDeclarations.php:5-31  [duplication.clone]
                Recommendation: Extract duplicated code into a shared method or class.

            src/Multi.php (3 violations)
              WARN at line 7
                Duplicated code block (31 code lines, 2 occurrences): "public function summarise(array $rows, string $mode, int $limit): array..." — also at src/Copy.php:8-39  [duplication.clone]
                Recommendation: Extract duplicated code into a shared method or class.

              WARN at line 8
                Duplicated code block (29 code lines, 3 occurrences): "public function summarise(array $rows, string $mode, int $limit): array..." — also at src/Cart.php:17-46, src/Copy.php:9-38  [duplication.clone]
                Recommendation: Extract duplicated code into a shared method or class.

              WARN at line 10
                Duplicated code block (26 code lines, 4 occurrences): "$result = []; $index = 0; foreach ($rows as $key => $row) {" — also at src/Cart.php:19-45, src/Copy.php:11-37, src/NoDeclarations.php:5-31  [duplication.clone]
                Recommendation: Extract duplicated code into a shared method or class.

            Technical debt by rule:
              duplication.clone                        ~2h       (8 violations)
              code-smell.eval                          ~15min    (1 violation)
              annotation.unresolved-directive          ~15min    (1 violation)
            SUMMARY;
        $namespaceSummary .= "\n";
        $differentClock = str_replace(', 0.2s', ', 0.1s', $namespaceSummary);
        $normalizedSummary = str_replace(', 0.2s', ', ' . Normalization::REDACTED . 's', $namespaceSummary);
        $this->same(
            $normalizedSummary,
            $tracked->normalize('format:summary', $namespaceSummary),
            'the tracked namespace summary rule changes only the measured clock in the complete publication',
        );
        $this->same(
            $normalizedSummary,
            $tracked->normalize('format:summary', $differentClock),
            'different namespace summary clocks compare equal through the tracked rule',
        );
        foreach (['6 files' => '7 files', '7.50' => '8.50', 'src/Cart.php:8' => 'src/Cart.php:9'] as $before => $after) {
            $changedSummary = str_replace($before, $after, $namespaceSummary);
            $normalizedChanged = $tracked->normalize('format:summary', $changedSummary);
            $this->same(
                str_replace($before, $after, $normalizedSummary),
                $normalizedChanged,
                'namespace summary normalization preserves the complete publication beside ' . $before,
            );
            $this->assert($normalizedSummary !== $normalizedChanged, 'namespace summary changes remain compared for ' . $before);
        }
        $otherSelector = str_replace('subtree:Shop', 'subtree:ShopElse', $namespaceSummary);
        $this->same($otherSelector, $tracked->normalize('format:summary', $otherSelector), 'the measured namespace locator does not match a neighbouring selector');
        $this->assert(
            $normalizedSummary !== $tracked->normalize('format:summary', $otherSelector),
            'a different namespace selector remains compared',
        );
        $this->same($namespaceSummary, $tracked->normalize('format:text', $namespaceSummary), 'the namespace clock rule does not match another format');
        $derivedSummary = NormalizationDeriver::derive([
            ['case:drill-down|format:summary' => $namespaceSummary],
            ['case:drill-down|format:summary' => $differentClock],
        ]);
        $this->same(1, \count($derivedSummary), 'the complete namespace summary derives only its measured clock');
        $this->assert(
            \in_array($derivedSummary[0]->row(), array_map(static fn(NormalizationRule $rule): array => $rule->row(), $tracked->rules()), true),
            'the stock derived namespace clock row is present in the tracked table',
        );

        $stale = array_map(static fn(NormalizationRule $rule): string => $rule->locator, $normalization->staleRules());
        $this->same(['meta.absent'], $stale, 'a rule that matched nothing is reported stale, and one that matched is not');
    }

    public function deriver(): void
    {
        $rules = NormalizationDeriver::derive([
            ['case:x|format:json' => '{"meta":{"timestamp":"a","version":"1"}}'],
            ['case:x|format:json' => '{"meta":{"timestamp":"b","version":"1"}}'],
        ]);
        $this->same(1, \count($rules), 'the deriver emits one row per diverging field');
        $this->same('meta.timestamp', $rules[0]->locator, 'the row names the field by path');
        $this->same(NormalizationRule::KIND_JSON_PATH, $rules[0]->kind, 'a JSON surface derives a json-path row');

        $many = [[], []];
        for ($index = 0; $index < 6; ++$index) {
            $many[0]['case:x|report:' . $index] = '{"clock":"a","duration":1}';
            $many[1]['case:x|report:' . $index] = '{"clock":"b","duration":2}';
        }
        try {
            $this->same(12, \count(NormalizationDeriver::derive($many)), 'independent metadata copies across six publications do not share a global row budget');
        } catch (GateError) {
            $this->assert(false, 'independent metadata copies across six publications do not share a global row budget');
        }
        $fields = array_fill_keys(array_map(static fn(int $index): string => 'clock' . $index, range(0, 10)), 1);
        $changed = array_fill_keys(array_keys($fields), 2);
        $this->assert(self::throws(static fn(): array => NormalizationDeriver::derive([
            ['case:x|format:json' => json_encode($fields, \JSON_THROW_ON_ERROR)],
            ['case:x|format:json' => json_encode($changed, \JSON_THROW_ON_ERROR)],
        ])), 'eleven varying fields in one published surface remain refused');

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
}

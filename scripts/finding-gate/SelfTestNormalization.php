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

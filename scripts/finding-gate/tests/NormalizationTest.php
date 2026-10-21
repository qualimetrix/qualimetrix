<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\Corpus;
use QmxFindingGate\FailureClass;
use QmxFindingGate\GateReport;
use QmxFindingGate\Normalization;
use QmxFindingGate\NormalizationCheck;
use QmxFindingGate\NormalizationDeriver;
use QmxFindingGate\NormalizationRule;
use QmxFindingGate\Options;
use QmxFindingGate\SyntheticTree;

/**
 * What a normalization rule of each kind redacts, the bytes it must leave
 * alone, and the staleness that keeps a rule from outliving what it matched.
 */
final class NormalizationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itRedactsAJsonPathFieldAndEveryElementAStarSegmentReaches(): void
    {
        $json = self::normalization()->normalize('format:json', '{"meta":{"timestamp":"now","version":"1"},"violations":[{"seenAt":"a"},{"seenAt":"b"}]}');

        self::assertSame(
            '{"meta":{"timestamp":"' . Normalization::REDACTED . '","version":"1"},"violations":[{"seenAt":"'
            . Normalization::REDACTED . '"},{"seenAt":"' . Normalization::REDACTED . '"}]}',
            $json,
        );
    }

    /**
     * What a JSON surface publishes is its bytes: a formatter that changed
     * layout, escaping or number spelling must not compare equal.
     */
    #[Test]
    public function itPreservesExactJsonByteSpansBesideTheRedactedValue(): void
    {
        $normalization = self::normalization();

        foreach ([
            'layout' => ["{\n    \"meta\": {\"timestamp\": \"x\"},\n    \"a\": \"b/c\"\n}", '{"meta":{"timestamp":"y"},"a":"b/c"}'],
            'escaping' => ['{"meta":{"timestamp":"x"},"a":"b/c"}', '{"meta":{"timestamp":"y"},"a":"b\\/c"}'],
            'number spelling' => ['{"meta":{"timestamp":"x"},"a":1.5}', '{"meta":{"timestamp":"y"},"a":1.50}'],
            'repeated key' => ['{"meta":{"timestamp":"x"},"a":1}', '{"meta":{"timestamp":"y"},"a":0,"a":1}'],
        ] as $difference => [$published, $republished]) {
            self::assertSame(
                [str_replace('"x"', '"' . Normalization::REDACTED . '"', $published), str_replace('"y"', '"' . Normalization::REDACTED . '"', $republished)],
                [$normalization->normalize('format:json', $published), $normalization->normalize('format:json', $republished)],
                'normalization preserves exact JSON byte spans for ' . $difference,
            );
            self::assertNotSame(
                $normalization->normalize('format:json', $published),
                $normalization->normalize('format:json', $republished),
                'a JSON surface that differs only in ' . $difference . ' still differs once normalized',
            );
        }

        self::assertSame(
            $normalization->normalize('format:json', "{\n    \"meta\": {\"timestamp\": \"x\"}\n}\n"),
            $normalization->normalize('format:json', "{\n    \"meta\": {\"timestamp\": 17}\n}\n"),
            'two documents that differ only in a redacted value are equal once normalized',
        );
    }

    #[Test]
    public function itReachesIntoTheEmbeddedHtmlReportDataAndLeavesSiblingsAlone(): void
    {
        $html = self::normalization()->normalize(
            'format:html',
            '<script type="application/json" id="report-data">{"project":{"generatedAt":"then","name":"x"}}</script>',
        );

        self::assertStringContainsString(Normalization::REDACTED, $html);
        self::assertStringContainsString('"name":"x"', $html);
    }

    #[Test]
    public function itRedactsOnlyTheVaryingPartOfALineRegexMatch(): void
    {
        self::assertSame(
            'Analysed in ' . Normalization::REDACTED . "\nAnalysed nothing else\n",
            self::normalization()->normalize('format:summary', "Analysed in 1.2s\nAnalysed nothing else\n"),
        );
    }

    #[Test]
    public function itRaisesNormalizationStaleForARuleThatMatchedNothingInTheWholeRun(): void
    {
        $normalization = self::normalization();
        $normalization->normalize('format:json', '{"meta":{"timestamp":"now"},"violations":[{"seenAt":"a"}]}');
        $normalization->normalize('format:html', '<script type="application/json" id="report-data">{"project":{"generatedAt":"then"}}</script>');
        $normalization->normalize('format:summary', "Analysed in 1.2s\n");

        $report = new GateReport();
        (new NormalizationCheck(Options::parse(['gate', '--reference=HEAD'], \dirname(__DIR__, 3)), $report, $normalization))->checkStaleNormalization();

        self::assertSame(
            [[FailureClass::NORMALIZATION_STALE, 'format:json / meta.absent']],
            array_map(static fn(array $failure): array => [$failure['class'], $failure['scope']], $report->raised()),
        );
    }

    #[Test]
    public function itRaisesNormalizationOverreachForARowWhoseLocatorNamesATupleField(): void
    {
        $root = SyntheticTree::fixture(SyntheticTree::clean());
        try {
            $raised = static function (NormalizationRule $rule) use ($root): array {
                $report = new GateReport();
                (new NormalizationCheck(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD'], $root), $report, Normalization::fromRules([$rule])))->checkNormalizationScope();

                return array_map(static fn(array $failure): array => [$failure['class'], $failure['scope']], $report->raised());
            };

            self::assertSame(
                [[FailureClass::NORMALIZATION_OVERREACH, 'format:metrics / summary.channel']],
                $raised(new NormalizationRule('format:metrics', 'summary.channel', NormalizationRule::KIND_JSON_PATH, 'test')),
            );
            self::assertSame([], $raised(new NormalizationRule('format:metrics', 'summary.duration', NormalizationRule::KIND_JSON_PATH, 'test')));
        } finally {
            SyntheticTree::remove($root);
        }
    }

    /** A locator need not name a tuple field to reach one: `violations` alone takes every finding with it. */
    #[Test]
    public function itRaisesNormalizationOverreachWhenNormalizingTheFindingsSurfaceChangesItsFindings(): void
    {
        $tree = SyntheticTree::clean();
        $root = SyntheticTree::fixture($tree);
        try {
            $case = Corpus::load($root)->cases[0];
            $findings = $tree['findings']['alpha'];
            $artifact = (string) json_encode(['violations' => $findings, 'meta' => ['clock' => 'now']], \JSON_THROW_ON_ERROR);
            $raised = static function (string $locator) use ($root, $case, $artifact, $findings): array {
                $report = new GateReport();
                $rule = new NormalizationRule('format:json', $locator, NormalizationRule::KIND_JSON_PATH, 'test');
                (new NormalizationCheck(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD'], $root), $report, Normalization::fromRules([$rule])))
                    ->checkNormalizationLeavesFindings('candidate', $case, $artifact, $findings);

                return array_map(static fn(array $failure): array => [$failure['class'], $failure['scope']], $report->raised());
            };

            self::assertSame([[FailureClass::NORMALIZATION_OVERREACH, 'candidate / case:alpha|format:json']], $raised('violations'));
            self::assertSame([], $raised('meta.clock'));
        } finally {
            SyntheticTree::remove($root);
        }
    }

    /**
     * The tracked namespace summary rule was measured on a whole publication;
     * it may move the clock and nothing beside it.
     */
    #[Test]
    public function itChangesOnlyTheMeasuredClockWithTheTrackedNamespaceSummaryRule(): void
    {
        $tracked = Normalization::load(\dirname(__DIR__, 3) . '/finding-gate/normalization.tsv');
        $namespaceRules = array_values(array_filter(
            $tracked->rules(),
            static fn(NormalizationRule $rule): bool => $rule->surface === 'format:summary'
                && str_contains($rule->locator, 'namespace\\: subtree\\:Shop'),
        ));
        self::assertCount(1, $namespaceRules, 'the measured namespace summary rule is present exactly once');
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
        self::assertSame(
            $normalizedSummary,
            $tracked->normalize('format:summary', $namespaceSummary),
            'the tracked namespace summary rule changes only the measured clock in the complete publication',
        );
        self::assertSame(
            $normalizedSummary,
            $tracked->normalize('format:summary', $differentClock),
            'different namespace summary clocks compare equal through the tracked rule',
        );
        foreach (['6 files' => '7 files', '7.50' => '8.50', 'src/Cart.php:8' => 'src/Cart.php:9'] as $before => $after) {
            $changedSummary = str_replace($before, $after, $namespaceSummary);
            $normalizedChanged = $tracked->normalize('format:summary', $changedSummary);
            self::assertSame(
                str_replace($before, $after, $normalizedSummary),
                $normalizedChanged,
                'namespace summary normalization preserves the complete publication beside ' . $before,
            );
            self::assertNotSame($normalizedSummary, $normalizedChanged, 'namespace summary changes remain compared for ' . $before);
        }
        $otherSelector = str_replace('subtree:Shop', 'subtree:ShopElse', $namespaceSummary);
        self::assertSame($otherSelector, $tracked->normalize('format:summary', $otherSelector), 'the measured namespace locator does not match a neighbouring selector');
        self::assertNotSame(
            $normalizedSummary,
            $tracked->normalize('format:summary', $otherSelector),
            'a different namespace selector remains compared',
        );
        self::assertSame($namespaceSummary, $tracked->normalize('format:text', $namespaceSummary), 'the namespace clock rule does not match another format');
        $derivedSummary = NormalizationDeriver::derive([
            ['case:drill-down|format:summary' => $namespaceSummary],
            ['case:drill-down|format:summary' => $differentClock],
        ]);
        self::assertCount(1, $derivedSummary, 'the complete namespace summary derives one measured field');
        $derived = Normalization::fromRules($derivedSummary);
        self::assertSame(
            [$derived->normalize('format:summary', $namespaceSummary), $derived->normalize('format:summary', $differentClock)],
            [$normalizedSummary, $tracked->normalize('format:summary', $differentClock)],
            'the measured and tracked rules produce identical bytes for both captured clock values',
        );
        $activeNamespaceRules = array_values(array_filter(
            $tracked->activeRules(),
            static fn(NormalizationRule $rule): bool => $rule->surface === 'format:summary'
                && str_contains($rule->locator, 'namespace\\: subtree\\:Shop'),
        ));
        self::assertCount(1, $activeNamespaceRules, 'the measured tracked namespace rule is active on the full publication');

        foreach ([
            'before clock' => str_replace('], 0.2s', '], 123 errors, 0.2s', $namespaceSummary),
            'after clock' => str_replace('], 0.2s', '], 0.2s, 123 skipped files', $namespaceSummary),
        ] as $position => $semanticSummary) {
            $normalizedSemantic = $tracked->normalize('format:summary', $semanticSummary);
            self::assertSame($semanticSummary, $normalizedSemantic, 'the namespace rule preserves semantic header text ' . $position);
            self::assertNotSame(
                $normalizedSummary,
                $normalizedSemantic,
                'semantic header text ' . $position . ' remains compared after normalization',
            );
        }
    }

    private static function normalization(): Normalization
    {
        return Normalization::fromRules([
            new NormalizationRule('format:json', 'meta.timestamp', NormalizationRule::KIND_JSON_PATH, 'test'),
            new NormalizationRule('format:json', 'violations.*.seenAt', NormalizationRule::KIND_JSON_PATH, 'test'),
            new NormalizationRule('format:json', 'meta.absent', NormalizationRule::KIND_JSON_PATH, 'test'),
            new NormalizationRule('format:html', 'project.generatedAt', NormalizationRule::KIND_HTML_REPORT_DATA_PATH, 'test'),
            new NormalizationRule('format:summary', '~^(Analysed in ).*()$~m', NormalizationRule::KIND_LINE_REGEX, 'test'),
        ]);
    }
}

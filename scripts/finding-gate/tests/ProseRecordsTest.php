<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\DeclaredFieldMoves;
use QmxFindingGate\Fs;
use QmxFindingGate\GateError;
use QmxFindingGate\ProseRecords;
use QmxFindingGate\PublishedVocabulary;
use QmxFindingGate\ReportRecords;
use QmxFindingGate\Tsv;

final class ProseRecordsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itAllowsOnlyCompleteComparedFieldDescriptorsAndKeepsTheLineMarkerOracle(): void
    {
        $root = Fs::temporaryDirectory('compared-field-descriptor-');
        try {
            foreach ([...ProseRecords::SURFACES, 'check:output:file', 'check:parallel', 'check:baseline', 'check:baseline-source'] as $surface) {
                self::assertContains('message', PublishedVocabulary::comparedFieldsOf($surface));
                Fs::write($root . '/' . DeclaredFieldMoves::INDEX, Tsv::render(DeclaredFieldMoves::COLUMNS, [['case:alpha|' . $surface, 'message', 'Old', 'New', 'Change this exact published message.']]));
                self::assertTrue(DeclaredFieldMoves::load($root)->allows('case:alpha|' . $surface, 'message', 'Old', 'New'));
            }
            self::assertSame([], PublishedVocabulary::valuesOn('format:text', 'src/A.php:3: error[a.b]: Old', 'message'));
            foreach ([['format:github', 'symbol'], ['check:unknown', 'message'], ['format:metrics', 'message'], ['format:text', 'unknown']] as [$surface, $field]) {
                Fs::write($root . '/' . DeclaredFieldMoves::INDEX, Tsv::render(DeclaredFieldMoves::COLUMNS, [['case:alpha|' . $surface, $field, 'Old', 'New', 'Invalid descriptor.']]));
                try {
                    DeclaredFieldMoves::load($root);
                    self::fail('An unavailable compared field was licensed.');
                } catch (GateError $error) {
                    self::assertStringContainsString('where nothing can read that field', $error->getMessage());
                }
            }
        } finally {
            Fs::removeRecursively($root);
        }
    }

    #[Test]
    public function itKeepsParenthesesInsideTheMessageAndMatchesOnlyThePublishedSymbolSuffix(): void
    {
        $finding = self::finding('Message (with parentheses).');
        $text = 'src/A.php:3: error[a.b]: Message (with parentheses). (A::run)' . "\n";
        $entries = ProseRecords::extract('format:text', $text);
        self::assertCount(1, $entries);
        self::assertTrue(ProseRecords::matches('format:text', $entries[0]['fields'], $finding));
        $finding['symbol'] = 'App\\B::run';
        self::assertFalse(ProseRecords::matches('format:text', $entries[0]['fields'], $finding));
        self::assertSame('Message (with parentheses). (A::run)', $entries[0]['fields']['message']);
    }

    #[Test]
    public function itReadsGroupedVerboseLocationsAndRecommendationText(): void
    {
        $finding = self::finding('Diagnostic');
        $finding['recommendation'] = 'Use another operation.';
        $text = "src/A.php (1 violation)\n  ERROR at line 3  A::run\n    Use another operation.  [a.b]\n\n";
        $entries = ProseRecords::extract('format:text-verbose', $text);
        self::assertSame([1, 2], $entries[0]['lines']);
        self::assertTrue(ProseRecords::matches('format:text-verbose', $entries[0]['fields'], $finding));
        self::assertSame("src/A.php (1 violation)\n\n", ProseRecords::erase($text, $entries[0]['lines']));
    }

    #[Test]
    public function itDecodesGithubPropertiesAndDataWithoutDoubleDecodingPercentEscapes(): void
    {
        $finding = self::finding("line\n%0A");
        $finding['file'] = 'src/A,B.php';
        $finding['code'] = 'a:b';
        $text = '::error file=src/A%2CB.php,line=3,title=a%3Ab::line%0A%250A' . "\n";
        $entry = ProseRecords::extract('format:github', $text)[0];
        self::assertTrue(ProseRecords::matches('format:github', $entry['fields'], $finding));
        self::assertSame("line\n%0A", $entry['fields']['message']);
    }

    #[Test]
    public function itReadsSummaryIssueLocationsAndKeepsThePublishedScore(): void
    {
        $text = "  1. [ERR] 42.0  src/A.php:3  [15m]\n         a.b: Diagnostic (A::run)\n";
        $entry = ProseRecords::extract('format:summary', $text)[0];
        self::assertTrue(ProseRecords::matches('format:summary', $entry['fields'], self::finding('Diagnostic')));
        self::assertSame('42.0', $entry['fields']['score']);
        self::assertSame([0, 1], $entry['lines']);
    }

    #[Test]
    public function itTreatsShowSuppressedStdoutAsTheSameFindingText(): void
    {
        $text = 'src/A.php:3: error[a.b]: Diagnostic (A::run)' . "\n";
        self::assertSame(ProseRecords::extract('format:text', $text), ProseRecords::extract('show-suppressed', $text));
        self::assertTrue(ProseRecords::matches('show-suppressed', ProseRecords::extract('show-suppressed', $text)[0]['fields'], self::finding('Diagnostic')));
    }

    /** @return array<string,mixed> */
    private static function finding(string $message): array
    {
        return array_replace(array_fill_keys(ReportRecords::SCHEMAS['json'], null), ['file' => 'src/A.php', 'line' => 3, 'subject' => 'callable:App\\A::run', 'symbol' => 'App\\A::run', 'code' => 'a.b', 'channel' => 'a.b', 'rule' => 'a.b', 'severity' => 'error', 'message' => $message]);
    }
}

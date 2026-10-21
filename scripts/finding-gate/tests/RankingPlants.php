<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use QmxFindingGate\CaseDefinition;
use QmxFindingGate\FailureClass;
use QmxFindingGate\RankingSchema;
use QmxFindingGate\SyntheticTree;
use QmxFindingGate\ValueCheck;

/**
 * Trees that plant one ranking defect each, with the failures the comparison
 * must raise for it and the ones it may raise besides.
 *
 * @phpstan-import-type Specification from SyntheticTree
 */
final class RankingPlants
{
    /** @return list<array{id: string, plant: callable(Specification): Specification, expect: list<array{0: string, 1: string}>, tolerate: list<array{0: string, 1: string}>}> */
    public static function scenarios(): array
    {
        return [
            self::scenario('ranking-capture-metadata', static fn(array $tree): array => self::plantedTree($tree, 'metadata'), [[FailureClass::RANKING_PROJECTION_MISMATCH, 'candidate / case:ranking-metadata|format:json'], [FailureClass::RANKING_PROJECTION_MISMATCH, 'candidate / case:ranking-metadata|format:json']], [[FailureClass::SURFACE_MISMATCH, 'case:ranking-metadata|format:json'], [FailureClass::SURFACE_MISMATCH, 'case:ranking-metadata|check:output:file']]),
            self::scenario('ranking-complete-shape', static fn(array $tree): array => self::plantedTree($tree, 'shape'), [[FailureClass::RANKING_PROJECTION_MISMATCH, 'candidate / case:ranking-shape|format:json'], [FailureClass::RANKING_PROJECTION_MISMATCH, 'candidate / case:ranking-shape|format:json'], [FailureClass::RUN_FAILED, 'candidate-2 / ranking-shape']], [[FailureClass::SURFACE_MISMATCH, 'case:ranking-shape|format:json'], [FailureClass::SURFACE_MISMATCH, 'case:ranking-shape|check:output:file']]),
            self::scenario('ranking-duplicate-ambiguity', static fn(array $tree): array => self::plantedTree($tree, 'ambiguity'), [[FailureClass::RECORD_AMBIGUOUS, 'candidate / case:ranking-ambiguity|format:json'], [FailureClass::RECORD_AMBIGUOUS, 'candidate / case:ranking-ambiguity|format:json'], [FailureClass::RUN_FAILED, 'candidate-2 / ranking-ambiguity']], [[FailureClass::SURFACE_MISMATCH, 'case:ranking-ambiguity|format:json'], [FailureClass::SURFACE_MISMATCH, 'case:ranking-ambiguity|check:output:file']]),
            self::scenario('ranking-unannounced-limit', static fn(array $tree): array => self::plantedTree($tree, 'limit'), [[FailureClass::VALUE_MISMATCH, 'case:ranking-limit|format:json'], [FailureClass::VALUE_MISMATCH, 'case:ranking-limit|check:output:file']], [[FailureClass::SURFACE_MISMATCH, 'case:ranking-limit|format:summary']]),
            self::scenario('ranking-unchanged-order', static fn(array $tree): array => self::plantedTree($tree, 'order'), [[FailureClass::RANKING_ORDER_MISMATCH, 'case:ranking-order|format:json|record:*']], []),
        ];
    }

    /**
     * @param callable(Specification): Specification $plant
     * @param list<array{0: string, 1: string}> $expect
     * @param list<array{0: string, 1: string}> $tolerate
     *
     * @return array{id: string, plant: callable(Specification): Specification, expect: list<array{0: string, 1: string}>, tolerate: list<array{0: string, 1: string}>}
     */
    private static function scenario(string $id, callable $plant, array $expect, array $tolerate): array
    {
        return ['id' => $id, 'plant' => $plant, 'expect' => $expect, 'tolerate' => $tolerate];
    }

    /** @param Specification $tree
     * @return Specification
     */
    private static function plantedTree(array $tree, string $kind): array
    {
        $id = 'ranking-' . $kind;
        $tree = self::fixture($tree, $id);
        $x = $tree['findings']['alpha'][0];
        $y = array_replace($x, ['file' => 'src/Neighbour.php', 'symbol' => 'Replay\\Neighbour::run', 'subject' => 'declaration:callable:Replay\\Neighbour::run@src/Neighbour.php', 'message' => 'Independent neighbour.']);
        $records = $kind === 'ambiguity' ? [$x, $x] : (\in_array($kind, ['order', 'limit'], true) ? [$x, $y] : [$x]);
        $tree['findings'][$id] = $records;
        if ($kind === 'ambiguity') {
            $baseline = ValueCheck::value(['version' => 13, 'scope' => ['src'], 'entries' => [$x['subject'] => [['channel' => $x['channel'], 'magnitudes' => [$x['metricValue'], $x['metricValue']]]]]]);
            $tree['answers']['case:' . $id . '|baseline-file'] = ['stdout' => $baseline, 'file' => $baseline];
        }
        $old = self::issues($records, array_fill(0, \count($records), 30));
        $new = $old;
        if ($kind === 'shape') {
            $new[0]['rank'] = 7;
        } elseif ($kind === 'ambiguity') {
            $new[1]['impactScore'] = 29;
        } elseif ($kind === 'order') {
            $new = self::issues([$y, $x], [30, 30]);
        }
        foreach (['answers' => $old, 'candidateAnswers' => $new] as $side => $issues) {
            $slice = $kind === 'order' ? $issues : ($kind === 'limit' && $side === 'answers' ? \array_slice($issues, 0, 1) : []);
            $document = self::document($records, $slice);
            $answer = ['stdout' => $document, 'ranked' => ['stdout' => self::document($records, $issues)]];
            if ($kind === 'metadata' && $side === 'candidateAnswers') {
                $answer['ranked']['stderr'] = 'A warning unique to the internal capture.';
            }
            $tree[$side]['case:' . $id . '|format:json'] = $answer;
            $tree[$side]['case:' . $id . '|check:output'] = ['file' => $document];
            $tree[$side]['case:' . $id . '|format:summary'] = ['stdout' => self::summary($kind === 'order' ? $old : $slice, $records)];
        }
        return $tree;
    }

    /** @param list<array<string,mixed>> $records
     * @param list<int|float> $scores
     *
     * @return list<array<string,mixed>>
     */
    private static function issues(array $records, array $scores): array
    {
        $issues = [];
        foreach ($records as $index => $record) {
            $issues[] = ['rank' => $index + 1, ...array_intersect_key($record, array_flip(RankingSchema::PROJECTION)), 'impactScore' => $scores[$index], 'coupling.class-rank-share' => null, 'debtMinutes' => $record['techDebtMinutes']];
        }
        return $issues;
    }

    /** @param list<array<string,mixed>> $records
     * @param list<array<string,mixed>> $issues
     */
    private static function document(array $records, array $issues): string
    {
        $counts = [];
        foreach ($records as $record) {
            $rule = (string) $record['rule'];
            $counts[$rule] = ($counts[$rule] ?? 0) + 1;
        }
        return ValueCheck::value(['violations' => $records, 'topIssues' => $issues, 'violationsMeta' => ['total' => \count($records), 'shown' => \count($records), 'truncated' => false, 'byRule' => $counts]]);
    }

    /** @param list<array<string,mixed>> $issues
     * @param list<array<string,mixed>> $records
     */
    private static function summary(array $issues, array $records): string
    {
        $text = "Analysis complete\n";
        foreach ($issues as $issue) {
            foreach ($records as $record) {
                if (RankingSchema::joinKey($record, false) !== RankingSchema::joinKey($issue, true)) {
                    continue;
                }
                $symbol = (string) $record['symbol'];
                $symbol = substr($symbol, (int) strrpos('\\' . $symbol, '\\'));
                $text .= '  ' . $issue['rank'] . '. [ERR] 30.0  ' . $record['file'] . ':' . $record['line'] . "  [15min]\n         " . $record['code'] . ': ' . SyntheticTree::message($record, true) . ' (' . $symbol . ")\n";
                break;
            }
        }
        return $text;
    }

    /**
     * @param Specification $tree
     *
     * @return Specification
     */
    private static function fixture(array $tree, string $id): array
    {
        $tree['cases'][$id] = ['replay.alpha@callable'];
        $tree['findings'][$id] = $tree['findings']['alpha'];
        $tree['declarations']['cases/' . $id . '/case.json'] = ValueCheck::value(['id' => $id, 'description' => 'An independent record publication.', 'coverage' => CaseDefinition::COVERAGE_AUXILIARY, 'paths' => ['src'], 'config' => 'qmx.yaml', 'args' => [], 'channels' => ['replay.alpha@callable']]);
        return $tree;
    }
}

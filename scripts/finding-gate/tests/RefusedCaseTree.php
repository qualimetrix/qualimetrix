<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use QmxFindingGate\CaseOutcome;
use QmxFindingGate\Surfaces;
use QmxFindingGate\SyntheticTree;

/**
 * A synthetic tree whose case `alpha` declares a refused input.
 *
 * @phpstan-import-type Specification from SyntheticTree
 */
final class RefusedCaseTree
{
    /** @return Specification */
    public static function fixture(): array
    {
        $tree = SyntheticTree::clean();
        $tree['cases']['keeper'] = ['replay.alpha@callable'];
        $tree['findings']['keeper'] = [SyntheticTree::finding($tree['tuple'], 'replay.alpha', 'declaration:callable:Replay\\Keeper::run@src/Keeper.php')];
        $tree['declarations']['cases/alpha/case.json'] = json_encode([
            'id' => 'alpha', 'description' => 'A declared input refusal.', 'coverage' => 'auxiliary',
            'paths' => ['src'], 'config' => 'qmx.yaml', 'channels' => ['replay.alpha@callable'],
            'outcome' => ['kind' => CaseOutcome::REFUSAL, 'exit' => 3],
        ], \JSON_THROW_ON_ERROR);
        $envelope = json_encode(['error' => 'Refused input', 'exit_code' => 3, 'position' => null], \JSON_THROW_ON_ERROR) . "\n";
        foreach ([...array_map(static fn(string $format): string => 'format:' . $format, Surfaces::FORMATS), 'show-suppressed', 'check:output', 'check:baseline', 'check:baseline-source', 'check:parallel', 'baseline:generate'] as $surface) {
            $tree['answers']['case:alpha|' . $surface] = ['stdout' => '', 'stderr' => "Refused input\n", 'exit' => 3];
        }
        $tree['answers']['case:alpha|format:json']['stdout'] = $envelope;
        $tree['answers']['case:alpha|baseline:generate']['file'] = '';
        $tree['answers']['case:alpha|check:output'] = ['stdout' => '', 'stderr' => "Refused input\nReport written to {{output}}\n", 'exit' => 3, 'file' => $envelope];
        return $tree;
    }
}

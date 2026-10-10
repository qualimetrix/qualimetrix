<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\CorpusInvalid;
use QmxFindingGate\SyntheticTree;
use Qualimetrix\Tests\Analysis\Finding\Support\CorpusCaseRun;
use RuntimeException;

final class CorpusCaseRunTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itDistinguishesDeclaredOutcomesWithoutInventingEmptyFindings(): void
    {
        foreach ([null, ['kind' => 'refusal', 'exit' => 3], ['kind' => 'incomplete', 'exit' => 4]] as $outcome) {
            $tree = SyntheticTree::clean();
            $definition = ['id' => 'alpha', 'description' => 'A typed observation.', 'paths' => ['src'], 'config' => 'qmx.yaml', 'channels' => ['replay.alpha@callable']];
            if ($outcome !== null) {
                $definition['outcome'] = $outcome;
            }
            $tree['declarations']['cases/alpha/case.json'] = json_encode($definition, \JSON_THROW_ON_ERROR);
            $root = SyntheticTree::fixture($tree);
            try {
                $cases = CorpusCaseRun::cases($root);
                $case = $cases[$root . '/finding-gate/cases/alpha'];
                self::assertSame($outcome === null, CorpusCaseRun::isAnalysis($case));
                if ($outcome !== null) {
                    try {
                        CorpusCaseRun::findings($case->directory, $case);
                        self::fail('A non-analysis case became a complete observation.');
                    } catch (RuntimeException $error) {
                        self::assertStringContainsString('not a complete channel observation', $error->getMessage());
                    }
                }
            } finally {
                SyntheticTree::remove($root);
            }
        }
    }

    #[Test]
    public function itRefusesMalformedOutcomesAndUnknownAnalysisPopulations(): void
    {
        foreach ([['outcome' => ['kind' => 'incomplete', 'exit' => '4']], ['paths' => null], ['paths' => 'src'], ['paths' => [7]], ['paths' => ['named' => 'src']]] as $changes) {
            $tree = SyntheticTree::clean();
            $definition = ['id' => 'alpha', 'description' => 'A typed observation.', 'paths' => ['src'], 'config' => 'qmx.yaml', 'channels' => ['replay.alpha@callable']];
            $tree['declarations']['cases/alpha/case.json'] = json_encode(array_replace($definition, $changes), \JSON_THROW_ON_ERROR);
            $root = SyntheticTree::fixture($tree);
            try {
                try {
                    CorpusCaseRun::cases($root);
                    self::fail('Malformed corpus metadata became an analysis population.');
                } catch (CorpusInvalid $error) {
                    self::assertNotSame('', $error->getMessage());
                }
            } finally {
                SyntheticTree::remove($root);
            }
        }
    }
}

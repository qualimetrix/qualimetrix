<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\Fs;
use QmxFindingGate\Process;
use QmxFindingGate\SyntheticTree;

final class SyntheticTreeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itRequiresAnExactKnownInvocationAndExposesTheChildEnvironment(): void
    {
        $tree = SyntheticTree::clean();
        $tree['answers']['tree|rules'] = ['env' => true];
        $root = SyntheticTree::create($tree);
        $cache = Fs::temporaryDirectory('replay-cache-');
        try {
            $unknown = Process::run([\PHP_BINARY, $root . '/bin/qmx', 'rules'], $root, environmentAdditions: ['QMX_GATE_INVOCATION' => 'tree|unknown']);
            self::assertSame(70, $unknown['exit']);
            self::assertStringContainsString('no answer', $unknown['stderr']);
            $known = Process::run([\PHP_BINARY, $root . '/bin/qmx', 'rules'], $root, environmentAdditions: ['QMX_GATE_INVOCATION' => 'tree|rules', 'XDG_CACHE_HOME' => $cache]);
            self::assertSame(0, $known['exit']);
            self::assertSame(['invocation' => 'tree|rules', 'cache' => $cache, 'locale' => 'C', 'timezone' => 'UTC', 'argv' => ['rules'], 'cwd' => $root], json_decode($known['stdout'], true, 512, \JSON_THROW_ON_ERROR));
        } finally {
            SyntheticTree::remove($root);
            Fs::removeRecursively($cache);
        }
    }
    #[Test]
    public function itPublishesOneSortedSarifCatalogEntryPerCode(): void
    {
        $tree = SyntheticTree::clean();
        $first = SyntheticTree::finding($tree['tuple'], 'replay.alpha', 'declaration:callable:Replay\\Alpha::run@src/Alpha.php');
        $second = SyntheticTree::finding($tree['tuple'], 'replay.alpha', 'declaration:callable:Replay\\Beta::run@src/Beta.php');
        $third = SyntheticTree::finding($tree['tuple'], 'replay.before', 'declaration:callable:Replay\\Before::run@src/Before.php');
        $tree['findings']['alpha'] = [$first, $second, $third];
        $root = SyntheticTree::create($tree);
        try {
            $result = Process::run(
                [\PHP_BINARY, $root . '/bin/qmx', 'check', '-f', 'sarif'],
                $root,
                environmentAdditions: ['QMX_GATE_INVOCATION' => 'case:alpha|format:sarif'],
            );
            self::assertSame(0, $result['exit']);
            $report = json_decode($result['stdout'], true, 512, \JSON_THROW_ON_ERROR);
            $run = $report['runs'][0];
            self::assertSame([['id' => 'replay.alpha'], ['id' => 'replay.before']], $run['tool']['driver']['rules']);
            self::assertSame([0, 0, 1], array_column($run['results'], 'ruleIndex'));
        } finally {
            SyntheticTree::remove($root);
        }
    }

}

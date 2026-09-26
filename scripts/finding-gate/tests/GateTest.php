<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\Fs;
use QmxFindingGate\Process;
use QmxFindingGate\SyntheticTree;

/**
 * What the gate refuses before it runs anything, on a synthetic tree.
 */
final class GateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itRefusesACaseWhoseOutcomeNoRegisteredCheckVerifies(): void
    {
        $tree = SyntheticTree::clean();
        $tree['declarations']['cases/alpha/case.json'] = json_encode([
            'id' => 'alpha',
            'description' => 'A case that is expected to be refused.',
            'paths' => ['src'],
            'config' => 'qmx.yaml',
            'channels' => ['replay.alpha@callable'],
            'outcome' => ['kind' => 'refusal', 'exit' => 3],
        ], \JSON_THROW_ON_ERROR);
        $root = SyntheticTree::create($tree);

        $runtime = Fs::temporaryDirectory('gate-without-outcome-check-');

        try {
            $files = glob(\dirname(__DIR__) . '/*.php');
            self::assertIsArray($files);
            foreach ($files as $file) {
                Fs::write($runtime . '/finding-gate/' . basename($file), Fs::read($file));
            }
            $registration = require \dirname(__DIR__) . '/wiring-outcomes.php';
            unset($registration['caseChecks']);
            Fs::write($runtime . '/finding-gate/wiring-outcomes.php', "<?php\nreturn " . var_export($registration, true) . ";\n");
            $run = Process::run([
                \PHP_BINARY,
                '-r',
                <<<'PHP'
                    require $argv[1];
                    require $argv[2] . '/finding-gate/classes.php';
                    try {
                        new QmxFindingGate\Gate(QmxFindingGate\Options::parse(['gate', '--candidate=' . $argv[3], '--reference=HEAD'], $argv[3]), new QmxFindingGate\GateReport());
                        fwrite(STDERR, 'The gate accepted a case no check holds to its outcome.');
                        exit(1);
                    } catch (QmxFindingGate\GateError $error) {
                        echo $error->getMessage();
                    }
                    PHP,
                \dirname(__DIR__, 3) . '/vendor/autoload.php',
                $runtime,
                $root,
            ], $runtime);
            self::assertSame(0, $run['exit'], $run['stderr']);
            self::assertStringContainsString('no registered check ("refusal")', $run['stdout']);
        } finally {
            Fs::removeRecursively($runtime);
            SyntheticTree::remove($root);
        }
    }
}

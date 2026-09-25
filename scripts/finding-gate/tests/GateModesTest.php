<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\Fs;
use QmxFindingGate\Process;

/**
 * The exit code of the gate's command line, seen from outside the process.
 *
 * The self-test cannot witness its own verdict: a self-test that prints RED
 * and exits 0 is exactly what it would miss. So the entry point runs here as a
 * subprocess — the self-test mode in a copy of the gate whose `SelfTest` is
 * replaced by one with a known answer, the refusal on the real tree. The
 * planted answer lives only in the temporary copy; the tracked gate carries no
 * switch that could plant one.
 */
final class GateModesTest extends TestCase
{
    private ?string $copy = null;

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    protected function tearDown(): void
    {
        if ($this->copy !== null) {
            Fs::removeRecursively($this->copy);
        }
    }

    #[Test]
    public function itExitsOneWhenTheSelfTestFails(): void
    {
        $run = $this->selfTestOfACopyAnswering(['a planted failure']);

        self::assertSame(1, $run['exit'], $run['stdout'] . $run['stderr']);
        self::assertStringContainsString('FAIL  a planted failure', $run['stdout']);
        self::assertStringContainsString('self-test RED (1)', $run['stdout']);
    }

    #[Test]
    public function itExitsZeroWhenTheSelfTestIsGreen(): void
    {
        $run = $this->selfTestOfACopyAnswering([]);

        self::assertSame(0, $run['exit'], $run['stdout'] . $run['stderr']);
        self::assertStringContainsString('self-test green', $run['stdout']);
    }

    #[Test]
    public function itExitsThreeWhenTheGateCannotRun(): void
    {
        $root = \dirname(__DIR__, 3);
        $run = Process::run([\PHP_BINARY, $root . '/scripts/finding-gate.php', '--reference=HEAD', '--cases=nope'], $root);

        self::assertSame(3, $run['exit'], $run['stdout'] . $run['stderr']);
        self::assertStringStartsWith('finding-gate: ', $run['stderr']);
        self::assertStringContainsString('No case selected', $run['stderr']);
    }

    /**
     * @param list<string> $failures
     *
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function selfTestOfACopyAnswering(array $failures): array
    {
        $source = \dirname(__DIR__, 2);
        $this->copy = Fs::temporaryDirectory('gate-modes-test-');
        Fs::write($this->copy . '/scripts/finding-gate.php', Fs::read($source . '/finding-gate.php'));

        $files = glob($source . '/finding-gate/*.php');

        foreach ($files === false ? [] : $files as $file) {
            Fs::write($this->copy . '/scripts/finding-gate/' . basename($file), Fs::read($file));
        }

        Fs::write($this->copy . '/scripts/finding-gate/SelfTest.php', \sprintf(
            "<?php\n\nnamespace QmxFindingGate;\n\nfinal class SelfTest\n{\n    public function __construct(string \$root) {}\n\n"
            . "    /** @return list<string> */\n    public function run(): array\n    {\n        return %s;\n    }\n}\n",
            var_export($failures, true),
        ));

        return Process::run([\PHP_BINARY, $this->copy . '/scripts/finding-gate.php', '--self-test'], $this->copy);
    }
}

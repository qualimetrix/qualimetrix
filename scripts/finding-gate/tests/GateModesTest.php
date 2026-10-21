<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\Process;

/**
 * The exit code of the gate's command line, seen from outside the process.
 */
#[Group('finding-gate-e2e')]
final class GateModesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
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
}

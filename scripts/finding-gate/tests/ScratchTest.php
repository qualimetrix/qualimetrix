<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\Process;

/**
 * The registry of what a run must give back.
 *
 * Driven in a child process: releasing the registry releases everything this
 * process holds, including other tests' scratch directories, and stops
 * interrupts from raising for the rest of the process.
 */
final class ScratchTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itReleasesHoldingsInReverseOnceAndNotACancelledOneAndSurvivesAFailingOne(): void
    {
        $run = Process::run([
            \PHP_BINARY,
            '-r',
            <<<'PHP'
                namespace QmxFindingGate;
                require $argv[1] . '/classes.php';
                $released = [];
                Scratch::hold(static function () use (&$released): void {
                    $released[] = 'first';
                });
                Scratch::hold(static function (): void {
                    throw new GateError('a deliberately failing release');
                });
                $cancel = Scratch::hold(static function () use (&$released): void {
                    $released[] = 'cancelled';
                });
                Scratch::hold(static function () use (&$released): void {
                    $released[] = 'last';
                });
                $cancel();
                Scratch::releaseAll();
                Scratch::releaseAll();
                echo json_encode($released, JSON_THROW_ON_ERROR);
                PHP,
            \dirname(__DIR__),
        ], __DIR__);

        self::assertSame(0, $run['exit'], $run['stderr']);
        self::assertSame('["last","first"]', $run['stdout']);
        self::assertSame("finding-gate: cannot release scratch state: a deliberately failing release\n", $run['stderr']);
    }
}

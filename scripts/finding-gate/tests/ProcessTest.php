<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\GateError;
use QmxFindingGate\Process;

final class ProcessTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itPassesAdditionsToTheChildWhilePreservingFixedAndParentEnvironment(): void
    {
        $before = getenv('XDG_CACHE_HOME');
        $run = Process::run([\PHP_BINARY, '-r', 'echo json_encode([getenv("XDG_CACHE_HOME"),getenv("LC_ALL"),getenv("TZ")]);'], __DIR__, environmentAdditions: ['XDG_CACHE_HOME' => '/tmp/gate-child-cache']);
        self::assertSame(0, $run['exit'], $run['stderr']);
        self::assertSame('["\/tmp\/gate-child-cache","C","UTC"]', $run['stdout']);
        self::assertSame($before, getenv('XDG_CACHE_HOME'));
    }

    /** @return iterable<string,array{string}> */
    public static function fixedKeys(): iterable
    {
        foreach (['PATH', 'HOME', 'LC_ALL', 'TZ', 'COLUMNS', 'NO_COLOR', 'TMPDIR'] as $key) {
            yield $key => [$key];
        }
    }

    #[Test]
    #[DataProvider('fixedKeys')]
    public function itRefusesReplacingAFixedChildEnvironmentKey(string $key): void
    {
        $this->expectException(GateError::class);
        $this->expectExceptionMessage('fixed child environment');
        Process::run([\PHP_BINARY, '-r', 'exit(0);'], __DIR__, environmentAdditions: [$key => 'replacement']);
    }
}

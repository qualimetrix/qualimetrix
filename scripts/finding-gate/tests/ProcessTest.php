<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\GateError;
use QmxFindingGate\Process;
use QmxFindingGate\ProcessHandle;

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
        $inherited = Process::run([\PHP_BINARY, '-r', 'echo getenv("XDG_CACHE_HOME");'], __DIR__);
        self::assertSame(0, $inherited['exit'], $inherited['stderr']);
        self::assertSame($before, $inherited['stdout']);
        $run = Process::run([\PHP_BINARY, '-r', 'echo json_encode([getenv("XDG_CACHE_HOME"),getenv("LC_ALL"),getenv("TZ")]);'], __DIR__, environmentAdditions: ['XDG_CACHE_HOME' => '/tmp/gate-child-cache']);
        self::assertSame(0, $run['exit'], $run['stderr']);
        self::assertSame('["\/tmp\/gate-child-cache","C","UTC"]', $run['stdout']);
        self::assertSame($before, getenv('XDG_CACHE_HOME'));
    }

    #[Test]
    public function itMeasuresChildAgeInSecondsWhenWallTimeMovesBackwards(): void
    {
        $script = <<<'PHP'
            namespace QmxFindingGate;

            $monotonicReads = 0;
            $wallReads = 0;

            function hrtime(bool $asNumber = false): int
            {
                global $monotonicReads;

                return 10_000_000_000 + 1_250_000_000 * $monotonicReads++;
            }

            function microtime(bool $asFloat = false): float
            {
                global $wallReads;

                return 100.0 - 1.25 * $wallReads++;
            }

            require $argv[1];
            $type = new \ReflectionClass(ProcessHandle::class);
            $child = $type->newInstanceWithoutConstructor();
            $pipes = [1 => fopen('php://temp', 'w+'), 2 => fopen('php://temp', 'w+')];
            $type->getConstructor()->invoke($child, null, $pipes, 0);
            $age = $child->age();

            foreach ($pipes as $pipe) {
                fclose($pipe);
            }

            echo json_encode(['age' => $age, 'monotonicReads' => $monotonicReads, 'wallReads' => $wallReads], JSON_THROW_ON_ERROR);
            PHP;

        $run = Process::run([\PHP_BINARY, '-r', $script, \dirname(__DIR__) . '/ProcessHandle.php'], __DIR__);
        self::assertSame(0, $run['exit'], $run['stderr']);
        $measurement = json_decode($run['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(1.25, $measurement['age']);
        self::assertSame(2, $measurement['monotonicReads']);
        self::assertSame(0, $measurement['wallReads']);
    }

    #[Test]
    public function itTransportsGitConfigurationVerbatimAndLeavesValidationToGit(): void
    {
        $names = ['GIT_CONFIG_COUNT', 'GIT_CONFIG_KEY_weird', 'GIT_CONFIG_VALUE_weird', 'GIT_CONFIG_PARAMETERS'];
        $before = [];
        foreach ($names as $name) {
            $before[$name] = getenv($name);
        }

        try {
            putenv('GIT_CONFIG_COUNT=not-a-number');
            putenv('GIT_CONFIG_KEY_weird=maintenance.auto');
            putenv('GIT_CONFIG_VALUE_weird=false');
            putenv('GIT_CONFIG_PARAMETERS=must-not-be-inherited');

            $probe = Process::run([
                \PHP_BINARY,
                '-r',
                'echo json_encode([getenv("GIT_CONFIG_COUNT"), getenv("GIT_CONFIG_KEY_weird"), getenv("GIT_CONFIG_VALUE_weird"), getenv("GIT_CONFIG_PARAMETERS")]);',
            ], __DIR__);
            self::assertSame(0, $probe['exit'], $probe['stderr']);
            self::assertSame('["not-a-number","maintenance.auto","false",false]', $probe['stdout']);

            $git = Process::run(['git', 'config', '--get', 'maintenance.auto'], __DIR__);
            self::assertNotSame(0, $git['exit']);
            self::assertStringContainsString('GIT_CONFIG_COUNT', $git['stderr']);
        } finally {
            foreach ($before as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
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

    #[Test]
    public function itRefusesReplacingEveryInheritedGitConfigurationKey(): void
    {
        $configuration = ProcessHandle::inheritedGitConfiguration();
        self::assertNotEmpty($configuration);

        foreach (array_keys($configuration) as $key) {
            try {
                Process::run([\PHP_BINARY, '-r', 'exit(0);'], __DIR__, environmentAdditions: [$key => 'replacement']);
                self::fail('The inherited Git configuration key was replaceable: ' . $key);
            } catch (GateError $error) {
                self::assertStringContainsString('fixed child environment key ' . $key, $error->getMessage());
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Refusal\OutOfMemoryHint;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

#[CoversClass(OutOfMemoryHint::class)]
final class OutOfMemoryHintProcessTest extends TestCase
{
    #[Test]
    public function itReportsAControlledMemoryFatalOnStderrWithExitFour(): void
    {
        $script = <<<'PHP'
require $argv[1];
\Qualimetrix\Infrastructure\Console\Refusal\OutOfMemoryHint::register();
$used = memory_get_usage(true);
$limit = (int) ceil(($used + 2 * 1024 * 1024) / (1024 * 1024));
ini_set('memory_limit', $limit . 'M');
fwrite(STDOUT, "registered\n");
str_repeat('x', 4 * 1024 * 1024);
PHP;
        $result = $this->runChild($script);

        self::assertSame(4, $result['exitCode'], $result['stderr']);
        self::assertSame("registered\n", $result['stdout']);
        self::assertStringContainsString('Qualimetrix ran out of memory at ', $result['stderr']);
        self::assertMatchesRegularExpression('/memory_limit=\d+M/', $result['stderr']);
        self::assertStringContainsString('raise --memory-limit or memory_limit in qmx.yaml', $result['stderr']);
        self::assertStringNotContainsString('Fatal error', $result['stdout']);
    }

    #[Test]
    public function itLeavesAnUnrelatedFatalOutsideTheMemoryHint(): void
    {
        $result = $this->runChild(<<<'PHP'
require $argv[1];
\Qualimetrix\Infrastructure\Console\Refusal\OutOfMemoryHint::register();
undefined_function_for_oom_test();
PHP);

        self::assertNotSame(4, $result['exitCode']);
        self::assertStringNotContainsString('Qualimetrix ran out of memory', $result['stderr']);
    }

    /** @return array{stdout: string, stderr: string, exitCode: int} */
    private function runChild(string $script): array
    {
        return ChildProcess::run([
            \PHP_BINARY,
            '-d',
            'xdebug.mode=off',
            '-r',
            $script,
            \dirname(__DIR__, 4) . '/vendor/autoload.php',
        ]);
    }
}

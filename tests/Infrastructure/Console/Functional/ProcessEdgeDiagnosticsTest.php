<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

#[CoversClass(Application::class)]
final class ProcessEdgeDiagnosticsTest extends TestCase
{
    #[Test]
    public function itPresentsBuildFailuresInsideTheLadderAndNeverBuildsForVersionOrTypo(): void
    {
        foreach ([['check', 'src', '--format=json'], ['hook:status', '--format=json']] as $arguments) {
            $result = self::brokenContainer($arguments);
            self::assertSame(5, $result['exitCode'], $result['stderr']);
            $document = json_decode($result['stdout'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame(5, $document['exit_code']);
            self::assertStringContainsString('Internal error: bad product tag', $document['error']);
            self::assertStringNotContainsString('Fatal error', $result['stderr']);
        }
        $result = self::brokenContainer(['--version']);
        self::assertSame(0, $result['exitCode'], $result['stderr']);
        $result = self::brokenContainer(['chek', 'src', '--format=json']);
        self::assertSame(3, $result['exitCode'], $result['stderr']);
        self::assertStringContainsString('check', $result['stdout']);
        self::assertStringNotContainsString('bad product tag', $result['stdout']);
    }

    #[Test]
    public function itKeepsPhpShutdownWarningsOffAJsonAnswer(): void
    {
        $script = <<<'PHP'
require $argv[1];
register_shutdown_function(static function (): void { trigger_error('shutdown diagnostic', E_USER_WARNING); });
$hint = \Qualimetrix\Infrastructure\Console\Refusal\OutOfMemoryHint::register();
$app = \Qualimetrix\Infrastructure\Console\Application::forProduct(static function (): never { throw new LogicException('bad product tag'); }, $hint);
exit($app->run(new \Symfony\Component\Console\Input\ArgvInput(['qmx', 'check', '--format=json'])));
PHP;
        $result = ChildProcess::run([\PHP_BINARY, '-d', 'display_errors=1', '-d', 'log_errors=0', '-r', $script, \dirname(__DIR__, 4) . '/vendor/autoload.php']);
        self::assertSame(5, $result['exitCode']);
        self::assertSame(5, json_decode($result['stdout'], true, flags: \JSON_THROW_ON_ERROR)['exit_code']);
        self::assertStringContainsString('shutdown diagnostic', $result['stderr']);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private static function brokenContainer(array $arguments): array
    {
        $script = <<<'PHP'
require $argv[1];
$hint = \Qualimetrix\Infrastructure\Console\Refusal\OutOfMemoryHint::register();
$app = \Qualimetrix\Infrastructure\Console\Application::forProduct(static function (): never { throw new LogicException('bad product tag'); }, $hint);
exit($app->run(new \Symfony\Component\Console\Input\ArgvInput(array_merge(['qmx'], array_slice($argv, 2)))));
PHP;

        return ChildProcess::run([\PHP_BINARY, '-d', 'log_errors=0', '-r', $script, \dirname(__DIR__, 4) . '/vendor/autoload.php', ...$arguments]);
    }
}

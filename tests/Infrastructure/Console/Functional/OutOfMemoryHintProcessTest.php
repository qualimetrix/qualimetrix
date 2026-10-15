<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertStringContainsString('undefined_function_for_oom_test', $result['stderr']);
        self::assertSame('', $result['stdout']);
    }

    #[Test]
    public function itPublishesAJsonEnvelopeAfterCapturingFatalAndReleasingTargets(): void
    {
        $result = $this->runChild(<<<'PHP'
require $argv[1];
$hint = \Qualimetrix\Infrastructure\Console\Refusal\OutOfMemoryHint::register();
$diagnostics = \Qualimetrix\Infrastructure\Console\ConsoleDiagnostics::around($hint);
$hint->answerThrough($diagnostics->refusalPresenter, new \Symfony\Component\Console\Output\ConsoleOutput(), 'json');
$hint->limitSetBy(\Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin::of(\Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource::CommandLine, '--memory-limit'));
$remove = $hint->onExhaustion(static function (): void { fwrite(STDERR, 'removed callback'); });
$remove();
$hint->onExhaustion(static function (): void { trigger_error('release warning', E_USER_WARNING); });
$hint->onExhaustion(static function (): void { throw new LogicException('release failed'); });
$limit = (int) ceil((memory_get_usage(true) + 2 * 1024 * 1024) / (1024 * 1024));
ini_set('memory_limit', $limit . 'M');
str_repeat('x', 4 * 1024 * 1024);
PHP);

        self::assertSame(4, $result['exitCode'], $result['stderr']);
        $document = json_decode($result['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(4, $document['exit_code']);
        self::assertStringContainsString('--memory-limit', $document['error']);
        self::assertStringContainsString('release warning', $result['stderr']);
        self::assertStringNotContainsString('removed callback', $result['stderr']);
        self::assertStringNotContainsString('Fatal error', $result['stderr']);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function appliedLimitSources(): iterable
    {
        yield 'command line' => ['cli', 'source=the command line', false];
        yield 'configuration file' => ['qmx.yaml', 'source=configuration file "qmx.yaml"', false];
        yield 'next run restores ini source' => ['cli', 'source=php.ini', true];
    }

    #[Test]
    #[DataProvider('appliedLimitSources')]
    public function itCarriesTheAppliedLimitSourceThroughTheControllerAndClearsItOnReset(string $source, string $expected, bool $reset): void
    {
        $script = <<<'PHP'
require $argv[1];
$hint = \Qualimetrix\Infrastructure\Console\Refusal\OutOfMemoryHint::register();
$controller = new \Qualimetrix\Infrastructure\Console\RuntimeLimitsController($hint);
$document = \Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument::of(
    [['source' => '__SOURCE__', 'values' => ['memory_limit' => '-1']]],
    \Qualimetrix\Core\Path\AbsolutePath::fromString('/project'),
);
$controller->apply(\Qualimetrix\Infrastructure\Console\RuntimeLimits::fromResolvedValue($document->resolved()->get('memory_limit')));
if (__RESET__) {
    $controller->reset();
}
$limit = (int) ceil((memory_get_usage(true) + 2 * 1024 * 1024) / (1024 * 1024));
ini_set('memory_limit', $limit . 'M');
str_repeat('x', 4 * 1024 * 1024);
PHP;
        $result = $this->runChild(str_replace(['__SOURCE__', '__RESET__'], [$source, $reset ? 'true' : 'false'], $script));
        self::assertSame(4, $result['exitCode'], $result['stderr']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString($expected, $result['stderr']);
        self::assertStringNotContainsString('Fatal error', $result['stderr']);
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

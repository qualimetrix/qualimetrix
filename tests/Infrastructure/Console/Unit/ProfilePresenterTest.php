<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\ProfilePresenter;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargets;
use Qualimetrix\Infrastructure\Logging\LoggerFactory;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileFormat;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileReportInterface;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileSummary;
use Qualimetrix\Subprocess\ChildProcess;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

/**
 * The precheck before analysis is not a guarantee, so the write after it
 * refuses on its own: a failed export is a typed refusal, never a line printed
 * beside a run that then reports success.
 */
#[CoversClass(ProfilePresenter::class)]
final class ProfilePresenterTest extends TestCase
{
    #[Test]
    public function itRefusesAnExportWhoseWriteFails(): void
    {
        $target = sys_get_temp_dir() . '/qmx-profile-fault-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($target, 'old');
        $script = <<<'PHP'
            namespace Qualimetrix\Core\FileTarget {
                function fwrite($stream, string $bytes): int|false
                {
                    ++$GLOBALS['qmx_profile_hit'];
                    return 0;
                }
            }
            namespace {
                require $argv[1];
                $GLOBALS['qmx_profile_hit'] = 0;
                $target = $argv[2];
                $targets = new \Qualimetrix\Infrastructure\Console\RunTarget\RunTargets(new \Qualimetrix\Infrastructure\Logging\LoggerFactory());
                $targets->judge('--profile', $target);
                $targets->claim();
                $report = new class implements \Qualimetrix\Infrastructure\Profiler\Contract\ProfileReportInterface {
                    public function isEnabled(): bool { return true; }
                    public function summary(): \Qualimetrix\Infrastructure\Profiler\Contract\ProfileSummary { throw new \LogicException('Summary is not requested.'); }
                    public function export(\Qualimetrix\Infrastructure\Profiler\Contract\ProfileFormat $format): string { return '{"profile":true}'; }
                };
                $input = new \Symfony\Component\Console\Input\ArrayInput(
                    ['--profile' => $target],
                    new \Symfony\Component\Console\Input\InputDefinition([
                        new \Symfony\Component\Console\Input\InputOption('profile', null, \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL, '', false),
                        new \Symfony\Component\Console\Input\InputOption('profile-format', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, '', 'json'),
                    ]),
                );
                $presenter = new \Qualimetrix\Infrastructure\Console\ProfilePresenter($report, new \Qualimetrix\Infrastructure\Console\ErrorStream());
                try {
                    $presenter->present($input, new \Symfony\Component\Console\Output\BufferedOutput(), $targets);
                    $failure = null;
                } catch (\Qualimetrix\Infrastructure\Console\Refusal\EnvironmentRefusal $caught) {
                    $failure = $caught->summary();
                }
                $targets->abandon();
                echo json_encode(['hit' => $GLOBALS['qmx_profile_hit'], 'failure' => $failure, 'content' => file_get_contents($target)]);
            }
            PHP;

        try {
            $run = ChildProcess::run([\PHP_BINARY, '-r', $script, \dirname(__DIR__, 4) . '/vendor/autoload.php', $target]);
            self::assertSame(0, $run['exitCode'], $run['stderr']);
            $result = json_decode($run['stdout'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertGreaterThan(0, $result['hit']);
            self::assertStringContainsString('--profile', $result['failure']);
            self::assertSame('', $result['content']);
        } finally {
            unlink($target);
        }
    }

    #[Test]
    public function itWritesAnExportItCanWrite(): void
    {
        $target = sys_get_temp_dir() . '/qmx-profile-' . bin2hex(random_bytes(6)) . '.json';
        $presenter = new ProfilePresenter($this->enabledReport(), new ErrorStream());
        $targets = new RunTargets(new LoggerFactory());

        try {
            $targets->judge('--profile', $target);
            $targets->claim();
            $presenter->present($this->input($target), new BufferedOutput(), $targets);

            self::assertSame('{"format":"json"}', file_get_contents($target));
        } finally {
            $targets->abandon();
            if (file_exists($target)) {
                unlink($target);
            }
        }
    }

    private function input(string $target): ArrayInput
    {
        return new ArrayInput(
            ['--profile' => $target, '--profile-format' => 'json'],
            new InputDefinition([
                new InputOption('profile', null, InputOption::VALUE_OPTIONAL, '', false),
                new InputOption('profile-format', null, InputOption::VALUE_REQUIRED, '', 'json'),
            ]),
        );
    }

    private function enabledReport(): ProfileReportInterface
    {
        return new class implements ProfileReportInterface {
            public function isEnabled(): bool
            {
                return true;
            }

            public function summary(): ProfileSummary
            {
                throw new LogicException('Not asked for by an export.');
            }

            public function export(ProfileFormat $format): string
            {
                return \sprintf('{"format":"%s"}', $format->value);
            }
        };
    }
}

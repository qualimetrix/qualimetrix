<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\ProfilePresenter;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileFormat;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileReportInterface;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileSummary;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

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
        $presenter = new ProfilePresenter($this->enabledReport(), new ErrorStream());

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('--profile');

        $presenter->present($this->input('/nonexistent-qmx-directory/p.json'), new BufferedOutput());
    }

    #[Test]
    public function itWritesAnExportItCanWrite(): void
    {
        $target = sys_get_temp_dir() . '/qmx-profile-' . bin2hex(random_bytes(6)) . '.json';
        $presenter = new ProfilePresenter($this->enabledReport(), new ErrorStream());

        try {
            $presenter->present($this->input($target), new BufferedOutput());

            self::assertSame('{"format":"json"}', file_get_contents($target));
        } finally {
            @unlink($target);
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

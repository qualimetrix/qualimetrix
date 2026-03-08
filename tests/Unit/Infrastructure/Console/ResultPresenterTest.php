<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Console;

use AiMessDetector\Baseline\BaselineGenerator;
use AiMessDetector\Baseline\BaselineWriter;
use AiMessDetector\Baseline\ViolationHasher;
use AiMessDetector\Core\Profiler\ProfilerHolder;
use AiMessDetector\Core\Profiler\ProfilerInterface;
use AiMessDetector\Infrastructure\Console\ResultPresenter;
use AiMessDetector\Reporting\Formatter\FormatterRegistryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversClass(ResultPresenter::class)]
final class ResultPresenterTest extends TestCase
{
    private ResultPresenter $presenter;

    protected function setUp(): void
    {
        $this->presenter = new ResultPresenter(
            formatterRegistry: $this->createMock(FormatterRegistryInterface::class),
            profilerHolder: new ProfilerHolder(),
            baselineGenerator: new BaselineGenerator(new ViolationHasher()),
            baselineWriter: new BaselineWriter(),
        );
    }

    protected function tearDown(): void
    {
        ProfilerHolder::reset();
    }

    #[Test]
    public function presentProfileShowsErrorWhenTmpFileWriteFails(): void
    {
        // Enable profiler
        $profiler = $this->createMock(ProfilerInterface::class);
        $profiler->method('isEnabled')->willReturn(true);
        $profiler->method('export')->willReturn('{"test":"data"}');
        ProfilerHolder::set($profiler);

        // Use a non-existent directory to trigger write failure
        $invalidPath = '/non/existent/dir/profile.json';

        $input = $this->createStub(InputInterface::class);
        $input->method('getOption')->willReturnCallback(
            static fn(string $name): mixed => match ($name) {
                'profile' => $invalidPath,
                'profile-format' => 'json',
                default => null,
            },
        );

        $output = $this->createMock(OutputInterface::class);

        // Should output an error message, not a success message
        $output->expects(self::once())
            ->method('writeln')
            ->with(
                self::stringContains('Failed to write profile data'),
                self::anything(),
            );

        $this->presenter->presentProfile($input, $output);
    }

    #[Test]
    public function presentProfileShowsSuccessOnValidWrite(): void
    {
        // Enable profiler
        $profiler = $this->createMock(ProfilerInterface::class);
        $profiler->method('isEnabled')->willReturn(true);
        $profiler->method('export')->willReturn('{"test":"data"}');
        ProfilerHolder::set($profiler);

        $tmpDir = sys_get_temp_dir();
        $profilePath = $tmpDir . '/test_profile_' . getmypid() . '.json';

        $input = $this->createStub(InputInterface::class);
        $input->method('getOption')->willReturnCallback(
            static fn(string $name): mixed => match ($name) {
                'profile' => $profilePath,
                'profile-format' => 'json',
                default => null,
            },
        );

        $output = $this->createMock(OutputInterface::class);

        $output->expects(self::once())
            ->method('writeln')
            ->with(
                self::stringContains('Profile exported to'),
                self::anything(),
            );

        $this->presenter->presentProfile($input, $output);

        // Cleanup
        if (file_exists($profilePath)) {
            unlink($profilePath);
        }
    }
}

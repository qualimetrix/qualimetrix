<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargetClaimLifecycle;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

#[CoversClass(RunTargetClaimLifecycle::class)]
final class ExhaustionReleasesTargetsTest extends TestCase
{
    #[Test]
    public function itReleasesUnpublishedReportAndProfileTargetsWhenTheCoordinatorRunsOutOfMemory(): void
    {
        $directory = sys_get_temp_dir() . '/qmx-oom-targets-' . bin2hex(random_bytes(6));
        $project = sys_get_temp_dir() . '/qmx-oom-project-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        self::assertTrue(mkdir($project . '/src', 0777, true));
        self::assertNotFalse(file_put_contents($project . '/composer.json', '{"autoload":{"psr-4":{"Fixture\\\\":"src/"}}}'));
        self::assertNotFalse(file_put_contents($project . '/src/Source.php', '<?php namespace Fixture; final class Source {}'));
        $report = $directory . '/report.json';
        $profile = $directory . '/profile.json';
        self::assertSame(3, file_put_contents($report, 'OLD'));

        try {
            $run = ChildProcess::run([
                \PHP_BINARY,
                '-r',
                self::applicationWithExhaustedPipeline(),
                'check',
                'src',
                '--workers=0',
                '--no-cache',
                '--no-progress',
                '--memory-limit=64M',
                '--format=json',
                '--output=' . $report,
                '--profile=' . $profile,
            ], $project);

            self::assertSame(4, $run['exitCode'], $run['stdout'] . $run['stderr']);
            /** @var array{error: string, exit_code: int} $answer */
            $answer = json_decode($run['stdout'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame(4, $answer['exit_code']);
            self::assertStringContainsString('ran out of memory', $answer['error']);
            self::assertStringContainsString('memory_limit=64M, source=option --memory-limit', $answer['error']);
            self::assertSame('', $run['stderr']);
            self::assertSame('OLD', file_get_contents($report));
            self::assertFileDoesNotExist($profile);
            self::assertSame(['report.json'], array_values(array_diff((array) scandir($directory), ['.', '..'])));
        } finally {
            if (is_file($report)) {
                unlink($report);
            }
            if (is_file($profile)) {
                unlink($profile);
            }
            foreach (array_diff((array) scandir($directory), ['.', '..']) as $entry) {
                unlink($directory . '/' . $entry);
            }
            rmdir($directory);
            unlink($project . '/composer.json');
            unlink($project . '/src/Source.php');
            rmdir($project . '/src');
            rmdir($project);
        }
    }

    private static function applicationWithExhaustedPipeline(): string
    {
        return str_replace(
            '__AUTOLOAD__',
            var_export(\dirname(__DIR__, 4) . '/vendor/autoload.php', true),
            <<<'PHP'
require __AUTOLOAD__;

$hint = \Qualimetrix\Infrastructure\Console\Refusal\OutOfMemoryHint::register();
exit(\Qualimetrix\Infrastructure\Console\Application\Application::forProduct(
    static function (\Qualimetrix\Infrastructure\Console\Application\ConsoleDiagnostics $diagnostics): \Symfony\Component\DependencyInjection\ContainerBuilder {
        $container = (new \Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory())->configure($diagnostics);
        $container->getDefinition('qmx.run.analysis_pipeline')->setSynthetic(true);
        $container->compile();
        $container->set('qmx.run.analysis_pipeline', new class implements \Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface {
            public function analyze(\Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration $configuration): \Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult
            {
                str_repeat('x', 256 * 1024 * 1024);
            }
        });

        return $container;
    },
    $hint,
)->run());
PHP,
        );
    }
}

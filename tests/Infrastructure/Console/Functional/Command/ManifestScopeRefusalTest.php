<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Symfony\Component\Process\Process;

#[CoversClass(CheckCommand::class)]
final class ManifestScopeRefusalTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>, int, ?string}> */
    public static function provideSourceScopes(): iterable
    {
        yield 'invalid defaults refuse' => ['{invalid', [], 3, null];
        yield 'array root defaults refuse' => ['[]', [], 3, null];
        yield 'all records rejected defaults refuse' => ['{"autoload":{"files":[false]}}', [], 3, null];
        yield 'partial inferred root withholds' => ['{"autoload":{"classmap":["",false]}}', [], 0, 'unmeasured'];
        yield 'same partial authored root judges' => ['{"autoload":{"classmap":["",false]}}', ['.'], 0, 'unknown'];
        yield 'invalid authored subset withholds' => ['{invalid', ['src'], 0, 'unmeasured'];
        yield 'invalid authored root judges' => ['{invalid', ['.'], 0, 'unknown'];
    }

    /** @param list<string> $paths */
    #[Test]
    #[DataProvider('provideSourceScopes')]
    public function itPublishesTheMeasuredVerdictOrRefusesUnusableDefaults(string $manifest, array $paths, int $exit, ?string $state): void
    {
        $root = sys_get_temp_dir() . '/qmx-manifest-scope-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0777, true);
        file_put_contents($root . '/src/A.php', '<?php namespace App; final class A {}');
        file_put_contents($root . '/composer.json', $manifest);
        try {
            $process = new Process([\PHP_BINARY, \dirname(__DIR__, 5) . '/bin/qmx', 'check', ...$paths, '--working-dir=' . $root, '--no-cache', '--workers=0', '--format=json', '--fail-on=error']);
            $process->run();
            self::assertSame($exit, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            $report = json_decode($process->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
            if ($state === null) {
                self::assertSame(3, $report['exit_code']);
                self::assertStringContainsString('Cannot infer analysis paths', $report['error']);
                self::assertArrayNotHasKey('projectScope', $report);
                self::assertStringContainsString('Cannot infer analysis paths', $process->getOutput());
            } else {
                self::assertSame($state, $report['projectScope']['state']);
                self::assertNotEmpty($report['projectScope']['reasons']);
                self::assertCount($state === 'unmeasured' ? 8 : 0, $report['projectScope']['unjudgedChannels']);
            }
        } finally {
            unlink($root . '/src/A.php');
            unlink($root . '/composer.json');
            rmdir($root . '/src');
            rmdir($root);
        }
    }
}

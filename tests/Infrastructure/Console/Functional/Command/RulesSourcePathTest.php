<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\RulesCommand;
use Symfony\Component\Process\Process;

#[CoversClass(RulesCommand::class)]
final class RulesSourcePathTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function provideRefusedDocuments(): iterable
    {
        yield 'auto-discovered computed metric' => [self::computedMetricDocument(), false];
        yield 'auto-discovered malformed YAML' => ["rules: [\n", false];
        yield 'explicit external computed metric' => [self::computedMetricDocument(), true];
        yield 'explicit external malformed YAML' => ["rules: [\n", true];
    }

    #[Test]
    #[DataProvider('provideRefusedDocuments')]
    public function itNamesTheAuthoredSourceWithoutLeakingAnAutoDiscoveredRoot(string $document, bool $explicit): void
    {
        $base = sys_get_temp_dir() . '/qmx-rules-source-' . bin2hex(random_bytes(6));
        mkdir($base);
        $external = $base . '/external.yaml';
        if ($explicit) {
            file_put_contents($external, $document);
        }

        try {
            $errors = [];
            foreach (['a', 'b'] as $name) {
                $root = $base . '/' . $name;
                mkdir($root);
                if (!$explicit) {
                    file_put_contents($root . '/qmx.yaml', $document);
                }

                $arguments = [\PHP_BINARY, \dirname(__DIR__, 5) . '/bin/qmx', 'rules', '--working-dir=' . $root, '--no-ansi'];
                if ($explicit) {
                    $arguments[] = '--config=' . $external;
                }
                $process = new Process($arguments);
                $process->run();
                self::assertSame(3, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
                $errors[] = $process->getErrorOutput();
                self::assertStringContainsString(
                    'Source: configuration file "' . ($explicit ? $external : 'qmx.yaml') . '".',
                    $process->getErrorOutput(),
                );
                if (!$explicit) {
                    self::assertStringNotContainsString($base, $process->getErrorOutput());
                }
            }

            self::assertSame($errors[0], $errors[1]);
        } finally {
            foreach (['a', 'b'] as $name) {
                if (!$explicit && file_exists($base . '/' . $name . '/qmx.yaml')) {
                    unlink($base . '/' . $name . '/qmx.yaml');
                }
                if (is_dir($base . '/' . $name)) {
                    rmdir($base . '/' . $name);
                }
            }
            if ($explicit) {
                unlink($external);
            }
            rmdir($base);
        }
    }

    private static function computedMetricDocument(): string
    {
        return <<<'YAML'
computed_metrics:
  computed.class-load:
    formula: 'm["size.method-count"] + 1'
    levels: [class]
  computed.project-load:
    formula: 'm["computed.class-load"] + 1'
    levels: [project]
YAML;
    }
}

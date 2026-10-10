<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RfcResolvedRunTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx-rfc-run-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/src', 0o755, true);
        file_put_contents($this->root . '/src/Types.php', '<?php namespace App; class ParentType { function inherited() {} } trait Work { function run() { $this->inherited(); } } interface Contract { function required(); }');
        file_put_contents($this->root . '/src/First.php', '<?php namespace App; class Twin extends ParentType { use Work { run as alias; } function own() { $this->alias(); } } if (false) { class Twin { function own() {} } }');
        file_put_contents($this->root . '/src/Second.php', '<?php namespace App; class Twin { function own() { External::call(); } } class Bare {}');
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    #[Test]
    public function itPreservesExactResolvedMetricsAcrossWorkersAndWarmAstCache(): void
    {
        $cold = $this->responses(0);
        self::assertSame($cold, $this->responses(0));
        self::assertSame($cold, $this->responses(2));
        $twins = array_filter($cold, static fn(array $symbol): bool => $symbol['name'] === 'App\\Twin');
        self::assertSame([4, 1, 2], array_column(array_values($twins), 'rfc'));
        foreach ($cold as $symbol) {
            if ($symbol['type'] === 'project' || ($symbol['type'] === 'namespace' && $symbol['name'] === 'App')) {
                self::assertSame(11, $symbol['rfc']);
                self::assertSame(7, $symbol['count']);
            }
            if ($symbol['name'] === 'App\\Bare') {
                self::assertSame(0, $symbol['rfc']);
            }
        }
    }

    /** @return list<array{type: string, name: string, subject: string, rfc: int, count: int}> */
    private function responses(int $workers): array
    {
        $process = new Process([
            \PHP_BINARY, \dirname(__DIR__, 5) . '/bin/qmx', 'check', 'src',
            '--workers=' . $workers, '--no-progress', '--format=metrics', '--fail-on=none',
        ], $this->root);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        /** @var array{symbols: list<array{type: string, name: string, subject: string, metrics: array<string, int>}>} $report */
        $report = json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
        $responses = [];
        foreach ($report['symbols'] as $symbol) {
            if (isset($symbol['metrics']['coupling.rfc'])) {
                $responses[] = ['type' => $symbol['type'], 'name' => $symbol['name'], 'subject' => $symbol['subject'], 'rfc' => $symbol['metrics']['coupling.rfc'], 'count' => 1];
            } elseif (isset($symbol['metrics']['coupling.rfc.sum'])) {
                $responses[] = ['type' => $symbol['type'], 'name' => $symbol['name'], 'subject' => $symbol['subject'], 'rfc' => $symbol['metrics']['coupling.rfc.sum'], 'count' => $symbol['metrics']['coupling.rfc.count']];
            }
        }

        return $responses;
    }

    private static function removeTree(string $path): void
    {
        if (is_dir($path)) {
            $entries = scandir($path);
            foreach ($entries === false ? [] : $entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }
}

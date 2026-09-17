<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ModularOwnership;

use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModularArchitectureGovernanceIntegrationTest extends TestCase
{
    #[Group('live-freshness')]
    #[Test]
    public function itChecksEveryGeneratedProjectionWithoutWriting(): void
    {
        [$exitCode, $output] = $this->runProcess([
            \PHP_BINARY,
            $this->root() . '/scripts/generate-modular-architecture.php',
            '--check',
        ]);

        self::assertSame(0, $exitCode, $output);
    }

    #[Test]
    public function itRoutesFreshnessOraclesExactlyOnceThroughAggregateCheck(): void
    {
        $this->assertFreshnessScriptGraph($this->composer());
    }

    /** @param array{scripts: array<string, string|list<string>>} $composer */
    private function assertFreshnessScriptGraph(array $composer): void
    {
        $scripts = $composer['scripts'];

        self::assertSame(
            ['Composer\\Config::disableProcessTimeout', 'phpunit --no-coverage --exclude-group=benchmark'],
            $scripts['test'],
            'Standalone composer test must retain its full freshness coverage.',
        );
        self::assertSame(
            [
                'Composer\\Config::disableProcessTimeout',
                'python3 scripts/phpunit-aggregate.py',
            ],
            $scripts['test:aggregate'],
        );
        self::assertSame(['@architecture:check', '@selfcheck:analysis'], $scripts['selfcheck']);
        self::assertSame(
            'php bin/qmx check src/ --baseline=qmx-baseline.json --fail-on=warning --memory-limit=512M',
            $scripts['selfcheck:analysis'],
        );
        self::assertSame('@test:aggregate', $this->scriptSteps($scripts, 'check:code')[2]);
        self::assertContains('@architecture:check', $this->scriptSteps($scripts, 'check:artifacts'));
        self::assertContains('@suppression-snapshot:check', $this->scriptSteps($scripts, 'check:artifacts'));
        self::assertContains(
            "python3 -m unittest discover -s tests/System/TestRunnerConfiguration/Tests -p 'test_*.py'",
            $this->scriptSteps($scripts, 'test:cross-tool'),
        );
        self::assertSame(['@gate:self-test', '@selfcheck:analysis', '@directives:audit'], $scripts['check:self']);
    }

    #[Test]
    public function itPublishesOnlyPermanentExactCompositionBindingsForDiInternals(): void
    {
        $manifest = $this->manifest();
        self::assertSame(2, $manifest['version']);
        self::assertArrayNotHasKey('temporary_internal_grants', $manifest);

        $bindings = [];
        foreach ($manifest['declarations'] as $target => $declaration) {
            foreach ($declaration['consumers'] as $consumer) {
                if (($consumer['relation'] ?? 'import') !== 'composition_binding') {
                    continue;
                }
                self::assertSame('internal', $declaration['visibility']);
                self::assertSame('Infrastructure.DependencyInjection', $consumer['owner']);
                self::assertNull($consumer['closes_in']);
                self::assertNotEmpty($consumer['operations']);
                self::assertArrayHasKey($consumer['source_fqcn'], $manifest['declarations']);
                self::assertNotSame($consumer['source_fqcn'], $target);
                $bindings[$consumer['source_fqcn'] . "\0" . $target] = true;
            }
        }
        self::assertNotEmpty($bindings);

        $rows = $this->tsv('production-composition-bindings.tsv');
        self::assertCount(\count($bindings), $rows);
        foreach ($rows as $row) {
            self::assertSame('used', $row['behavioral_verdict']);
            self::assertNotSame('', $row['qmx_projection']);
            self::assertSame($row['declared_operations'], $row['observed_operations']);
            self::assertNotSame('', $row['observed_operations']);
        }
        self::assertContains('service_alias,service_reference,service_registration', array_column($rows, 'observed_operations'));
        self::assertContains('conditional_service_reference', array_column($rows, 'observed_operations'));
        self::assertContains('definition_argument_mutation', array_column($rows, 'observed_operations'));
    }

    #[Test]
    public function itPublishesTheReviewedTopologyEvidenceAndRejectsProductionToTestImports(): void
    {
        self::assertCount(28, $this->tsv('test-orphan-dispositions.tsv'));
        self::assertCount(3, $this->tsv('test-system-support-owners.tsv'));
        self::assertSame([], $this->tsv('production-to-test-imports.tsv'));
        self::assertNotEmpty($this->tsv('production-public-imports.tsv'));
        self::assertNotEmpty($this->tsv('production-module-fan-in.tsv'));
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $contents = file_get_contents($this->root() . '/docs/internal/modular-architecture-manifest.json');
        self::assertIsString($contents);
        $manifest = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        return $manifest;
    }

    /** @return array{scripts: array<string, string|list<string>>} */
    private function composer(): array
    {
        $contents = file_get_contents($this->root() . '/composer.json');
        self::assertIsString($contents);
        $composer = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertArrayHasKey('scripts', $composer);
        self::assertIsArray($composer['scripts']);

        return $composer;
    }

    /**
     * @param array<string, string|list<string>> $scripts
     *
     * @return list<string>
     */
    private function scriptSteps(array $scripts, string $name): array
    {
        self::assertArrayHasKey($name, $scripts);
        $steps = $scripts[$name];

        return \is_string($steps) ? [$steps] : $steps;
    }

    /** @return list<array<string, string>> */
    private function tsv(string $name): array
    {
        $lines = file($this->root() . '/docs/internal/generated/modular-architecture/' . $name, \FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        $headerLine = array_shift($lines);
        self::assertIsString($headerLine);
        $header = array_map(
            static function (?string $column): string {
                if (!\is_string($column)) {
                    throw new LogicException('TSV header contains a non-string column.');
                }

                return $column;
            },
            str_getcsv($headerLine, "\t", '"', '\\'),
        );

        return array_map(
            static function (string $line) use ($header): array {
                $row = array_combine($header, str_getcsv($line, "\t", '"', '\\'));

                return array_map(static fn(?string $value): string => $value ?? '', $row);
            },
            array_values(array_filter($lines, static fn(string $line): bool => $line !== '')),
        );
    }

    /** @param list<string> $command
     * @return array{int, string}
     */
    private function runProcess(array $command, ?string $workingDirectory = null): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workingDirectory ?? $this->root());
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    private function root(): string
    {
        $root = realpath(__DIR__ . '/../../');
        self::assertIsString($root);

        return $root;
    }
}

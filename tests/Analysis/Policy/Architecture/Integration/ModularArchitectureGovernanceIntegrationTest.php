<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Integration;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModularArchitectureGovernanceIntegrationTest extends TestCase
{
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
    public function itRejectsCompositionBindingsWhenOnlyIdentityEvidenceRemains(): void
    {
        $cases = [
            ['OutputConfigurator.php', 'register(BaselineCleanupCommand::class)', "register('unrelated.service')"],
            ['AnalysisConfigurator.php', 'new Reference(DelegatingLogger::class)', "new Reference('unrelated.service')"],
            ['OutputConfigurator.php', 'setAlias(BaselineRunInterface::class, BaselineRun::class)', 'setUnrelatedAlias(BaselineRunInterface::class, BaselineRun::class)'],
            ['OutputConfigurator.php', '$container->register(BaselineCleanupCommand::class)', '$unrelated->register(BaselineCleanupCommand::class)'],
            ['OutputConfigurator.php', '$container->setAlias(BaselineRunInterface::class, BaselineRun::class)', '$unrelated->setAlias(BaselineRunInterface::class, BaselineRun::class)'],
            ['ChannelDeclarationCompilerPass.php', '->setArgument(', '->setUnrelatedArgument('],
            ['RuleOptionsCompilerPass.php', 'new Reference($serviceId)', "new Reference('unrelated.service')"],
        ];

        foreach ($cases as [$file, $needle, $replacement]) {
            $sourcePath = $this->sourcePath($file);
            $source = file_get_contents($sourcePath);
            self::assertIsString($source);
            self::assertStringContainsString($needle, $source, $file);
            $override = str_replace($needle, $replacement, $source, $replacements);
            self::assertGreaterThan(0, $replacements, $file);

            $overridePath = tempnam(sys_get_temp_dir(), 'qmx-composition-');
            $mappingPath = tempnam(sys_get_temp_dir(), 'qmx-composition-map-');
            self::assertIsString($overridePath);
            self::assertIsString($mappingPath);
            try {
                file_put_contents($overridePath, $override);
                file_put_contents($mappingPath, json_encode([
                    $this->relativePath($sourcePath) => $overridePath,
                ], \JSON_THROW_ON_ERROR));
                [$exitCode, $output] = $this->runProcess([
                    \PHP_BINARY,
                    $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
                    '--source-overrides=' . $mappingPath,
                ]);

                self::assertNotSame(0, $exitCode, $file . " unexpectedly retained its binding\n" . $output);
                self::assertMatchesRegularExpression('/(?:unclassified composition_binding|composition_binding operation mismatch)/', $output);
            } finally {
                @unlink($overridePath);
                @unlink($mappingPath);
            }
        }
    }

    #[Test]
    public function itPublishesTheReviewedTopologyEvidenceAndRejectsProductionToTestImports(): void
    {
        self::assertCount(28, $this->tsv('test-orphan-dispositions.tsv'));
        self::assertCount(4, $this->tsv('test-system-support-owners.tsv'));
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
    private function runProcess(array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root());
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    private function root(): string
    {
        $root = realpath(__DIR__ . '/../../../../..');
        self::assertIsString($root);

        return $root;
    }

    private function sourcePath(string $filename): string
    {
        $paths = glob($this->root() . '/src/Infrastructure/DependencyInjection/{Configurator,CompilerPass}/' . $filename, \GLOB_BRACE);
        self::assertIsArray($paths);
        self::assertCount(1, $paths, $filename);

        return $paths[0];
    }

    private function relativePath(string $path): string
    {
        return ltrim(substr($path, \strlen($this->root())), '/');
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModularArchitectureGovernanceIntegrationTest extends TestCase
{
    #[Test]
    public function itChecksEveryGeneratedProjectionWithoutWriting(): void
    {
        $generatedPaths = glob($this->root() . '/docs/internal/generated/modular-architecture/*');
        self::assertIsArray($generatedPaths);
        $paths = array_merge(
            [$this->root() . '/qmx.yaml'],
            $generatedPaths,
        );
        $before = $this->hashes($paths);

        [$exitCode, $output] = $this->runCommand([
            \PHP_BINARY,
            $this->root() . '/scripts/generate-modular-architecture.php',
            '--check',
        ]);

        self::assertSame(0, $exitCode, $output);
        self::assertSame($before, $this->hashes($paths), '--check must not modify qmx or generated evidence.');
    }

    #[Test]
    public function itPinsTheReviewedSnapshotAsComputedEvidence(): void
    {
        $lines = file(
            $this->root() . '/docs/internal/generated/modular-architecture/manifest-enforcement-summary.tsv',
            \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES,
        );
        self::assertIsArray($lines);
        $rows = array_map(
            static fn(string $line): array => explode("\t", $line),
            $lines,
        );
        $summary = array_column(\array_slice($rows, 1), 1, 0);
        $manifest = $this->manifest();
        $declarations = $manifest['declarations'];
        self::assertIsArray($declarations);
        $owners = array_values(array_unique(array_column($declarations, 'owner')));
        $consumerEntries = array_sum(array_map(
            static fn(array $declaration): int => \count($declaration['consumers']),
            $declarations,
        ));
        $temporaryConsumerEntries = array_sum(array_map(
            static fn(array $declaration): int => \count(array_filter(
                $declaration['consumers'],
                static fn(array $consumer): bool => $consumer['source_fqcn'] !== null,
            )),
            $declarations,
        ));
        $coarseGrantEdges = [];
        foreach ($manifest['temporary_internal_grants'] as $grant) {
            $sourceOwner = $declarations[$grant['source_fqcn']]['owner'];
            $targetOwner = $declarations[$grant['target_fqcn']]['owner'];
            $coarseGrantEdges[$sourceOwner . "\0" . $targetOwner] = true;
        }
        [$qmxLayerCount, $qmxAllowEdgeCount] = $this->generatedQmxCounts();

        self::assertSame('697', $summary['declarations']);
        self::assertSame((string) \count(array_unique(array_column($declarations, 'path'))), $summary['files']);
        self::assertSame((string) \count($owners), $summary['semantic_owners']);
        self::assertSame((string) \count($owners), $summary['semantic_owner_layers']);
        self::assertSame((string) $consumerEntries, $summary['contract_consumer_entries']);
        self::assertSame((string) $temporaryConsumerEntries, $summary['temporary_contract_consumer_entries']);
        self::assertMatchesRegularExpression('/^[1-9][0-9]*$/', $summary['exact_dependency_edges']);
        self::assertMatchesRegularExpression('/^[1-9][0-9]*$/', $summary['cross_owner_imports']);
        self::assertSame((string) \count($manifest['enforcement_seams']), $summary['singleton_seams']);
        self::assertSame((string) \count($manifest['temporary_internal_grants']), $summary['exact_internal_grants']);
        self::assertSame((string) \count($coarseGrantEdges), $summary['coarse_internal_grant_edges']);
        self::assertSame((string) $qmxLayerCount, $summary['internal_enforcement_layers']);
        self::assertSame((string) $qmxAllowEdgeCount, $summary['declared_allow_edges']);
    }

    #[Test]
    public function itEncodesThePostP1DuplicationBoundary(): void
    {
        $manifest = $this->manifest();
        $declarations = $manifest['declarations'];
        self::assertCount(697, $declarations);

        $prefix = 'Qualimetrix\\Analysis\\Evidence\\Duplication\\';
        $duplication = array_filter(
            $declarations,
            static fn(string $fqcn): bool => str_starts_with($fqcn, $prefix),
            \ARRAY_FILTER_USE_KEY,
        );
        self::assertCount(18, $duplication);
        self::assertArrayHasKey($prefix . 'DuplicationResultProvider', $duplication);
        self::assertArrayNotHasKey('Qualimetrix\\Analysis\\Duplication\\DuplicationDetectorInterface', $declarations);
        self::assertArrayNotHasKey('Qualimetrix\\Core\\Duplication\\DuplicateBlock', $declarations);
        self::assertArrayNotHasKey('Qualimetrix\\Rules\\Duplication\\CodeDuplicationRule', $declarations);

        $contractFqcn = $prefix . 'Contract\\DuplicationInspectionInterface';
        self::assertSame([$contractFqcn], array_keys(array_filter(
            $duplication,
            static fn(array $declaration): bool => $declaration['visibility'] === 'contract',
        )));
        self::assertSame([
            [
                'owner' => 'Analysis.Run',
                'source_fqcn' => 'Qualimetrix\\Analysis\\Pipeline\\MetricEnricher',
                'closes_in' => 'P3',
            ],
            [
                'owner' => 'Infrastructure.DependencyInjection',
                'source_fqcn' => null,
                'closes_in' => null,
            ],
        ], $duplication[$contractFqcn]['consumers']);

        foreach ($duplication as $fqcn => $declaration) {
            if ($fqcn === $contractFqcn) {
                continue;
            }
            self::assertSame('internal', $declaration['visibility'], $fqcn);
            self::assertSame([], $declaration['consumers'], $fqcn);
        }
        self::assertSame([], array_values(array_filter(
            $manifest['temporary_internal_grants'],
            static fn(array $grant): bool => $grant['owner'] === 'Analysis.Evidence.Duplication',
        )));
    }

    #[Test]
    public function itClassifiesLegacyAndTargetDuplicationTestsWithoutACatchAll(): void
    {
        $cases = [
            'tests/Unit/Analysis/Duplication/DuplicationDetectorTest.php',
            'tests/Unit/Core/Duplication/DuplicateBlockIdentityTest.php',
            'tests/Unit/Rules/Duplication/CodeDuplicationRuleTest.php',
            'tests/Analysis/Evidence/Duplication/Unit/DuplicationDetectorTest.php',
        ];

        foreach ($cases as $path) {
            [$exitCode, $output] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-test-inventory.php',
                '--classification-probe=' . $path,
            ]);

            self::assertSame(0, $exitCode, $output);
            self::assertSame(
                "Analysis/Evidence/Duplication\tP1\tUnit\ttests/Analysis/Evidence/Duplication/Unit/" . basename($path) . "\n",
                $output,
                $path,
            );
        }

        [$exitCode, $output] = $this->runCommand([
            \PHP_BINARY,
            $this->root() . '/scripts/generate-modular-architecture-test-inventory.php',
            '--classification-probe=tests/Analysis/Evidence/Duplication/Integration/UnexpectedTest.php',
        ]);
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString(
            'Unclassified test artifact: tests/Analysis/Evidence/Duplication/Integration/UnexpectedTest.php',
            $output,
        );
    }

    #[Test]
    public function itClassifiesTheP1DuplicationModuleReadmeExactly(): void
    {
        [$exitCode, $output] = $this->runCommand([
            \PHP_BINARY,
            $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
            '--documentation-probe=src/Analysis/Evidence/Duplication/README.md',
        ]);

        self::assertSame(0, $exitCode, $output);
        self::assertSame(
            "Analysis.Evidence.Duplication\tP1\tMove or update atomically with the named migration package.\n",
            $output,
        );

        [$exitCode, $output] = $this->runCommand([
            \PHP_BINARY,
            $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
            '--documentation-probe=src/Analysis/Evidence/Duplication/Unexpected/README.md',
        ]);
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString(
            'unclassified committable documentation path: src/Analysis/Evidence/Duplication/Unexpected/README.md',
            $output,
        );
    }

    #[Test]
    public function itRejectsAnUnlistedExactInternalPairBehindACoarseOwnerEdge(): void
    {
        $manifest = $this->manifest();
        $source = 'Qualimetrix\\Infrastructure\\DependencyInjection\\CompilerPass\\ChannelDeclarationCompilerPass';
        $target = 'Qualimetrix\\Core\\Rule\\RuleInterface';
        $contractSibling = 'Qualimetrix\\Core\\Rule\\ChannelDeclarationReader';
        self::assertSame($manifest['declarations'][$target]['owner'], $manifest['declarations'][$contractSibling]['owner']);
        self::assertSame('internal', $manifest['declarations'][$target]['visibility']);
        self::assertSame('contract', $manifest['declarations'][$contractSibling]['visibility']);
        self::assertContains(
            'Infrastructure.DependencyInjection',
            array_column($manifest['declarations'][$contractSibling]['consumers'], 'owner'),
        );
        $matchingGrant = array_search(
            $source . "\0" . $target,
            array_map(
                static fn(array $grant): string => $grant['source_fqcn'] . "\0" . $grant['target_fqcn'],
                $manifest['temporary_internal_grants'],
            ),
            true,
        );
        self::assertIsInt($matchingGrant);
        $removed = $manifest['temporary_internal_grants'][$matchingGrant];
        array_splice($manifest['temporary_internal_grants'], $matchingGrant, 1);
        self::assertIsArray($removed);

        [$exitCode, $output] = $this->runWithManifest($manifest);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString(
            'unapproved exact internal import ' . $removed['source_fqcn'] . ' -> ' . $removed['target_fqcn'],
            $output,
        );
    }

    #[Test]
    public function itRejectsGeneratedLayerNameCollisions(): void
    {
        $manifest = $this->replaceOwner($this->manifest(), 'Analysis.Evidence.Size', 'Seam.Architecture.Lifecycle.Hook');

        [$exitCode, $output] = $this->runWithManifest($manifest);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('generated qmx layer name collision on seam-architecture-lifecycle-hook', $output);
    }

    #[Test]
    public function itRejectsNonCanonicalSegmentedSeamLayerNames(): void
    {
        $manifest = $this->manifest();
        $fqcn = array_key_first($manifest['enforcement_seams']);
        self::assertIsString($fqcn);
        $manifest['enforcement_seams'][$fqcn]['layer'] = 'seam-a--b';

        [$exitCode, $output] = $this->runWithManifest($manifest);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('manifest schema validation failed', $output);
        self::assertStringContainsString('enforcement_seams', $output);
    }

    #[Test]
    public function itRejectsOrphanGeneratedArtifacts(): void
    {
        $path = $this->root() . '/docs/internal/generated/modular-architecture/orphan-review-artifact.tsv';
        file_put_contents($path, "stale\n");

        try {
            [$exitCode, $output] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture.php',
                '--check',
            ]);
            self::assertNotSame(0, $exitCode);
            self::assertStringContainsString('orphan=[orphan-review-artifact.tsv]', $output);

            [$writeExitCode, $writeOutput] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture.php',
            ]);
            self::assertSame(0, $writeExitCode, $writeOutput);
            self::assertFileDoesNotExist($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    #[Test]
    public function itPublishesNothingWhenTestInventoryRenderingFails(): void
    {
        $generatedPaths = glob($this->root() . '/docs/internal/generated/modular-architecture/*');
        self::assertIsArray($generatedPaths);
        $paths = array_merge([$this->root() . '/qmx.yaml'], $generatedPaths);
        $before = $this->hashes($paths);
        $fixture = $this->root() . '/tests/Architecture/Fixtures/ModularTopologySample/TransientUndiscoveredTest.php';
        file_put_contents(
            $fixture,
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Qualimetrix\\Tests\\Architecture\\Fixtures\\ModularTopologySample;\n\nfinal class TransientUndiscoveredTest {}\n",
        );

        try {
            [$exitCode, $output] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture.php',
            ]);
        } finally {
            unlink($fixture);
        }

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('generate-modular-architecture-test-inventory.php', $output);
        self::assertStringContainsString('TransientUndiscoveredTest.php', $output);
        self::assertSame($before, $this->hashes($paths));
    }

    #[Test]
    public function itRollsBackTheWholePublicationAfterTheFirstReplacement(): void
    {
        $orphan = $this->root() . '/docs/internal/generated/modular-architecture/rollback-orphan.tsv';
        file_put_contents($orphan, "preserve me\n");
        $generatedPaths = glob($this->root() . '/docs/internal/generated/modular-architecture/*');
        self::assertIsArray($generatedPaths);
        $paths = array_merge([$this->root() . '/qmx.yaml'], $generatedPaths);
        $before = $this->hashes($paths);

        try {
            [$exitCode, $output] = $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture.php',
                '--fail-after-publish=1',
            ]);

            self::assertNotSame(0, $exitCode);
            self::assertStringContainsString('Publication transaction rolled back', $output);
            self::assertSame($before, $this->hashes($paths));
            self::assertSame("preserve me\n", file_get_contents($orphan));
        } finally {
            if (is_file($orphan)) {
                unlink($orphan);
            }
        }
    }

    #[Test]
    public function itInventoriesOnlyCommittableDocumentation(): void
    {
        $contents = file_get_contents($this->root() . '/docs/internal/generated/modular-architecture/documentation-ownership.tsv');
        self::assertIsString($contents);

        self::assertStringContainsString("AGENTS.md\t", $contents);
        self::assertStringContainsString("CLAUDE.md\t", $contents);
        self::assertStringNotContainsString('.local.md', $contents);
        self::assertStringNotContainsString('/node_modules/', $contents);
        self::assertStringNotContainsString('/vendor/', $contents);
        self::assertStringContainsString(
            "docs/adr/0016-subject-cohesion.md\tArchitecture.Governance\tshared\t",
            $contents,
        );
        self::assertStringContainsString(
            "docs/internal/plans/modular-architecture.md\tArchitecture.Governance\tP0-D\t",
            $contents,
        );
    }

    #[Test]
    public function itRejectsAnUnknownCommittableDocumentationPath(): void
    {
        [$exitCode, $output] = $this->runCommand([
            \PHP_BINARY,
            $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
            '--check',
            '--documentation-probe=website/docs/rules/duplication-guide.md',
        ]);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString(
            'unclassified committable documentation path: website/docs/rules/duplication-guide.md',
            $output,
        );
    }

    #[Test]
    public function itRejectsMissingDuplicateAndMisorderedQmxMarkers(): void
    {
        $qmx = file_get_contents($this->root() . '/qmx.yaml');
        self::assertIsString($qmx);
        $begin = '# BEGIN GENERATED MODULAR ARCHITECTURE - DO NOT EDIT';
        $end = '# END GENERATED MODULAR ARCHITECTURE';
        $misordered = str_replace([$begin, $end], ['__QMX_END__', '__QMX_BEGIN__'], $qmx);
        $misordered = str_replace(['__QMX_BEGIN__', '__QMX_END__'], [$begin, $end], $misordered);
        $cases = [
            'missing' => str_replace([$begin, $end], '', $qmx),
            'duplicate' => $qmx . "\n{$begin}\n{$end}\n",
            'misordered' => $misordered,
        ];

        foreach ($cases as $label => $contents) {
            [$exitCode, $output] = $this->runWithQmx($contents);
            self::assertNotSame(0, $exitCode, $label);
            self::assertStringContainsString('qmx.yaml', $output, $label);
            self::assertStringContainsString('marker', $output, $label);
        }
    }

    #[Test]
    public function itDoesNotExtendATemporaryContractConsumerToAnotherDeclarationOfItsOwner(): void
    {
        $manifest = $this->manifest();
        $target = 'Qualimetrix\\Analysis\\Collection\\CollectionOrchestratorInterface';
        foreach ($manifest['declarations'][$target]['consumers'] as &$consumer) {
            if ($consumer['owner'] !== 'Infrastructure.DependencyInjection') {
                continue;
            }
            $consumer['source_fqcn'] = 'Qualimetrix\\Infrastructure\\DependencyInjection\\Configurator\\AnalysisConfigurator';
            $consumer['closes_in'] = 'P3';
        }
        unset($consumer);

        [$exitCode, $output] = $this->runWithManifest($manifest);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString(
            'contract import Qualimetrix\\Infrastructure\\DependencyInjection\\Configurator\\ArchitectureConfigurator -> ' . $target . ' has 0 matching consumer entries',
            $output,
        );
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

    /** @param array<string, mixed> $manifest
     *
     * @return array<string, mixed>
     */
    private function replaceOwner(array $manifest, string $from, string $to): array
    {
        array_walk_recursive($manifest, static function (mixed &$value) use ($from, $to): void {
            if ($value === $from) {
                $value = $to;
            }
        });

        return $manifest;
    }

    /** @return array{int, string} */
    private function runWithQmx(string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-config-');
        self::assertIsString($path);
        file_put_contents($path, $contents);

        try {
            return $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
                '--check',
                '--qmx-source=' . $path,
                '--qmx-output=' . $path,
            ]);
        } finally {
            unlink($path);
        }
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return array{int, string}
     */
    private function runWithManifest(array $manifest): array
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-manifest-');
        self::assertIsString($path);
        $json = json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        file_put_contents($path, $json . "\n");

        try {
            return $this->runCommand([
                \PHP_BINARY,
                $this->root() . '/scripts/generate-modular-architecture-production-inventory.php',
                '--check',
                '--manifest=' . $path,
            ]);
        } finally {
            unlink($path);
        }
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string}
     */
    private function runCommand(array $command): array
    {
        $pipes = [];
        $stderr = tmpfile();
        self::assertIsResource($stderr);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => $stderr], $pipes, $this->root());
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        self::assertIsString($output);
        fclose($pipes[1]);
        $exitCode = proc_close($process);
        rewind($stderr);
        $stderrOutput = stream_get_contents($stderr);
        self::assertIsString($stderrOutput);
        fclose($stderr);

        return [$exitCode, $output . $stderrOutput];
    }

    /**
     * @param list<string> $paths
     *
     * @return array<string, string>
     */
    private function hashes(array $paths): array
    {
        $hashes = [];
        foreach ($paths as $path) {
            $hash = hash_file('sha256', $path);
            self::assertIsString($hash);
            $hashes[$path] = $hash;
        }
        ksort($hashes, \SORT_STRING);

        return $hashes;
    }

    /** @return array{int, int} */
    private function generatedQmxCounts(): array
    {
        $contents = file_get_contents($this->root() . '/qmx.yaml');
        self::assertIsString($contents);
        $begin = strpos($contents, '# BEGIN GENERATED MODULAR ARCHITECTURE - DO NOT EDIT');
        $end = strpos($contents, '# END GENERATED MODULAR ARCHITECTURE');
        self::assertIsInt($begin);
        self::assertIsInt($end);
        $generated = substr($contents, $begin, $end - $begin);
        $allowPosition = strpos($generated, "  allow:\n");
        self::assertIsInt($allowPosition);
        $layers = substr($generated, 0, $allowPosition);
        $allow = substr($generated, $allowPosition);
        $layerCount = preg_match_all('/^    - name: /m', $layers);
        $externalLayerCount = preg_match_all('/^    - name: external$/m', $layers);
        $allowEdgeCount = preg_match_all('/^      - /m', $allow);
        self::assertIsInt($layerCount);
        self::assertSame(1, $externalLayerCount);
        self::assertIsInt($allowEdgeCount);

        return [$layerCount - $externalLayerCount, $allowEdgeCount];
    }

    private function root(): string
    {
        $root = realpath(__DIR__ . '/../../..');
        self::assertIsString($root);

        return $root;
    }
}

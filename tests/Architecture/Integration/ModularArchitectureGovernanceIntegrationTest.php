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

        self::assertSame('695', $summary['declarations']);
        self::assertSame('693', $summary['files']);
        self::assertSame('2951', $summary['exact_dependency_edges']);
        self::assertSame('1945', $summary['cross_owner_imports']);
        self::assertSame('37', $summary['semantic_owner_layers']);
        self::assertSame('771', $summary['contract_consumer_entries']);
        self::assertSame('0', $summary['temporary_contract_consumer_entries']);
        self::assertSame('14', $summary['singleton_seams']);
        self::assertSame('85', $summary['exact_internal_grants']);
        self::assertSame('16', $summary['coarse_internal_grant_edges']);
        self::assertSame('51', $summary['internal_enforcement_layers']);
        self::assertSame('296', $summary['declared_allow_edges']);
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

    private function root(): string
    {
        $root = realpath(__DIR__ . '/../../..');
        self::assertIsString($root);

        return $root;
    }
}

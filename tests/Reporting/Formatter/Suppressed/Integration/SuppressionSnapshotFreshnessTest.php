<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Formatter\Suppressed\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs `scripts/generate-suppression-snapshot.php --check` as a subprocess,
 * the same way {@see \Qualimetrix\Tests\Analysis\Policy\Architecture\Integration\ModularArchitectureGovernanceIntegrationTest}
 * checks its own generated projections — see PLAN.md, rule-vocabulary Ш6
 * decision (м).
 *
 * The snapshot lives outside the test tree
 * (`docs/internal/generated/suppression/*.tsv`) because it is committed
 * evidence a reviewer reads, not test fixture data; this test only asserts
 * that the committed copy still matches a fresh self-analysis of `src/`.
 */
final class SuppressionSnapshotFreshnessTest extends TestCase
{
    #[Test]
    public function itMatchesAFreshSelfAnalysisOfSrc(): void
    {
        [$exitCode, $output] = $this->runProcess([
            \PHP_BINARY,
            $this->root() . '/scripts/generate-suppression-snapshot.php',
            '--check',
        ]);

        self::assertSame(0, $exitCode, $output);
    }

    /**
     * @param list<string> $command
     *
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
}

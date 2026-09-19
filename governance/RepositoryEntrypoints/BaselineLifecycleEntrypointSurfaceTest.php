<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\RepositoryEntrypoints;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Repository entrypoints are executable consumers of the CLI surface, not
 * historical documentation. Keep them in lockstep with the command
 * definitions so an action, compose profile, or installed hook does not
 * fail only after users upgrade.
 */
final class BaselineLifecycleEntrypointSurfaceTest extends TestCase
{
    #[Test]
    public function itKeepsRepositoryEntrypointsOnTheBaselineLifecycleSurface(): void
    {
        $entrypoints = [
            'action.yml' => ['ARGS="$ARGS --baseline=${{ inputs.baseline }}"'],
            'docker-compose.yml' => [
                'docker-compose run --rm qmx check lib/',
                'command: check src/',
                'command: baseline:generate baseline.json src/ --force',
                'command: check src/ --format=sarif',
                'command: check src/ --baseline=baseline.json',
                'command: check src/ --config=qmx.yaml',
            ],
            'scripts/pre-commit-hook.sh' => [
                'BASELINE_ADVICE="Replace accepted levels intentionally: $QMX_BIN baseline:generate baseline.json src/ --force"',
                'BASELINE_ADVICE="Create a baseline: $QMX_BIN baseline:generate baseline.json src/"',
            ],
        ];

        foreach ($entrypoints as $path => $expectedSnippets) {
            $contents = file_get_contents(\dirname(__DIR__, 2) . '/' . $path);

            self::assertIsString($contents, \sprintf('Could not read repository entrypoint %s.', $path));
            self::assertStringNotContainsString('--generate-baseline', $contents, $path);
            self::assertStringNotContainsString('--baseline-ignore-stale', $contents, $path);

            if ($path === 'docker-compose.yml') {
                self::assertStringNotContainsString('analyze ', $contents, $path);
                self::assertStringContainsString('check ', $contents, $path);
            }

            foreach ($expectedSnippets as $expectedSnippet) {
                self::assertStringContainsString($expectedSnippet, $contents, $path);
            }
        }
    }
}

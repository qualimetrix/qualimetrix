<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\RepositoryEntrypoints;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Hook\PreCommitHook;

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

    /**
     * The pre-commit hook is an entrypoint like the two above, but it has no
     * file to read: `hook:install` generates it.
     *
     * The subject here is the hook a user ends up with, so the assertion is
     * made against what the generator produces and not against the source
     * that produces it. Reading the source would pass for a literal that
     * happens to spell these lines and fail for a generator that assembles
     * the same lines from parts — which is a fact about how the template is
     * written, not about the surface this control guards.
     */
    #[Test]
    public function itKeepsTheGeneratedPreCommitHookOnTheBaselineLifecycleSurface(): void
    {
        $script = PreCommitHook::script('/probe/bin/qmx');

        self::assertStringNotContainsString('--generate-baseline', $script);
        self::assertStringNotContainsString('--baseline-ignore-stale', $script);

        self::assertStringContainsString(
            'BASELINE_ADVICE="Replace accepted levels intentionally: $QMX_BIN baseline:generate baseline.json src/ --force"',
            $script,
        );
        self::assertStringContainsString(
            'BASELINE_ADVICE="Create a baseline: $QMX_BIN baseline:generate baseline.json src/"',
            $script,
        );
    }
}

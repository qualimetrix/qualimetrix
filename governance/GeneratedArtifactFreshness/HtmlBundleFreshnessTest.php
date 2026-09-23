<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\GeneratedArtifactFreshness;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;
use RuntimeException;

require_once \dirname(__DIR__, 2) . '/scripts/subprocess/ChildProcess.php';

/**
 * Runs `scripts/check-html-bundle-freshness.php` as a subprocess, the same
 * way {@see SuppressionSnapshotFreshnessTest} checks its own generated
 * artifact.
 *
 * The bundle lives outside the test tree (`html-report/dist/report.min.js`)
 * because it is a shipped artifact {@see \Qualimetrix\Reporting\Formatter\Html\HtmlFormatter}
 * inlines into every HTML report, not test fixture data; this test only
 * asserts that the committed bundle still matches a fresh build of
 * `html-report/src/`.
 */
final class HtmlBundleFreshnessTest extends TestCase
{
    #[Group('live-freshness')]
    #[Test]
    public function itMatchesAFreshBuildOfHtmlReportSrc(): void
    {
        [$exitCode, $output] = $this->runProcess([
            \PHP_BINARY,
            $this->root() . '/scripts/check-html-bundle-freshness.php',
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
        try {
            $result = ChildProcess::run($command, $this->root());
        } catch (RuntimeException $exception) {
            self::fail($exception->getMessage());
        }

        return [$result['exitCode'], $result['stdout'] . $result['stderr']];
    }

    private function root(): string
    {
        $root = realpath(__DIR__ . '/../..');
        self::assertIsString($root);

        return $root;
    }
}

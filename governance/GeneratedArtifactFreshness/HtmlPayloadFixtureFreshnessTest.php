<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\GeneratedArtifactFreshness;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__, 2) . '/scripts/subprocess/ChildProcess.php';

/** The viewer fixture must describe what the real PHP publisher emits today. */
final class HtmlPayloadFixtureFreshnessTest extends TestCase
{
    #[Test]
    public function itMatchesTheNativeCliPayload(): void
    {
        $root = \dirname(__DIR__, 2);
        $result = ChildProcess::run([\PHP_BINARY, $root . '/scripts/generate-html-payload-fixture.php', '--check'], $root);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
    }
}

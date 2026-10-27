<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\GeneratedArtifactFreshness;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;
use RuntimeException;

require_once \dirname(__DIR__, 2) . '/scripts/subprocess/ChildProcess.php';

/**
 * The editor schema of `qmx.yaml` is generated from the section declarations
 * the document engine composes against
 * (`scripts/generate-configuration-schema.php`); a stale one offers editors
 * keys the engine no longer reads.
 */
final class ConfigurationSchemaFreshnessTest extends TestCase
{
    private const string GENERATOR = 'scripts/generate-configuration-schema.php';

    #[Test]
    public function itMatchesWhatTheSectionDeclarationsGenerate(): void
    {
        $root = realpath(\dirname(__DIR__, 2));
        self::assertIsString($root);

        try {
            $result = ChildProcess::run([\PHP_BINARY, $root . '/' . self::GENERATOR, '--check'], $root);
        } catch (RuntimeException $exception) {
            self::fail($exception->getMessage());
        }

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Functional\Console\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Tests\Support\Console\TempDirectory;

final class BaselineMigrateCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = TempDirectory::create('qmx-baseline-migrate-retired-');
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->tempDir);
    }

    #[Test]
    public function itDoesNotOfferTheRetiredMigrationCommandOrRewriteAVersionFiveFile(): void
    {
        $baselinePath = $this->tempDir . '/baseline.json';
        $legacy = (string) json_encode([
            'version' => 5,
            'generated' => '2026-01-01T00:00:00+00:00',
            'violations' => [],
        ], \JSON_THROW_ON_ERROR);
        file_put_contents($baselinePath, $legacy);

        $container = (new ContainerFactory())->create();
        self::assertFalse($container->has('Qualimetrix\\Infrastructure\\Console\\Command\\BaselineMigrateCommand'));

        $output = [];
        exec(
            \sprintf(
                '%s %s baseline:migrate %s 2>&1',
                escapeshellarg(\PHP_BINARY),
                escapeshellarg(\dirname(__DIR__, 4) . '/bin/qmx'),
                escapeshellarg($baselinePath),
            ),
            $output,
            $status,
        );

        self::assertNotSame(0, $status);
        self::assertStringContainsString('baseline:migrate', implode("\n", $output));
        self::assertSame($legacy, file_get_contents($baselinePath));
    }
}

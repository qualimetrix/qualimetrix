<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Exception\ConfigLoadException;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;

final class DeadConfigurationKeysTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function removedKeys(): iterable
    {
        yield 'namespace strategy' => ["namespace:\n  strategy: chain\n"];
        yield 'namespace composer json' => ["namespace:\n  composer_json: composer.json\n"];
        yield 'aggregation prefixes' => ["aggregation:\n  prefixes: [App]\n"];
        yield 'aggregation auto depth' => ["aggregation:\n  auto_depth: 2\n"];
    }

    #[Test]
    #[DataProvider('removedKeys')]
    public function itRejectsRemovedConfigurationKeys(string $yaml): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qmx-dead-key-');
        self::assertIsString($path);
        file_put_contents($path, $yaml);

        try {
            $this->expectException(ConfigLoadException::class);
            (new YamlConfigLoader())->load($path);
        } finally {
            @unlink($path);
        }
    }
}

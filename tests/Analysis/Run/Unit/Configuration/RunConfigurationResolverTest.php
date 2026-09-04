<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Run\Configuration\RunConfigurationResolver;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Core\Path\AbsolutePath;

final class RunConfigurationResolverTest extends TestCase
{
    #[Test]
    public function itResolvesOwnerDefaultsAndLastPathContributionAgainstTheInvocationRoot(): void
    {
        $configuration = (new RunConfigurationResolver())->resolve(new ConfigurationDocument([
            ['source' => 'composer', 'values' => ['paths' => ['lib'], 'excludes' => ['build']]],
            ['source' => 'cli', 'values' => ['paths' => ['src'], 'include_generated' => true]],
        ], AbsolutePath::fromString(sys_get_temp_dir())));

        self::assertSame([sys_get_temp_dir() . '/src'], array_map(static fn($path): string => $path->value(), $configuration->paths));
        self::assertSame(['vendor', 'node_modules', '.git', 'build'], $configuration->pathExcludes);
        self::assertSame(GeneratedFilePolicy::Include, $configuration->generatedFilePolicy);
    }

    #[Test]
    public function itKeepsRelativePathsRootedAtIngressWhenTheProcessDirectoryChanges(): void
    {
        $original = getcwd();
        self::assertNotFalse($original);
        $rootA = sys_get_temp_dir() . '/qmx-run-root-a-' . bin2hex(random_bytes(6));
        $rootB = sys_get_temp_dir() . '/qmx-run-root-b-' . bin2hex(random_bytes(6));
        mkdir($rootA);
        mkdir($rootB);

        try {
            $document = new ConfigurationDocument([
                ['source' => 'cli', 'values' => ['paths' => ['src']]],
            ], AbsolutePath::fromString($rootA));
            chdir($rootB);

            $configuration = (new RunConfigurationResolver())->resolve($document);

            self::assertSame($rootA, $configuration->projectRoot->value());
            self::assertSame([$rootA . '/src'], array_map(
                static fn(AbsolutePath $path): string => $path->value(),
                $configuration->paths,
            ));
        } finally {
            chdir($original);
            rmdir($rootA);
            rmdir($rootB);
        }
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Pipeline\Stage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\CliStage;
use Qualimetrix\Core\Path\AbsolutePath;

#[CoversClass(CliStage::class)]
final class CliStageTest extends TestCase
{
    #[Test]
    public function itHasTheHighestSourcePriority(): void
    {
        self::assertSame(30, (new CliStage())->priority());
        self::assertSame('cli', (new CliStage())->name());
    }

    #[Test]
    public function itReturnsNullWithoutNormalizedOverrides(): void
    {
        self::assertNull((new CliStage())->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project'))));
    }

    #[Test]
    public function itPublishesAlreadyNormalizedOverridesWithoutSymfonyInput(): void
    {
        $overrides = [
            'paths' => ['src', 'lib'],
            'cache.enabled' => false,
            'parallel.workers' => 0,
            'format' => 'json',
        ];

        $layer = (new CliStage())->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project'), null, [], $overrides));

        self::assertNotNull($layer);
        self::assertSame('cli', $layer->source);
        self::assertSame($overrides, $layer->values);
    }
}

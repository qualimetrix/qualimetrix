<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Pipeline\Stage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\DefaultsStage;
use Qualimetrix\Core\Path\AbsolutePath;

#[CoversClass(DefaultsStage::class)]
final class DefaultsStageTest extends TestCase
{
    #[Test]
    public function itContributesOnlySourceProvenanceBecauseDefaultsAreOwnerLocal(): void
    {
        $stage = new DefaultsStage();
        $layer = $stage->apply(new ConfigurationResolutionRequest(AbsolutePath::fromString('/project')));

        self::assertSame(0, $stage->priority());
        self::assertSame('defaults', $stage->name());
        self::assertSame('defaults', $layer->source);
        self::assertSame([], $layer->values);
    }
}

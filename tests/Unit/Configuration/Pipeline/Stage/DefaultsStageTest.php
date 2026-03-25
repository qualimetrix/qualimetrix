<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Pipeline\Stage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\Pipeline\ConfigurationContext;
use Qualimetrix\Configuration\Pipeline\Stage\DefaultsStage;
use Symfony\Component\Console\Input\ArrayInput;

#[CoversClass(DefaultsStage::class)]
final class DefaultsStageTest extends TestCase
{
    private DefaultsStage $stage;

    protected function setUp(): void
    {
        $this->stage = new DefaultsStage();
    }

    #[Test]
    public function hasPriorityZero(): void
    {
        self::assertSame(0, $this->stage->priority());
    }

    #[Test]
    public function hasNameDefaults(): void
    {
        self::assertSame('defaults', $this->stage->name());
    }

    #[Test]
    public function returnsDefaultConfiguration(): void
    {
        $context = new ConfigurationContext(new ArrayInput([]), '/tmp');

        $layer = $this->stage->apply($context);

        self::assertSame('defaults', $layer->source);
        self::assertSame(['.'], $layer->values['paths']);
        self::assertSame(['vendor', 'node_modules', '.git'], $layer->values['excludes']);
        self::assertSame('.qmx-cache', $layer->values['cache.dir']);
        self::assertTrue($layer->values['cache.enabled']);
        self::assertSame('summary', $layer->values['format']);
        self::assertSame('chain', $layer->values['namespace.strategy']);
    }
}

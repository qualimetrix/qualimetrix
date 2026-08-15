<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Reporting\Configuration\OutputFormatResolver;

final class OutputFormatResolverTest extends TestCase
{
    #[Test]
    public function itUsesSummaryByDefaultAndTheLastExplicitFormat(): void
    {
        $resolver = new OutputFormatResolver();
        self::assertSame('summary', $resolver->resolve(new ConfigurationDocument([], AbsolutePath::fromString('/project')))->value);
        self::assertSame('json', $resolver->resolve(new ConfigurationDocument([
            ['source' => 'config', 'values' => ['format' => 'text']],
            ['source' => 'cli', 'values' => ['format' => 'json']],
        ], AbsolutePath::fromString('/project')))->value);
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Core\Path\AbsolutePath;
use ReflectionClass;

#[CoversClass(ConfigurationDocument::class)]
final class ConfigurationHolderTest extends TestCase
{
    #[Test]
    public function itTracksAppliedSourcesInPrecedenceOrder(): void
    {
        $document = new ConfigurationDocument([
            ['source' => 'defaults', 'values' => []],
            ['source' => 'strict', 'values' => ['format' => 'text']],
            ['source' => 'strict', 'values' => ['fail_on' => 'warning']],
            ['source' => 'cli', 'values' => ['format' => 'json']],
        ], AbsolutePath::fromString('/project'));

        self::assertSame(['defaults', 'strict', 'cli'], $document->appliedSources());
    }

    #[Test]
    public function itDoesNotProvideMutableReplacementOperations(): void
    {
        $methodNames = array_map(
            static fn($method): string => $method->getName(),
            (new ReflectionClass(ConfigurationDocument::class))->getMethods(),
        );

        self::assertNotContains('replace', $methodNames);
        self::assertNotContains('setConfiguration', $methodNames);
    }
}

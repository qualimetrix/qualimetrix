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
final class AnalysisConfigurationTest extends TestCase
{
    #[Test]
    public function itPreservesOrderedContributionsWithoutApplyingOwnerSemantics(): void
    {
        $document = new ConfigurationDocument([
            ['source' => 'strict', 'values' => ['rules' => ['size.loc' => ['warning' => 1000]]]],
            ['source' => 'qmx.yaml', 'values' => ['rules' => ['size.loc' => ['error' => 2000]]]],
            ['source' => 'cli', 'values' => ['format' => 'json']],
        ], AbsolutePath::fromString('/project'));

        self::assertSame([
            ['size.loc' => ['warning' => 1000]],
            ['size.loc' => ['error' => 2000]],
        ], $document->contributions('rules'));
        self::assertSame(['json'], $document->contributions('format'));
    }

    #[Test]
    public function itDoesNotExposeAMergedGenericConfigurationBag(): void
    {
        $methodNames = array_map(
            static fn($method): string => $method->getName(),
            (new ReflectionClass(ConfigurationDocument::class))->getMethods(),
        );

        self::assertNotContains('all', $methodNames);
        self::assertNotContains('sources', $methodNames);
    }

    #[Test]
    public function itReturnsTheInvocationWorkingDirectory(): void
    {
        self::assertEquals(
            AbsolutePath::fromString('/invocation'),
            (new ConfigurationDocument([], AbsolutePath::fromString('/invocation')))->workingDirectory(),
        );
    }
}

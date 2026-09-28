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
    public function itExposesOnlyFindingsTemporaryOrderedRawInputs(): void
    {
        $document = new ConfigurationDocument([
            ['source' => 'strict', 'values' => ['rules' => ['size.loc' => ['warning' => 1000]]]],
            ['source' => 'qmx.yaml', 'values' => [
                'rules' => ['size.loc' => ['error' => 2000]],
                'only_rules' => ['size.loc'],
                'disabled_rules' => ['security'],
            ]],
        ], AbsolutePath::fromString('/project'));

        self::assertSame([
            ['size.loc' => ['warning' => 1000]],
            ['size.loc' => ['error' => 2000]],
        ], $document->ruleContributions());
        self::assertSame([['size.loc']], $document->onlyRuleContributions());
        self::assertSame([['security']], $document->disabledRuleContributions());
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
        self::assertNotContains('contributions', $methodNames);
    }

    #[Test]
    public function itKeepsComposerDiscoveryFactsSeparateFromAuthoredValues(): void
    {
        $document = new ConfigurationDocument([
            ['source' => 'composer.json', 'values' => [
                'discovered_autoload_paths' => ['src'],
                'discovered_autoload_dev_paths' => ['tests'],
            ]],
        ], AbsolutePath::fromString('/project'));

        self::assertSame(['src'], $document->discoveredProductionAutoloadTargets());
        self::assertSame(['tests'], $document->discoveredDevelopmentAutoloadTargets());
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

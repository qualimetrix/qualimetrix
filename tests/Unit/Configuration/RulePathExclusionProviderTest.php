<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\RulePathExclusionProvider;

#[CoversClass(RulePathExclusionProvider::class)]
final class RulePathExclusionProviderTest extends TestCase
{
    public function testIsExcludedReturnsFalseWhenNoExclusions(): void
    {
        $provider = new RulePathExclusionProvider();

        self::assertFalse($provider->isExcluded('coupling.cbo', 'src/Service/UserService.php'));
    }

    public function testIsExcludedMatchesGlobPattern(): void
    {
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', ['src/Metrics/*Visitor.php']);

        self::assertTrue($provider->isExcluded('coupling.cbo', 'src/Metrics/CodeSmellVisitor.php'));
        self::assertFalse($provider->isExcluded('coupling.cbo', 'src/Rules/SomeRule.php'));
    }

    public function testIsExcludedMatchesWildcardDirectory(): void
    {
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', ['src/Infrastructure/DependencyInjection/*']);

        self::assertTrue($provider->isExcluded('coupling.cbo', 'src/Infrastructure/DependencyInjection/ContainerFactory.php'));
        self::assertFalse($provider->isExcluded('coupling.cbo', 'src/Infrastructure/Console/Command.php'));
    }

    public function testIsExcludedIsolatedPerRule(): void
    {
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', ['src/Metrics/*']);

        self::assertTrue($provider->isExcluded('coupling.cbo', 'src/Metrics/SomeFile.php'));
        self::assertFalse($provider->isExcluded('complexity.cyclomatic', 'src/Metrics/SomeFile.php'));
    }

    public function testSetExclusionsIgnoresEmptyPatterns(): void
    {
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', []);

        self::assertFalse($provider->isExcluded('coupling.cbo', 'src/Any.php'));
    }

    public function testIsExcludedReturnsFalseForEmptyFilePath(): void
    {
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', ['src/*']);

        self::assertFalse($provider->isExcluded('coupling.cbo', ''));
    }

    public function testResetClearsAllExclusions(): void
    {
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', ['src/*']);

        self::assertTrue($provider->isExcluded('coupling.cbo', 'src/File.php'));

        $provider->reset();

        self::assertFalse($provider->isExcluded('coupling.cbo', 'src/File.php'));
    }

    public function testStringValueCoercedToArray(): void
    {
        // This tests the RuleOptionsFactory behavior indirectly,
        // but we verify PathMatcher works with a single pattern
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', ['src/Entity/*.php']);

        self::assertTrue($provider->isExcluded('coupling.cbo', 'src/Entity/User.php'));
    }
}

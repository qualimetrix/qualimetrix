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

    public function testIsExcludedMatchesPathPrefix(): void
    {
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', ['src/Metrics']);

        self::assertTrue($provider->isExcluded('coupling.cbo', 'src/Metrics/CodeSmellVisitor.php'));
        self::assertFalse($provider->isExcluded('coupling.cbo', 'src/Rules/SomeRule.php'));
    }

    public function testIsExcludedMatchesDirectoryPrefix(): void
    {
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', ['src/Infrastructure/DependencyInjection']);

        self::assertTrue($provider->isExcluded('coupling.cbo', 'src/Infrastructure/DependencyInjection/ContainerFactory.php'));
        self::assertFalse($provider->isExcluded('coupling.cbo', 'src/Infrastructure/Console/Command.php'));
    }

    public function testIsExcludedDoesNotMatchPartialPrefix(): void
    {
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', ['src/Entity']);

        self::assertTrue($provider->isExcluded('coupling.cbo', 'src/Entity/User.php'));
        self::assertFalse($provider->isExcluded('coupling.cbo', 'src/EntityManager/Foo.php'));
    }

    public function testIsExcludedIsolatedPerRule(): void
    {
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', ['src/Metrics']);

        self::assertTrue($provider->isExcluded('coupling.cbo', 'src/Metrics/SomeFile.php'));
        self::assertFalse($provider->isExcluded('complexity.cyclomatic', 'src/Metrics/SomeFile.php'));
    }

    public function testSetExclusionsIgnoresEmptyPrefixes(): void
    {
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', []);

        self::assertFalse($provider->isExcluded('coupling.cbo', 'src/Any.php'));
    }

    public function testIsExcludedReturnsFalseForEmptyFilePath(): void
    {
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', ['src']);

        self::assertFalse($provider->isExcluded('coupling.cbo', ''));
    }

    public function testResetClearsAllExclusions(): void
    {
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', ['src']);

        self::assertTrue($provider->isExcluded('coupling.cbo', 'src/File.php'));

        $provider->reset();

        self::assertFalse($provider->isExcluded('coupling.cbo', 'src/File.php'));
    }

    public function testIsExcludedMatchesGlobPattern(): void
    {
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', ['src/Metrics/*Visitor.php']);

        self::assertTrue($provider->isExcluded('coupling.cbo', 'src/Metrics/CboVisitor.php'));
        self::assertFalse($provider->isExcluded('coupling.cbo', 'src/Metrics/CboCollector.php'));
    }

    public function testSinglePrefixMatchesExactFile(): void
    {
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', ['src/Entity/User.php']);

        self::assertTrue($provider->isExcluded('coupling.cbo', 'src/Entity/User.php'));
        self::assertFalse($provider->isExcluded('coupling.cbo', 'src/Entity/Order.php'));
    }
}

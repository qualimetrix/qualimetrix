<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\RulePathExclusionProvider;
use Qualimetrix\Core\Path\RelativePath;

#[CoversClass(RulePathExclusionProvider::class)]
final class RulePathExclusionProviderTest extends TestCase
{
    #[Test]
    public function itReturnsFalseWhenNoExclusions(): void
    {
        $provider = new RulePathExclusionProvider();

        self::assertFalse($provider->isExcluded('coupling.cbo', RelativePath::fromString('src/Service/UserService.php')));
    }

    #[Test]
    public function itMatchesPathPrefix(): void
    {
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', ['src/Metrics']);

        self::assertTrue($provider->isExcluded('coupling.cbo', RelativePath::fromString('src/Metrics/CodeSmellVisitor.php')));
        self::assertFalse($provider->isExcluded('coupling.cbo', RelativePath::fromString('src/Rules/SomeRule.php')));
    }

    #[Test]
    public function itMatchesDirectoryPrefix(): void
    {
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', ['src/Infrastructure/DependencyInjection']);

        self::assertTrue($provider->isExcluded('coupling.cbo', RelativePath::fromString('src/Infrastructure/DependencyInjection/ContainerFactory.php')));
        self::assertFalse($provider->isExcluded('coupling.cbo', RelativePath::fromString('src/Infrastructure/Console/Command.php')));
    }

    #[Test]
    public function itDoesNotMatchPartialPrefix(): void
    {
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', ['src/Entity']);

        self::assertTrue($provider->isExcluded('coupling.cbo', RelativePath::fromString('src/Entity/User.php')));
        self::assertFalse($provider->isExcluded('coupling.cbo', RelativePath::fromString('src/EntityManager/Foo.php')));
    }

    #[Test]
    public function itIsExclusionIsolatedPerRule(): void
    {
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', ['src/Metrics']);

        self::assertTrue($provider->isExcluded('coupling.cbo', RelativePath::fromString('src/Metrics/SomeFile.php')));
        self::assertFalse($provider->isExcluded('complexity.cyclomatic', RelativePath::fromString('src/Metrics/SomeFile.php')));
    }

    #[Test]
    public function itIgnoresEmptyPrefixesOnSetExclusions(): void
    {
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', []);

        self::assertFalse($provider->isExcluded('coupling.cbo', RelativePath::fromString('src/Any.php')));
    }

    #[Test]
    public function itClearsAllExclusionsOnReset(): void
    {
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', ['src']);

        self::assertTrue($provider->isExcluded('coupling.cbo', RelativePath::fromString('src/File.php')));

        $provider->reset();

        self::assertFalse($provider->isExcluded('coupling.cbo', RelativePath::fromString('src/File.php')));
    }

    #[Test]
    public function itMatchesGlobPattern(): void
    {
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', ['src/Metrics/*Visitor.php']);

        self::assertTrue($provider->isExcluded('coupling.cbo', RelativePath::fromString('src/Metrics/CboVisitor.php')));
        self::assertFalse($provider->isExcluded('coupling.cbo', RelativePath::fromString('src/Metrics/CboCollector.php')));
    }

    #[Test]
    public function itMatchesExactFileWithSinglePrefix(): void
    {
        $provider = new RulePathExclusionProvider();
        $provider->setExclusions('coupling.cbo', ['src/Entity/User.php']);

        self::assertTrue($provider->isExcluded('coupling.cbo', RelativePath::fromString('src/Entity/User.php')));
        self::assertFalse($provider->isExcluded('coupling.cbo', RelativePath::fromString('src/Entity/Order.php')));
    }
}

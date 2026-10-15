<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Core\Environment\EnvironmentFailureInterface;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\ContainerBuildFailure;
use Qualimetrix\Infrastructure\Console\ProductCommandLoader;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Throwable;

#[CoversClass(ProductCommandLoader::class)]
final class ProductCommandLoaderTest extends TestCase
{
    #[Test]
    public function itDoesNotBuildForNameQueriesAndWrapsBuildFailuresOnce(): void
    {
        $counter = new class {
            public int $calls = 0;
        };
        $cause = new LogicException('bad product tag');
        $loader = new ProductCommandLoader(static function () use ($counter, $cause): never {
            ++$counter->calls;
            throw $cause;
        });
        self::assertTrue($loader->has('check'));
        self::assertFalse($loader->has('che'));
        self::assertCount(13, $loader->getNames());
        $loader->finishInvocation();
        self::assertSame(0, $counter->calls);
        try {
            $loader->get('check');
            self::fail('The build must fail.');
        } catch (ContainerBuildFailure $failure) {
            self::assertSame($cause, $failure->getPrevious());
            self::assertSame(1, $counter->calls);
        }
    }

    #[Test]
    public function itPreservesConstructorRefusalAndEnvironmentCarriers(): void
    {
        $environment = new class ('missing capability') extends RuntimeException implements EnvironmentFailureInterface {};
        foreach ([ConfigurationRefusal::aboutCommandLineInput('--example', 'bad input'), $environment] as $cause) {
            $container = new ContainerBuilder();
            $container->register(CheckCommand::class)
                ->setFactory([self::class, 'failedConstructor'])
                ->setArguments([$cause])
                ->setPublic(true);
            $loader = new ProductCommandLoader(static fn() => $container);
            try {
                $loader->get('check');
                self::fail('The constructor must fail.');
            } catch (Throwable $failure) {
                self::assertSame($cause, $failure);
            }
        }
    }

    #[Test]
    public function itWrapsDependencyInjectionResolutionFailures(): void
    {
        $loader = new ProductCommandLoader(static fn() => new ContainerBuilder());
        try {
            $loader->get('check');
            self::fail('A missing command service must fail.');
        } catch (ContainerBuildFailure $failure) {
            self::assertInstanceOf(ServiceNotFoundException::class, $failure->getPrevious());
        }
    }

    public static function failedConstructor(Throwable $cause): never
    {
        throw $cause;
    }
}

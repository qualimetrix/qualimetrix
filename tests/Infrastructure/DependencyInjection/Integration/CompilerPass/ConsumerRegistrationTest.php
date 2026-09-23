<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\DependencyInjection\Integration\CompilerPass;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ConsumerBoundPassInterface;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ConsumerRegistrationCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;

/**
 * The product build refuses the silent skip of every pass that writes into a
 * service it names: each case takes one such service out of the real,
 * uncompiled container and requires the refusal to name that pass and that
 * id. The cases are read off the passes the factory registers, not listed.
 */
#[CoversClass(ConsumerRegistrationCompilerPass::class)]
#[CoversClass(ContainerFactory::class)]
final class ConsumerRegistrationTest extends TestCase
{
    #[Test]
    #[DataProvider('provideEveryConsumerOfEveryRegisteredPass')]
    public function itRefusesTheBuildWhenAServiceAPassWritesIntoIsNotRegistered(string $passClass, string $serviceId): void
    {
        $container = (new ContainerFactory())->configure();
        self::assertTrue($container->hasDefinition($serviceId), 'The product container should register it.');

        $container->removeDefinition($serviceId);

        try {
            $container->compile();
        } catch (LogicException $exception) {
            self::assertStringContainsString($passClass, $exception->getMessage());
            self::assertStringContainsString(\sprintf('"%s"', $serviceId), $exception->getMessage());

            return;
        }

        self::fail(\sprintf('The build compiled without "%s", which %s writes into.', $serviceId, $passClass));
    }

    /**
     * The legitimate absence is a partial container that never registers the
     * check: there each pass keeps its own skip, which its unit test pins.
     * The product container holds every service, so the check passes there.
     */
    #[Test]
    public function itBuildsTheProductContainerWithEveryConsumerRegistered(): void
    {
        $container = (new ContainerFactory())->create();

        self::assertTrue($container->isCompiled());
    }

    /**
     * Second witness to the population: every pass in the compiler-pass
     * directory that asks whether a service is registered implements the
     * interface the check reads, so no pass can skip silently outside it.
     */
    #[Test]
    public function itBindsEveryPassThatAsksForARegisteredServiceToTheCheck(): void
    {
        $directory = \dirname(__DIR__, 5) . '/src/Infrastructure/DependencyInjection/CompilerPass';
        $files = glob($directory . '/*CompilerPass.php');
        self::assertNotFalse($files);
        self::assertNotSame([], $files);

        $unbound = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            self::assertIsString($contents);

            $class = 'Qualimetrix\\Infrastructure\\DependencyInjection\\CompilerPass\\' . basename($file, '.php');

            if ($class === ConsumerRegistrationCompilerPass::class
                || preg_match('/->has(Definition)?\(/', $contents) !== 1
            ) {
                continue;
            }

            if (!is_a($class, ConsumerBoundPassInterface::class, true)) {
                $unbound[] = $class;
            }
        }

        self::assertSame([], $unbound, 'Compiler passes that can skip a step outside the registration check.');
    }

    #[Test]
    public function itRegistersEveryConsumerBoundPassOfTheDirectoryWithTheProductContainer(): void
    {
        $registered = array_map(
            static fn(CompilerPassInterface $pass): string => $pass::class,
            (new ContainerFactory())->configure()->getCompilerPassConfig()->getPasses(),
        );

        $directory = \dirname(__DIR__, 5) . '/src/Infrastructure/DependencyInjection/CompilerPass';
        $files = glob($directory . '/*.php');
        self::assertNotFalse($files);

        foreach ($files as $file) {
            $class = 'Qualimetrix\\Infrastructure\\DependencyInjection\\CompilerPass\\' . basename($file, '.php');

            if (!class_exists($class) || new ReflectionClass($class)->isAbstract()) {
                continue;
            }

            if (is_a($class, ConsumerBoundPassInterface::class, true)) {
                self::assertContains($class, $registered);
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideEveryConsumerOfEveryRegisteredPass(): iterable
    {
        $count = 0;

        foreach ((new ContainerFactory())->configure()->getCompilerPassConfig()->getPasses() as $pass) {
            if (!$pass instanceof ConsumerBoundPassInterface) {
                continue;
            }

            foreach ($pass::consumerServiceIds() as $serviceId) {
                ++$count;

                yield $pass::class . ' -> ' . $serviceId => [$pass::class, $serviceId];
            }
        }

        if ($count === 0) {
            throw new LogicException('No consumer-bound pass is registered, so this provider yields nothing to check.');
        }
    }
}

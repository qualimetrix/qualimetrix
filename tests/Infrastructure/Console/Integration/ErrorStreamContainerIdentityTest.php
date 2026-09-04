<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\Command\GraphExportCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionObject;

/**
 * The composed run has exactly one error-stream owner, and it is the container's.
 *
 * A spelling guard proves nobody writes around the owner; this proves nobody
 * holds a *different* one. Two `ErrorStream` objects each keep their own
 * section list, so the frame drawn on one erases lines the other never
 * recorded — the identical defect, with everything spelled correctly.
 *
 * The instance is reached the way the run reaches it: the container is built
 * as `bin/qmx` builds it, the public roots are resolved, and every
 * `ErrorStream` reachable by property from any of them must be that one
 * object.
 */
#[CoversClass(ErrorStream::class)]
final class ErrorStreamContainerIdentityTest extends TestCase
{
    /**
     * Every class the composed run gives the owner to.
     *
     * The list is an enumeration, not a floor: a consumer that disappears is
     * as much a change of ownership as one that appears, and both are meant to
     * be argued for in review.
     *
     * @var list<class-string>
     */
    private const array CONSUMERS = [
        'Qualimetrix\\Infrastructure\\Console\\Command\\GraphExportCommand',
        'Qualimetrix\\Infrastructure\\Console\\FindingFilterOrchestrator',
        'Qualimetrix\\Infrastructure\\Console\\ProfilePresenter',
        'Qualimetrix\\Infrastructure\\Console\\Progress\\ProgressConfigurator',
        'Qualimetrix\\Infrastructure\\Console\\ResultPresenter',
        'Qualimetrix\\Infrastructure\\Console\\RuntimeLoggerConfigurator',
    ];

    #[Test]
    public function itGivesEveryConsumerTheOneOwnerTheContainerHolds(): void
    {
        $container = (new ContainerFactory())->create();

        $owner = $container->get(ErrorStream::class);
        self::assertInstanceOf(ErrorStream::class, $owner);

        $holders = [];
        $foreign = [];

        foreach ([CheckCommand::class, GraphExportCommand::class, RuntimeConfigurator::class] as $root) {
            $service = $container->get($root);
            self::assertIsObject($service);

            self::collectOwners($service, $holders, $foreign, $owner, []);
        }

        sort($holders);

        self::assertSame([], $foreign, 'these hold an error-stream owner the container did not compose');
        self::assertSame(self::CONSUMERS, array_values(array_unique($holders)));
    }

    #[Test]
    public function itLeavesNoConsumerAbleToInventItsOwnOwner(): void
    {
        // A constructor that can be called without the owner is the way a
        // second instance gets composed by omission rather than by decision.
        $optional = [];

        foreach ([...self::CONSUMERS, 'Qualimetrix\\Infrastructure\\Console\\Application'] as $class) {
            $constructor = (new ReflectionClass($class))->getConstructor();
            self::assertNotNull($constructor, $class . ' has no constructor to inject through');

            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();
                if (!$type instanceof ReflectionNamedType || $type->getName() !== ErrorStream::class) {
                    continue;
                }

                if ($parameter->isOptional()) {
                    $optional[] = $class . '::$' . $parameter->getName();
                }
            }
        }

        self::assertSame([], $optional, 'these can be constructed with an owner of their own making');
    }

    /**
     * @param list<class-string> $holders
     * @param list<string> $foreign
     * @param list<int> $seen
     */
    private static function collectOwners(
        object $service,
        array &$holders,
        array &$foreign,
        ErrorStream $owner,
        array $seen,
    ): void {
        $id = spl_object_id($service);
        if (\in_array($id, $seen, true)) {
            return;
        }
        $seen[] = $id;

        foreach ((new ReflectionObject($service))->getProperties() as $property) {
            if (!$property->isInitialized($service)) {
                continue;
            }

            $value = $property->getValue($service);
            if (!\is_object($value)) {
                continue;
            }

            if ($value instanceof ErrorStream) {
                $holders[] = $service::class;

                if ($value !== $owner) {
                    $foreign[] = $service::class . '::$' . $property->getName();
                }

                continue;
            }

            if (!str_starts_with($value::class, 'Qualimetrix\\')) {
                continue;
            }

            self::collectOwners($value, $holders, $foreign, $owner, $seen);
        }
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

/**
 * Every console command is reachable both ways it has to be reachable.
 *
 * The binary loads commands lazily from the container by class name, so a
 * command can be declared with `#[AsCommand]`, written, tested through
 * `CommandTester`, and still be missing from `bin/qmx` or from the container —
 * neither of which any other test would notice, because a lazy loader only
 * fails when the name is typed.
 *
 * The set under test is read off the filesystem rather than out of the
 * binary's map: an enumeration taken from the map could only ever confirm the
 * map against itself, and the failure being guarded is precisely a command the
 * map does not mention.
 */
#[Group('integration')]
final class CommandRegistrationTest extends TestCase
{
    private const string COMMAND_DIRECTORY = __DIR__ . '/../../../../src/Infrastructure/Console/Command';

    private const string BINARY = __DIR__ . '/../../../../bin/qmx';

    /** @param class-string<Command> $class */
    #[Test]
    #[DataProvider('provideCommandClasses')]
    public function itLoadsEveryDeclaredCommandFromTheContainerUnderTheNameTheBinaryUses(
        string $class,
        string $name,
    ): void {
        $binary = (string) file_get_contents(self::BINARY);
        self::assertStringContainsString(
            \sprintf("'%s' => ", $name),
            $binary,
            \sprintf('%s declares the name "%s" but bin/qmx does not map it.', $class, $name),
        );

        $command = (new ContainerFactory())->create()->get($class);
        self::assertInstanceOf($class, $command);
        self::assertSame($name, $command->getName());
    }

    /** @return iterable<string, array{class-string<Command>, string}> */
    public static function provideCommandClasses(): iterable
    {
        $directory = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::COMMAND_DIRECTORY, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($directory as $file) {
            \assert($file instanceof SplFileInfo);
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = self::classOf($file);
            if ($class === null || !is_subclass_of($class, Command::class)) {
                continue;
            }

            $attributes = (new ReflectionClass($class))->getAttributes(AsCommand::class);
            if ($attributes === []) {
                continue;
            }

            $name = $attributes[0]->newInstance()->name;
            yield $name => [$class, $name];
        }
    }

    /** @return ?class-string */
    private static function classOf(SplFileInfo $file): ?string
    {
        $source = (string) file_get_contents($file->getPathname());

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
            return null;
        }

        $class = $namespace[1] . '\\' . $file->getBasename('.php');

        return class_exists($class) ? $class : null;
    }
}

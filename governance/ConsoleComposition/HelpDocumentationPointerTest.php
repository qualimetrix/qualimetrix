<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ConsoleComposition;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

/**
 * Every command this repository registers carries the documentation address
 * ({@see ProductIdentity::llmsTxtUrl()}) in its `Help:` section, so a reader
 * who lands on `--help` for any one command is never the exception that a
 * hand-maintained list would eventually drop.
 *
 * The population is read off the filesystem via `#[AsCommand]`, exactly as
 * {@see CommandRegistrationTest} does and for the same reason stated in its
 * docblock: an enumeration taken from `bin/qmx`'s command-loader map could
 * only ever confirm the map against itself, and the failure this guard exists
 * to catch is precisely a command the map does not mention.
 *
 * **The population is not "every command a running `qmx` process answers
 * to."** Symfony adds `help`, `list`, `completion` and `_complete` to a live
 * `Application`; none of them carries an `#[AsCommand]` attribute under
 * `src/Infrastructure/Console/Command/`, so the filesystem sweep already
 * leaves all four out on its own — named here so that silence is read as a
 * deliberate scope, not an oversight. (`list`'s own `Help:` section does
 * carry the pointer, appended by {@see \Qualimetrix\Infrastructure\Console\Application::appendPointerToListCommandHelp()};
 * that mechanism belongs to the application, not to a command this
 * repository declares, so it is out of this guard's population and untested
 * here.)
 *
 * **This guard is silent on everything a command prints after it runs** —
 * the `Docs:` tail some commands write at the end of their output is a
 * separate mechanism, covered by each command's own functional tests. Some
 * registered commands have no such tail by design (`check` hands its output
 * to a formatter, `graph:export` writes the requested artifact and nothing
 * else), and asserting over the tail here would need exactly the per-command
 * exception table this guard is built to avoid needing for `Help:`.
 *
 * The assertion reads `getHelp()`'s final string, not how a command arrived
 * at it — it is agnostic to whether the pointer came from a command's own
 * inline `setHelp()` call (e.g. {@see \Qualimetrix\Infrastructure\Console\Command\RulesCommand}),
 * an inherited `configure()` shared by the hook family (via
 * {@see \Qualimetrix\Infrastructure\Console\Command\AbstractHookCommand}), or
 * a shared helper the baseline family calls from each command's own
 * `configure()` (`withDocsPointer()`).
 *
 * @see \Qualimetrix\Governance\DocumentationCensus\LlmsIndexRegisteredSurfaceTest
 *     for the sibling guard this test's second-witness idea is borrowed from:
 *     same rationale ("a count is not an enumeration"), applied here to
 *     `Help:` content instead of the `llms.txt` index.
 */
#[Group('integration')]
final class HelpDocumentationPointerTest extends TestCase
{
    private const string COMMAND_DIRECTORY = __DIR__ . '/../../src/Infrastructure/Console/Command';

    private const string BINARY_PATH = __DIR__ . '/../../bin/qmx';

    /** @param class-string<Command> $class */
    #[Test]
    #[DataProvider('provideCommandClasses')]
    public function itCarriesTheDocumentationPointerInItsHelp(string $class, string $name): void
    {
        $command = (new ContainerFactory())->create()->get($class);
        self::assertInstanceOf($class, $command);

        self::assertStringContainsString(
            ProductIdentity::llmsTxtUrl(),
            $command->getHelp(),
            \sprintf('The "%s" command\'s Help: text does not carry the documentation address.', $name),
        );
    }

    /**
     * Independent second witness for the population's *size*, not its
     * membership — same distinction {@see \Qualimetrix\Governance\DocumentationCensus\LlmsIndexRegisteredSurfaceTest::itRefusesWhenCommandSweepDisagreesWithTheBinaryMap()}
     * draws. A sweep that comes back fully empty is already refused by
     * PHPUnit itself (an empty `#[DataProvider]` result is an error, not a
     * silent pass, measured while rehearsing this guard) — this test's own
     * contribution is the sweep that loses *some* members while keeping the
     * rest: a moved file, a glob that stopped matching one command, a broken
     * `#[AsCommand]` read on one class. That failure mode leaves every
     * surviving case green in {@see self::itCarriesTheDocumentationPointerInItsHelp()}
     * and nothing else notices. `bin/qmx`'s command-loader map is a second
     * source, built by hand and not by this sweep, so comparing the two
     * *counts* catches it without ever reading the map's enumeration back
     * out — which is the one thing {@see CommandRegistrationTest}'s docblock
     * forbids ("an enumeration taken from the map could only ever confirm the
     * map against itself"). A count borrowed from the map to check a number
     * is not that.
     */
    #[Test]
    public function itRefusesWhenTheCommandSweepDisagreesWithTheBinaryMapCount(): void
    {
        $sweptCount = \count(iterator_to_array(self::provideCommandClasses()));
        $mappedCount = self::commandMapCountInBinary();

        self::assertGreaterThan(0, $mappedCount, 'Found no "\'name\' => XxxCommand::class" entries in bin/qmx.');
        self::assertSame(
            $mappedCount,
            $sweptCount,
            \sprintf(
                'The #[AsCommand] filesystem sweep found %d command(s) but bin/qmx\'s command-loader map has %d '
                . 'entries. One of the two sources is broken, and the Help: check above cannot be trusted until '
                . 'they agree.',
                $sweptCount,
                $mappedCount,
            ),
        );
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

    private static function commandMapCountInBinary(): int
    {
        $binary = (string) file_get_contents(self::BINARY_PATH);
        $count = preg_match_all('/\'[\w:-]+\'\s*=>\s*[A-Za-z_][A-Za-z0-9_]*Command::class/', $binary);

        return $count === false ? 0 : $count;
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Every rule the file system declares is registered with the container, and
 * nothing else is.
 *
 * The **file system** is the point: it is the second witness. Every other
 * guard on the rule set reads the registry — `RuleDocsPageCoverageTest` and
 * `RuleRemediationMinutesCoverageTest` pin its size, `ChannelUniverseCoverageTest`
 * reads both of its witnesses out of the container, and
 * `ContainerFactoryTest::itRegistersAllRules` compares it against a
 * hand-written list — so a rule that never enters the registry moves none of
 * them and ships documented, manifested, layered and inert.
 *
 * That gap was theoretical while every capability globbed `**\/*Rule.php`. It
 * stopped being theoretical when `DesignConfigurator` began naming its rules
 * one by one, which it does because the order channels enter the universe is
 * published (see the fixture `Fixtures/Channels/order.txt`) and a glob would
 * have the file system decide it. The explicit list buys a decided order and
 * costs the automatic net; this test is the net, and it is written over every
 * capability rather than over Design alone, because the next configurator to
 * need a decided order should not have to remember to extend it.
 */
#[CoversClass(RuleRegistryInterface::class)]
final class RuleRegistrationDriftTest extends TestCase
{
    #[Test]
    public function everyConcreteRuleClassOnDiskIsRegistered(): void
    {
        $onDisk = self::ruleClassesOnDisk();
        $registered = self::registeredRuleClasses();

        self::assertNotEmpty($onDisk, 'the sweep found no rule classes at all, so it proves nothing');

        self::assertSame(
            [],
            array_values(array_diff($onDisk, $registered)),
            'A *Rule.php class implementing RuleInterface exists on disk but no configurator registers it. It'
            . ' would be absent from the registry, and every other guard on the rule set reads the registry —'
            . ' so it would ship inert, with the rule count unmoved. Add it to its capability configurator.',
        );

        self::assertSame(
            [],
            array_values(array_diff($registered, $onDisk)),
            'The registry names a class the sweep did not find. Either the class moved out of src/Analysis or it'
            . ' is not named *Rule.php, and this guard stopped covering it.',
        );
    }

    /**
     * @return list<string>
     */
    private static function ruleClassesOnDisk(): array
    {
        $classes = [];
        $root = \dirname(__DIR__, 3) . '/src';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/Analysis'));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), 'Rule.php')) {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($root) + 1, -\strlen('.php'));
            $fqcn = 'Qualimetrix\\' . str_replace('/', '\\', $relative);

            if (!class_exists($fqcn)) {
                continue;
            }

            $reflection = new ReflectionClass($fqcn);

            if ($reflection->isAbstract() || !$reflection->implementsInterface(RuleInterface::class)) {
                continue;
            }

            $classes[] = $fqcn;
        }

        sort($classes);

        return $classes;
    }

    /**
     * @return list<string>
     */
    private static function registeredRuleClasses(): array
    {
        $registry = (new ContainerFactory())->create()->get(RuleRegistryInterface::class);
        \assert($registry instanceof RuleRegistryInterface);

        $classes = array_values($registry->getClasses());
        sort($classes);

        return $classes;
    }
}

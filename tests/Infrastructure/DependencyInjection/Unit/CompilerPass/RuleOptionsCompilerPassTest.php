<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\DependencyInjection\Unit\CompilerPass;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyAnalysis;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellOptions;
use Qualimetrix\Analysis\Evidence\CodeSmell\CountInLoopRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\DebugCodeRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\EmptyCatchRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\EvalRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\ExitRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\GotoRule;
use Qualimetrix\Analysis\Evidence\CodeSmell\SuperglobalsRule;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityOptions;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityRule;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ProjectNamespaceResolverInterface;
use Qualimetrix\Analysis\Evidence\Security\CommandInjectionRule;
use Qualimetrix\Analysis\Evidence\Security\SecurityPatternOptions;
use Qualimetrix\Analysis\Evidence\Security\SqlInjectionRule;
use Qualimetrix\Analysis\Evidence\Security\XssRule;
use Qualimetrix\Analysis\Evidence\Size\ClassCountRule;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleOptionsCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Subprocess\ChildProcess;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

require_once \dirname(__DIR__, 5) . '/scripts/subprocess/ChildProcess.php';

#[CoversClass(RuleOptionsCompilerPass::class)]
final class RuleOptionsCompilerPassTest extends TestCase
{
    #[Test]
    public function itRegistersAnOptionsServiceThatReadsTheReadySnapshot(): void
    {
        $container = new ContainerBuilder();
        $container->register(RuleOptionsRegistry::class)->setSynthetic(true);
        $container->register(ComplexityRule::class)
            ->setClass(ComplexityRule::class)
            ->addTag(RuleCompilerPass::TAG);

        $pass = new RuleOptionsCompilerPass();
        $pass->process($container);

        $optionsReference = $container->getDefinition(ComplexityRule::class)->getArgument('$options');
        self::assertInstanceOf(Reference::class, $optionsReference);
        self::assertSame(
            self::optionsServiceId(ComplexityRule::NAME, ComplexityOptions::class),
            (string) $optionsReference,
        );

        $optionsDef = $container->getDefinition((string) $optionsReference);
        self::assertSame(ComplexityOptions::class, $optionsDef->getClass());
        $factory = $optionsDef->getFactory();
        self::assertIsArray($factory);
        self::assertInstanceOf(Reference::class, $factory[0]);
        self::assertSame('optionsFor', $factory[1]);
        self::assertSame(RuleOptionsRegistry::class, (string) $factory[0]);
        self::assertFalse($optionsDef->isShared());

        $args = $optionsDef->getArguments();
        self::assertSame(ComplexityRule::NAME, $args[0]);
        self::assertSame(ComplexityOptions::class, $args[1]);
    }

    #[Test]
    public function itDoesNothingWhenTheSnapshotRegistryIsNotRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->register(ComplexityRule::class)
            ->setClass(ComplexityRule::class)
            ->addTag(RuleCompilerPass::TAG);

        $pass = new RuleOptionsCompilerPass();
        $pass->process($container);

        self::assertFalse($container->hasDefinition(
            self::optionsServiceId(ComplexityRule::NAME, ComplexityOptions::class),
        ));
    }

    #[Test]
    public function itRefusesTaggedServicesWithoutAClass(): void
    {
        $container = new ContainerBuilder();
        $container->register(RuleOptionsRegistry::class)->setSynthetic(true);
        $container->register('rule.null_class')
            ->addTag(RuleCompilerPass::TAG);

        $pass = new RuleOptionsCompilerPass();
        self::expectException(LogicException::class);
        self::expectExceptionMessage('Tagged rule service "rule.null_class" has no class.');
        $pass->process($container);
    }

    #[Test]
    public function itRefusesATagOnACollaboratorThatIsNotARule(): void
    {
        $container = new ContainerBuilder();
        $container->register(RuleOptionsRegistry::class)->setSynthetic(true);
        $container->register(CircularDependencyAnalysis::class, CircularDependencyAnalysis::class)->addTag(RuleCompilerPass::TAG);
        self::expectException(LogicException::class);
        self::expectExceptionMessage(CircularDependencyAnalysis::class . ': it does not implement');
        (new RuleOptionsCompilerPass())->process($container);
    }

    #[Test]
    public function itRefusesAnUnavailableClassTypedConstructorDependencyDuringTheBuild(): void
    {
        $container = (new ContainerFactory())->configure();
        $container->removeAlias(ProjectNamespaceResolverInterface::class);
        self::expectException(LogicException::class);
        self::expectExceptionMessage('DistanceRule::$namespaceResolver: service "' . ProjectNamespaceResolverInterface::class . '" is not registered.');
        $container->compile();
    }

    #[Test]
    public function itRefusesMalformedOptionsMetadataInsteadOfSkippingTheRule(): void
    {
        // The fixture deliberately breaks the class-string PHPDoc contract;
        // a subprocess keeps that invalid declaration outside static fixtures.
        $script = <<<'PHP'
require $argv[1];
final class WrongOptionsRule extends \Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule {
    public const string NAME = 'fixture.wrong-options';
    public static function getOptionsClass(): string { return stdClass::class; }
    public function analyze(\Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext $context): array { return []; }
}
$c = new \Symfony\Component\DependencyInjection\ContainerBuilder();
$c->register(\Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry::class)->setSynthetic(true);
$c->register(WrongOptionsRule::class, WrongOptionsRule::class)->addTag('qmx.rule');
try {
    (new \Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleOptionsCompilerPass())->process($c);
    exit(1);
} catch (LogicException $failure) {
    echo $failure->getMessage();
    exit(0);
}
PHP;
        $result = ChildProcess::run([\PHP_BINARY, '-d', 'xdebug.mode=off', '-r', $script, \dirname(__DIR__, 5) . '/vendor/autoload.php']);
        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertStringContainsString('WrongOptionsRule::getOptionsClass() did not name', $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    #[Test]
    public function itInjectsOptionsIntoEveryTaggedRule(): void
    {
        $container = new ContainerBuilder();
        $container->register(RuleOptionsRegistry::class)->setSynthetic(true);
        $container->register(ComplexityRule::class)
            ->setClass(ComplexityRule::class)
            ->addTag(RuleCompilerPass::TAG);
        $container->register(ClassCountRule::class)
            ->setClass(ClassCountRule::class)
            ->addTag(RuleCompilerPass::TAG);

        $pass = new RuleOptionsCompilerPass();
        $pass->process($container);

        // Both rules should have options injected
        $complexityDef = $container->getDefinition(ComplexityRule::class);
        self::assertInstanceOf(Reference::class, $complexityDef->getArgument('$options'));

        $classCountDef = $container->getDefinition(ClassCountRule::class);
        self::assertInstanceOf(Reference::class, $classCountDef->getArgument('$options'));
    }

    #[Test]
    public function itKeepsTheOptionsServiceIdentitySeparateForEveryProducer(): void
    {
        $container = new ContainerBuilder();
        $container->register(RuleOptionsRegistry::class)->setSynthetic(true);

        $producers = [
            CountInLoopRule::class => CodeSmellOptions::class,
            DebugCodeRule::class => CodeSmellOptions::class,
            EmptyCatchRule::class => CodeSmellOptions::class,
            EvalRule::class => CodeSmellOptions::class,
            ExitRule::class => CodeSmellOptions::class,
            GotoRule::class => CodeSmellOptions::class,
            SuperglobalsRule::class => CodeSmellOptions::class,
            CommandInjectionRule::class => SecurityPatternOptions::class,
            SqlInjectionRule::class => SecurityPatternOptions::class,
            XssRule::class => SecurityPatternOptions::class,
        ];

        foreach ($producers as $ruleClass => $optionsClass) {
            $container->register($ruleClass)
                ->setClass($ruleClass)
                ->addTag(RuleCompilerPass::TAG);
        }

        (new RuleOptionsCompilerPass())->process($container);

        $optionsServiceIds = [];
        foreach ($producers as $ruleClass => $optionsClass) {
            $optionsReference = $container->getDefinition($ruleClass)->getArgument('$options');
            self::assertInstanceOf(Reference::class, $optionsReference);

            $optionsServiceId = (string) $optionsReference;
            $optionsServiceIds[] = $optionsServiceId;
            self::assertSame(self::optionsServiceId($ruleClass::NAME, $optionsClass), $optionsServiceId);

            $optionsDefinition = $container->getDefinition($optionsServiceId);
            self::assertSame($optionsClass, $optionsDefinition->getClass());
            self::assertSame([$ruleClass::NAME, $optionsClass], $optionsDefinition->getArguments());
        }

        self::assertCount(10, array_unique($optionsServiceIds));
    }

    #[Test]
    public function itKeepsAnExistingOptionsServiceDefinitionInsteadOfOverwritingIt(): void
    {
        $container = new ContainerBuilder();
        $container->register(RuleOptionsRegistry::class)->setSynthetic(true);

        $optionsServiceId = self::optionsServiceId(ComplexityRule::NAME, ComplexityOptions::class);

        // Pre-register this producer's Options with a custom factory
        $container->register($optionsServiceId, ComplexityOptions::class)
            ->setFactory([new Reference(RuleOptionsRegistry::class), 'optionsFor'])
            ->setArguments(['custom.name', ComplexityOptions::class]);

        $container->register(ComplexityRule::class)
            ->setClass(ComplexityRule::class)
            ->addTag(RuleCompilerPass::TAG);

        $pass = new RuleOptionsCompilerPass();
        $pass->process($container);

        // Should keep the existing definition (with 'custom.name')
        $optionsDef = $container->getDefinition($optionsServiceId);
        self::assertSame('custom.name', $optionsDef->getArgument(0));
    }

    /**
     * @param class-string $optionsClass
     */
    private static function optionsServiceId(string $ruleName, string $optionsClass): string
    {
        return $ruleName . '.options.' . $optionsClass;
    }
}

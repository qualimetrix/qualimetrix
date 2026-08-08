<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\RuleOptionsFactory;
use Qualimetrix\Configuration\RuleOptionsRegistry;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\RuleInterface;
use Qualimetrix\Core\Rule\RuleOptionsInterface;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleOptionsCompilerPass;
use Qualimetrix\Rules\AbstractRule;
use Qualimetrix\Rules\CodeSmell\CodeSmellOptions;
use Qualimetrix\Rules\CodeSmell\CountInLoopRule;
use Qualimetrix\Rules\CodeSmell\DebugCodeRule;
use Qualimetrix\Rules\CodeSmell\EmptyCatchRule;
use Qualimetrix\Rules\CodeSmell\EvalRule;
use Qualimetrix\Rules\CodeSmell\ExitRule;
use Qualimetrix\Rules\CodeSmell\GotoRule;
use Qualimetrix\Rules\CodeSmell\SuperglobalsRule;
use Qualimetrix\Rules\Security\CommandInjectionRule;
use Qualimetrix\Rules\Security\SecurityPatternOptions;
use Qualimetrix\Rules\Security\SqlInjectionRule;
use Qualimetrix\Rules\Security\XssRule;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(RuleOptionsCompilerPass::class)]
final class SharedRuleOptionsContainerTest extends TestCase
{
    /**
     * @var array<class-string<RuleInterface>, class-string<RuleOptionsInterface>>
     */
    private const array PRODUCERS = [
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

    #[Test]
    public function itCreatesIndependentlyConfiguredOptionsForEverySharedOptionsProducer(): void
    {
        $container = $this->createContainer();
        $registry = $container->get(RuleOptionsRegistry::class);
        self::assertInstanceOf(RuleOptionsRegistry::class, $registry);

        // This is the registry shape produced after YAML/preset resolution.
        $registry->setConfigFileOptions([
            EvalRule::NAME => [
                'enabled' => false,
                'exclude_paths' => ['src/Eval'],
            ],
            GotoRule::NAME => [
                'exclude_namespaces' => ['App\\Legacy'],
            ],
            SqlInjectionRule::NAME => ['enabled' => false],
        ]);

        // This is the registry seam used by --rule-opt.
        $registry->addCliOption(XssRule::NAME, 'enabled', false);

        $optionsByProducer = [];
        foreach (self::PRODUCERS as $ruleClass => $optionsClass) {
            $rule = $container->get($ruleClass);
            self::assertInstanceOf($ruleClass, $rule);

            $options = self::optionsOf($rule);
            self::assertInstanceOf($optionsClass, $options);
            $optionsByProducer[$ruleClass::NAME] = $options;
        }

        self::assertCount(10, array_unique(array_map(spl_object_id(...), $optionsByProducer)));

        self::assertTrue($optionsByProducer[CountInLoopRule::NAME]->isEnabled());
        self::assertFalse($optionsByProducer[EvalRule::NAME]->isEnabled());
        self::assertTrue($optionsByProducer[CommandInjectionRule::NAME]->isEnabled());
        self::assertFalse($optionsByProducer[SqlInjectionRule::NAME]->isEnabled());
        self::assertFalse($optionsByProducer[XssRule::NAME]->isEnabled());

        $pathExclusions = $registry->getPathExclusionProvider();
        self::assertTrue($pathExclusions->isExcluded(EvalRule::NAME, RelativePath::fromString('src/Eval/File.php')));
        self::assertFalse($pathExclusions->isExcluded(CountInLoopRule::NAME, RelativePath::fromString('src/Eval/File.php')));

        $namespaceExclusions = $registry->getExclusionProvider();
        self::assertTrue($namespaceExclusions->isExcluded(GotoRule::NAME, 'App\\Legacy\\Service'));
        self::assertFalse($namespaceExclusions->isExcluded(DebugCodeRule::NAME, 'App\\Legacy\\Service'));
    }

    private function createContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(RuleOptionsRegistry::class)->setPublic(true);
        $container->register(RuleOptionsFactory::class)
            ->setArguments([new Reference(RuleOptionsRegistry::class)]);

        foreach (self::PRODUCERS as $ruleClass => $optionsClass) {
            $container->register($ruleClass, $ruleClass)
                ->addTag(RuleCompilerPass::TAG)
                ->setPublic(true);
        }

        (new RuleOptionsCompilerPass())->process($container);
        $container->compile();

        return $container;
    }

    private static function optionsOf(RuleInterface $rule): RuleOptionsInterface
    {
        $property = new ReflectionProperty(AbstractRule::class, 'options');
        $options = $property->getValue($rule);
        self::assertInstanceOf(RuleOptionsInterface::class, $options);

        return $options;
    }
}

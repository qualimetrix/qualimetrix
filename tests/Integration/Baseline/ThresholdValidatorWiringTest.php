<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Baseline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\Suppression\RuleValidatorMapFactory;
use Qualimetrix\Baseline\Suppression\ThresholdOverrideExtractor;
use Qualimetrix\Core\Rule\Override\OverrideValidatorInterface;
use Qualimetrix\Core\Rule\RuleInterface;
use Qualimetrix\Core\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\RuleRegistryCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ThresholdValidatorMapCompilerPass;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Qualimetrix\Rules\Size\MethodCountOptions;
use Qualimetrix\Rules\Size\MethodCountRule;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Verifies that every ThresholdAware rule receives its validator through
 * the production DI wiring.
 *
 * Two layers of coverage:
 *
 * 1. {@see RuleValidatorMapFactory::build()} against the real rule
 *    registry — proves the factory recognises every ThresholdAware
 *    Options class and yields the validator each returns.
 * 2. {@see ThresholdValidatorMapCompilerPass} on a synthetic container —
 *    proves the pass collects tagged rule services and writes the
 *    resolved map onto the Extractor's `$validators` argument.
 *
 * The two layers compose: production wiring runs the same factory with
 * the same rule class list against the same Extractor service.
 */
#[CoversClass(ThresholdValidatorMapCompilerPass::class)]
#[CoversClass(RuleValidatorMapFactory::class)]
#[CoversClass(ThresholdOverrideExtractor::class)]
final class ThresholdValidatorWiringTest extends TestCase
{
    #[Test]
    public function factoryProducesValidatorForEveryThresholdAwareRuleInRegistry(): void
    {
        $container = (new ContainerFactory())->create();

        $registry = $container->get(RuleRegistryInterface::class);
        \assert($registry instanceof RuleRegistryInterface);

        $ruleClasses = $registry->getClasses();
        $validatorMap = RuleValidatorMapFactory::build($ruleClasses);

        $checkedThresholdAware = 0;

        foreach ($ruleClasses as $ruleClass) {
            $optionsClass = $ruleClass::getOptionsClass();
            if (!is_subclass_of($optionsClass, ThresholdAwareOptionsInterface::class)) {
                continue;
            }

            $ruleName = self::resolveRuleName($ruleClass);
            $expectedValidator = $optionsClass::getOverrideValidator();

            self::assertArrayHasKey(
                $ruleName,
                $validatorMap,
                "Rule '{$ruleName}' implements ThresholdAwareOptionsInterface but the factory did not emit a validator for it.",
            );
            self::assertSame(
                $expectedValidator,
                $validatorMap[$ruleName],
                "Factory-emitted validator for '{$ruleName}' differs from the one returned by {$optionsClass}::getOverrideValidator().",
            );

            ++$checkedThresholdAware;
        }

        // Sanity guard: ensure the test does not silently degrade to zero
        // iterations if the registry shrinks. The number of rules can shift as
        // rules are added or merged, but going below ~15 would mean the test
        // covers practically nothing.
        self::assertGreaterThanOrEqual(
            15,
            $checkedThresholdAware,
            'Suspiciously few ThresholdAware rules — the test may have stopped iterating.',
        );
    }

    #[Test]
    public function compilerPassWritesValidatorMapOntoExtractorArgument(): void
    {
        $container = new ContainerBuilder();

        // Real rule service tagged for the registry pipeline — MethodCountRule
        // uses the StandardOverrideValidator via the trait, the most common path.
        $ruleDefinition = new Definition(MethodCountRule::class);
        $ruleDefinition->addTag(RuleRegistryCompilerPass::TAG);
        $container->setDefinition('test.rule.method_count', $ruleDefinition);

        $extractorDefinition = new Definition(ThresholdOverrideExtractor::class);
        $extractorDefinition->setArgument('$validators', []);
        $container->setDefinition(ThresholdOverrideExtractor::class, $extractorDefinition);

        (new ThresholdValidatorMapCompilerPass())->process($container);

        /** @var array<string, OverrideValidatorInterface> $validators */
        $validators = $extractorDefinition->getArgument('$validators');

        self::assertArrayHasKey(MethodCountRule::NAME, $validators);
        self::assertSame(
            MethodCountOptions::getOverrideValidator(),
            $validators[MethodCountRule::NAME],
        );
    }

    #[Test]
    public function compilerPassNoOpsWhenExtractorIsAbsent(): void
    {
        $container = new ContainerBuilder();

        // No ThresholdOverrideExtractor definition — pass must not throw.
        (new ThresholdValidatorMapCompilerPass())->process($container);

        self::assertFalse($container->hasDefinition(ThresholdOverrideExtractor::class));
    }

    /**
     * @param class-string<RuleInterface> $ruleClass
     */
    private static function resolveRuleName(string $ruleClass): string
    {
        $reflection = new ReflectionClass($ruleClass);
        $name = $reflection->getConstant('NAME');
        \assert(\is_string($name));

        return $name;
    }
}

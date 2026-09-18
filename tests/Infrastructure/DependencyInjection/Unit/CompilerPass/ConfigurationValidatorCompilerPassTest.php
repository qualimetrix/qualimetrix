<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\DependencyInjection\Unit\CompilerPass;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ConfigurationValidatorCompilerPass;
use Qualimetrix\Infrastructure\Rule\ConfigurationValidatorRegistry;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * What the pass does with a tagged service, and the two integrity refusals.
 *
 * A validator that fails to reach the executor runs never and declares
 * nothing, and no second reader would notice: the channel pass reads the same
 * tag and would be missing the same member. Both failures are therefore loud.
 *
 * The docblock said that and the file carried only the two refusals, so the
 * arrival the refusals exist to protect was the one thing nothing here
 * checked: a pass that dropped every validator on the floor passed both.
 */
#[CoversClass(ConfigurationValidatorCompilerPass::class)]
final class ConfigurationValidatorCompilerPassTest extends TestCase
{
    #[Test]
    public function itHandsEveryTaggedValidatorToTheExecutorAndTheRegistry(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ConfigurationValidatorRegistry::class, new Definition(stdClass::class));
        $container->setDefinition('Qualimetrix\\Analysis\\Finding\\RuleExecution', new Definition(stdClass::class));
        $container->register('validator.arriving', ArrivingValidator::class)
            ->addTag(ConfigurationValidatorCompilerPass::TAG);

        (new ConfigurationValidatorCompilerPass())->process($container);

        $executorArgument = $container
            ->getDefinition('Qualimetrix\\Analysis\\Finding\\RuleExecution')
            ->getArgument('$configurationValidators');
        self::assertIsArray($executorArgument);
        self::assertContainsOnlyInstancesOf(Reference::class, $executorArgument);
        self::assertSame(
            ['validator.arriving'],
            array_map(static fn(Reference $reference): string => (string) $reference, $executorArgument),
        );

        self::assertSame(
            [ArrivingValidator::class],
            $container->getDefinition(ConfigurationValidatorRegistry::class)->getArgument('$validatorClasses'),
        );
    }

    #[Test]
    public function itThrowsOnATaggedServiceWithNoClass(): void
    {
        $container = new ContainerBuilder();
        $container->register('validator.null_class')
            ->addTag(ConfigurationValidatorCompilerPass::TAG);

        self::expectException(LogicException::class);
        self::expectExceptionMessage('names no class');

        (new ConfigurationValidatorCompilerPass())->process($container);
    }

    #[Test]
    public function itThrowsOnATaggedServiceThatIsNotAValidator(): void
    {
        $container = new ContainerBuilder();
        $container->register('validator.impostor', stdClass::class)
            ->addTag(ConfigurationValidatorCompilerPass::TAG);

        self::expectException(LogicException::class);
        self::expectExceptionMessage('does not implement');

        (new ConfigurationValidatorCompilerPass())->process($container);
    }
}

final class ArrivingValidator implements ConfigurationValidatorInterface
{
    public static function producerRuleName(): string
    {
        return 'test.rule';
    }

    public static function shape(): ChannelShape
    {
        return ChannelShape::Occurrence;
    }

    public static function channelDeclarations(): array
    {
        return [];
    }

    public function validate(AnalysisContext $context): array
    {
        return [];
    }
}

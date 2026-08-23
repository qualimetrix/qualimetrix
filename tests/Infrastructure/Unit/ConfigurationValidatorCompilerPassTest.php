<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ConfigurationValidatorCompilerPass;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The pass's integrity refusals.
 *
 * A validator that fails to reach the executor runs never and declares
 * nothing, and no second reader would notice: the channel pass reads the same
 * tag and would be missing the same member. Both failures are therefore loud.
 */
#[CoversClass(ConfigurationValidatorCompilerPass::class)]
final class ConfigurationValidatorCompilerPassTest extends TestCase
{
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

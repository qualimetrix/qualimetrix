<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CompilerPass;

use LogicException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final readonly class TagPopulationFloorCompilerPass implements CompilerPassInterface
{
    /** @param array<string, positive-int> $minimumByTag */
    public function __construct(private array $minimumByTag) {}

    public function process(ContainerBuilder $container): void
    {
        foreach ($this->minimumByTag as $tag => $minimum) {
            $count = \count($container->findTaggedServiceIds($tag));
            if ($count < $minimum) {
                throw new LogicException(\sprintf(
                    'Tagged population "%s" has %d services; at least %d is required.',
                    $tag,
                    $count,
                    $minimum,
                ));
            }
        }
    }
}

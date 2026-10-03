<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Registration;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/** Creates fresh loaders and prototypes for exact evidence registration. */
final class EvidenceRegistration
{
    public static function loader(ContainerBuilder $container, string $srcDir): PhpFileLoader
    {
        return new PhpFileLoader($container, new FileLocator($srcDir));
    }

    public static function collectors(): Definition
    {
        return (new Definition())->setAutoconfigured(true)->setAutowired(true);
    }

    public static function rules(): Definition
    {
        return (new Definition())->setAutoconfigured(true)->setAutowired(false)->setLazy(true);
    }
}

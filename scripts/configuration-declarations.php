<?php

declare(strict_types=1);

/**
 * The sections of the configuration document, as the product composes them: the
 * roots Configuration declares and every service the container tags as an owner
 * section. Generators and controls read the document's shape from here, so a
 * section added to the product reaches them without an edit.
 *
 * Safe to `require_once` from several scripts: it only declares a function.
 */

namespace Qualimetrix\ConfigurationDeclarations;

use Qualimetrix\Analysis\Configuration\ConfigurationRoot;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Infrastructure\DependencyInjection\Configuration\ConfigurationConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use RuntimeException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * @throws RuntimeException when the container tags no owner section or tags a service that is not one
 *
 * @return list<DocumentSectionSchemaInterface> Configuration's roots first, then the owner sections
 */
function configurationSections(): array
{
    $container = (new ContainerFactory())->configure();
    $container->addCompilerPass(new class implements CompilerPassInterface {
        public function process(ContainerBuilder $container): void
        {
            foreach (array_keys($container->findTaggedServiceIds(ConfigurationConfigurator::SECTION_TAG)) as $id) {
                $container->getDefinition($id)->setPublic(true);
            }
        }
    }, PassConfig::TYPE_BEFORE_REMOVING, 150);
    $container->compile();
    $owners = [];

    foreach (array_keys($container->findTaggedServiceIds(ConfigurationConfigurator::SECTION_TAG)) as $id) {
        $section = $container->get($id);

        if (!$section instanceof DocumentSectionSchemaInterface) {
            throw new RuntimeException(\sprintf('service "%s" carries the section tag but declares no section', $id));
        }

        $owners[] = $section;
    }

    if ($owners === []) {
        throw new RuntimeException('the container tags no configuration section; the document would be described by Configuration alone');
    }

    return [...ConfigurationRoot::cases(), ...$owners];
}

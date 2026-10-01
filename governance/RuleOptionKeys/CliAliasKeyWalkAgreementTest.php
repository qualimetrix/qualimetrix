<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\RuleOptionKeys;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionRefusalWording;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParserFactory;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;

/**
 * Every registered CLI alias must reach one recognized option through the
 * real command definition, authored CLI layer and document composer.
 *
 * Values come from the declared form only to reach that key walk; this control
 * does not promise coverage of each option's value grammar. The alias target
 * and schema are independently exercised by the resulting authored path and
 * written document node. An alias addressing a level block is refused.
 */
#[CoversClass(RuleOptionRefusalWording::class)]
final class CliAliasKeyWalkAgreementTest extends TestCase
{
    #[Test]
    public function itLetsEveryCliAliasTheProductShipsThroughTheKeyWalk(): void
    {
        $unknownKey = 'is not an option';
        $notAMap = 'takes a map of options';

        self::assertStringContainsString($unknownKey, RuleOptionRefusalWording::notAnOptionOfRule('k', 'r', []));
        self::assertStringContainsString($unknownKey, RuleOptionRefusalWording::notAnOptionAtLevel('k', 'r', 'class', []));
        self::assertStringContainsString($notAMap, RuleOptionRefusalWording::levelTakesAMapOfOptions('class', 'r', 1));

        $container = (new ContainerFactory())->create();

        $ruleRegistry = $container->get(RuleRegistryInterface::class);
        self::assertInstanceOf(RuleRegistryInterface::class, $ruleRegistry);

        $execution = $container->get(RuleExecutionInterface::class);
        self::assertInstanceOf(RuleExecutionInterface::class, $execution);

        $optionsClasses = [];
        foreach ($execution->allRules() as $rule) {
            $optionsClasses[$rule->name] = $rule->optionsClass;
        }

        $parser = (new RuleOptionsParserFactory())->createFromClasses($ruleRegistry->getClasses());
        $aliases = $parser->getAliasNames();

        self::assertNotSame([], $aliases, 'No CLI alias found — the subject of this test is empty');

        $command = new \Symfony\Component\Console\Command\Command('check');
        \Qualimetrix\Infrastructure\Console\CheckCommandDefinition::addOptions($command, $ruleRegistry);
        foreach ($aliases as $alias) {
            $target = $parser->aliasTarget($alias);
            self::assertNotNull($target, \sprintf('Alias "%s" is registered but has no target', $alias));
            self::assertArrayHasKey($target['rule'], $optionsClasses, \sprintf('Alias "%s" names an unregistered rule', $alias));
            $surface = $parser->surfaceFor($target['rule']);
            self::assertNotNull($surface);
            $address = $surface->locate($target['option']);
            self::assertNotNull($address, \sprintf('--%s addresses an unrecognised key', $alias));
            self::assertFalse($address->level === null && $surface->levelNamed($address->key) !== null, \sprintf('--%s addresses a level slot instead of an option', $alias));
            $schema = $surface->schemaAt($address);
            $form = $schema->scalarForms()[0] ?? $schema->element()->scalarForms()[0] ?? null;
            $text = match ($form) {
                \Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm::Boolean => true,
                \Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm::Integer,
                \Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm::Number => (string) (max(1, $schema->minimum() ?? 0)),
                \Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm::String => $schema->choices()[0] ?? 'fixture',
                default => throw new LogicException('This control needs an explicit value sample for an alias with no scalar or list scalar form.'),
            };
            $input = new \Symfony\Component\Console\Input\ArrayInput(['--' . $alias => $text], $command->getDefinition());
            $writes = (new \Qualimetrix\Infrastructure\Console\CliOptionsParser($parser))->pathWrites($input);
            self::assertCount(1, $writes, \sprintf('--%s did not produce exactly one authored record', $alias));
            self::assertSame(['rules', $target['rule'], ...($address->level === null ? [] : [$address->level]), $address->key], $writes[0]->path);
            $configuration = \Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration::none();
            $resolved = \Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture::authoredConfiguration($configuration, $execution->allRules(), $writes);
            self::assertNotNull($resolved->document->get(...$writes[0]->path), \sprintf('--%s never reached the resolved key walk', $alias));
        }
    }
}

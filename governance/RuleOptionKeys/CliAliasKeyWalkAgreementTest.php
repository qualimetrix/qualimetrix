<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\RuleOptionKeys;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionRefusalWording;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParserFactory;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Throwable;

/**
 * Every `#[CliAlias]` the product ships addresses a key the class at that
 * flag's depth answers for — a universal property over the real registry, not
 * a per-rule case, which is why it lives apart from
 * {@see \Qualimetrix\Tests\Analysis\Finding\Unit\RuleConfiguration\UnknownRuleOptionKeyRefusalTest}.
 *
 * The two sides were only ever kept in agreement by hand — the alias names
 * an option in the attribute, the class declares its key set, and nothing
 * compared them. While an unrecognised key was a warning, a disagreement
 * cost a line on `stderr`; now it costs exit 3 on a flag the product itself
 * declares, which a user has no way around. All 80 agree today, so this is
 * the invariant being closed, not a defect being fixed.
 *
 * The alias goes in through its own door and no other: the parser folds the
 * spelling the attribute's author wrote, and the factory expands the dot of
 * a `slot.key` alias into the nesting depth 2 is walked at. Rebuilding
 * either step here would let this test agree with a defect in it.
 *
 * The value is deliberately not the point: an alias naming a string option
 * is refused for its `1` by the options class itself, and that refusal is
 * the class speaking about a value, not about a key. Only a refusal in the
 * words of {@see RuleOptionRefusalWording} is a failure here, and the two
 * sentences are asked for their own marker rather than transcribed.
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

        foreach ($aliases as $alias) {
            $parsed = $parser->parseShortAlias($alias, 1);
            self::assertIsArray($parsed, \sprintf('Alias "%s" is registered but parses to nothing', $alias));

            $ruleName = $parsed['rule'];
            self::assertArrayHasKey($ruleName, $optionsClasses, \sprintf('Alias "%s" names rule "%s", which is not registered', $alias, $ruleName));

            $registry = new RuleOptionsRegistry();
            $registry->setCliOptions($ruleName, [$parsed['option'] => $parsed['value']]);

            $refusal = $this->capture(
                static function () use ($registry, $ruleName, $optionsClasses): void {
                    (new RuleOptionsFactory($registry))->create($ruleName, $optionsClasses[$ruleName]);
                },
            );

            $message = $refusal?->getMessage() ?? '';

            self::assertStringNotContainsString(
                $unknownKey,
                $message,
                \sprintf('--%s writes "%s" on rule "%s", which the key walk does not recognise', $alias, $parsed['option'], $ruleName),
            );
            self::assertStringNotContainsString(
                $notAMap,
                $message,
                \sprintf('--%s writes "%s" on rule "%s", which the key walk reads as a level slot', $alias, $parsed['option'], $ruleName),
            );
        }
    }

    private function capture(callable $act): ?Throwable
    {
        try {
            $act();
        } catch (Throwable $refusal) {
            return $refusal;
        }

        return null;
    }
}

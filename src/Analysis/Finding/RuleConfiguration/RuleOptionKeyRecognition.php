<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionRefusalWording;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;

/**
 * Which option keys a rule answers for at each of the two depths a user can
 * write one at, and the refusal for every key nothing there answers for.
 *
 * The two depths are compared against two different declarations: the rule's
 * own {@see RuleOptionsInterface::acceptedOptionKeys()}, and — for a key naming
 * one of a hierarchical rule's level slots — that slot's level options class,
 * named by {@see HierarchicalRuleOptionsInterface::levelOptionsClasses()}.
 * There is no single "keys allowed at a level" list to compare against:
 * `callable` takes `warning`/`error` where `class` on the same rule takes
 * `max-warning`/`max-error`.
 *
 * A key the class declares as answered-by-itself passes here untouched, so that
 * `fromArray()` may refuse it in its own words — the generic sentence printed
 * one line above a specific one is the defect this walk removes, not a second
 * thing it does.
 */
final class RuleOptionKeyRecognition
{
    /**
     * The keys the framework consumes before `fromArray()` ever sees them.
     *
     * No options class declares them and none ever will: {@see RuleOptionsFactory}
     * takes them out of the user's config on its way to `fromArray()`, so a
     * correctly spelled one never reaches the comparison below — but a mistyped
     * one does, and a refusal that lists the allowed keys without listing these
     * would name the fix nowhere.
     *
     * @var list<string>
     */
    private const array FRAMEWORK_KEYS = [
        'suppress-namespace-channels',
        'suppress-namespaces',
        'suppress-paths',
    ];

    /**
     * Refuses a framework key whose value is of no form the framework can use.
     *
     * Separate from {@see self::refuseUnknownKeys()} and called before it,
     * because the factory takes these three keys out of the config on the way
     * to `fromArray()`: by the time the key walk below runs they are gone.
     *
     * Only `suppress-paths` is judged here. The two namespace keys are read by
     * a provider that already answers about their form in its own words —
     * naming `suppress_namespace_channels` as the fix for a channel map
     * written under `suppress_namespaces`, and naming the offending selector —
     * and a general sentence raised one step earlier would replace a specific
     * answer with a vaguer one.
     *
     * @param array<string, mixed> $userConfig what the user actually wrote, framework keys still in place
     *
     * @throws ConfigurationRefusal on the first framework key of the wrong form, in document order
     */
    public static function refuseMalformedFrameworkKeys(array $userConfig, string $ruleName): void
    {
        $shapes = self::frameworkKeyShapes();

        foreach ($userConfig as $writtenKey => $value) {
            $key = (string) $writtenKey;
            $shape = $shapes->shapeOf(ConfigKeySpelling::normalize($key));

            if ($shape === null || $shape->matches($value)) {
                continue;
            }

            throw ConfigurationRefusal::atResolvedKey(
                RefusedPosition::open([$ruleName], $key),
                RuleOptionRefusalWording::valueOfTheWrongShape($key, $ruleName, null, $shape, $value),
            );
        }
    }

    /**
     * The form of the framework key nothing else judges: path patterns are
     * read as strings, one or many, and `null` means the key was written with
     * nothing under it.
     */
    private static function frameworkKeyShapes(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'suppress-paths' => RuleOptionShape::either(
                RuleOptionShape::nonEmptyText(),
                RuleOptionShape::listOf(RuleOptionShape::nonEmptyText()),
            )->orNull(),
        ]);
    }

    /**
     * Refuses every rule option key the class at its depth does not answer for.
     *
     * The subject must be what the user actually wrote, never an array that
     * fell back to constructor defaults: a hierarchical wrapper's defaults hold
     * level *objects*, and a depth-2 walk over those would either fault or
     * validate the defaults against themselves.
     *
     * @param array<string, mixed> $userConfig what the user actually wrote, after the framework keys were taken out
     * @param class-string<RuleOptionsInterface> $optionsClass
     *
     * @throws ConfigurationRefusal on the first unrecognised key in document order
     */
    public static function refuseUnknownKeys(array $userConfig, string $ruleName, string $optionsClass): void
    {
        $acceptedHere = $optionsClass::acceptedOptionKeys();
        $slots = is_a($optionsClass, HierarchicalRuleOptionsInterface::class, true)
            ? $optionsClass::levelOptionsClasses()
            : [];

        foreach ($userConfig as $writtenKey => $value) {
            $key = (string) $writtenKey;
            $normalized = ConfigKeySpelling::normalize($key);

            if (isset($slots[$normalized])) {
                self::refuseUnknownKeysInsideLevel($value, $normalized, $ruleName, $slots[$normalized]);

                continue;
            }

            if ($acceptedHere->knows($normalized) || \in_array($normalized, self::normalizedFrameworkKeys(), true)) {
                self::refuseWrongShape($acceptedHere, $normalized, $key, $ruleName, null, $value);

                continue;
            }

            throw ConfigurationRefusal::atResolvedKey(
                RefusedPosition::closed([$ruleName], $key, self::optionsHere($acceptedHere)),
                RuleOptionRefusalWording::notAnOptionOfRule(
                    $key,
                    $ruleName,
                    self::optionsHere($acceptedHere),
                ),
            );
        }
    }

    /**
     * The depth-2 half, and the answer to a slot holding something that is not
     * a map — which has to come first, because a non-map has no keys to walk.
     *
     * `null` is accepted: an empty level block means what an omitted one means,
     * and refusing it would refuse a harmless YAML idiom.
     *
     * @param class-string<LevelOptionsInterface> $levelOptionsClass
     *
     * @throws ConfigurationRefusal
     */
    private static function refuseUnknownKeysInsideLevel(
        mixed $value,
        string $level,
        string $ruleName,
        string $levelOptionsClass,
    ): void {
        if ($value === null) {
            return;
        }

        if (!\is_array($value)) {
            throw ConfigurationRefusal::atResolvedKey(
                RefusedPosition::open([$ruleName, $level], $level),
                RuleOptionRefusalWording::levelTakesAMapOfOptions($level, $ruleName, $value),
            );
        }

        $acceptedThere = $levelOptionsClass::acceptedOptionKeys();

        foreach ($value as $writtenKey => $written) {
            $key = (string) $writtenKey;

            if ($acceptedThere->knows(ConfigKeySpelling::normalize($key))) {
                self::refuseWrongShape(
                    $acceptedThere,
                    ConfigKeySpelling::normalize($key),
                    $key,
                    $ruleName,
                    $level,
                    $written,
                );

                continue;
            }

            throw ConfigurationRefusal::atResolvedKey(
                RefusedPosition::closed([$ruleName, $level], $key, $acceptedThere->acceptedForDisplay()),
                RuleOptionRefusalWording::notAnOptionAtLevel(
                    $key,
                    $ruleName,
                    $level,
                    $acceptedThere->acceptedForDisplay(),
                ),
            );
        }
    }

    /**
     * Refuses a value whose form is not the one its key was declared with.
     *
     * Silent for a key the class answers about itself: that half carries no
     * form here on purpose, so that `fromArray()` keeps the whole answer about
     * it rather than being contradicted one line earlier.
     *
     * @throws ConfigurationRefusal
     */
    private static function refuseWrongShape(
        RuleOptionKeySet $declaration,
        string $normalized,
        string $writtenKey,
        string $ruleName,
        ?string $level,
        mixed $written,
    ): void {
        $shape = $declaration->shapeOf($normalized);

        if ($shape === null || $shape->matches($written)) {
            return;
        }

        throw ConfigurationRefusal::atResolvedKey(
            RefusedPosition::open($level === null ? [$ruleName] : [$ruleName, $level], $writtenKey),
            RuleOptionRefusalWording::valueOfTheWrongShape($writtenKey, $ruleName, $level, $shape, $written),
        );
    }

    /**
     * The allowed set printed at depth 1: what the class declared, plus the
     * three framework keys no options class declares and none ever will.
     *
     * They are legal here and illegal inside a slot, and a refusal that omits
     * them would name the fix for a mistyped `suppress_path` nowhere.
     *
     * @return list<string>
     */
    private static function optionsHere(RuleOptionKeySet $acceptedHere): array
    {
        $options = [...$acceptedHere->acceptedForDisplay(), ...self::FRAMEWORK_KEYS];
        sort($options);

        return $options;
    }

    /**
     * @return list<string>
     */
    private static function normalizedFrameworkKeys(): array
    {
        return array_map(ConfigKeySpelling::normalize(...), self::FRAMEWORK_KEYS);
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

/** The framework-owned forms shared by every producer's root declaration. */
final readonly class FrameworkOptionKeys
{
    public const string PATHS = 'suppress-paths';
    public const string NAMESPACES = 'suppress-namespaces';
    public const string NAMESPACE_CHANNELS = 'suppress-namespace-channels';

    /** The forms of framework-owned keys at a producer's root depth. */
    public static function declared(): RuleOptionKeySet
    {
        $selector = static fn(bool $namespace): RuleOptionShape => RuleOptionShape::mapOf(RuleOptionShape::nonEmptyText())->judgedInEachLayer(
            static function (\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface $value, array $path) use ($namespace): void {
                (new \Qualimetrix\Analysis\Finding\RuleConfiguration\RuleSuppressionSelectorDecoder())->judgeSelector($value, $path, $namespace);
            },
        );
        $paths = RuleOptionShape::listOf($selector(false))->orNull();
        $namespaces = RuleOptionShape::listOf($selector(true))->orNull();

        return RuleOptionKeySet::of([
            'enabled' => RuleOptionShape::boolean(),
            self::PATHS => $paths,
            self::NAMESPACES => $namespaces,
            self::NAMESPACE_CHANNELS => RuleOptionShape::mapOf(RuleOptionShape::listOf($selector(true)))->orNull()->judgedInEachLayer(
                static function (\Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface $value, array $path): void {
                    (new \Qualimetrix\Analysis\Finding\RuleConfiguration\RuleSuppressionSelectorDecoder())->judgeChannels($value, $path);
                },
            ),
        ]);
    }

    /** @return list<string> Suppression keys consumed by Finding, without the independent enablement switch. */
    public static function suppressionKeys(): array
    {
        return array_values(array_filter(self::all(), static fn(string $key): bool => $key !== 'enabled'));
    }

    /**
     * Canonical kebab spellings, sorted — the order the "allowed here" sentence
     * and the listing's footer both print them in.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return self::declared()->acceptedForDisplay();
    }
}

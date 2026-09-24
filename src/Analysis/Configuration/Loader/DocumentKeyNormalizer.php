<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;

/**
 * Folds the keys of a parsed configuration document into the one spelling
 * every consumer compares against, section by section, as each root's
 * {@see SectionNormalizationPolicy} declares.
 *
 *  - {@code NORMALIZE_TO_CAMEL_CASE}: keys are camelCased at every depth.
 *  - {@code PRESERVE_IMMEDIATE_CHILDREN}: level-1 keys are preserved
 *    verbatim (user identifiers); level-2 and deeper resume normalization.
 *  - {@code PRESERVE_SUBTREE}: every descendant key is preserved verbatim,
 *    including scalar leaves at every depth.
 *
 * Folding makes `suppress_paths` and `suppressPaths` one key, so a mapping
 * that writes both would collapse into one cell with the later spelling
 * winning. That is refused here, where the collision is created: the YAML
 * parser already refuses an exact duplicate, and this is the same refusal for
 * the duplicate the fold makes.
 *
 * See [ADR 0009](../../../../docs/adr/0009-yaml-loader-normalization-model.md).
 */
final class DocumentKeyNormalizer
{
    /**
     * @param array<string|int, mixed> $config the document as parsed
     *
     * @throws ConfigurationRefusal when one mapping writes two spellings of one key
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $config, string $path): array
    {
        $knownRoots = ConfigSchema::allowedRootKeys();
        $result = [];
        $authored = [];

        foreach ($config as $key => $value) {
            $stringKey = (string) $key;
            $normalizedRoot = ConfigKeySpelling::normalize($stringKey);
            self::refuseSecondSpelling($authored, $normalizedRoot, $stringKey, [], $path);

            // A known root asks policyFor(), which fails fast when the root was
            // added without a policy (ADR 0009). A key nobody declared is the
            // author's mistake, refused by the loader's root-key validation; it
            // is only given a shape here so that refusal can be reached.
            $policy = \in_array($normalizedRoot, $knownRoots, true)
                ? ConfigSchema::policyFor($normalizedRoot)
                : SectionNormalizationPolicy::NORMALIZE_TO_CAMEL_CASE;

            $result[$normalizedRoot] = \is_array($value)
                ? self::walk($value, $policy, 0, $path, [$stringKey])
                : $value;
        }

        return $result;
    }

    /**
     * List items (integer keys) carry no user-facing spelling and pass through
     * unchanged. Where a sub-array goes next is the policy's own answer
     * ({@see SectionNormalizationPolicy::descentFor()}): an option declared
     * identifier-keyed opens a fresh identifier boundary for its children.
     *
     * @param array<string|int, mixed> $config
     * @param list<string> $trail the authored keys above this mapping
     *
     * @return array<string|int, mixed>
     */
    private static function walk(array $config, SectionNormalizationPolicy $policy, int $depth, string $path, array $trail): array
    {
        $preserveKeysHere = $policy === SectionNormalizationPolicy::PRESERVE_SUBTREE
            || ($policy === SectionNormalizationPolicy::PRESERVE_IMMEDIATE_CHILDREN && $depth === 0);

        $result = [];
        $authored = [];

        foreach ($config as $key => $value) {
            $normalizedKey = \is_int($key) ? $key : ConfigKeySpelling::normalize($key);
            $newKey = \is_int($key) || $preserveKeysHere ? $key : self::claimed($authored, $key, $trail, $path);

            $result[$newKey] = \is_array($value)
                ? self::descend($value, $policy->descentFor($normalizedKey, ConfigSchema::identifierKeyedOptions(), $depth), $path, [...$trail, (string) $key])
                : $value;
        }

        return $result;
    }

    /**
     * The normalized key, once no other spelling in this mapping has claimed it.
     *
     * @param array<string, string> $authored
     * @param list<string> $trail
     */
    private static function claimed(array &$authored, string $written, array $trail, string $path): string
    {
        $normalized = ConfigKeySpelling::normalize($written);
        self::refuseSecondSpelling($authored, $normalized, $written, $trail, $path);

        return $normalized;
    }

    /**
     * @param array<string|int, mixed> $value
     * @param array{policy: SectionNormalizationPolicy, depth: int} $descent
     * @param list<string> $trail
     *
     * @return array<string|int, mixed>
     */
    private static function descend(array $value, array $descent, string $path, array $trail): array
    {
        return self::walk($value, $descent['policy'], $descent['depth'], $path, $trail);
    }

    /**
     * @param array<string, string> $authored normalized key => the spelling first written for it
     * @param list<string> $trail the authored keys above this mapping
     */
    private static function refuseSecondSpelling(array &$authored, string $normalized, string $written, array $trail, string $path): void
    {
        $first = $authored[$normalized] ?? null;
        $authored[$normalized] ??= $written;

        if ($first === null || $first === $written) {
            return;
        }

        throw ConfigurationRefusal::atConfigFileKey(
            $path,
            RefusedPosition::open([...$trail, $written], $written),
            \sprintf(
                'Keys "%s" and "%s"%s are two spellings of one key, and a document may set it only once. Keep one of them.',
                $first,
                $written,
                $trail === [] ? '' : \sprintf(' under "%s"', implode('.', $trail)),
            ),
        );
    }
}

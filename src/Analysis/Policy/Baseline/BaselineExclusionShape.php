<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use stdClass;

/** Required discovery-exclusion grammar within a baseline document. */
final class BaselineExclusionShape
{
    public const array KEYS = ['patterns', 'generated'];

    private function __construct() {}

    /** @param array<mixed, mixed> $exclusions */
    public static function assertKeys(array $exclusions, string $path): void
    {
        foreach ($exclusions as $key => $_) {
            $written = (string) $key;
            if (!\in_array($written, self::KEYS, true)) {
                throw ConfigurationRefusal::atBaselineFileKey(
                    $path,
                    RefusedPosition::closed(['exclusions', $written], $written, self::KEYS),
                    \sprintf('Unknown baseline key "%s" at %s; accepted keys: %s', $written, implode(' › ', ['exclusions', $written]), implode(', ', self::KEYS)),
                );
            }
        }
    }

    public static function parse(mixed $raw, string $path): RecordedExclusions
    {
        $fields = self::exclusionFields($raw, $path);
        $patterns = self::exclusionPatterns($fields['patterns'] ?? null, $path);
        $policy = self::generatedPolicy($fields['generated'] ?? null, $path);

        try {
            return new RecordedExclusions($patterns, $policy);
        } catch (InvalidArgumentException $e) {
            throw self::valueRefusal($path, ['exclusions', 'patterns'], $e->getMessage());
        }
    }

    /** @return array<mixed, mixed> */
    private static function exclusionFields(mixed $raw, string $path): array
    {
        if ($raw instanceof stdClass) {
            $raw = (array) $raw;
        } elseif (!\is_array($raw) || array_is_list($raw)) {
            throw self::valueRefusal($path, ['exclusions'], 'Baseline "exclusions" must be an object containing patterns and generated');
        }
        self::assertKeys($raw, $path);

        return $raw;
    }

    /** @return list<string> */
    private static function exclusionPatterns(mixed $patterns, string $path): array
    {
        if (!\is_array($patterns) || !array_is_list($patterns)) {
            throw self::valueRefusal($path, ['exclusions', 'patterns'], 'Baseline "exclusions.patterns" must be an array of explicit selectors');
        }
        foreach ($patterns as $index => $pattern) {
            self::exclusionPattern($pattern, $path, $index);
        }

        /** @var list<string> $patterns */
        return $patterns;
    }

    private static function exclusionPattern(mixed $pattern, string $path, int $index): void
    {
        if (!\is_string($pattern)) {
            throw self::valueRefusal($path, ['exclusions', 'patterns', (string) $index], 'Baseline exclusion selectors must be strings');
        }
        try {
            $parts = explode(':', $pattern, 2);
            if (\count($parts) !== 2) {
                throw new InvalidArgumentException('An exclusion must use exact:value, subtree:value, or regex:value');
            }
            new PathPattern(SelectorDefinition::fromKindAndValue($parts[0], $parts[1]));
        } catch (InvalidArgumentException $e) {
            throw self::valueRefusal($path, ['exclusions', 'patterns', (string) $index], $e->getMessage());
        }
    }

    private static function generatedPolicy(mixed $generated, string $path): GeneratedFilePolicy
    {
        return match ($generated) {
            'included' => GeneratedFilePolicy::Include,
            'excluded' => GeneratedFilePolicy::Exclude,
            default => throw ConfigurationRefusal::atBaselineFileKey(
                $path,
                RefusedPosition::closed(['exclusions', 'generated'], 'generated', ['included', 'excluded']),
                'Baseline "exclusions.generated" must be "included" or "excluded"',
            ),
        };
    }

    /** @param non-empty-list<string> $position */
    private static function valueRefusal(string $path, array $position, string $summary): ConfigurationRefusal
    {
        return ConfigurationRefusal::atBaselineFileKey($path, RefusedPosition::open($position, $position[\count($position) - 1]), $summary);
    }
}

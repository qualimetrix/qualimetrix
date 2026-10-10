<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use stdClass;

/** Closed entry and edge keys before JSON objects are normalized to arrays. */
final class BaselineEntryShape
{
    public const array ENTRY = ['channel', 'occurrence', 'edge', 'count', 'magnitudes', 'mode'];
    public const array EDGE = ['target', 'type'];

    private function __construct() {}

    /** @param array<array-key, mixed> $entries */
    public static function assertBlocks(array $entries, string $path): void
    {
        foreach ($entries as $subject => $block) {
            if (!\is_array($block) || !array_is_list($block)) {
                continue;
            }

            foreach ($block as $index => $raw) {
                if ($raw instanceof stdClass) {
                    self::assertEntryKeys((array) $raw, $path, (string) $subject, $index);
                }
            }
        }
    }

    /** @param array<mixed, mixed> $entry */
    public static function assertEntryKeys(array $entry, string $path, string $subject, int $index): void
    {
        $position = ['entries', $subject, (string) $index];
        self::assertKeys($entry, self::ENTRY, $position, $path);
        $edge = $entry['edge'] ?? null;
        if ($edge instanceof stdClass) {
            self::assertEdgeKeys((array) $edge, $path, [...$position, 'edge']);
        } elseif (\is_array($edge) && !array_is_list($edge)) {
            self::assertEdgeKeys($edge, $path, [...$position, 'edge']);
        }
    }

    /**
     * @param array<mixed, mixed> $edge
     * @param list<string> $position
     */
    public static function assertEdgeKeys(array $edge, string $path, array $position): void
    {
        self::assertKeys($edge, self::EDGE, $position, $path);
    }

    /**
     * @param array<mixed, mixed> $object
     * @param list<string> $accepted
     * @param list<string> $position
     */
    private static function assertKeys(array $object, array $accepted, array $position, string $path): void
    {
        $unknown = array_diff_key($object, array_fill_keys($accepted, true));
        if ($unknown === []) {
            return;
        }

        $written = (string) array_key_first($unknown);
        throw ConfigurationRefusal::atBaselineFileKey(
            $path,
            RefusedPosition::closed([...$position, $written], $written, $accepted),
            \sprintf('Unknown baseline key "%s" at %s; accepted keys: %s', $written, implode(' › ', [...$position, $written]), implode(', ', $accepted)),
        );
    }
}

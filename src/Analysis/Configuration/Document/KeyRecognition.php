<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

/**
 * The one spelling rule for every dictionary key of the document: a key is
 * recognised when written as one of its three accepted spellings; the same
 * words in any other style are refused with the canonical key offered; a key
 * that is none of the dictionary is refused as unknown, whatever its value.
 */
final class KeyRecognition
{
    /**
     * @param list<string> $dictionary canonical keys
     *
     * @throws ConfigurationRefusal
     *
     * @return ?string the canonical key; null for an unknown key when `$admitUnknown`
     */
    public static function recognise(string $written, array $dictionary, ReadingContext $at, bool $admitUnknown = false): ?string
    {
        foreach ($dictionary as $canonical) {
            if (\in_array($written, ConfigKeySpelling::acceptedSpellings($canonical), true)) {
                return $canonical;
            }
        }

        foreach ($dictionary as $canonical) {
            if (ConfigKeySpelling::sameWords($written, $canonical)) {
                throw $at->refusal(
                    \sprintf(
                        'Key %s is not written in an accepted spelling; write "%s" (its snake_case, camelCase and kebab-case spellings are accepted).',
                        $at->where(),
                        $canonical,
                    ),
                    $written,
                    [$canonical],
                );
            }
        }

        if ($admitUnknown) {
            return null;
        }

        $suggestion = self::closest($written, $dictionary);

        throw $at->refusal(
            \sprintf(
                'Unknown key %s%s. Accepted keys: %s.',
                $at->where(),
                $suggestion === null ? '' : \sprintf(' (did you mean "%s"?)', $suggestion),
                $dictionary === [] ? '(none)' : implode(', ', $dictionary),
            ),
            $written,
            $dictionary,
        );
    }

    /** @param list<string> $candidates */
    public static function closest(string $written, array $candidates): ?string
    {
        $best = null;
        $bestDistance = 4;

        foreach ($candidates as $candidate) {
            $distance = levenshtein(strtolower($written), strtolower($candidate));
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        return $best;
    }
}

<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Fingerprints are compared by recomputation, on each side from that side's own
 * published fields.
 *
 * They cannot be mapped: they hash the finding's identity, so any legitimate
 * rename moves them. Excluding them would drop the only guard against a silent
 * fingerprint reset — a reset that would make every consumer treat every finding
 * as new. So the gate rebuilds the expected value from the same composition
 * `Violation::getFingerprint()` uses and asserts the published one matches.
 *
 * @see \Qualimetrix\Analysis\Finding\Contract\Violation::getFingerprint()
 * @see \Qualimetrix\Reporting\Formatter\Sarif\SarifFormatter
 * @see \Qualimetrix\Reporting\Formatter\GitLabCodeQualityFormatter
 */
final class Fingerprints
{
    /**
     * @param list<array<string, mixed>> $findings
     *
     * @return list<string>
     */
    public static function expected(array $findings): array
    {
        $expected = [];

        foreach ($findings as $finding) {
            $parts = [self::text($finding, 'channel'), self::text($finding, 'subject')];
            $occurrence = $finding['occurrence'] ?? null;

            if (\is_string($occurrence)) {
                $parts[] = $occurrence;
            }

            $edge = $finding['edge'] ?? null;

            if (\is_array($edge)) {
                $target = \is_string($edge['target'] ?? null) ? $edge['target'] : '';
                $type = $edge['type'] ?? null;
                $parts[] = \is_string($type)
                    ? $type . ':' . $target
                    : 'untyped-edge:' . \strlen($target) . ':' . $target;
            }

            $expected[] = implode(':', $parts);
        }

        sort($expected);

        return $expected;
    }

    /**
     * @param list<string> $expected
     *
     * @return list<string>
     */
    public static function md5Of(array $expected): array
    {
        $hashed = array_map(md5(...), $expected);
        sort($hashed);

        return $hashed;
    }

    /**
     * @return list<string>
     */
    public static function publishedInSarif(string $sarif): array
    {
        $decoded = json_decode($sarif, true, flags: \JSON_THROW_ON_ERROR);
        $published = [];

        foreach ((array) ($decoded['runs'] ?? []) as $run) {
            foreach ((array) (\is_array($run) ? $run['results'] ?? [] : []) as $result) {
                $hash = \is_array($result) ? $result['partialFingerprints']['primaryLocationLineHash'] ?? null : null;

                if (\is_string($hash)) {
                    $published[] = $hash;
                }
            }
        }

        sort($published);

        return $published;
    }

    /**
     * The analysis-failure issues GitLab also carries hash a path and a failure
     * kind, not a finding, so they are not part of this comparison.
     *
     * @return list<string>
     */
    public static function publishedInGitLab(string $gitlab): array
    {
        $decoded = json_decode($gitlab, true, flags: \JSON_THROW_ON_ERROR);
        $published = [];

        foreach ((array) $decoded as $issue) {
            if (!\is_array($issue) || !\is_string($issue['fingerprint'] ?? null)) {
                continue;
            }

            if (str_starts_with((string) ($issue['check_name'] ?? ''), 'analysis.')) {
                continue;
            }

            $published[] = $issue['fingerprint'];
        }

        sort($published);

        return $published;
    }

    /** @param array<string, mixed> $finding */
    private static function text(array $finding, string $key): string
    {
        $value = $finding[$key] ?? null;

        return \is_string($value) ? $value : '';
    }
}

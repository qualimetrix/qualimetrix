<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Fingerprints are compared by recomputation, on each side from that side's own
 * published fields — and, where the published value is a hash, by substituting
 * the identity it was proved to hash before the two sides are compared at all.
 *
 * They cannot be mapped as hashes: they hash the finding's identity, so any
 * legitimate rename moves them, and no declared row can say `9e510e2b… ->
 * ffc793be…`. Excluding them would drop the only guard against a silent
 * fingerprint reset — a reset that would make every consumer treat every finding
 * as new.
 *
 * The two publications are not the same problem, measured 2026-08-24. SARIF
 * publishes the composition **in plain text** (`partialFingerprints.primaryLocationLineHash`
 * carries `channel:subject[:occurrence][:edge]` verbatim), so a declared channel
 * row translates it like any other name and the byte comparison keeps working.
 * GitLab publishes `md5` of that same string, and md5 of a renamed identity is
 * new bytes that no map can reach. That one surface — and only that one — is
 * therefore substituted: each published hash is replaced by the very string this
 * class proved it hashes, so the surface carries the identity as a name that a
 * declared row can translate, and an identity that moved with no row to explain
 * it is `surface-mismatch` on that surface, in readable text rather than hex.
 *
 * **Order matters, and getting it backwards is the trap.** The reference's
 * artifacts are translated FORWARD, so after translation its `channel` field
 * already speaks the candidate's vocabulary while the hash beside it is still the
 * old one — recomputing from translated fields and comparing that against the
 * published hash would report a mismatch on every finding of every honest
 * rename. So the two directions are kept apart:
 *
 * - self-consistency is measured on RAW artifacts, each side's published value
 *   against a recomputation from that same side's own untranslated fields
 *   ({@see \QmxFindingGate\Gate::checkFingerprints()});
 * - substitution happens on the RAW artifact too, from that side's own verified
 *   hash-to-identity pairs, and only the substituted text is then translated.
 *
 * Nothing ever recomputes a fingerprint from translated fields.
 *
 * The licence for substituting is {@see INPUT_FIELDS}: every input of the
 * composition is a field the equivalence tuple already compares, so the hash
 * carries no datum the comparison loses. That is asserted against the tracked
 * tuple rather than argued here.
 *
 * @see \Qualimetrix\Analysis\Finding\Contract\Finding::getFingerprint()
 * @see \Qualimetrix\Reporting\Formatter\Sarif\SarifFormatter
 * @see \Qualimetrix\Reporting\Formatter\GitLabCodeQualityFormatter
 */
final class Fingerprints
{
    /**
     * The one surface whose published fingerprint is opaque, and is therefore
     * substituted before comparison.
     */
    public const OPAQUE_SURFACE = 'format:gitlab';

    /**
     * The published finding fields the composition reads.
     *
     * Its being a subset of the equivalence tuple is what licenses substituting
     * the opaque publication: an input outside the tuple would be a datum the
     * hash carries and nothing else compares, and dropping the hash would then
     * retire it from the comparison. Checked against the tracked tuple in
     * {@see \QmxFindingGate\Gate::checkTuple()}.
     *
     * @var list<string>
     */
    public const INPUT_FIELDS = ['channel', 'subject', 'occurrence', 'edge'];

    /**
     * @param list<array<string, mixed>> $findings
     *
     * @return list<string>
     */
    public static function expected(array $findings): array
    {
        $expected = array_map(self::preimage(...), $findings);
        sort($expected);

        return $expected;
    }

    /**
     * Each published hash against the identity it hashes, taken from one side's
     * own raw findings.
     *
     * Keyed by the hash because that is what the artifact carries and what has
     * to be found there. Two findings sharing an identity share a hash, which is
     * why this is a map and not a list of pairs.
     *
     * @param list<array<string, mixed>> $findings
     *
     * @return array<string, string> md5 => the identity it hashes
     */
    public static function preimagesByHash(array $findings): array
    {
        $pairs = [];

        foreach ($findings as $finding) {
            $preimage = self::preimage($finding);
            $pairs[md5($preimage)] = $preimage;
        }

        return $pairs;
    }

    /**
     * Replaces every published hash with the identity it hashes.
     *
     * Textual, on the raw artifact, and only for values this side already proved
     * it hashes: an occurrence of some other hash — GitLab also carries
     * analysis-failure issues, whose fingerprint hashes a path and a failure kind
     * rather than a finding — is left exactly as it is, because no mechanism
     * replaces its comparison.
     *
     * The replacement is JSON-encoded, so the artifact stays a JSON document:
     * normalization re-encodes it, and a broken document would arrive there as a
     * plain-text surface instead.
     *
     * @param array<string, string> $preimagesByHash
     * @param int $published how many fingerprints this surface published, i.e. how many replacements must happen
     */
    public static function substitute(string $text, array $preimagesByHash, int $published): FingerprintSubstitution
    {
        $replaced = 0;
        $missing = [];

        foreach ($preimagesByHash as $hash => $preimage) {
            $count = 0;
            $text = str_replace(
                '"' . $hash . '"',
                (string) json_encode($preimage, \JSON_THROW_ON_ERROR),
                $text,
                $count,
            );

            if ($count === 0) {
                $missing[] = $hash;

                continue;
            }

            $replaced += $count;
        }

        return new FingerprintSubstitution($text, $replaced, $published, $missing);
    }

    /**
     * One finding's identity, composed exactly as `Finding::getFingerprint()`
     * composes it.
     *
     * @param array<string, mixed> $finding
     */
    private static function preimage(array $finding): string
    {
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

        return implode(':', $parts);
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

<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * What a declared rename splits, and the machine check that keeps a split from
 * retiring a compared field.
 *
 * A channel row translates the whole `rule#code` key and each differing half.
 * When several rows disagree about one half — `design.type-coverage` becoming
 * three different rule names — no textual translation of that half is correct,
 * so RenameMaps stops substituting it. That alone would be a hole: `rule` and
 * `code` are fields the equivalence tuple compares, and an untranslatable half
 * would reach the surface comparison as a difference for a declared delta to
 * absorb. Normalization is explicitly forbidden from reaching a compared field
 * (`normalization-overreach`), and a declared delta gets no waiver normalization
 * did not get.
 *
 * So every occurrence of a split half is explained instead, per record rather
 * than per string: the reference finding's own `(rule, code)` pair must be named
 * by a declared row, and the candidate must publish the pair that row computes,
 * on the same finding. An occurrence no declared row accounts for is
 * `split-unmapped`. What that check proves is then what
 * `delta-overreach` allows a declared delta to cover, and nothing wider.
 */
final class ChannelSplit
{
    /** The finding fields whose values a split rewrites. */
    private const FIELDS = ['channel', 'rule', 'code'];

    /** @var array<string, true> "field\0value" pairs an explained record accounts for */
    private array $allowed = [];

    /**
     * @param array<string, list<string>> $splits old half => the several new halves
     * @param array<string, string> $channelKeys old whole key => new whole key
     */
    private function __construct(
        private readonly array $splits,
        private readonly array $channelKeys,
    ) {}

    public static function of(RenameMaps $maps): self
    {
        return new self($maps->splits(), $maps->channelKeys());
    }

    public function isEmpty(): bool
    {
        return $this->splits === [];
    }

    /** @return list<string> */
    public function halves(): array
    {
        return array_keys($this->splits);
    }

    /**
     * Explains every reference finding that carries a split half, and reports
     * the ones nothing explains.
     *
     * The reference findings are expected to have been through the forward map
     * already: everything a row can translate is then translated, and what is
     * left carrying a split half is exactly what has to be explained by record.
     *
     * @param list<array<string, mixed>> $referenceFindings
     * @param list<array<string, mixed>> $candidateFindings
     *
     * @return list<string>
     */
    public function unexplained(array $referenceFindings, array $candidateFindings): array
    {
        if ($this->splits === []) {
            return [];
        }

        $candidateByIdentity = [];

        foreach ($candidateFindings as $finding) {
            $candidateByIdentity[self::identity($finding)][] = $finding;
        }

        $unexplained = [];

        foreach ($referenceFindings as $index => $finding) {
            $half = $this->halfIn($finding);

            if ($half === null) {
                continue;
            }

            $key = self::string($finding, 'rule') . '#' . self::string($finding, 'code');
            $declared = $this->channelKeys[$key] ?? null;

            if ($declared === null) {
                $unexplained[] = \sprintf(
                    'reference finding #%d carries the split half "%s" in channel "%s", and no declared channel row'
                    . ' names that key. A half a map cannot translate must be explained record by record.',
                    $index,
                    $half,
                    $key,
                );

                continue;
            }

            [$expectedRule, $expectedCode] = array_pad(explode('#', $declared, 2), 2, '');
            $identity = self::identity($finding);
            $match = null;

            foreach ($candidateByIdentity[$identity] ?? [] as $candidate) {
                if (self::string($candidate, 'rule') === $expectedRule && self::string($candidate, 'code') === $expectedCode) {
                    $match = $candidate;

                    break;
                }
            }

            if ($match === null) {
                $unexplained[] = \sprintf(
                    'reference finding #%d on %s is declared to become "%s", but the candidate publishes no finding'
                    . ' with that key on the same subject.',
                    $index,
                    $identity,
                    $declared,
                );

                continue;
            }

            $this->allow($finding);
            $this->allow($match);
        }

        return $unexplained;
    }

    /**
     * Whether a declared delta may show this field carrying this value.
     *
     * Only the values of records `unexplained()` has already accounted for are
     * allowed, so the delta's reach is exactly the machine-checked split and not
     * one record wider.
     */
    public function allows(string $field, string $value): bool
    {
        return isset($this->allowed[$field . "\0" . $value]);
    }

    /** @param array<string, mixed> $finding */
    private function allow(array $finding): void
    {
        foreach (self::FIELDS as $field) {
            $value = self::string($finding, $field);

            if ($value !== '') {
                $this->allowed[$field . "\0" . $value] = true;
            }
        }
    }

    /** @param array<string, mixed> $finding */
    private function halfIn(array $finding): ?string
    {
        foreach (['rule', 'code'] as $field) {
            $value = self::string($finding, $field);

            if (isset($this->splits[$value])) {
                return $value;
            }
        }

        return null;
    }

    /**
     * A finding's identity with the channel left out — what stays the same
     * across a rename of the channel, and therefore what pairs the two sides.
     *
     * @param array<string, mixed> $finding
     */
    private static function identity(array $finding): string
    {
        $encoded = json_encode(
            [
                'subject' => $finding['subject'] ?? null,
                'occurrence' => $finding['occurrence'] ?? null,
                'edge' => $finding['edge'] ?? null,
            ],
            \JSON_UNESCAPED_SLASHES,
        );

        return $encoded === false ? '?' : $encoded;
    }

    /** @param array<string, mixed> $finding */
    private static function string(array $finding, string $field): string
    {
        $value = $finding[$field] ?? null;

        return \is_string($value) ? $value : '';
    }
}

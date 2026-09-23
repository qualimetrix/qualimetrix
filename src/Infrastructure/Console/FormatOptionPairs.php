<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Reporting\Formatter\FormatOptionValue;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;

/**
 * The `--format-opt` pairs a run was given, after `--all` has written its own:
 * each key known to some formatter, written once and never beside another
 * spelling of its value, and each value parsing under that key's grammar.
 *
 * Every pair is judged as written, before any fold by key: a fold lets a later
 * pair — or `--all` — replace an earlier one that was never judged.
 */
final readonly class FormatOptionPairs
{
    /**
     * Takes the registry rather than one formatter: the set of real keys is the
     * union over every formatter, and a key another format reads is a real key
     * typed at the wrong run, not a typo.
     */
    public function __construct(private FormatterRegistryInterface $formatterRegistry) {}

    /**
     * @param list<string> $written the `--format-opt` values in the order written
     *
     * @return array<string, string>
     */
    public function resolve(array $written): array
    {
        $pairs = array_map(self::pair(...), $written);
        $this->refuseUnknownKeys(array_column($pairs, 0));

        return self::judged($pairs);
    }

    /**
     * The same pairs under `--all`, an alias for `--format-opt=violations=all
     * --detail=all`.
     *
     * @param list<string> $written the `--format-opt` values in the order written
     *
     * @return array<string, string>
     */
    public function resolveUnderAllFlag(array $written): array
    {
        $pairs = array_map(self::pair(...), $written);
        // The key --all writes is held to the same declaration as one the
        // user typed, so a formatter dropping `violations` cannot leave --all
        // writing into a void.
        $this->refuseUnknownKeys([...array_column($pairs, 0), 'violations']);
        $options = self::judged($pairs);

        foreach (FormatOptionValue::spellingsOf('violations') as $key) {
            // `violations=all` is what --all writes itself; any other value,
            // or the key's other spelling, would be silently overridden.
            if (\array_key_exists($key, $options) && ($key !== 'violations' || $options[$key] !== 'all')) {
                throw ConfigurationRefusal::aboutCommandLineInput(
                    '--all',
                    \sprintf(
                        'Conflicting options: --all cannot be combined with --format-opt=%s=N. '
                        . 'Use either --all (show everything) or --format-opt=%s=N (explicit limit)',
                        $key,
                        $key,
                    ),
                );
            }
        }
        $options['violations'] = 'all';

        return $options;
    }

    /**
     * @param list<array{string, string}> $pairs
     *
     * @return array<string, string>
     */
    private static function judged(array $pairs): array
    {
        $options = [];
        foreach ($pairs as [$key, $value]) {
            self::refuseUnparsable($key, $value);
            self::refuseRepeated($key, $value, $options);
            self::refuseOtherSpelling($key, $value, $options);
            $options[$key] = $value;
        }

        return $options;
    }

    /** @return array{string, string} */
    private static function pair(string $written): array
    {
        $eqPos = strpos($written, '=');
        if ($eqPos === false) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--format-opt',
                \sprintf('Invalid --format-opt value "%s": expected format key=value', $written),
            );
        }

        return [substr($written, 0, $eqPos), substr($written, $eqPos + 1)];
    }

    private static function refuseUnparsable(string $key, string $value): void
    {
        $expected = FormatOptionValue::problem($key, $value);
        if ($expected !== null) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--format-opt',
                \sprintf('Invalid --format-opt value "%s=%s": expected %s.', $key, $value, $expected),
            );
        }
    }

    /**
     * Two values for one key are refused rather than the later one winning,
     * the way a configuration document refuses one key written twice.
     *
     * @param array<string, string> $options the pairs accepted so far
     */
    private static function refuseRepeated(string $key, string $value, array $options): void
    {
        if (!\array_key_exists($key, $options)) {
            return;
        }

        throw ConfigurationRefusal::aboutCommandLineInput(
            '--format-opt',
            \sprintf(
                'The --format-opt key "%s" is written twice ("%s=%s", then "%s=%s"). Write it once.',
                $key,
                $key,
                $options[$key],
                $key,
                $value,
            ),
        );
    }

    /**
     * Two keys that set one value are refused together for the same reason
     * one key written twice is: whichever the reader preferred, the other
     * was dropped without a word.
     *
     * @param array<string, string> $options the pairs accepted so far
     */
    private static function refuseOtherSpelling(string $key, string $value, array $options): void
    {
        $spellings = FormatOptionValue::spellingsOf($key);
        foreach ($spellings as $other) {
            if ($other === $key || !\array_key_exists($other, $options)) {
                continue;
            }

            throw ConfigurationRefusal::aboutCommandLineInput(
                '--format-opt',
                \sprintf(
                    'The --format-opt keys %s set one value ("%s=%s", then "%s=%s"). Write one of them.',
                    implode(' and ', array_map(static fn(string $spelling): string => '"' . $spelling . '"', $spellings)),
                    $other,
                    $options[$other],
                    $key,
                    $value,
                ),
            );
        }
    }

    /**
     * Refuses keys no registered formatter reads.
     *
     * A key belonging to another formatter passes: scripts run one option set
     * through several formats, and only a key nobody reads is a miss.
     *
     * @param list<string> $keys
     */
    private function refuseUnknownKeys(array $keys): void
    {
        $known = $this->formatterRegistry->declaredFormatOptionKeys();
        $unknown = array_values(array_unique(array_diff($keys, $known)));

        if ($unknown === []) {
            return;
        }

        throw ConfigurationRefusal::aboutCommandLineInput(
            '--format-opt',
            \sprintf(
                'Unknown --format-opt %s %s. No formatter reads %s. Known keys: %s.',
                \count($unknown) === 1 ? 'key' : 'keys',
                implode(', ', array_map(static fn(string $key): string => \sprintf('"%s"', $key), $unknown)),
                \count($unknown) === 1 ? 'it' : 'them',
                $known !== [] ? implode(', ', $known) : 'none',
            ),
        );
    }
}

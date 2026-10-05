<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use stdClass;

/** The grammar shared by every baseline document reader; channel semantics are judged separately. */
final class BaselineFileShape
{
    public const array ENVELOPE = ['version', 'generated', 'scope', 'exclusions', 'entries'];
    public const array ENTRY = ['channel', 'occurrence', 'edge', 'count', 'magnitudes', 'mode'];
    public const array EDGE = ['target', 'type'];
    public const array EXCLUSIONS = ['patterns', 'generated'];

    private const array GENERATED_FORMATS = ['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP'];

    private const array REJECTED_VERSION_REASONS = [
        13 => 'Baseline version 13 does not record discovery exclusions. Recover the complete exclusion definition '
            . 'used to capture this file (consult `git log -1 -- <baseline-file>` and its configuration), add '
            . '"exclusions": {"patterns": [...], "generated": "included" or "excluded"}, and change '
            . '"version" to %v%. Preserve and review the accepted entries; regenerate only if accepting the '
            . 'current findings is intended.',
        5 => 'This baseline is version 5, a historical format that cannot be loaded or converted to version %v% '
            . 'because declaration identity cannot be inferred from a logical symbol key. Run a fresh analysis, '
            . 'deliberately map or split accepted entries, review every mapping, then write a new version %v% '
            . 'baseline (or regenerate and review the accepted state).',
        10 => 'Baseline version 10 cannot be converted automatically because declaration identity cannot be inferred '
            . 'from a logical symbol key. Run a fresh analysis, deliberately map or split accepted entries, then '
            . 'write a new version %v% baseline (or regenerate and review the accepted state).',
        11 => 'Baseline version 11 cannot be converted automatically: version %v% drops the redundant "count" field '
            . 'and shortens the occurrence key, and there is no converter for either change. Run a fresh analysis '
            . 'and write a new version %v% baseline (or regenerate and review the accepted state).',
        12 => 'Baseline version 12 cannot be converted automatically: version %v% replaces the file position in a '
            . 'declaration key with an assigned ordinal, and no converter can recover which declaration a stored '
            . 'position meant. Run a fresh analysis and write a new version %v% baseline (or regenerate and review '
            . 'the accepted state).',
    ];

    private function __construct() {}

    /** @return array<string, mixed> */
    public static function decode(string $content, string $path): array
    {
        try {
            $document = json_decode($content, false, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ConfigurationRefusal::aboutBaselineFileDocument($path, "Invalid JSON in baseline file: {$e->getMessage()}", $e);
        }

        if (!$document instanceof stdClass) {
            throw ConfigurationRefusal::aboutBaselineFileDocument($path, 'Baseline file must contain a JSON object');
        }

        $fields = (array) $document;
        unset($document);
        self::assertDocument($fields, $path);

        self::normalizeValues($fields);

        /** @var array<string, mixed> $fields */
        return $fields;
    }

    /** @param array<mixed, mixed> $document */
    public static function assertDocument(array $document, string $path): void
    {
        self::envelope($document, $path);
        $entries = self::entryBlocks($document['entries'] ?? null, $path);

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

    /**
     * @param array<mixed, mixed> $document
     *
     * @return array{generated: DateTimeImmutable, scope: list<string>, exclusions: RecordedExclusions}
     */
    public static function envelope(array $document, string $path): array
    {
        self::assertVersion($document['version'] ?? null, $path);
        self::assertEnvelopeKeys($document, $path);

        self::entryBlocks($document['entries'] ?? null, $path);

        return [
            'generated' => self::parseGenerated($document['generated'] ?? null, $path),
            'scope' => self::parseScope($document['scope'] ?? null, $path),
            'exclusions' => self::parseExclusions($document['exclusions'] ?? null, $path),
        ];
    }

    /** @return array<array-key, mixed> */
    private static function entryBlocks(mixed $entries, string $path): array
    {
        if ($entries instanceof stdClass) {
            return get_object_vars($entries);
        }
        if (!\is_array($entries)) {
            throw ConfigurationRefusal::aboutBaselineFileDocument($path, 'Baseline "entries" must be an object');
        }

        return $entries;
    }

    /** @param array<mixed, mixed> $envelope */
    public static function assertEnvelopeKeys(array $envelope, string $path): void
    {
        self::assertKeys($envelope, self::ENVELOPE, [], $path);
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

    /** @param array<mixed, mixed> $exclusions */
    public static function assertExclusionsKeys(array $exclusions, string $path): void
    {
        self::assertKeys($exclusions, self::EXCLUSIONS, ['exclusions'], $path);
    }

    /**
     * @param array<mixed, mixed> $object
     * @param list<string> $accepted
     * @param list<string> $position
     */
    private static function assertKeys(array $object, array $accepted, array $position, string $path): void
    {
        foreach ($object as $key => $_) {
            $written = (string) $key;
            if (!\in_array($written, $accepted, true)) {
                throw ConfigurationRefusal::atBaselineFileKey(
                    $path,
                    RefusedPosition::closed([...$position, $written], $written, $accepted),
                    \sprintf('Unknown baseline key "%s" at %s; accepted keys: %s', $written, implode(' › ', [...$position, $written]), implode(', ', $accepted)),
                );
            }
        }
    }

    private static function assertVersion(mixed $version, string $path): void
    {
        if (!\is_int($version)) {
            throw ConfigurationRefusal::aboutBaselineFileDocument($path, 'Baseline "version" must be an integer');
        }
        if ($version === BaselineFormatVersion::CURRENT) {
            return;
        }

        $reason = self::REJECTED_VERSION_REASONS[$version] ?? null;
        throw ConfigurationRefusal::aboutBaselineFileDocument($path, $reason === null
            ? \sprintf('Unsupported baseline version: %d. Expected version %d.', $version, BaselineFormatVersion::CURRENT)
            : strtr($reason, ['%v%' => (string) BaselineFormatVersion::CURRENT]));
    }

    private static function parseGenerated(mixed $generated, string $path): DateTimeImmutable
    {
        if (!\is_string($generated)) {
            throw ConfigurationRefusal::aboutBaselineFileDocument($path, 'Baseline "generated" must be a string (ISO 8601 datetime)');
        }
        foreach (self::GENERATED_FORMATS as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $generated);
            $problems = DateTimeImmutable::getLastErrors();
            if ($parsed !== false && ($problems === false || ($problems['warning_count'] + $problems['error_count']) === 0)) {
                return $parsed;
            }
        }
        throw ConfigurationRefusal::aboutBaselineFileDocument($path, \sprintf(
            'Baseline "generated" must be an ISO 8601 datetime with an offset (for example 2026-08-05T12:00:00+03:00), got: %s',
            $generated,
        ));
    }

    /** @return list<string> */
    private static function parseScope(mixed $scope, string $path): array
    {
        if (!\is_array($scope) || !array_is_list($scope)) {
            throw ConfigurationRefusal::aboutBaselineFileDocument($path, 'Baseline "scope" must be an array of analysed paths');
        }
        foreach ($scope as $scopePath) {
            if (!\is_string($scopePath)) {
                throw ConfigurationRefusal::aboutBaselineFileDocument($path, 'Baseline "scope" must hold strings');
            }
        }

        return $scope;
    }

    private static function parseExclusions(mixed $raw, string $path): RecordedExclusions
    {
        if ($raw instanceof stdClass) {
            $raw = (array) $raw;
        } elseif (!\is_array($raw) || array_is_list($raw)) {
            throw self::valueRefusal($path, ['exclusions'], 'Baseline "exclusions" must be an object containing patterns and generated');
        }
        self::assertExclusionsKeys($raw, $path);
        $patterns = $raw['patterns'] ?? null;
        if (!\is_array($patterns) || !array_is_list($patterns)) {
            throw self::valueRefusal($path, ['exclusions', 'patterns'], 'Baseline "exclusions.patterns" must be an array of explicit selectors');
        }
        foreach ($patterns as $index => $pattern) {
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

        $generated = $raw['generated'] ?? null;
        $policy = match ($generated) {
            'included' => GeneratedFilePolicy::Include,
            'excluded' => GeneratedFilePolicy::Exclude,
            default => null,
        };
        if ($policy === null) {
            throw ConfigurationRefusal::atBaselineFileKey(
                $path,
                RefusedPosition::closed(['exclusions', 'generated'], 'generated', ['included', 'excluded']),
                'Baseline "exclusions.generated" must be "included" or "excluded"',
            );
        }

        try {
            return new RecordedExclusions($patterns, $policy);
        } catch (InvalidArgumentException $e) {
            throw self::valueRefusal($path, ['exclusions', 'patterns'], $e->getMessage());
        }
    }

    /** Normalize only after object-key checks, so numeric object keys cannot masquerade as list values. */
    public static function normalizeValues(mixed &$value): void
    {
        $pending = [&$value];
        while ($pending !== []) {
            $index = array_key_last($pending);
            $current = &$pending[$index];
            unset($pending[$index]);

            if ($current instanceof stdClass) {
                $current = get_object_vars($current);
            }
            if (\is_array($current)) {
                foreach ($current as &$member) {
                    if (\is_array($member) || $member instanceof stdClass) {
                        $pending[] = &$member;
                    }
                }
                unset($member);
            }
            unset($current);
        }
    }

    /** @param non-empty-list<string> $position */
    private static function valueRefusal(string $path, array $position, string $summary): ConfigurationRefusal
    {
        return ConfigurationRefusal::atBaselineFileKey($path, RefusedPosition::open($position, $position[\count($position) - 1]), $summary);
    }
}

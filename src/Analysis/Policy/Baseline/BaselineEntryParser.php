<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;

/**
 * Turns one decoded entry of a baseline file into either an applicable
 * {@see BaselineEntry} or an {@see InertBaselineEntry} explaining why it is
 * not one.
 *
 * It never throws for bad input. Refusing to load punishes a whole run for
 * one bad line (ADR 0017), so every defect this class
 * can find resolves to an inert entry — which does not suppress, and which
 * `check` reports.
 *
 * The channel declaration is consulted here rather than at comparison time
 * because both shape checks are facts about the *file*: a `magnitude`
 * channel whose entry stores no magnitudes, and an `occurrence` channel
 * whose entry stores some, are each unusable before any run is compared
 * against them. Catching them at load also means the reason survives into
 * the report with the line still in hand.
 */
final readonly class BaselineEntryParser
{
    public function __construct(
        private ChannelDeclarationRegistryInterface $declarations,
    ) {}

    /**
     * @param string $subjectKey the file key this entry sits under
     * @param mixed $raw the decoded entry
     */
    public function parse(string $subjectKey, mixed $raw): BaselineEntry|InertBaselineEntry
    {
        if (!\is_array($raw) || array_is_list($raw)) {
            return InertBaselineEntry::forRaw(
                $subjectKey,
                null,
                InertEntryReason::Malformed,
                'entry must be a JSON object',
                $raw,
            );
        }

        try {
            $identity = $this->parseIdentity($subjectKey, $raw);
        } catch (BaselineEntryRejection $rejection) {
            $channel = $raw['channel'] ?? null;

            return InertBaselineEntry::forRaw(
                $subjectKey,
                \is_string($channel) ? $channel : null,
                $rejection->reason,
                $rejection->getMessage(),
                $raw,
            );
        }

        try {
            return $this->parseEntry($identity, $raw);
        } catch (BaselineEntryRejection $rejection) {
            return InertBaselineEntry::forIdentity(
                $identity,
                $rejection->reason,
                $rejection->getMessage(),
                $raw,
            );
        }
    }

    /**
     * @param array<mixed, mixed> $raw
     *
     * @throws BaselineEntryRejection
     */
    private function parseIdentity(string $subjectKey, array $raw): BaselineIdentity
    {
        $channel = self::readRequiredNonEmptyString(
            $raw,
            'channel',
            '"channel" must be a non-empty string in the "ruleName#violationCode" form',
        );

        try {
            return new BaselineIdentity(
                $subjectKey,
                ViolationChannel::fromKey($channel),
                self::readOptionalNonEmptyString(
                    $raw,
                    'occurrence',
                    '"occurrence" must be a non-empty string when present',
                ),
                self::readEdge($raw),
            );
        } catch (InvalidArgumentException $e) {
            throw new BaselineEntryRejection(InertEntryReason::Malformed, $e->getMessage());
        }
    }

    /**
     * @param array<mixed, mixed> $object
     *
     * @throws BaselineEntryRejection
     */
    private static function readRequiredNonEmptyString(array $object, string $field, string $label): string
    {
        $value = $object[$field] ?? null;
        if (!\is_string($value) || $value === '') {
            throw new BaselineEntryRejection(InertEntryReason::Malformed, $label);
        }

        return $value;
    }

    /**
     * @param array<mixed, mixed> $object
     *
     * @throws BaselineEntryRejection
     */
    private static function readOptionalNonEmptyString(array $object, string $field, string $label): ?string
    {
        $value = $object[$field] ?? null;
        if ($value === null) {
            return null;
        }

        return self::readRequiredNonEmptyString([$field => $value], $field, $label);
    }

    /**
     * @param array<mixed, mixed> $object
     *
     * @throws BaselineEntryRejection
     *
     * @return ?array<mixed, mixed>
     */
    private static function readOptionalObject(array $object, string $field, string $label): ?array
    {
        $value = $object[$field] ?? null;
        if ($value === null) {
            return null;
        }

        if (!\is_array($value) || array_is_list($value)) {
            throw new BaselineEntryRejection(InertEntryReason::Malformed, $label);
        }

        return $value;
    }

    /**
     * @param array<mixed, mixed> $raw
     *
     * @throws BaselineEntryRejection
     */
    private function parseEntry(BaselineIdentity $identity, array $raw): BaselineEntry
    {
        $values = BaselineEntryValues::decode($raw);

        $declaration = $this->declarations->declarationFor($identity->channel);
        if ($declaration === null) {
            throw new BaselineEntryRejection(
                InertEntryReason::UndeclaredChannel,
                \sprintf('no rule declares the channel "%s"', $identity->channel->toKey()),
            );
        }

        try {
            $entry = new BaselineEntry($identity, $values->magnitudes, $values->count, $values->mode);
        } catch (InvalidArgumentException $e) {
            throw new BaselineEntryRejection(InertEntryReason::Malformed, $e->getMessage());
        }

        if ($entry->shape() !== $declaration->shape) {
            throw new BaselineEntryRejection(InertEntryReason::ShapeMismatch, \sprintf(
                'the channel declares shape "%s" but the entry stores %s',
                $declaration->shape->value,
                $entry->shape() === ChannelShape::Magnitude ? 'magnitudes' : 'no magnitudes',
            ));
        }

        return $entry;
    }

    /**
     * @param array<mixed, mixed> $raw
     *
     * @throws BaselineEntryRejection
     */
    private static function readEdge(array $raw): ?BaselineEdge
    {
        $edge = self::readOptionalObject($raw, 'edge', '"edge" must be a JSON object');
        if ($edge === null) {
            return null;
        }

        return new BaselineEdge(
            self::readRequiredNonEmptyString(
                $edge,
                'target',
                '"edge.target" must be a non-empty canonical symbol path',
            ),
            self::readEdgeType($edge),
        );
    }

    /**
     * @param array<mixed, mixed> $edge
     *
     * @throws BaselineEntryRejection
     */
    private static function readEdgeType(array $edge): ?DependencyType
    {
        $type = $edge['type'] ?? null;
        if ($type === null) {
            return null;
        }

        $dependencyType = \is_string($type) ? DependencyType::tryFrom($type) : null;

        if ($dependencyType === null) {
            throw new BaselineEntryRejection(
                InertEntryReason::Malformed,
                \sprintf('"edge.type" is not a known dependency type: %s', self::describe($type)),
            );
        }

        return $dependencyType;
    }

    /**
     * Names a rejected value in a message. Strings are quoted rather than
     * reported as `string`, because for `mode` and `edge.type` the offending
     * value *is* the information a user needs.
     */
    private static function describe(mixed $value): string
    {
        return \is_string($value) ? '"' . $value . '"' : get_debug_type($value);
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

use InvalidArgumentException;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Violation\ChannelDeclarationRegistryInterface;
use Qualimetrix\Core\Violation\ChannelShape;
use Qualimetrix\Core\Violation\ViolationChannel;

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
     * @param string $symbolKey the file key this entry sits under
     * @param mixed $raw the decoded entry
     */
    public function parse(string $symbolKey, mixed $raw): BaselineEntry|InertBaselineEntry
    {
        if (!\is_array($raw)) {
            return InertBaselineEntry::forRaw(
                $symbolKey,
                null,
                InertEntryReason::Malformed,
                'entry must be a JSON object',
                $raw,
            );
        }

        try {
            $identity = $this->parseIdentity($symbolKey, $raw);
        } catch (BaselineEntryRejection $rejection) {
            $channel = $raw['channel'] ?? null;

            return InertBaselineEntry::forRaw(
                $symbolKey,
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
    private function parseIdentity(string $symbolKey, array $raw): BaselineIdentity
    {
        $channel = $raw['channel'] ?? null;
        if (!\is_string($channel) || $channel === '') {
            throw new BaselineEntryRejection(
                InertEntryReason::Malformed,
                '"channel" must be a non-empty string in the "ruleName#violationCode" form',
            );
        }

        try {
            return new BaselineIdentity(
                $symbolKey,
                ViolationChannel::fromKey($channel),
                $this->parseEdge($raw['edge'] ?? null),
            );
        } catch (InvalidArgumentException $e) {
            throw new BaselineEntryRejection(InertEntryReason::Malformed, $e->getMessage());
        }
    }

    /**
     * @throws BaselineEntryRejection
     */
    private function parseEdge(mixed $raw): ?BaselineEdge
    {
        if ($raw === null) {
            return null;
        }

        if (!\is_array($raw)) {
            throw new BaselineEntryRejection(InertEntryReason::Malformed, '"edge" must be a JSON object');
        }

        $target = $raw['target'] ?? null;
        if (!\is_string($target) || $target === '') {
            throw new BaselineEntryRejection(
                InertEntryReason::Malformed,
                '"edge.target" must be a non-empty canonical symbol path',
            );
        }

        return new BaselineEdge($target, $this->parseEdgeType($raw['type'] ?? null));
    }

    /**
     * An absent `type` is a legal edge — ADR 0017 writes the key only when the
     * finding carries one — while an unrecognized one is not, and the two
     * answers are different enough to be worth reading apart from the target
     * validation above.
     *
     * @throws BaselineEntryRejection
     */
    private function parseEdgeType(mixed $type): ?DependencyType
    {
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
     * @param array<mixed, mixed> $raw
     *
     * @throws BaselineEntryRejection
     */
    private function parseEntry(BaselineIdentity $identity, array $raw): BaselineEntry
    {
        $count = $raw['count'] ?? null;
        if (!\is_int($count)) {
            throw new BaselineEntryRejection(InertEntryReason::Malformed, '"count" must be an integer');
        }

        $magnitudes = $this->parseMagnitudes($raw['magnitudes'] ?? null);
        $mode = $this->parseMode($raw);

        $declaration = $this->declarations->declarationFor($identity->channel);
        if ($declaration === null) {
            throw new BaselineEntryRejection(
                InertEntryReason::UndeclaredChannel,
                \sprintf('no rule declares the channel "%s"', $identity->channel->toKey()),
            );
        }

        try {
            $entry = new BaselineEntry($identity, $magnitudes, $count, $mode);
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
     * @throws BaselineEntryRejection
     *
     * @return ?list<int|float>
     */
    private function parseMagnitudes(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        if (!\is_array($raw) || !array_is_list($raw)) {
            throw new BaselineEntryRejection(InertEntryReason::Malformed, '"magnitudes" must be a JSON array');
        }

        $magnitudes = [];
        foreach ($raw as $value) {
            if (!\is_int($value) && !\is_float($value)) {
                throw new BaselineEntryRejection(
                    InertEntryReason::Malformed,
                    \sprintf('"magnitudes" must hold numbers, found %s', self::describe($value)),
                );
            }

            $magnitudes[] = $value;
        }

        return $magnitudes;
    }

    /**
     * @param array<mixed, mixed> $raw
     *
     * @throws BaselineEntryRejection
     */
    private function parseMode(array $raw): ?BaselineEntryMode
    {
        if (!\array_key_exists('mode', $raw) || $raw['mode'] === null) {
            return null;
        }

        $mode = \is_string($raw['mode']) ? BaselineEntryMode::tryFrom($raw['mode']) : null;

        if ($mode === null) {
            throw new BaselineEntryRejection(
                InertEntryReason::UnrecognizedMode,
                \sprintf('"mode" is not a recognized mode: %s', self::describe($raw['mode'])),
            );
        }

        return $mode;
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

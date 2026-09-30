<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Document\Resolved\ResolvedScalar;

/**
 * The form of one written value against the node declared for it: a value of
 * the wrong shape, or a scalar of none of the declared forms, is refused with
 * the node's hint. `~` is not a form: it is the value left unwritten.
 */
final class WrittenForm
{
    /**
     * @throws ConfigurationRefusal
     */
    public static function scalar(NodeSchema $schema, AuthoredNode $node, ReadingContext $at): ?ResolvedScalar
    {
        if ($node->isUnwritten()) {
            return null;
        }

        $forms = $schema->scalarForms();
        $expected = $schema->describe();

        if ($node->shape !== AuthoredShape::Scalar || $node->scalar === null) {
            throw $at->refusal(self::hinted(\sprintf('%s must be %s, got %s.', ucfirst($at->where()), $expected, self::shapeName($node)), $schema));
        }

        foreach ($forms as $form) {
            if ($form->accepts($node->scalar)) {
                self::judgeScalar($schema, $node->scalar, $at);

                return new ResolvedScalar($node->scalar, $at->provenance($node));
            }
        }

        if ($forms !== []) {
            throw $at->refusal(self::hinted(\sprintf('%s must be %s, got %s.', ucfirst($at->where()), $expected, get_debug_type($node->scalar)), $schema));
        }

        self::judgeScalar($schema, $node->scalar, $at);

        return new ResolvedScalar($node->scalar, $at->provenance($node));
    }

    private static function judgeScalar(NodeSchema $schema, int|float|string|bool $value, ReadingContext $at): void
    {
        $minimum = $schema->minimum();
        if ($minimum !== null && (\is_int($value) || \is_float($value)) && $value < $minimum) {
            throw $at->refusal(\sprintf('%s must be at least %s, got %s.', ucfirst($at->where()), $minimum, $value));
        }

        if ($schema->requiresNonEmpty() && \is_string($value) && trim($value) === '') {
            throw $at->refusal(\sprintf('%s must be non-empty text.', ucfirst($at->where())));
        }

        $choices = $schema->choices();
        if ($choices !== [] && \is_string($value)) {
            $matches = static fn(string $choice): bool => $schema->foldsCase()
                ? strcasecmp($value, $choice) === 0
                : $value === $choice;
            if (!array_any($choices, $matches)) {
                throw $at->refusal(\sprintf('%s must be one of %s, got "%s".', ucfirst($at->where()), implode(', ', $choices), $value));
            }
        }
    }

    /**
     * False for `~`; refuses a scalar or a non-empty list where a map is declared.
     *
     * @throws ConfigurationRefusal
     */
    public static function isMap(NodeSchema $schema, AuthoredNode $node, ReadingContext $at): bool
    {
        if ($node->isUnwritten()) {
            return false;
        }

        if ($node->shape === AuthoredShape::Scalar || ($node->shape === AuthoredShape::Sequence && $node->children !== [])) {
            throw $at->refusal(self::hinted(\sprintf('%s must be a map, got %s.', ucfirst($at->where()), self::shapeName($node)), $schema));
        }

        return true;
    }

    /**
     * False for `~`; refuses a scalar or a map where a list is declared.
     *
     * @throws ConfigurationRefusal
     */
    public static function isList(NodeSchema $schema, AuthoredNode $node, ReadingContext $at): bool
    {
        if ($node->isUnwritten()) {
            return false;
        }

        if ($node->shape === AuthoredShape::Scalar || $node->shape === AuthoredShape::Mapping) {
            throw $at->refusal(self::hinted(\sprintf('%s must be a list, got %s.', ucfirst($at->where()), self::shapeName($node)), $schema));
        }

        return true;
    }

    private static function hinted(string $refusal, NodeSchema $schema): string
    {
        $hint = $schema->hint();

        return $hint === null ? $refusal : $refusal . ' ' . $hint;
    }

    private static function shapeName(AuthoredNode $node): string
    {
        return match ($node->shape) {
            AuthoredShape::Scalar => $node->scalar === null ? 'null' : get_debug_type($node->scalar),
            AuthoredShape::Mapping => 'a map',
            AuthoredShape::Sequence => 'a list',
            AuthoredShape::EmptyCollection => 'an empty collection',
        };
    }
}

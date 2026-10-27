<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\EditorSchema;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeScalarFacts;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SchemaWordSet;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\TextRequirement;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\WordComparison;

/** The JSON Schema of a last-writer-wins scalar: its forms and the constraints on its value. */
final class ScalarProjection
{
    /** What `trim()` removes by default, so non-blank text is text holding anything else. */
    private const string TRIMMED = " \t\n\r\0\x0B";

    /** @return array<string, mixed> */
    public static function of(NodeScalarFacts $facts, NullAdmission $null): array
    {
        $types = $facts->forms === []
            ? ['string', 'number', 'boolean']
            : array_values(array_unique(array_map(self::type(...), $facts->forms)));
        $written = [...$types, ...$null->types()];
        $schema = ['type' => \count($written) === 1 ? $written[0] : $written];

        if ($facts->minimum !== null) {
            $schema['minimum'] = $facts->minimum;
        }

        if ($facts->textRequirement === TextRequirement::NonBlank) {
            $schema['pattern'] = PortableRegex::holdingCharacterOutside(self::TRIMMED);
        }

        return $facts->words === null ? $schema : self::withWords($schema, $facts->words, $types, $null);
    }

    private static function type(ScalarForm $form): string
    {
        return match ($form) {
            ScalarForm::String => 'string',
            ScalarForm::Integer => 'integer',
            ScalarForm::Number => 'number',
            ScalarForm::Boolean => 'boolean',
        };
    }

    /**
     * The engine judges words only when the value is text, so a scalar of
     * other forms besides keeps them; the enum is there for completion.
     *
     * @param array<string, mixed> $schema
     * @param list<string> $types
     *
     * @return array<string, mixed>
     */
    private static function withWords(array $schema, SchemaWordSet $words, array $types, NullAdmission $null): array
    {
        return match ($words->comparison) {
            WordComparison::Sensitive => $types === ['string']
                ? $schema + ['enum' => $null === NullAdmission::Admitted ? [...$words->words, null] : $words->words]
                : $schema + ['anyOf' => [['enum' => $words->words], ['not' => ['type' => 'string']]]],
            WordComparison::Folding => $schema + ['anyOf' => [
                ['enum' => $words->words],
                ['pattern' => PortableRegex::exactlyFoldingCase($words->words)],
            ]],
        };
    }
}

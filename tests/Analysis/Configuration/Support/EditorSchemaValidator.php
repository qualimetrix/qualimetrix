<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Support;

use Closure;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Constraints\Factory;
use JsonSchema\DraftIdentifiers;
use JsonSchema\Validator;
use LogicException;
use Qualimetrix\Analysis\Configuration\EditorSchema\EditorSchemaJson;
use stdClass;
use Symfony\Component\Yaml\Yaml;

/**
 * Judges data against an editor schema as an editor would: draft-07 in
 * `CHECK_MODE_STRICT`. Without it the validator skips `if`/`then`,
 * `propertyNames` and `false` subschemas and passes what an editor refuses.
 *
 * Strict mode reports every error at the document root, so the pointer of a
 * refusal is found here: the deepest value that still fails against the
 * subschemas its keys and items lead to. A value is not descended through
 * `anyOf`, `oneOf`, `not` or `if`; a refusal there stays with the value that
 * holds the keyword.
 */
final class EditorSchemaValidator
{
    private const string DEFINITIONS = '#/definitions/';

    /**
     * The refusals of a YAML text, each at the JSON pointer of the value that fails.
     *
     * @param array<string, mixed>|stdClass $schema a projected schema, or a decoded schema file
     *
     * @return list<array{pointer: string, messages: list<string>}>
     */
    public static function yamlErrors(array|stdClass $schema, string $yaml): array
    {
        return self::errors($schema, self::parseYaml($yaml));
    }

    /**
     * YAML parsed the way an editor sees it: a map is an object even when it
     * is empty, so `{}` and `[]` stay apart.
     */
    public static function parseYaml(string $yaml): mixed
    {
        return Yaml::parse($yaml, Yaml::PARSE_OBJECT_FOR_MAP);
    }

    /**
     * @param array<string, mixed>|stdClass $schema
     *
     * @return list<array{pointer: string, messages: list<string>}>
     */
    public static function errors(array|stdClass $schema, mixed $data): array
    {
        $document = $schema instanceof stdClass ? $schema : json_decode(EditorSchemaJson::encode($schema), false, 512, \JSON_THROW_ON_ERROR);
        \assert($document instanceof stdClass);
        $dialect = $document->{'$schema'} ?? DraftIdentifiers::DRAFT_7;
        if ($dialect !== DraftIdentifiers::DRAFT_7) {
            throw new LogicException(\sprintf('An editor schema is draft-07, got "%s".', \is_string($dialect) ? $dialect : get_debug_type($dialect)));
        }

        $definitions = $document->definitions ?? new stdClass();
        \assert($definitions instanceof stdClass);

        return self::refusals($document, $data, '', $definitions);
    }

    /** @return list<string> */
    private static function messages(mixed $schema, mixed $data, stdClass $definitions): array
    {
        // Draft-07 ignores every sibling of `$ref`, so a reference is judged through `allOf`.
        // The validator annotates the schema it is given; each run gets its own copy.
        $keywords = $schema instanceof stdClass && !isset($schema->{'$ref'}) ? get_object_vars($schema) : ['allOf' => [$schema]];
        $root = json_decode(
            json_encode(
                ['$schema' => DraftIdentifiers::DRAFT_7, 'definitions' => $definitions] + $keywords,
                \JSON_THROW_ON_ERROR,
            ),
            false,
            512,
            \JSON_THROW_ON_ERROR,
        );
        $factory = new Factory();
        $factory->setDefaultDialect(DraftIdentifiers::DRAFT_7);
        $validator = new Validator($factory);
        $copy = $data;
        $validator->validate($copy, $root, Constraint::CHECK_MODE_STRICT);

        return array_values(array_map(
            static fn(array $error): string => (string) $error['message'],
            $validator->getErrors(),
        ));
    }

    /** @return list<array{pointer: string, messages: list<string>}> */
    private static function refusals(mixed $schema, mixed $data, string $pointer, stdClass $definitions): array
    {
        $messages = self::messages($schema, $data, $definitions);
        if ($messages === []) {
            return [];
        }

        $deeper = [];
        foreach (self::children($data) as $index => $child) {
            $key = (string) $index;
            $childSchema = self::childSchema(
                $schema,
                $definitions,
                \is_array($data) ? self::itemSchemas(...) : static fn(stdClass $part): array => self::propertySchemas($part, $key),
            );
            if ($childSchema !== null) {
                $deeper = [...$deeper, ...self::refusals($childSchema, $child, $pointer . '/' . strtr($key, ['~' => '~0', '/' => '~1']), $definitions)];
            }
        }

        return $deeper !== [] ? $deeper : [['pointer' => $pointer, 'messages' => $messages]];
    }

    /** @return array<array-key, mixed> */
    private static function children(mixed $data): array
    {
        return match (true) {
            $data instanceof stdClass => get_object_vars($data),
            \is_array($data) => $data,
            default => [],
        };
    }

    /**
     * Every subschema a key or an item is held to, across `$ref` and `allOf`;
     * null when none applies.
     *
     * @param Closure(stdClass): list<mixed> $applying the subschemas one part holds the child to
     */
    private static function childSchema(mixed $schema, stdClass $definitions, Closure $applying): mixed
    {
        $applicable = [];
        foreach (self::conjuncts($schema, $definitions) as $part) {
            $applicable = [...$applicable, ...$applying($part)];
        }

        return match (\count($applicable)) {
            0 => null,
            1 => $applicable[0],
            default => (object) ['allOf' => $applicable],
        };
    }

    /** @return list<stdClass> the schema and everything it holds the same value to through `$ref` and `allOf` */
    private static function conjuncts(mixed $schema, stdClass $definitions): array
    {
        if (!$schema instanceof stdClass) {
            return [];
        }

        $parts = [$schema];
        if (isset($schema->{'$ref'}) && \is_string($schema->{'$ref'}) && str_starts_with($schema->{'$ref'}, self::DEFINITIONS)) {
            $parts = [...$parts, ...self::conjuncts($definitions->{substr($schema->{'$ref'}, \strlen(self::DEFINITIONS))} ?? null, $definitions)];
        }

        foreach (\is_array($schema->allOf ?? null) ? $schema->allOf : [] as $member) {
            $parts = [...$parts, ...self::conjuncts($member, $definitions)];
        }

        return $parts;
    }

    /** @return list<mixed> */
    private static function itemSchemas(stdClass $schema): array
    {
        return isset($schema->items) && !\is_array($schema->items) ? [$schema->items] : [];
    }

    /** @return list<mixed> */
    private static function propertySchemas(stdClass $schema, string $key): array
    {
        $matched = [];
        if (($schema->properties ?? null) instanceof stdClass && property_exists($schema->properties, $key)) {
            $matched[] = $schema->properties->{$key};
        }

        foreach (($schema->patternProperties ?? null) instanceof stdClass ? get_object_vars($schema->patternProperties) : [] as $pattern => $patternSchema) {
            if (preg_match('/' . str_replace('/', '\/', (string) $pattern) . '/u', $key) === 1) {
                $matched[] = $patternSchema;
            }
        }

        if ($matched === [] && property_exists($schema, 'additionalProperties')) {
            $matched[] = $schema->additionalProperties;
        }

        return $matched;
    }
}

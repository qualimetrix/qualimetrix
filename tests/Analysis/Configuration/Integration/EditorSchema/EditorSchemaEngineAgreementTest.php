<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Integration\EditorSchema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\EditorSchema\EditorSchemaProjection;
use Qualimetrix\Tests\Analysis\Configuration\Support\EditorSchemaValidator;

/**
 * The editor schema against the engine it describes, on one config file at a
 * time: the engine is the oracle, not what the projection's author expected.
 *
 * Each case names the mapping it exercises, the engine's verdict and the
 * schema's; a schema refusal names the JSON pointer it lands on, so a refusal
 * for some other reason does not count. A case may declare that the engine
 * refuses what the schema accepts — the schema does not judge everything — but
 * never the reverse: that would be a red squiggle on a working configuration.
 *
 * Scalars YAML parsers type differently (`1_000`, `0755`, dates) are outside
 * the promise and stay out of the table.
 */
#[CoversClass(EditorSchemaProjection::class)]
final class EditorSchemaEngineAgreementTest extends TestCase
{
    private const bool ACCEPTS = true;

    private const bool REFUSES = false;

    private const ?string SCHEMA_ACCEPTS = null;

    #[Test]
    #[DataProvider('cases')]
    public function itNeverRefusesWhatTheEngineAccepts(string $mapping, string $yaml, bool $engine, ?string $schemaRefusesAt): void
    {
        self::assertFalse(
            $engine === self::ACCEPTS && $schemaRefusesAt !== self::SCHEMA_ACCEPTS,
            'A case may not declare that the schema refuses what the engine accepts.',
        );

        $refusal = EngineReading::refusal($yaml);
        self::assertSame(
            $engine,
            $refusal === null,
            $refusal === null ? 'The engine accepts the text.' : 'The engine refuses the text: ' . $refusal->getMessage(),
        );

        $errors = EditorSchemaValidator::yamlErrors(EngineReading::schema(), $yaml);
        if ($schemaRefusesAt === self::SCHEMA_ACCEPTS) {
            self::assertSame([], $errors, $mapping);

            return;
        }

        self::assertSame([$schemaRefusesAt], array_column($errors, 'pointer'), $mapping . ': ' . json_encode($errors, \JSON_THROW_ON_ERROR));
    }

    /**
     * The retirement sentence is what an editor shows on the key, so a retired
     * key is refused by its own subschema in every spelling, not by the one an
     * unknown key falls to: both refusals land on the same pointer.
     */
    #[Test]
    #[DataProvider('retiredSpellings')]
    public function itRefusesARetiredKeyAsRetiredInEverySpelling(string $spelling): void
    {
        $schema = EngineReading::schema();
        $rule = self::resolved($schema['properties']['rules']['properties']['complexity.ccn'], $schema);
        $patterns = $rule['patternProperties'];
        self::assertIsArray($patterns);

        $matched = array_values(array_filter(
            $patterns,
            static fn(string $pattern): bool => preg_match('/' . str_replace('/', '\\/', $pattern) . '/u', $spelling) === 1,
            \ARRAY_FILTER_USE_KEY,
        ));

        self::assertCount(1, $matched, $spelling);
        self::assertIsArray($matched[0]);
        self::assertStringContainsString('"exclude-paths" option was retired', (string) ($matched[0]['description'] ?? ''));
    }

    /** @return iterable<string, array{string}> */
    public static function retiredSpellings(): iterable
    {
        foreach (['exclude-paths', 'exclude_paths', 'excludePaths'] as $spelling) {
            yield $spelling => [$spelling];
        }
    }

    /** @return iterable<string, array{string, string, bool, ?string}> */
    public static function cases(): iterable
    {
        yield 'a closed root refuses an unknown key' => ['document root', 'nope: 1', self::REFUSES, '/nope'];
        yield 'an empty file' => ['document root', '', self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'an empty list for the whole document' => ['document root', '[]', self::ACCEPTS, self::SCHEMA_ACCEPTS];

        yield 'an alternative root spelling' => ['alternative spellings', 'fail-on: error', self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a camel-case root spelling' => ['alternative spellings', 'failOn: error', self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a nested alternative spelling' => ['alternative spellings', "rules:\n  complexity.ccn:\n    suppressPaths: [{subtree: src/Legacy}]", self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a spelling no rule accepts' => ['alternative spellings', 'Fail_On: error', self::REFUSES, '/Fail_On'];

        yield '~ under a root key' => ['LastWriterWins', 'fail_on: ~', self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield '~ under a map key' => ['DeepMerge', 'cache: ~', self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield '~ under a list key' => ['Replace/Accumulate', 'paths: ~', self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield '~ as a list item' => ['Replace/Accumulate', 'paths: [src, ~]', self::REFUSES, '/paths/1'];
        yield '~ as a map item of a list' => ['Replace/Accumulate', 'exclude: [~]', self::REFUSES, '/exclude/0'];

        yield 'a scalar of the wrong type' => ['LastWriterWins', 'include_generated: "yes"', self::REFUSES, '/include_generated'];
        yield 'a string where both forms are allowed' => ['several forms', 'memory_limit: 512M', self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'an integer where both forms are allowed' => ['several forms', 'memory_limit: 1024', self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a value below the minimum' => ['minimum', "rules:\n  complexity.ccn:\n    callable: {warning: -1}", self::REFUSES, '/rules/complexity.ccn/callable/warning'];
        yield 'the minimum itself' => ['minimum', "rules:\n  complexity.ccn:\n    callable: {warning: 0}", self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a fraction where numbers are allowed' => ['LastWriterWins, Number', "rules:\n  coupling.class-rank: {warning: 0.5}", self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a fraction where integers are allowed' => ['LastWriterWins, Integer', "rules:\n  complexity.ccn:\n    callable: {warning: 0.5}", self::REFUSES, '/rules/complexity.ccn/callable/warning'];

        yield '[] on a map' => ['DeepMerge', 'cache: []', self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield '[] on the rules' => ['ByName, closed names', 'rules: []', self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield '[] on a rule' => ['ByName, closed names', "rules:\n  complexity.ccn: []", self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield '~ on the rules' => ['ByName, closed names', 'rules: ~', self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield '[] on an open named map' => ['ByName, open names', 'computed_metrics: []', self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield '~ on an open named map' => ['ByName, open names', 'computed_metrics: ~', self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield '~ on an entry of an open named map' => ['ByName, open names', "computed_metrics:\n  computed.code-density: ~", self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield '{} on a list' => ['Replace/Accumulate', 'exclude_health: {}', self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'an empty list its owner refuses' => ['layerJudge, outside the promise', 'paths: {}', self::REFUSES, self::SCHEMA_ACCEPTS];
        yield '{} on a scalar' => ['LastWriterWins', 'fail_on: {}', self::REFUSES, '/fail_on'];

        yield 'an import list' => ['imports', 'imports: [cfg/base.yaml, team.YML]', self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a bare import' => ['imports', 'imports: cfg/base.yaml', self::REFUSES, '/imports'];
        yield 'an import that is not text' => ['imports', 'imports: [1]', self::REFUSES, '/imports/0'];
        yield 'an empty import' => ['imports', 'imports: [""]', self::REFUSES, '/imports/0'];
        yield 'an import naming a URL' => ['imports, layerJudge outside the promise', 'imports: ["https://example.com/a.yaml"]', self::REFUSES, self::SCHEMA_ACCEPTS];
        yield 'an import with a glob' => ['imports', 'imports: ["cfg/*.yaml"]', self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'an import of a built-in preset' => ['imports', 'imports: [strict]', self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'an import with a recursive glob' => ['imports, layerJudge outside the promise', 'imports: ["cfg/**/*.yaml"]', self::REFUSES, self::SCHEMA_ACCEPTS];
        yield 'a glob without an extension' => ['imports, layerJudge outside the promise', 'imports: ["cfg/*"]', self::REFUSES, self::SCHEMA_ACCEPTS];
        yield 'an import of another format' => ['imports, layerJudge outside the promise', 'imports: [a.json]', self::REFUSES, self::SCHEMA_ACCEPTS];
        yield 'an unknown key of a map' => ['DeepMerge', 'cache: {nope: 1}', self::REFUSES, '/cache/nope'];

        yield 'an inline merge key' => ['YAML merge keys', 'cache: {<<: {dir: .cache}}', self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a block merge key beside a plain one' => ['YAML merge keys', "cache:\n  <<: {dir: .cache}\n  enabled: true", self::ACCEPTS, self::SCHEMA_ACCEPTS];

        yield 'a map keyed 0..n for a list' => ['Replace/Accumulate', 'paths: {0: src, 1: tests}', self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a map keyed 1 for a list' => ['Replace/Accumulate, wider than the engine', 'paths: {1: src}', self::REFUSES, self::SCHEMA_ACCEPTS];

        yield 'a shorthand beside its target' => ['Shorthand', "rules:\n  complexity.ccn:\n    callable: {threshold: 5, warning: 5}", self::REFUSES, '/rules/complexity.ccn/callable'];
        yield 'a shorthand beside a ~ target' => ['Shorthand', "rules:\n  complexity.ccn:\n    callable: {threshold: 5, warning: ~}", self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a ~ shorthand beside its target' => ['Shorthand', "rules:\n  complexity.ccn:\n    callable: {threshold: ~, warning: 5}", self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a shorthand beside a dotted target' => ['Shorthand, dotted target', "rules:\n  complexity.ccn:\n    threshold: 5\n    callable: {warning: 5}", self::REFUSES, '/rules/complexity.ccn'];
        yield 'a shorthand beside a ~ dotted target' => ['Shorthand, dotted target', "rules:\n  complexity.ccn:\n    threshold: 5\n    callable: {warning: ~}", self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a ~ shorthand beside a dotted target' => ['Shorthand, dotted target', "rules:\n  complexity.ccn:\n    threshold: ~\n    callable: {warning: 5}", self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a shorthand beside an empty intermediate map' => ['Shorthand, dotted target', "rules:\n  complexity.ccn:\n    threshold: 5\n    callable: []", self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'two shorthands into one leaf' => ['Shorthand, chained', "rules:\n  complexity.ccn:\n    threshold: 5\n    callable: {threshold: 6}", self::REFUSES, '/rules/complexity.ccn'];

        yield 'a rule switched off by a bare boolean' => ['bareField', "rules:\n  complexity.ccn: false", self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a rule given a bare number' => ['bareField', "rules:\n  complexity.ccn: 5", self::REFUSES, '/rules/complexity.ccn'];
        yield 'an unknown rule' => ['ByName, closed names', "rules:\n  complexity.nope: {}", self::REFUSES, '/rules/complexity.nope'];
        yield 'a ~ rule' => ['ByName, closed names', "rules:\n  complexity.ccn: ~", self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'an unknown computed metric' => ['ByName, open names', "computed_metrics:\n  computed.code-density: {formula: '1', levels: [class]}", self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'an unknown key of a computed metric' => ['ByName, open names', "computed_metrics:\n  computed.code-density: {nope: 1}", self::REFUSES, '/computed_metrics/computed.code-density/nope'];

        yield 'a canonical word' => ['words, sensitive', "rules:\n  coupling.cbo: {scope: application}", self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a word in another case where case matters' => ['words, sensitive', "rules:\n  coupling.cbo: {scope: Application}", self::REFUSES, '/rules/coupling.cbo/scope'];
        yield '~ where case matters' => ['words, sensitive', "rules:\n  coupling.cbo: {class: {scope: ~}}", self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a word in another case' => ['words, folding', "rules:\n  architecture.layer-violation: {severity: ERROR}", self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a word outside the vocabulary' => ['words, folding', "rules:\n  architecture.layer-violation: {severity: errors}", self::REFUSES, '/rules/architecture.layer-violation/severity'];
        yield 'a reporting level' => ['words, sensitive, list item', "computed_metrics:\n  computed.code-density: {formula: '1', levels: [class, project]}", self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a reporting level in another case' => ['words, sensitive, list item', "computed_metrics:\n  computed.code-density: {formula: '1', levels: [Class]}", self::REFUSES, '/computed_metrics/computed.code-density/levels/0'];
        yield 'a level a computed metric cannot report at' => ['words, sensitive, list item', "computed_metrics:\n  computed.code-density: {formula: '1', levels: [class, callable]}", self::REFUSES, '/computed_metrics/computed.code-density/levels/1'];
        yield 'a registered output format' => ['words, sensitive', 'format: sarif', self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a retired output format' => ['words, sensitive', 'format: text-verbose', self::REFUSES, '/format'];
        yield 'a fail_on threshold' => ['words, sensitive', 'fail_on: none', self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a report-only severity as fail_on' => ['words, sensitive', 'fail_on: info', self::REFUSES, '/fail_on'];
        yield 'a coverage mode in another case' => ['words, folding', "architecture:\n  layers:\n    - {name: domain, patterns: ['App\\Domain\\**']}\n  coverage-gap: WARN", self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a coverage mode outside the vocabulary' => ['words, folding', "architecture:\n  coverage-gap: warning", self::REFUSES, '/architecture/coverage-gap'];

        yield 'a form feed is not blank' => ['NonBlank', "disabled_rules: [\"\\f\"]", self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a no-break space is not blank' => ['NonBlank', "disabled_rules: [\"\u{00A0}\"]", self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'spaces are blank' => ['NonBlank', 'disabled_rules: ["  "]', self::REFUSES, '/disabled_rules/0'];
        yield 'text with a space inside is not blank' => ['NonBlank', "rules:\n  complexity.ccn:\n    suppress-paths: [{subtree: 'src/My Legacy'}]", self::ACCEPTS, self::SCHEMA_ACCEPTS];

        yield 'a single element where one is allowed' => ['SingleAllowed', "rules:\n  code-smell.boolean-argument: {allowed-prefixes: is}", self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a list where a single element is allowed' => ['SingleAllowed', "rules:\n  code-smell.boolean-argument: {allowed-prefixes: [is, has]}", self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a ~ item where a single element is allowed' => ['SingleAllowed', "rules:\n  code-smell.boolean-argument: {allowed-prefixes: [is, ~]}", self::REFUSES, '/rules/code-smell.boolean-argument/allowed-prefixes'];

        yield 'an owner-judged value' => ['PerLayer', "architecture:\n  layers:\n    - {name: domain, patterns: ['App\\Domain\\**']}", self::ACCEPTS, self::SCHEMA_ACCEPTS];
        yield 'a ~ item of an owner-judged list' => ['PerLayer, outside the promise', "architecture:\n  layers:\n    - {name: domain, patterns: ['App\\Domain\\**']}\n  allow:\n    domain: [~]", self::REFUSES, self::SCHEMA_ACCEPTS];

        yield 'a retired key, kebab-case' => ['retiredKeys', "rules:\n  complexity.ccn: {exclude-paths: [src]}", self::REFUSES, '/rules/complexity.ccn/exclude-paths'];
        yield 'a retired key, snake_case' => ['retiredKeys', "rules:\n  complexity.ccn: {exclude_paths: [src]}", self::REFUSES, '/rules/complexity.ccn/exclude_paths'];
        yield 'a retired key, camelCase' => ['retiredKeys', "rules:\n  complexity.ccn: {excludePaths: [src]}", self::REFUSES, '/rules/complexity.ccn/excludePaths'];
    }

    /**
     * A subschema with its pooled definition in place of the reference.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private static function resolved(mixed $schema, array $document): array
    {
        self::assertIsArray($schema);
        $reference = $schema['$ref'] ?? $schema['allOf'][0]['$ref'] ?? null;
        if (!\is_string($reference)) {
            return $schema;
        }

        $definition = $document['definitions'][substr($reference, \strlen('#/definitions/'))] ?? null;
        self::assertIsArray($definition, $reference);

        return $definition;
    }
}

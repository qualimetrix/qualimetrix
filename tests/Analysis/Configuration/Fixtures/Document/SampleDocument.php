<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Fixtures\Document;

use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NameVocabulary;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\Shorthand;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;

/**
 * A document schema shaped like the product's — one section per merge policy
 * the engine offers — and the layers a test composes over it.
 */
final class SampleDocument
{
    public static function compose(AuthoredLayer ...$layers): ResolvedDocument
    {
        return DocumentComposer::compose(self::schema(), array_values($layers));
    }

    public static function schema(): DocumentSchema
    {
        return new DocumentSchema([
            self::section('fail_on', NodeSchema::scalar(ScalarForm::String)),
            self::section('memory_limit', NodeSchema::scalar(ScalarForm::String, ScalarForm::Integer)),
            self::section('cache', NodeSchema::map([
                'dir' => NodeSchema::scalar(ScalarForm::String),
                'enabled' => NodeSchema::scalar(ScalarForm::Boolean),
            ])),
            self::section('paths', NodeSchema::list(NodeSchema::scalar(ScalarForm::String))),
            self::section('only_rules', NodeSchema::list(NodeSchema::scalar(ScalarForm::String))->announcingEmptyOverride('Every rule runs.')),
            self::section('exclude', NodeSchema::set(NodeSchema::scalar(ScalarForm::String))),
            self::section('architecture', NodeSchema::map([
                'layers' => NodeSchema::list(NodeSchema::map([
                    'name' => NodeSchema::scalar(ScalarForm::String),
                    'patterns' => NodeSchema::list(NodeSchema::scalar(ScalarForm::String)),
                ])),
                'allow' => NodeSchema::namedMap(
                    NodeSchema::list(NodeSchema::scalar(ScalarForm::String)),
                    NameVocabulary::fromSibling('layers', self::layerNames(...)),
                ),
                'coverage-gap' => NodeSchema::scalar(ScalarForm::String),
            ])),
            self::section('computed_metrics', NodeSchema::namedMap(NodeSchema::map(
                [
                    'formula' => NodeSchema::scalar(ScalarForm::String),
                    'formulas' => NodeSchema::namedMap(
                        NodeSchema::scalar(ScalarForm::String),
                        NameVocabulary::fixed(['class', 'namespace', 'callable']),
                    ),
                    'warning' => NodeSchema::scalar(ScalarForm::Number),
                    'error' => NodeSchema::scalar(ScalarForm::Number),
                    'enabled' => NodeSchema::scalar(ScalarForm::Boolean),
                ],
                Shorthand::spreading('threshold', ['warning', 'error']),
            ))),
            self::section('rules', NodeSchema::opaque()),
        ]);
    }

    /** @param array<string, mixed> $document */
    public static function preset(array $document, string $name = 'strict'): AuthoredLayer
    {
        return new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::Preset, $name), AuthoredNode::fromPlain($document));
    }

    /** @param array<string, mixed> $document */
    public static function file(array $document, string $path = '/p/qmx.yaml'): AuthoredLayer
    {
        return new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, $path), AuthoredNode::fromPlain($document));
    }

    /**
     * A command-line layer: each root key is one option, named by `$options`.
     *
     * @param array<string, mixed> $values
     * @param array<string, string> $options root key => option name
     */
    public static function cli(array $values, array $options): AuthoredLayer
    {
        $children = [];
        foreach ($values as $key => $value) {
            $children[$key] = AuthoredNode::fromPlain($value, $options[$key]);
        }

        return new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::CommandLine), AuthoredNode::mapping($children), false);
    }

    /** @return list<string> */
    private static function layerNames(mixed $layers): array
    {
        $names = [];
        foreach (\is_array($layers) ? $layers : [] as $layer) {
            if (\is_array($layer) && \is_string($layer['name'] ?? null)) {
                $names[] = $layer['name'];
            }
        }

        return $names;
    }

    private static function section(string $key, NodeSchema $schema): DocumentSectionSchemaInterface
    {
        return new readonly class ($key, $schema) implements DocumentSectionSchemaInterface {
            public function __construct(private string $key, private NodeSchema $schema) {}

            public function declaration(): SectionDeclaration
            {
                return new SectionDeclaration($this->key, $this->schema);
            }
        };
    }
}

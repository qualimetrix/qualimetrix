<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;
use Qualimetrix\Core\Pattern\SelectorKind;

/** Configuration's document declarations, apart from the sections their consumers register. */
enum ConfigurationRoot: string implements DocumentSectionSchemaInterface
{
    case Exclude = 'exclude';
    case SuppressPaths = 'suppress_paths';
    case SuppressNamespaces = 'suppress_namespaces';
    case IncludeGenerated = 'include_generated';
    case IncludeAutoloadDev = 'include_autoload_dev';

    public function declaration(): SectionDeclaration
    {
        return new SectionDeclaration($this->value, self::schemas()[$this->value]);
    }

    /** @return array<string, NodeSchema> root key => schema */
    private static function schemas(): array
    {
        $boolean = NodeSchema::scalar(ScalarForm::Boolean);
        $selectors = self::selectorSet();

        return [
            self::Exclude->value => $selectors,
            self::SuppressPaths->value => $selectors,
            self::SuppressNamespaces->value => $selectors,
            self::IncludeGenerated->value => $boolean,
            self::IncludeAutoloadDev->value => $boolean,
        ];
    }

    /**
     * Selectors every layer adds to. Each is a mapping of one kind to its
     * value; that it names exactly one kind is judged by
     * {@see SelectorYamlDecoder}, which also compiles the value.
     */
    private static function selectorSet(): NodeSchema
    {
        $kinds = [];
        $forms = [];
        foreach (SelectorKind::cases() as $kind) {
            $kinds[$kind->value] = NodeSchema::scalar(ScalarForm::String);
            $forms[] = \sprintf('{%s: value}', $kind->value);
        }

        $last = array_pop($forms);

        return NodeSchema::set(NodeSchema::map($kinds)->withHint(
            \sprintf('A selector names its kind: %s, or %s.', implode(', ', $forms), $last),
        ));
    }
}

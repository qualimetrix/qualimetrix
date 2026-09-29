<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;
use Qualimetrix\Core\Pattern\SelectorKind;

/** Configuration's document declarations, apart from the sections their consumers register. */
enum ConfigurationRoot: string implements DocumentSectionSchemaInterface
{
    case Exclude = 'exclude';
    case DisabledRules = 'disabled_rules';
    case OnlyRules = 'only_rules';
    case SuppressPaths = 'suppress_paths';
    case SuppressNamespaces = 'suppress_namespaces';
    case IncludeGenerated = 'include_generated';
    case IncludeAutoloadDev = 'include_autoload_dev';
    case Cache = 'cache';

    public function declaration(): SectionDeclaration
    {
        return new SectionDeclaration($this->value, self::schemas()[$this->value]);
    }

    /** @return array<string, NodeSchema> root key => schema */
    private static function schemas(): array
    {
        $string = NodeSchema::scalar(ScalarForm::String);
        $boolean = NodeSchema::scalar(ScalarForm::Boolean);
        $selectors = self::selectorSet();

        return [
            self::Exclude->value => $selectors,
            self::DisabledRules->value => NodeSchema::set($string),
            self::OnlyRules->value => NodeSchema::stringList()->announcingEmptyOverride(
                'An empty only_rules applies no rule filter: every enabled rule runs.',
            ),
            self::SuppressPaths->value => $selectors,
            self::SuppressNamespaces->value => $selectors,
            self::IncludeGenerated->value => $boolean,
            self::IncludeAutoloadDev->value => $boolean,
            self::Cache->value => NodeSchema::map(['dir' => self::directory(), 'enabled' => $boolean]),
        ];
    }

    private static function directory(): NodeSchema
    {
        return NodeSchema::scalar(ScalarForm::String)->judgedInEachLayer(static function (ResolvedValueInterface $directory): void {
            if ($directory->plain() === '') {
                $directory->refuse('Invalid value for "cache.dir": a directory path cannot be empty. Omit the key to use the default (.qmx-cache).');
            }
        });
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

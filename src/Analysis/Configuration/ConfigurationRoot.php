<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Core\Pattern\SelectorKind;

/**
 * The roots of the configuration document Configuration declares itself:
 * every root outside the capability-owned document roots and the `rules`
 * subtree, each with its canonical key and schema.
 */
enum ConfigurationRoot: string implements DocumentSectionSchemaInterface
{
    case Paths = 'paths';
    case Exclude = 'exclude';
    case Format = 'format';
    case FailOn = 'fail_on';
    case DisabledRules = 'disabled_rules';
    case OnlyRules = 'only_rules';
    case SuppressPaths = 'suppress_paths';
    case SuppressNamespaces = 'suppress_namespaces';
    case IncludeGenerated = 'include_generated';
    case IncludeAutoloadDev = 'include_autoload_dev';
    case MemoryLimit = 'memory_limit';
    case Cache = 'cache';
    case Parallel = 'parallel';

    public function key(): string
    {
        return $this->value;
    }

    public function schema(): NodeSchema
    {
        return self::schemas()[$this->value];
    }

    /** @return array<string, NodeSchema> root key => schema */
    private static function schemas(): array
    {
        $string = NodeSchema::scalar(ScalarForm::String);
        $boolean = NodeSchema::scalar(ScalarForm::Boolean);
        $selectors = self::selectorSet();

        return [
            self::Paths->value => NodeSchema::list(
                NodeSchema::scalar(ScalarForm::String)->withHint('Quote a name that reads as a number or a keyword ("2024", "true").'),
            ),
            self::Exclude->value => $selectors,
            self::Format->value => $string,
            self::FailOn->value => $string,
            self::DisabledRules->value => NodeSchema::set($string),
            self::OnlyRules->value => NodeSchema::stringList()->announcingEmptyOverride(
                'An empty only_rules applies no rule filter: every enabled rule runs.',
            ),
            self::SuppressPaths->value => $selectors,
            self::SuppressNamespaces->value => $selectors,
            self::IncludeGenerated->value => $boolean,
            self::IncludeAutoloadDev->value => $boolean,
            // PHP reads an integer as a byte count, and `-1` is the documented "no limit".
            self::MemoryLimit->value => NodeSchema::scalar(ScalarForm::String, ScalarForm::Integer),
            self::Cache->value => NodeSchema::map(['dir' => $string, 'enabled' => $boolean]),
            self::Parallel->value => NodeSchema::map(['workers' => NodeSchema::scalar(ScalarForm::Integer)]),
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

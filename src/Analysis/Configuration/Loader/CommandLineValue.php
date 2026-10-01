<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\MergePolicy;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\LayerReading;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/** Reads a CLI scalar or flow list through the target document declaration. */
final class CommandLineValue
{
    /** @param non-empty-list<string> $path */
    public static function read(string $text, NodeSchema $target, string $optionName, array $path = ['value']): AuthoredNode
    {
        $trimmed = trim($text);
        if (\in_array($target->policy, [MergePolicy::Replace, MergePolicy::Accumulate], true)
            && !str_starts_with($trimmed, '[') && !str_starts_with($trimmed, '"')
            && !str_starts_with($trimmed, "'") && str_contains($trimmed, ',')
        ) {
            throw ConfigurationRefusal::aboutCommandLineInput($optionName, 'Write a flow list such as [a,b]; a comma alone does not separate list elements.');
        }
        if ($trimmed === '' || str_starts_with($trimmed, '{') || str_starts_with($trimmed, '- ') || str_contains($trimmed, "\n")) {
            self::refuse($optionName);
        }

        try {
            $plain = Yaml::parse($text);
        } catch (ParseException $exception) {
            throw ConfigurationRefusal::aboutCommandLineInput($optionName, 'Expected a YAML scalar or flow list of scalars.', $exception);
        }

        if ($plain === null || (\is_array($plain) && (!array_is_list($plain) || !str_starts_with($trimmed, '[')))) {
            self::refuse($optionName);
        }
        if (\is_array($plain)) {
            foreach ($plain as $element) {
                if ($element === null || !\is_scalar($element)) {
                    self::refuse($optionName);
                }
            }
        } elseif (!\is_scalar($plain)) {
            self::refuse($optionName);
        }

        return self::throughDeclaration(AuthoredNode::fromPlain($plain, $optionName), $target, $optionName, $path);
    }

    /**
     * @param array<string|int, mixed> $value
     * @param non-empty-list<string> $path
     */
    public static function selector(array $value, NodeSchema $target, string $optionName, array $path = ['value']): AuthoredNode
    {
        return self::throughDeclaration(AuthoredNode::fromPlain($value, $optionName), $target, $optionName, $path);
    }

    /** @param non-empty-list<string> $path */
    private static function throughDeclaration(AuthoredNode $node, NodeSchema $target, string $optionName, array $path): AuthoredNode
    {
        if ($path === []) {
            throw new LogicException('A CLI value requires its exact document path.');
        }
        $root = $target;
        $tree = $node;
        foreach (array_reverse($path) as $segment) {
            $root = NodeSchema::map([$segment => $root]);
            $tree = AuthoredNode::mapping([$segment => $tree]);
        }
        $layer = new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::CommandLine), $tree, positioned: false);
        $plain = (new LayerReading())->readRoot($root, $layer, 0)?->plain();
        foreach ($path as $segment) {
            if ($plain === null) {
                break;
            }
            if (!\is_array($plain)) {
                throw new LogicException('The declared CLI document path must traverse mappings.');
            }
            $plain = $plain[$segment] ?? null;
        }
        return AuthoredNode::fromPlain($plain, $optionName);
    }

    private static function refuse(string $optionName): never
    {
        throw ConfigurationRefusal::aboutCommandLineInput($optionName, 'Expected a YAML scalar or flow list of scalars; null and mappings are not accepted.');
    }
}

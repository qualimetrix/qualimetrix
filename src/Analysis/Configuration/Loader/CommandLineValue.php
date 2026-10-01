<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\LayerReading;

/** Reads a CLI scalar or flow list through the target document declaration. */
final class CommandLineValue
{
    /** @param non-empty-list<string> $path */
    public static function read(string $text, NodeSchema $target, string $optionName, array $path = ['value']): AuthoredNode
    {
        return self::throughDeclaration(CommandLineSyntax::parse($text, $target, $optionName), $target, $optionName, $path);
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

}

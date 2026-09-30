<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\DocumentRoots;

/**
 * The command line as a written layer. It has no key paths an author could
 * look up: each value sits at its document path, named by the option that
 * wrote it, so a refusal says `option --workers` rather than a path nobody
 * typed.
 */
final class CommandLineLayer
{
    public static function of(ConfigurationResolutionRequest $request): AuthoredLayer
    {
        $tree = [];
        foreach ($request->cliValues as $key => $value) {
            $tree = self::placed(
                $tree,
                DocumentRoots::pathOf($key),
                AuthoredNode::fromPlain($value, $request->cliOptionNames[$key] ?? null),
            );
        }

        $seen = [];
        foreach ($request->cliPathWrites as $write) {
            foreach ($seen as [$path, $optionName]) {
                if (self::prefix($path, $write->path) || self::prefix($write->path, $path)) {
                    throw ConfigurationRefusal::aboutCommandLineInput(
                        $write->optionName,
                        \sprintf('Options %s and %s both write overlapping rule option paths.', $optionName, $write->optionName),
                    );
                }
            }
            $seen[] = [$write->path, $write->optionName];
            $tree = self::placed(
                $tree,
                $write->path,
                $write->selectorValue === null
                    ? CommandLineValue::read($write->text, $write->target, $write->optionName)
                    : CommandLineValue::selector($write->selectorValue, $write->target, $write->optionName),
            );
        }

        return new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::CommandLine), self::mapping($tree), positioned: false);
    }

    /**
     * @param list<string> $prefix
     * @param list<string> $path
     */
    private static function prefix(array $prefix, array $path): bool
    {
        return \count($prefix) <= \count($path) && \array_slice($path, 0, \count($prefix)) === $prefix;
    }

    /**
     * @param array<string, mixed> $tree
     * @param list<string> $path
     *
     * @return array<string, mixed>
     */
    private static function placed(array $tree, array $path, AuthoredNode $node): array
    {
        $head = array_shift($path) ?? throw new LogicException('A configuration key has an empty document path.');
        if ($path === []) {
            $tree[$head] = $node;

            return $tree;
        }

        $branch = $tree[$head] ?? [];
        $tree[$head] = self::placed(\is_array($branch) ? $branch : [], $path, $node);

        return $tree;
    }

    /** @param array<string, mixed> $tree */
    private static function mapping(array $tree): AuthoredNode
    {
        $children = [];
        foreach ($tree as $key => $child) {
            $children[$key] = $child instanceof AuthoredNode ? $child : self::mapping(\is_array($child) ? $child : []);
        }

        return AuthoredNode::mapping($children);
    }
}

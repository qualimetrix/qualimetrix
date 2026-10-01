<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\MergePolicy;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/** The lexical and YAML grammar of a CLI scalar or flow list. */
final class CommandLineSyntax
{
    public static function parse(string $text, NodeSchema $target, string $optionName): AuthoredNode
    {
        $trimmed = trim($text);
        self::refuseCommaSeparatedList($trimmed, $target, $optionName);
        self::refuseForbiddenStart($trimmed, $optionName);
        $plain = self::parseYaml($text, $optionName);
        self::assertScalarOrFlowList($plain, $trimmed, $optionName);

        return AuthoredNode::fromPlain($plain, $optionName);
    }

    private static function refuseCommaSeparatedList(string $trimmed, NodeSchema $target, string $optionName): void
    {
        if (\in_array($target->policy, [MergePolicy::Replace, MergePolicy::Accumulate], true)
            && !str_starts_with($trimmed, '[') && !str_starts_with($trimmed, '"')
            && !str_starts_with($trimmed, "'") && str_contains($trimmed, ',')
        ) {
            throw ConfigurationRefusal::aboutCommandLineInput($optionName, 'Write a flow list such as [a,b]; a comma alone does not separate list elements.');
        }
    }

    private static function refuseForbiddenStart(string $trimmed, string $optionName): void
    {
        if ($trimmed === '' || str_starts_with($trimmed, '{') || str_starts_with($trimmed, '- ') || str_contains($trimmed, "\n")) {
            self::refuse($optionName);
        }
    }

    private static function parseYaml(string $text, string $optionName): mixed
    {
        try {
            return Yaml::parse($text);
        } catch (ParseException $exception) {
            throw ConfigurationRefusal::aboutCommandLineInput($optionName, 'Expected a YAML scalar or flow list of scalars.', $exception);
        }
    }

    private static function assertScalarOrFlowList(mixed $plain, string $trimmed, string $optionName): void
    {
        if ($plain === null) {
            self::refuse($optionName);
        }
        if (\is_array($plain)) {
            self::assertFlowList($plain, $trimmed, $optionName);
        } elseif (!\is_scalar($plain)) {
            self::refuse($optionName);
        }
    }

    /** @param array<array-key, mixed> $plain */
    private static function assertFlowList(array $plain, string $trimmed, string $optionName): void
    {
        if (!array_is_list($plain) || !str_starts_with($trimmed, '[')) {
            self::refuse($optionName);
        }
        foreach ($plain as $element) {
            if ($element === null || !\is_scalar($element)) {
                self::refuse($optionName);
            }
        }
    }

    private static function refuse(string $optionName): never
    {
        throw ConfigurationRefusal::aboutCommandLineInput($optionName, 'Expected a YAML scalar or flow list of scalars; null and mappings are not accepted.');
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;

/** Decodes the explicit `kind:value` selector scalar accepted by CLI options. */
final class CliSelectorDecoder
{
    public function decodePath(string $value, string $option): PathPattern
    {
        $definition = $this->definition($value, $option);

        try {
            return new PathPattern($definition);
        } catch (InvalidArgumentException $e) {
            throw ConfigurationRefusal::aboutCommandLineInput($option, $e->getMessage(), $e);
        }
    }

    public function decodeNamespace(string $value, string $option): NamespacePattern
    {
        $definition = $this->definition($value, $option);

        try {
            return new NamespacePattern($definition);
        } catch (InvalidArgumentException $e) {
            throw ConfigurationRefusal::aboutCommandLineInput($option, $e->getMessage(), $e);
        }
    }

    private function definition(string $value, string $option): SelectorDefinition
    {
        $separator = strpos($value, ':');
        if ($separator === false) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                $option,
                \sprintf(
                    'Selector "%s" must use KIND:VALUE (exact, subtree, or regex); bare values are not supported.',
                    $value,
                ),
            );
        }

        $kind = substr($value, 0, $separator);
        $pattern = substr($value, $separator + 1);

        try {
            return SelectorDefinition::fromKindAndValue($kind, $pattern);
        } catch (InvalidArgumentException $e) {
            throw ConfigurationRefusal::aboutCommandLineInput($option, $e->getMessage(), $e);
        }
    }
}

<?php

declare(strict_types=1);

namespace QmxFindingGate;

use RuntimeException;

/** The exact namespace transitions supported by the product probes. */
final class ProductProbeTypes
{
    private const array PREVIOUS = [
        'Qualimetrix\\Analysis\\Finding\\Contract\\Channel\\ChannelDeclarationRegistryInterface' => 'Qualimetrix\\Analysis\\Finding\\Contract\\ChannelDeclarationRegistryInterface',
        'Qualimetrix\\Analysis\\Finding\\Contract\\Channel\\ChannelUniverseInterface' => 'Qualimetrix\\Analysis\\Finding\\Contract\\ChannelUniverseInterface',
        'Qualimetrix\\Analysis\\Policy\\Baseline\\Capture\\BaselineGenerator' => 'Qualimetrix\\Analysis\\Policy\\Baseline\\BaselineGenerator',
        'Qualimetrix\\Infrastructure\\Console\\Invocation\\CommandDefinition\\CheckCommandDefinition' => 'Qualimetrix\\Infrastructure\\Console\\CheckCommandDefinition',
        'Qualimetrix\\Infrastructure\\Console\\Invocation\\Configuration\\ConfigurationInputAdapter' => 'Qualimetrix\\Infrastructure\\Console\\ConfigurationInputAdapter',
        'Qualimetrix\\Infrastructure\\Console\\Terminal\\ErrorStream' => 'Qualimetrix\\Infrastructure\\Console\\ErrorStream',
        'Qualimetrix\\Analysis\\Finding\\Contract\\Rule\\OptionLanguage\\RuleOptionDocumentFormsInterface' => 'Qualimetrix\\Analysis\\Finding\\Contract\\Rule\\RuleOptionDocumentFormsInterface',
    ];

    /** @return class-string */
    public static function existing(string $current): string
    {
        foreach ([$current, self::previous($current)] as $name) {
            if (class_exists($name) || interface_exists($name)) {
                return $name;
            }
        }

        throw new RuntimeException('The product probe cannot find either supported type for ' . $current . '.');
    }

    public static function isName(string $actual, string $current): bool
    {
        $previous = self::previous($current);

        return $actual === $current || $actual === $previous;
    }

    private static function previous(string $current): string
    {
        return self::PREVIOUS[$current] ?? throw new RuntimeException('Unknown product probe type: ' . $current . '.');
    }
}

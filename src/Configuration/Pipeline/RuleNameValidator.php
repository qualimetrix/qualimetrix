<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Pipeline;

use Psr\Log\LoggerInterface;
use Qualimetrix\Configuration\ConfigSchema;
use Qualimetrix\Configuration\KnownRuleNamesProviderInterface;

/**
 * Validates rule names in configuration data against registered rules.
 *
 * Shared by ConfigFileStage and PresetStage to warn about unknown rule names
 * before they silently get ignored at runtime.
 */
final class RuleNameValidator
{
    /**
     * Warns about unknown rule names in the "rules:" config section.
     *
     * Emits a PSR-3 warning for each rule name that does not match any registered rule.
     * Matching follows prefix logic: exact match, forward prefix ("complexity" matches
     * "complexity.cyclomatic"), and reverse prefix ("complexity.cyclomatic.method" refines
     * "complexity.cyclomatic").
     *
     * @param array<string, mixed> $data raw config data (before normalization)
     * @param string $configSource label for warning messages (e.g., "preset:strict", "qmx.yaml")
     */
    public static function warnAboutUnknownRuleNames(
        array $data,
        string $configSource,
        KnownRuleNamesProviderInterface $provider,
        LoggerInterface $logger,
    ): void {
        $rulesSection = $data[ConfigSchema::RULES] ?? null;
        if (!\is_array($rulesSection) || $rulesSection === []) {
            return;
        }

        $knownNames = $provider->getKnownRuleNames();

        foreach (array_keys($rulesSection) as $configuredName) {
            $name = (string) $configuredName;
            $matched = false;
            foreach ($knownNames as $known) {
                if ($name === $known
                    || str_starts_with($known, $name . '.')
                    || str_starts_with($name, $known . '.')
                ) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                $logger->warning(
                    'Unknown rule name "{rule}" in config file "{source}" — does not match any registered rule.',
                    ['rule' => $name, 'source' => $configSource],
                );
            }
        }
    }
}

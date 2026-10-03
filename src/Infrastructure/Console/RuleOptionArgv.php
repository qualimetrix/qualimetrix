<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Symfony\Component\Console\Input\ArgvInput;

/** Rule-option occurrences in the original token order, before binding folds them. */
final class RuleOptionArgv
{
    /**
     * @param array<string, bool> $available option name => accepts a value
     *
     * @return list<array{optionName: string, text: string, ordinal: int}>
     */
    public static function scan(ArgvInput $input, array $available): array
    {
        $tokens = $input->getRawTokens(false);
        $records = [];
        for ($index = 0, $count = \count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if ($token === '--') {
                break;
            }
            if (!str_starts_with($token, '--')) {
                continue;
            }
            $parts = explode('=', substr($token, 2), 2);
            $name = $parts[0];
            $inline = $parts[1] ?? null;
            if (!\array_key_exists($name, $available)) {
                continue;
            }
            $text = self::textFor($name, $inline, $available, $tokens, $index);
            $records[] = ['optionName' => '--' . $name, 'text' => $text, 'ordinal' => \count($records)];
        }

        return $records;
    }

    /**
     * @param array<string, bool> $available
     * @param list<string> $tokens
     */
    private static function textFor(string $name, ?string $inline, array $available, array $tokens, int &$index): string
    {
        if (!$available[$name]) {
            return 'true';
        }

        return $inline ?? $tokens[++$index] ?? '';
    }
}

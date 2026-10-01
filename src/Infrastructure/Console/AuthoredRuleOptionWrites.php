<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;

/** Preserves rule-option occurrences before Symfony folds repeated scalar options. */
final class AuthoredRuleOptionWrites
{
    /**
     * @param array<string, bool> $aliasesAcceptValue alias => whether its option takes text
     *
     * @return list<array{optionName: string, text: string, ordinal: int}>
     */
    public static function fromInput(InputInterface $input, array $aliasesAcceptValue): array
    {
        $available = [];
        foreach ($aliasesAcceptValue as $alias => $acceptsValue) {
            if ($input->hasOption($alias)) {
                $available[$alias] = $acceptsValue;
            }
        }
        if ($input->hasOption('rule-opt')) {
            $available['rule-opt'] = true;
        }

        if (!$input instanceof ArgvInput) {
            $records = [];
            foreach ($available as $name => $acceptsValue) {
                $value = $input->getOption($name);
                if ($value === null || ($value === false && !$acceptsValue) || $value === []) {
                    continue;
                }
                foreach (\is_array($value) ? $value : [$value] as $text) {
                    $records[] = ['optionName' => '--' . $name, 'text' => $acceptsValue ? CommandLineSpelling::of($text, '--' . $name) : 'true', 'ordinal' => \count($records)];
                }
            }

            return $records;
        }

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
            if (!$available[$name]) {
                $text = 'true';
            } else {
                $text = $inline ?? $tokens[++$index] ?? '';
            }
            $records[] = ['optionName' => '--' . $name, 'text' => $text, 'ordinal' => \count($records)];
        }

        return $records;
    }
}

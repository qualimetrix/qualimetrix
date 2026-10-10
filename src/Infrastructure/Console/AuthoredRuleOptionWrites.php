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
            return self::boundInput($input, $available);
        }

        return RuleOptionArgv::scan($input, $available);
    }

    /**
     * @param array<string, bool> $available option name => accepts a value
     *
     * @return list<array{optionName: string, text: string, ordinal: int}>
     */
    private static function boundInput(InputInterface $input, array $available): array
    {
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
}

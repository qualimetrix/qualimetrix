<?php

declare(strict_types=1);

namespace QmxFindingGateControls;

use QmxFindingGate\FailureClass;
use QmxFindingGate\Fs;
use RuntimeException;

/** A declared enumeration permutation, its unannounced twin and an idle scoped intent. */
final class MapControls
{
    public static function enumerationDeclared(): Control
    {
        return Control::greenWith(
            'enumeration-declared',
            'the suppressed mechanism enumeration is permuted at exactly its two declared positions',
            self::permutation()->and(ChannelRenamePlants::trackedMapPlus('report-values.tsv', self::rows(), 'the exact values and keys permutations')),
        );
    }

    public static function enumerationWithoutRow(): Control
    {
        $required = [];
        $cases = glob(\dirname(__DIR__, 2) . '/finding-gate/cases/*/case.json');
        foreach ($cases === false ? [] : $cases as $case) {
            $required[] = new Expectation(FailureClass::SURFACE_MISMATCH, 'case:' . basename(\dirname($case)) . '|format:suppressed');
        }
        if ($required === []) {
            throw new RuntimeException('The enumeration control has no corpus population.');
        }
        return Control::red('enumeration-without-row', 'the same enum order changes with no declared correspondence', self::permutation(), $required);
    }

    public static function enumerationIdle(): Control
    {
        $old = self::descriptor('values', 'neverPublished', ['a', 'b']);
        $new = self::descriptor('values', 'neverPublished', ['b', 'a']);
        return Control::red(
            'enumeration-idle',
            'a scoped enumeration intent whose path no report publishes',
            ChannelRenamePlants::trackedMapPlus('report-values.tsv', [$old . "\t" . $new . "\tan exact path that is absent"], 'an idle enumeration intent'),
            [new Expectation(FailureClass::MAP_STALE, 'neverPublished')],
        );
    }

    private static function permutation(): Mutation
    {
        return Mutation::edit('src/Reporting/FindingProjection/SuppressionMechanism.php', [
            "    /** `@qmx-ignore` / `@qmx-ignore-file` / `@qmx-ignore-next-line`. */\n    case Suppression = 'suppression';\n\n    /** Global `suppress_paths` (config or `--suppress-path`). */\n    case PathSuppression = 'path-suppression';"
                => "    /** Global `suppress_paths` (config or `--suppress-path`). */\n    case PathSuppression = 'path-suppression';\n\n    /** `@qmx-ignore` / `@qmx-ignore-file` / `@qmx-ignore-next-line`. */\n    case Suppression = 'suppression';",
        ], 'the first two enum declarations exchange positions without changing their values');
    }

    /** @return list<string> */
    private static function rows(): array
    {
        $path = \dirname(__DIR__, 2) . '/src/Reporting/FindingProjection/SuppressionMechanism.php';
        $matched = preg_match_all("~case \\w+ = '([^']+)';~", Fs::read($path), $values);
        if ($matched === false || $matched < 2 || \count(array_unique($values[1])) !== $matched) {
            throw new RuntimeException('The enum declaration yields no unique permutation population.');
        }
        $old = $values[1];
        $new = $old;
        [$new[0], $new[1]] = [$new[1], $new[0]];
        $rows = [];
        foreach (['values' => 'mechanisms', 'keys' => 'byMechanism'] as $kind => $field) {
            $rows[] = self::descriptor($kind, $field, $old) . "\t" . self::descriptor($kind, $field, $new) . "\tthe first two enum declarations exchange positions";
        }
        return $rows;
    }

    /** @param list<string> $members */
    private static function descriptor(string $kind, string $field, array $members): string
    {
        return json_encode(['surface' => 'format:suppressed', 'path' => [$field], 'kind' => $kind, 'members' => $members], \JSON_THROW_ON_ERROR);
    }
}

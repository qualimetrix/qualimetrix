<?php

declare(strict_types=1);

namespace Qualimetrix\PromiseEffect\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\PromiseEffect\Declarations;
use Qualimetrix\PromiseEffect\InProcess;
use Qualimetrix\PromiseEffect\Ledger;
use Qualimetrix\PromiseEffect\ProcessProbe;
use Qualimetrix\PromiseEffect\Stand;
use Qualimetrix\PromiseEffect\Verdict;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A valueless `cli-root` flag writes the same argv for a form's comparand as
 * for the form itself, so a collapse probe on it is the value probe again and
 * reads COLLAPSED whenever the flag has any effect at all. The comparison is a
 * question the door cannot carry; no cell on such a door may be decided by it.
 */
final class ValuelessFlagCollapseTest extends TestCase
{
    private const string COLLAPSE_DECISION = 'equal to the canonical write of another form';

    #[Test]
    public function itDecidesNoValuelessFlagCellByTheCollapseComparison(): void
    {
        $root = \dirname(__DIR__, 3);
        $valueless = [];

        foreach (self::rows($root . '/promise-effect/cli-root-flags.tsv') as $row) {
            if (($row[2] ?? '') === 'yes') {
                $valueless[$row[0]] = true;
            }
        }

        self::assertNotSame([], $valueless, 'the flag table declares no valueless flag; the question is vacuous');

        $judged = [];
        $collapsed = [];

        foreach (self::rows($root . '/docs/internal/generated/promise-effect/verdicts.tsv') as $row) {
            if ($row[0] !== 'D' || preg_match('/^form\|cli-root\|(.+)\|[^|]+$/', $row[1], $match) !== 1 || !isset($valueless[$match[1]])) {
                continue;
            }

            $judged[] = $row[1];

            if ($row[7] === self::COLLAPSE_DECISION) {
                $collapsed[] = $row[1];
            }
        }

        self::assertNotSame([], $judged, 'no valueless-flag cell in the verdicts; the question is vacuous');
        self::assertSame([], $collapsed);
    }

    #[Test]
    public function itReadsTheActualAliasModeBeforeTreatingScalarSpellingsAsValues(): void
    {
        require_once \dirname(__DIR__, 3) . '/scripts/promise-effect/InProcess.php';
        $scratch = sys_get_temp_dir() . '/qmx-valueless-alias-' . bin2hex(random_bytes(6));

        try {
            $helper = new InProcess($scratch);
            self::assertFalse($helper->aliasAcceptsValue('wmc-exclude-data-classes'));
            self::assertTrue($helper->aliasAcceptsValue('cyclomatic-warning'));
            $command = (new ReflectionProperty(InProcess::class, 'checkCommand'))->getValue($helper);
            self::assertInstanceOf(\Symfony\Component\Console\Command\Command::class, $command);
            $definition = $command->getDefinition();
            $present = new ArgvInput(['qmx', '--wmc-exclude-data-classes'], $definition);
            self::assertTrue($present->getOption('wmc-exclude-data-classes'));

            try {
                new ArgvInput(['qmx', '--wmc-exclude-data-classes='], $definition);
                self::fail('The valueless alias unexpectedly accepted an explicit empty value.');
            } catch (RuntimeException $exception) {
                self::assertSame('The "--wmc-exclude-data-classes" option does not accept a value.', $exception->getMessage());
            }
        } finally {
            (new Filesystem())->remove($scratch);
        }
    }

    #[Test]
    public function itKeepsOnlyTheExpressibleWmcAliasFormsInTheActualAxisACells(): void
    {
        $root = \dirname(__DIR__, 3);
        foreach (['Ledger', 'Declarations', 'InProcess', 'ProcessProbe', 'Classifier', 'Limits', 'Composition', 'Neighbourhood', 'Stand'] as $unit) {
            require_once $root . '/scripts/promise-effect/' . $unit . '.php';
        }
        $scratch = sys_get_temp_dir() . '/qmx-wmc-axis-a-' . bin2hex(random_bytes(6));

        try {
            $helper = new InProcess($scratch);
            $rows = array_values(array_filter(
                Ledger::load($root)->forms,
                static fn($row): bool => $row->door === 'cli-alias' && $row->path === 'rules.complexity.wmc.excludeDataClasses',
            ));
            self::assertCount(1, $rows);
            $ledgerReflection = new ReflectionClass(Ledger::class);
            $ledger = $ledgerReflection->newInstanceWithoutConstructor();
            (new ReflectionMethod(Ledger::class, '__construct'))->invoke($ledger, $rows, [], [], 0);
            $stand = new Stand($root, $ledger, Declarations::load($root), $helper, new ProcessProbe($root, $scratch));
            $cells = [];

            foreach ($stand->axisA() as $cell) {
                $cells[$cell->probe] = $cell;
            }

            foreach (['int', 'float', 'string-number', 'string-nonnumber', 'null', 'list', 'map'] as $form) {
                self::assertSame(Verdict::NOT_OBSERVABLE, $cells[$form]->verdict);
                self::assertSame('a valueless flag carries no spelling for this form', $cells[$form]->decidedBy);
            }

            self::assertNotSame(Verdict::COLLAPSED, $cells['bool']->verdict);
            $rawSides = array_map(static fn(array $side): string => $side[1] . '|' . $side[2], $stand->rawObservations());
            self::assertNotContains('form|cli-alias|rules.complexity.wmc.excludeDataClasses|bool|collapse', $rawSides);
            self::assertNotContains('form|cli-alias|rules.complexity.wmc.excludeDataClasses|null|value', $rawSides);
        } finally {
            (new Filesystem())->remove($scratch);
        }
    }

    /** @return list<list<string>> */
    private static function rows(string $file): array
    {
        $lines = file($file, \FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines, $file);
        $rows = [];

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $rows[] = explode("\t", $line);
        }

        return $rows;
    }
}

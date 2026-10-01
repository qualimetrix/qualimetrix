<?php

declare(strict_types=1);

namespace Qualimetrix\PromiseEffect\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\PromiseEffect\Classifier;
use Qualimetrix\PromiseEffect\Declarations;
use Qualimetrix\PromiseEffect\InProcess;
use Qualimetrix\PromiseEffect\Ledger;
use Qualimetrix\PromiseEffect\LedgerError;
use Qualimetrix\PromiseEffect\Neighbourhood;
use Qualimetrix\PromiseEffect\Observation;
use Qualimetrix\PromiseEffect\ProcessProbe;
use Qualimetrix\PromiseEffect\Stand;
use Qualimetrix\PromiseEffect\Verdict;
use ReflectionMethod;
use Symfony\Component\Filesystem\Filesystem;

final class ResolvedDocumentObservationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        foreach (['Ledger', 'Declarations', 'InProcess', 'ProcessProbe', 'Classifier', 'Limits', 'Composition', 'Neighbourhood', 'Stand'] as $unit) {
            require_once \dirname(__DIR__, 3) . '/scripts/promise-effect/' . $unit . '.php';
        }
    }

    #[Test]
    public function itObservesResolvedValuesAndAllRuleRoots(): void
    {
        $scratch = sys_get_temp_dir() . '/qmx-resolved-observation-' . bin2hex(random_bytes(6));

        try {
            $observations = (new InProcess($scratch))->take([
                'format' => 'json',
                'cache' => ['enabled' => false],
                'rules' => ['complexity.ccn' => ['callable' => ['warning' => 123]]],
                'only_rules' => ['complexity.ccn'],
                'disabled_rules' => ['security'],
            ], [], [], '');
            $merged = $observations['merged'];
            self::assertSame(Observation::ACCEPTED, $merged->outcome, $merged->text);
            $values = json_decode($merged->text, true, 512, \JSON_THROW_ON_ERROR);
            self::assertSame('json', $values['format']);
            self::assertFalse($values['cache']['enabled']);
            self::assertSame(['complexity.ccn' => ['callable' => ['warning' => 123]]], $values['rules']);
            self::assertSame(['complexity.ccn'], $values['only_rules']);
            self::assertSame(['security'], $values['disabled_rules']);
        } finally {
            (new Filesystem())->remove($scratch);
        }
    }

    #[Test]
    public function itKeepsUnwrittenFieldsBesideTheirEffectiveNeighbour(): void
    {
        $root = \dirname(__DIR__, 3);
        $scratch = sys_get_temp_dir() . '/qmx-null-neighbour-' . bin2hex(random_bytes(6));

        try {
            $helper = new InProcess($scratch);
            $points = [];
            foreach ([
                'omitted' => [],
                'neighbour' => ['threshold' => 123],
                'nullAlone' => ['enabled' => null],
                'both' => ['enabled' => null, 'threshold' => 123],
            ] as $name => $write) {
                $points[$name] = $helper->take(['rules' => ['complexity.ccn' => $write]], [], [], 'complexity.ccn');
                self::assertSame(Observation::ACCEPTED, $points[$name]['object']->outcome, $points[$name]['object']->text);
            }
            $neighbour = json_decode($points['neighbour']['object']->text, true, 512, \JSON_THROW_ON_ERROR);
            self::assertSame(123, $neighbour['options']['callable']['warning']);
            self::assertSame(123, $neighbour['options']['callable']['error']);
            self::assertNotSame($points['omitted']['object']->text, $points['neighbour']['object']->text);
            self::assertSame($points['omitted']['object']->text, $points['nullAlone']['object']->text);
            self::assertSame($points['neighbour']['object']->text, $points['both']['object']->text);
            $merged = json_decode($points['both']['merged']->text, true, 512, \JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('enabled', $merged['rules']['complexity.ccn']);
            $judgement = Classifier::neighbourhood($points['omitted']['object'], $points['neighbour']['object'], $points['nullAlone']['object'], $points['both']['object']);
            self::assertSame(Verdict::PRESENCE_NEUTRAL, $judgement->verdict);
            self::assertFalse($judgement->defect);

            $keys = array_map(static fn($row): string => $row->key(), (new Neighbourhood($root, $helper->optionsClasses))->rows);
            self::assertContains('neighbourhood|complexity.ccn|enabled|threshold|6-gate', $keys);
            self::assertContains('neighbourhood|complexity.ccn|callable.enabled|callable.warning|6-gate', $keys);
            foreach (['definitely_unknown_option' => null, 'callable' => ['suppress_paths' => null]] as $key => $value) {
                $refused = $helper->take(['rules' => ['complexity.ccn' => [$key => $value]]], [], [], 'complexity.ccn');
                self::assertSame(Observation::REFUSED_FRAMED, $refused['object']->outcome, $refused['object']->text);
            }
        } finally {
            (new Filesystem())->remove($scratch);
        }
    }

    #[Test]
    public function itSuppliesBothBooleanMagnitudesAtTheDeclaredDepth(): void
    {
        $root = \dirname(__DIR__, 3);
        $scratch = sys_get_temp_dir() . '/qmx-neighbour-magnitudes-' . bin2hex(random_bytes(6));

        try {
            $helper = new InProcess($scratch);
            $stand = new Stand($root, Ledger::load($root), Declarations::load($root), $helper, new ProcessProbe($root, $scratch));
            $supply = new ReflectionMethod(Stand::class, 'effectWritesFor');
            foreach (['enabled', 'callable.enabled'] as $key) {
                try {
                    $writes = $supply->invoke($stand, 'complexity.ccn', $key);
                } catch (LedgerError $error) {
                    self::fail('A declared boolean neighbour was not supplied: ' . $error->getMessage());
                }
                self::assertSame([true, false], $writes);
            }
            foreach (['unknown', 'callable.suppress-paths'] as $key) {
                try {
                    $supply->invoke($stand, 'complexity.ccn', $key);
                    self::fail('An undeclared neighbour was supplied: ' . $key);
                } catch (LedgerError $error) {
                    self::assertStringContainsString('no declaration recognises', $error->getMessage());
                }
            }
        } finally {
            (new Filesystem())->remove($scratch);
        }
    }

    #[Test]
    public function itSuppliesDeclaredNamespaceSelectorMappings(): void
    {
        $root = \dirname(__DIR__, 3);
        $scratch = sys_get_temp_dir() . '/qmx-selector-supply-' . bin2hex(random_bytes(6));

        try {
            $helper = new InProcess($scratch);
            $stand = new Stand($root, Ledger::load($root), Declarations::load($root), $helper, new ProcessProbe($root, $scratch));
            $supply = new ReflectionMethod(Stand::class, 'effectWritesFor');
            try {
                $writes = $supply->invoke($stand, 'coupling.distance', 'include-namespaces');
            } catch (LedgerError $error) {
                self::fail('A declared namespace selector was not supplied: ' . $error->getMessage());
            }
            self::assertSame([[['exact' => 'App']]], $writes);
            $observations = $helper->take(['rules' => ['coupling.distance' => ['include-namespaces' => $writes[0]]]], [], [], '');
            self::assertSame(Observation::ACCEPTED, $observations['merged']->outcome, $observations['merged']->text);
            $values = json_decode($observations['merged']->text, true, 512, \JSON_THROW_ON_ERROR);
            self::assertSame([['exact' => 'App']], $values['rules']['coupling.distance']['include-namespaces']);
        } finally {
            (new Filesystem())->remove($scratch);
        }
    }

    #[Test]
    public function itSuppliesDeclaredRetiredKeysForRefusalProbes(): void
    {
        $root = \dirname(__DIR__, 3);
        $scratch = sys_get_temp_dir() . '/qmx-retired-supply-' . bin2hex(random_bytes(6));

        try {
            $helper = new InProcess($scratch);
            $stand = new Stand($root, Ledger::load($root), Declarations::load($root), $helper, new ProcessProbe($root, $scratch));
            $supply = new ReflectionMethod(Stand::class, 'effectWritesFor');
            foreach (['empty-template-severity', 'potential-shadow-severity', 'unreachable-layer-severity'] as $key) {
                try {
                    $writes = $supply->invoke($stand, 'architecture.layer-violation', $key);
                } catch (LedgerError $error) {
                    self::fail('A declared retirement was not supplied: ' . $error->getMessage());
                }
                self::assertSame([false], $writes);
                $observations = $helper->take(['rules' => ['architecture.layer-violation' => [$key => $writes[0]]]], [], [], '');
                self::assertSame(Observation::REFUSED_FRAMED, $observations['merged']->outcome, $observations['merged']->text);
                self::assertStringContainsString('no longer exists', $observations['merged']->text);
            }
        } finally {
            (new Filesystem())->remove($scratch);
        }
    }
}

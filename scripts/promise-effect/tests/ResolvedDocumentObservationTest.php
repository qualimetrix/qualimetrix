<?php

declare(strict_types=1);

namespace Qualimetrix\PromiseEffect\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\PromiseEffect\Classifier;
use Qualimetrix\PromiseEffect\Declarations;
use Qualimetrix\PromiseEffect\FormRow;
use Qualimetrix\PromiseEffect\InProcess;
use Qualimetrix\PromiseEffect\Ledger;
use Qualimetrix\PromiseEffect\LedgerError;
use Qualimetrix\PromiseEffect\Neighbourhood;
use Qualimetrix\PromiseEffect\Observation;
use Qualimetrix\PromiseEffect\ProcessProbe;
use Qualimetrix\PromiseEffect\Stand;
use Qualimetrix\PromiseEffect\Verdict;
use ReflectionClass;
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
    public function itKeepsTheSameAuthoredThresholdCompanionAtEveryDoorAndChangesTheTarget(): void
    {
        $root = \dirname(__DIR__, 3);
        $scratch = sys_get_temp_dir() . '/qmx-threshold-context-' . bin2hex(random_bytes(6));

        try {
            $helper = new InProcess($scratch);
            $original = Declarations::load($root);
            $path = 'rules.complexity.ccn.callable.warning';
            $base = ['rules' => ['complexity.ccn' => ['callable' => ['error' => 10000]]]];
            $contexts = ['cli-alias|rules.coupling.class-rank.warning' => ['rules' => ['coupling.class-rank' => ['error' => 10000]]]];

            foreach (['yaml', 'rule-opt', 'cli-alias'] as $door) {
                $contexts[$door . '|' . $path] = $base;
            }

            $declarations = self::declarationsWith($original, $original->axisAHits, $contexts);
            $stand = new Stand($root, Ledger::load($root), $declarations, $helper, new ProcessProbe($root, $scratch));
            $write = new ReflectionMethod(Stand::class, 'write');

            foreach (['yaml' => '', 'rule-opt' => '', 'cli-alias' => 'cyclomatic-warning'] as $door => $alias) {
                $row = new FormRow($door, $path, ['int'], 'unwritten', '', '', 'PROMISED', '', ['int'], $alias);
                $omitted = $write->invoke($stand, $row, 'complexity.ccn', 'callable.warning', null);
                $written = $write->invoke($stand, $row, 'complexity.ccn', 'callable.warning', $original->forms['int']);
                self::assertSame(Observation::ACCEPTED, $omitted['object']->outcome, $omitted['object']->text);
                self::assertSame(Observation::ACCEPTED, $written['object']->outcome, $written['object']->text);
                $omittedOptions = json_decode($omitted['object']->text, true, 512, \JSON_THROW_ON_ERROR)['options'];
                $writtenOptions = json_decode($written['object']->text, true, 512, \JSON_THROW_ON_ERROR)['options'];
                self::assertSame(10000, $omittedOptions['callable']['error'], $door);
                self::assertSame(10000, $writtenOptions['callable']['error'], $door);
                self::assertSame(7331, $writtenOptions['callable']['warning'], $door);
                self::assertNotSame($omittedOptions['callable']['warning'], $writtenOptions['callable']['warning'], $door);
            }

            $wrongType = $write->invoke(
                $stand,
                new FormRow('yaml', $path, ['int'], 'unwritten', '', '', 'PROMISED', '', ['int']),
                'complexity.ccn',
                'callable.warning',
                $original->forms['map'],
            );
            self::assertSame(Observation::REFUSED_FRAMED, $wrongType['object']->outcome, $wrongType['object']->text);

            $floatPath = 'rules.coupling.class-rank.warning';
            $floatRow = new FormRow('cli-alias', $floatPath, ['float'], 'unwritten', '', '', 'PROMISED', '', ['float'], 'class-rank-warning');
            $float = $write->invoke($stand, $floatRow, 'coupling.class-rank', 'warning', $original->forms['float']);
            $comparand = $write->invoke($stand, $floatRow, 'coupling.class-rank', 'warning', $original->forms['float']->comparand());
            self::assertSame(Observation::ACCEPTED, $float['object']->outcome, $float['object']->text);
            self::assertSame(Observation::ACCEPTED, $comparand['object']->outcome, $comparand['object']->text);
            self::assertNotSame($float['object']->text, $comparand['object']->text);
            $floatOptions = json_decode($float['object']->text, true, 512, \JSON_THROW_ON_ERROR)['options'];
            self::assertSame(7331.9, $floatOptions['warning']);
            self::assertSame(10000, $floatOptions['error']);
        } finally {
            (new Filesystem())->remove($scratch);
        }
    }

    #[Test]
    public function itKeepsTypedYamlAndScalarCliSelectorHitsOnTheirOwnDoors(): void
    {
        $scratch = sys_get_temp_dir() . '/qmx-selector-door-hit-' . bin2hex(random_bytes(6));

        try {
            $helper = new InProcess($scratch);
            $yaml = $helper->take(['rules' => ['complexity.ccn' => ['suppress-paths' => [['subtree' => 'src/Sub']]]]], [], [], 'complexity.ccn');
            $cli = $helper->take([], ['complexity.ccn:suppress-paths=subtree:src/Sub'], [], 'complexity.ccn');
            self::assertSame(Observation::ACCEPTED, $yaml['object']->outcome, $yaml['object']->text);
            self::assertSame(Observation::ACCEPTED, $cli['object']->outcome, $cli['object']->text);
            $yamlObject = json_decode($yaml['object']->text, true, 512, \JSON_THROW_ON_ERROR);
            $cliObject = json_decode($cli['object']->text, true, 512, \JSON_THROW_ON_ERROR);
            self::assertTrue($yamlObject['excluded']['path']);
            self::assertTrue($cliObject['excluded']['path']);

            $root = \dirname(__DIR__, 3);
            $original = Declarations::load($root);
            $hits = $original->axisAHits;
            $hits['yaml|suppress-paths|list'] = '[{subtree: src/Sub}]';
            $stand = new Stand($root, Ledger::load($root), self::declarationsWith($original, $hits, []), $helper, new ProcessProbe($root, $scratch));
            $hit = new ReflectionMethod(Stand::class, 'hitFor');
            $yamlRow = new FormRow('yaml', 'rules.complexity.ccn.suppress-paths', ['list'], 'unwritten', '', '', 'PROMISED', '', ['list']);
            $cliRow = new FormRow('rule-opt', 'rules.complexity.ccn.suppress-paths', ['list'], 'unwritten', '', '', 'PROMISED', '', ['list']);
            self::assertSame('[{subtree: src/Sub}]', $hit->invoke($stand, $yamlRow, 'suppress-paths', 'list'));
            self::assertSame('[src/Sub]', $hit->invoke($stand, $cliRow, 'suppress-paths', 'list'));
        } finally {
            (new Filesystem())->remove($scratch);
        }
    }

    #[Test]
    public function itDistinguishesOwnedCacheDirectoriesWithEqualFileCountsByName(): void
    {
        $scratch = sys_get_temp_dir() . '/qmx-cache-observation-' . bin2hex(random_bytes(6));
        mkdir($scratch, 0o775, true);
        $count = new ReflectionMethod(ProcessProbe::class, 'newDirectoryCounts');

        try {
            mkdir($scratch . '/first');
            file_put_contents($scratch . '/first/item', 'same');
            self::assertSame('first:files=1', $count->invoke(null, $scratch, []));
            (new Filesystem())->remove($scratch . '/first');
            mkdir($scratch . '/second');
            file_put_contents($scratch . '/second/item', 'same');
            self::assertSame('second:files=1', $count->invoke(null, $scratch, []));
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
            $selector = [['exact' => 'App']];

            if ($helper->nativeProfile() === 'old') {
                self::assertSame([$selector], $writes);
                $observations = $helper->take(['rules' => ['coupling.distance' => ['include-namespaces' => $selector]]], [], [], 'coupling.distance');
                self::assertSame(Observation::REFUSED_FRAMED, $observations['object']->outcome);
                self::assertSame(
                    'Option "includeNamespaces" of rule "coupling.distance" must be a string or a list of strings or null, got a list.',
                    $observations['object']->text,
                );
            } else {
                self::assertSame([$selector], $writes);
                $observations = $helper->take(['rules' => ['coupling.distance' => ['include-namespaces' => $writes[0]]]], [], [], '');
                self::assertSame(Observation::ACCEPTED, $observations['merged']->outcome, $observations['merged']->text);
                $values = json_decode($observations['merged']->text, true, 512, \JSON_THROW_ON_ERROR);
                self::assertSame($selector, $values['rules']['coupling.distance']['include-namespaces']);

                $fixture = $scratch . '/promise-effect';
                $files = new Filesystem();
                $files->mkdir($fixture);

                foreach ([
                    'axis-a-hits.tsv',
                    'axis-d-envelopes.tsv',
                    'composition-magnitudes.tsv',
                    'effect-magnitudes.tsv',
                    'forms.tsv',
                    'pair-kind-scope.tsv',
                    'witness-envelopes.tsv',
                ] as $name) {
                    $files->copy($root . '/promise-effect/' . $name, $fixture . '/' . $name);
                }

                file_put_contents(
                    $fixture . '/effect-magnitudes.tsv',
                    "\noption_leaf\tmax-distance-warning\t[App]\tA list is not a numeric magnitude\n",
                    \FILE_APPEND,
                );
                $wrong = Declarations::load($scratch);
                $strict = new Stand($root, Ledger::load($root), $wrong, $helper, new ProcessProbe($root, $scratch));

                try {
                    $supply->invoke($strict, 'coupling.distance', 'max-distance-warning');
                    self::fail('The typed declaration accepted a list for a numeric option.');
                } catch (LedgerError $error) {
                    self::assertStringContainsString(
                        'effect-magnitudes.tsv declares "[App]" for the leaf "max-distance-warning"',
                        $error->getMessage(),
                    );
                }
            }
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
    /**
     * @param array<string, string> $hits
     * @param array<string, array<string, mixed>> $contexts
     */
    private static function declarationsWith(Declarations $original, array $hits, array $contexts): Declarations
    {
        $reflection = new ReflectionClass(Declarations::class);
        $declarations = $reflection->newInstanceWithoutConstructor();
        (new ReflectionMethod(Declarations::class, '__construct'))->invoke(
            $declarations,
            $original->forms,
            $original->envelopes,
            $original->witnessEnvelopes,
            $hits,
            $contexts,
            $original->pairScopes,
            $original->magnitudes,
            $original->formAlternates,
            $original->leafAlternates,
            $original->sideBLiterals,
        );

        return $declarations;
    }
}

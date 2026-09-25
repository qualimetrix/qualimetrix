<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\Gate;
use QmxFindingGate\GateError;
use QmxFindingGate\GateReport;
use QmxFindingGate\Options;
use QmxFindingGate\SyntheticTree;

/**
 * What the gate refuses before it runs anything, on a synthetic tree.
 */
final class GateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itRefusesACaseWhoseOutcomeNoRegisteredCheckVerifies(): void
    {
        $tree = SyntheticTree::clean();
        $tree['declarations']['cases/alpha/case.json'] = json_encode([
            'id' => 'alpha',
            'description' => 'A case that is expected to be refused.',
            'paths' => ['src'],
            'config' => 'qmx.yaml',
            'channels' => ['replay.alpha@callable'],
            'outcome' => ['kind' => 'refusal', 'exit' => 3],
        ], \JSON_THROW_ON_ERROR);
        $root = SyntheticTree::create($tree);

        try {
            new Gate(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD'], $root), new GateReport());
            self::fail('The gate accepted a case no check holds to its outcome.');
        } catch (GateError $error) {
            self::assertStringContainsString('no registered check ("refusal")', $error->getMessage());
        } finally {
            SyntheticTree::remove($root);
        }
    }
}

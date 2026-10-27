<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Cohesion\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Cohesion\CohesionFactWire;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomClassData;

#[CoversClass(LcomClassData::class)]
final class LcomClassDataTest extends TestCase
{
    #[Test]
    public function itCalculatesLcomWithExcludedMethods(): void
    {
        $classData = new LcomClassData(namespace: 'App', className: 'Service');

        // analyze and validate share $data
        $classData->addMethod('analyze');
        $classData->addPropertyAccess('analyze', 'data');

        $classData->addMethod('validate');
        $classData->addPropertyAccess('validate', 'data');

        // getName and getDescription are disconnected (stateless constants)
        $classData->addMethod('getName');
        $classData->markStatelessConstant('getName');

        $classData->addMethod('getDescription');
        $classData->markStatelessConstant('getDescription');

        // Without exclusion: analyze+validate share $data (1 component),
        // getName+getDescription merged into virtual stateless node (1 component) = LCOM 2
        self::assertSame(2, $classData->calculateLcom([], 0));

        // With excludeMethods=['getName', 'getDescription']:
        // only analyze+validate remain, they share $data = LCOM 1
        self::assertSame(1, $classData->calculateLcom(['getName', 'getDescription'], 0));
    }

    #[Test]
    public function itPreservesExactFactRowsAcrossTheCollectionWire(): void
    {
        $data = new LcomClassData('App', 'Service', 7, 19);
        $data->addMethod('work');
        $data->addMethod('metadata');
        $data->markStatic('metadata');
        $data->markStatelessConstant('metadata');
        $data->addPropertyAccess('work', 'state');
        $data->addMethodCall('work', 'metadata');

        $rows = [
            ['tag' => 'declaration', 'namespace' => 'App', 'name' => 'Service', 'line' => 7, 'position' => 19, 'rowCount' => 8],
            ['tag' => 'method', 'name' => 'work', 'static' => false, 'stateless' => false],
            ['tag' => 'method', 'name' => 'metadata', 'static' => true, 'stateless' => true],
            ['tag' => 'declaredMethod', 'name' => 'work', 'visibility' => 'public', 'abstract' => false],
            ['tag' => 'declaredMethod', 'name' => 'metadata', 'visibility' => 'public', 'abstract' => false],
            ['tag' => 'instancePropertyCount', 'count' => 0],
            ['tag' => 'property', 'method' => 'work', 'name' => 'state'],
            ['tag' => 'call', 'caller' => 'work', 'callee' => 'metadata'],
        ];

        self::assertSame($rows, CohesionFactWire::lcomRows($data));
        self::assertSame($rows, CohesionFactWire::lcomRows(CohesionFactWire::lcomFromRows($rows)));
    }

    #[Test]
    public function itKeepsDirectAndDeferredFactsApartAcrossTheCollectionWire(): void
    {
        $data = new LcomClassData('App', 'Service', 3, 5);
        $data->addMethod('__construct');
        $data->addMethodCall('__construct', 'handle');
        $data->addDeferredMethodCall('__construct', 'Handle');
        $data->addPropertyAccess('__construct', 'state');
        $data->addDeferredPropertyAccess('__construct', 'state');

        $rows = CohesionFactWire::lcomRows($data);
        $decoded = CohesionFactWire::lcomFromRows(unserialize(serialize($rows)));

        self::assertContains(['tag' => 'call', 'caller' => '__construct', 'callee' => 'handle'], $rows);
        self::assertContains(['tag' => 'deferredCall', 'caller' => '__construct', 'callee' => 'handle'], $rows);
        self::assertContains(['tag' => 'property', 'method' => '__construct', 'name' => 'state'], $rows);
        self::assertContains(['tag' => 'deferredProperty', 'method' => '__construct', 'name' => 'state'], $rows);
        self::assertSame($rows, CohesionFactWire::lcomRows($decoded));
        self::assertSame(['__construct' => ['handle' => true]], $decoded->factState()['methodCalls']);
        self::assertSame(['__construct' => ['handle' => true]], $decoded->factState()['deferredMethodCalls']);
        self::assertSame(['handle'], $decoded->callsFrom('__construct'));
        self::assertSame(['__construct' => ['state' => true]], $decoded->factState()['deferredPropertyAccesses']);
        self::assertSame(['state'], $decoded->getPropertiesAccessedBy('__construct'));
    }

    #[Test]
    public function itRemovesInitializationHelpersBeforeMergingStatelessMethods(): void
    {
        $data = new LcomClassData('App', 'Service');
        foreach (['__construct' => 'public', 'defaults' => 'private', 'read' => 'public', 'write' => 'public'] as $method => $visibility) {
            $data->declaration()->declareMethod($method, $visibility, false);
            $data->addMethod($method);
        }
        $data->markStatelessConstant('defaults');
        $data->addMethodCall('__construct', 'defaults');
        $data->addPropertyAccess('read', 'state');
        $data->addPropertyAccess('write', 'state');

        // A phantom virtual stateless node would make this 2 components over 3 counted methods.
        self::assertSame(1, $data->calculateLcom([], 0));
        self::assertSame(2, $data->lcomMethodCount([], 0));
    }

    #[Test]
    public function itMatchesConfiguredMethodExclusionsWithoutCase(): void
    {
        $data = new LcomClassData();
        foreach (['work', 'bridge', 'label'] as $method) {
            $data->addMethod($method);
            $data->addPropertyAccess($method, $method);
        }

        self::assertSame(3, $data->calculateLcom([], 0));
        self::assertSame(1, $data->calculateLcom(['BRIDGE', 'Bridge', 'LABEL'], 0));
        self::assertSame(['work', 'bridge', 'label'], $data->getMethods());
    }

    #[Test]
    public function itCalculatesLcomWhenExcludingNonexistentMethod(): void
    {
        $classData = new LcomClassData(namespace: 'App', className: 'Service');

        $classData->addMethod('methodA');
        $classData->addPropertyAccess('methodA', 'propA');

        $classData->addMethod('methodB');
        $classData->addPropertyAccess('methodB', 'propB');

        // Without exclusion: LCOM 2
        self::assertSame(2, $classData->calculateLcom([], 0));

        // Excluding a non-existent method: still LCOM 2
        self::assertSame(2, $classData->calculateLcom(['nonExistentMethod'], 0));
    }

    #[Test]
    public function itCalculatesLcomWhenExcludingAllMethods(): void
    {
        $classData = new LcomClassData(namespace: 'App', className: 'Service');

        $classData->addMethod('methodA');
        $classData->addPropertyAccess('methodA', 'propA');

        $classData->addMethod('methodB');
        $classData->addPropertyAccess('methodB', 'propB');

        // Excluding all methods: LCOM 0 (no methods in graph)
        self::assertSame(0, $classData->calculateLcom(['methodA', 'methodB'], 0));
    }
}

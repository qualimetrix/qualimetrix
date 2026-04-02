<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Structure;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Metrics\Structure\LcomClassData;

#[CoversClass(LcomClassData::class)]
final class LcomClassDataTest extends TestCase
{
    #[Test]
    public function testCalculateLcomWithExcludedMethods(): void
    {
        $classData = new LcomClassData(namespace: 'App', className: 'Service');

        // analyze and validate share $data
        $classData->addMethod('analyze');
        $classData->addPropertyAccess('analyze', 'data');
        $classData->markNonTrivial();

        $classData->addMethod('validate');
        $classData->addPropertyAccess('validate', 'data');

        // getName and getDescription are disconnected (stateless constants)
        $classData->addMethod('getName');
        $classData->markStatelessConstant('getName');

        $classData->addMethod('getDescription');
        $classData->markStatelessConstant('getDescription');

        // Without exclusion: analyze+validate share $data (1 component),
        // getName+getDescription merged into virtual stateless node (1 component) = LCOM 2
        self::assertSame(2, $classData->calculateLcom());

        // With excludeMethods=['getName', 'getDescription']:
        // only analyze+validate remain, they share $data = LCOM 1
        self::assertSame(1, $classData->calculateLcom(['getName', 'getDescription']));
    }

    #[Test]
    public function testCalculateLcomExcludeNonexistentMethod(): void
    {
        $classData = new LcomClassData(namespace: 'App', className: 'Service');

        $classData->addMethod('methodA');
        $classData->addPropertyAccess('methodA', 'propA');
        $classData->markNonTrivial();

        $classData->addMethod('methodB');
        $classData->addPropertyAccess('methodB', 'propB');

        // Without exclusion: LCOM 2
        self::assertSame(2, $classData->calculateLcom());

        // Excluding a non-existent method: still LCOM 2
        self::assertSame(2, $classData->calculateLcom(['nonExistentMethod']));
    }

    #[Test]
    public function testCalculateLcomExcludeAllMethods(): void
    {
        $classData = new LcomClassData(namespace: 'App', className: 'Service');

        $classData->addMethod('methodA');
        $classData->addPropertyAccess('methodA', 'propA');
        $classData->markNonTrivial();

        $classData->addMethod('methodB');
        $classData->addPropertyAccess('methodB', 'propB');

        // Excluding all methods: LCOM 0 (no methods in graph)
        self::assertSame(0, $classData->calculateLcom(['methodA', 'methodB']));
    }
}

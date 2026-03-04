<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Structure;

use AiMessDetector\Metrics\Structure\TccLccClassData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TccLccClassData::class)]
final class TccLccClassDataTest extends TestCase
{
    public function testNoMethods(): void
    {
        $data = new TccLccClassData();

        // No methods = perfect cohesion by definition
        self::assertSame(1.0, $data->calculateTcc());
        self::assertSame(1.0, $data->calculateLcc());
    }

    public function testSingleMethod(): void
    {
        $data = new TccLccClassData();
        $data->addMethod('method1');
        $data->addPropertyAccess('method1', 'prop1');

        // Single method = perfect cohesion by definition
        self::assertSame(1.0, $data->calculateTcc());
        self::assertSame(1.0, $data->calculateLcc());
    }

    public function testPerfectCohesion(): void
    {
        $data = new TccLccClassData();
        $data->addMethod('method1');
        $data->addMethod('method2');
        $data->addPropertyAccess('method1', 'sharedProp');
        $data->addPropertyAccess('method2', 'sharedProp');

        // Both methods share a property
        // NP = 2 * 1 / 2 = 1
        // NDC = 1 (method1-method2 connected)
        // TCC = 1/1 = 1.0
        self::assertSame(1.0, $data->calculateTcc());
        self::assertSame(1.0, $data->calculateLcc());
    }

    public function testNoCohesion(): void
    {
        $data = new TccLccClassData();
        $data->addMethod('method1');
        $data->addMethod('method2');
        $data->addPropertyAccess('method1', 'prop1');
        $data->addPropertyAccess('method2', 'prop2');

        // Methods use different properties
        // NP = 1, NDC = 0
        // TCC = 0/1 = 0.0
        self::assertSame(0.0, $data->calculateTcc());
        self::assertSame(0.0, $data->calculateLcc());
    }

    public function testPartialCohesionThreeMethods(): void
    {
        // 3 methods: m1-m2 share prop 'a', m2-m3 share prop 'b', m1-m3 don't share
        $data = new TccLccClassData();
        $data->addMethod('m1');
        $data->addMethod('m2');
        $data->addMethod('m3');
        $data->addPropertyAccess('m1', 'a');
        $data->addPropertyAccess('m2', 'a');
        $data->addPropertyAccess('m2', 'b');
        $data->addPropertyAccess('m3', 'b');

        // TCC: Direct connections
        // NP = 3 * 2 / 2 = 3
        // NDC = 2 (m1-m2 via 'a', m2-m3 via 'b')
        // TCC = 2/3 ≈ 0.667
        self::assertEqualsWithDelta(0.667, $data->calculateTcc(), 0.01);

        // LCC: m1 reaches m3 through m2 (transitive)
        // NIC = 3 (all pairs connected)
        // LCC = 3/3 = 1.0
        self::assertSame(1.0, $data->calculateLcc());
    }

    public function testPartialCohesionFourMethods(): void
    {
        // 4 methods: m1-m2 connected, m3-m4 connected, but two groups disconnected
        $data = new TccLccClassData();
        $data->addMethod('m1');
        $data->addMethod('m2');
        $data->addMethod('m3');
        $data->addMethod('m4');
        $data->addPropertyAccess('m1', 'propA');
        $data->addPropertyAccess('m2', 'propA');
        $data->addPropertyAccess('m3', 'propB');
        $data->addPropertyAccess('m4', 'propB');

        // NP = 4 * 3 / 2 = 6
        // NDC = 2 (m1-m2, m3-m4)
        // TCC = 2/6 ≈ 0.333
        self::assertEqualsWithDelta(0.333, $data->calculateTcc(), 0.01);

        // LCC: Same as TCC (no transitive connections between groups)
        // NIC = 2
        // LCC = 2/6 ≈ 0.333
        self::assertEqualsWithDelta(0.333, $data->calculateLcc(), 0.01);
    }

    public function testComplexTransitiveClosure(): void
    {
        // 5 methods in a chain: m1-m2-m3-m4-m5
        $data = new TccLccClassData();
        $data->addMethod('m1');
        $data->addMethod('m2');
        $data->addMethod('m3');
        $data->addMethod('m4');
        $data->addMethod('m5');
        // m1-m2 via prop1
        $data->addPropertyAccess('m1', 'prop1');
        $data->addPropertyAccess('m2', 'prop1');
        // m2-m3 via prop2
        $data->addPropertyAccess('m2', 'prop2');
        $data->addPropertyAccess('m3', 'prop2');
        // m3-m4 via prop3
        $data->addPropertyAccess('m3', 'prop3');
        $data->addPropertyAccess('m4', 'prop3');
        // m4-m5 via prop4
        $data->addPropertyAccess('m4', 'prop4');
        $data->addPropertyAccess('m5', 'prop4');

        // NP = 5 * 4 / 2 = 10
        // TCC: Direct connections = 4 (m1-m2, m2-m3, m3-m4, m4-m5)
        // TCC = 4/10 = 0.4
        self::assertSame(0.4, $data->calculateTcc());

        // LCC: All methods are transitively connected via the chain
        // NIC = 10 (all pairs)
        // LCC = 10/10 = 1.0
        self::assertSame(1.0, $data->calculateLcc());
    }

    public function testMethodsWithNoPropertyAccess(): void
    {
        $data = new TccLccClassData();
        $data->addMethod('method1');
        $data->addMethod('method2');
        // No property accesses

        // Methods don't share properties
        // TCC = 0.0
        self::assertSame(0.0, $data->calculateTcc());
        self::assertSame(0.0, $data->calculateLcc());
    }

    public function testMethodAccessingMultipleProperties(): void
    {
        $data = new TccLccClassData();
        $data->addMethod('m1');
        $data->addMethod('m2');
        $data->addMethod('m3');
        $data->addPropertyAccess('m1', 'prop1');
        $data->addPropertyAccess('m1', 'prop2');
        $data->addPropertyAccess('m1', 'prop3');
        $data->addPropertyAccess('m2', 'prop2');
        $data->addPropertyAccess('m3', 'prop3');

        // m1-m2 share prop2, m1-m3 share prop3, m2-m3 don't share
        // NP = 3, NDC = 2
        // TCC = 2/3 ≈ 0.667
        self::assertEqualsWithDelta(0.667, $data->calculateTcc(), 0.01);

        // m2 reaches m3 through m1
        // LCC = 3/3 = 1.0
        self::assertSame(1.0, $data->calculateLcc());
    }

    public function testGetMethods(): void
    {
        $data = new TccLccClassData();
        $data->addMethod('methodA');
        $data->addMethod('methodB');

        $methods = $data->getMethods();
        self::assertCount(2, $methods);
        self::assertContains('methodA', $methods);
        self::assertContains('methodB', $methods);
    }

    public function testGetPropertiesAccessedBy(): void
    {
        $data = new TccLccClassData();
        $data->addMethod('method1');
        $data->addPropertyAccess('method1', 'prop1');
        $data->addPropertyAccess('method1', 'prop2');

        $props = $data->getPropertiesAccessedBy('method1');
        self::assertCount(2, $props);
        self::assertContains('prop1', $props);
        self::assertContains('prop2', $props);
    }

    public function testGetPropertiesAccessedByNonexistentMethod(): void
    {
        $data = new TccLccClassData();

        $props = $data->getPropertiesAccessedBy('nonexistent');
        self::assertEmpty($props);
    }

    public function testRealWorldRectangleExample(): void
    {
        // Rectangle class: all methods use width and/or height
        $data = new TccLccClassData();
        $data->addMethod('getWidth');
        $data->addMethod('getHeight');
        $data->addMethod('getArea');
        $data->addMethod('getPerimeter');

        $data->addPropertyAccess('getWidth', 'width');
        $data->addPropertyAccess('getHeight', 'height');
        $data->addPropertyAccess('getArea', 'width');
        $data->addPropertyAccess('getArea', 'height');
        $data->addPropertyAccess('getPerimeter', 'width');
        $data->addPropertyAccess('getPerimeter', 'height');

        // All pairs share at least one property:
        // getWidth-getHeight: none (0)
        // getWidth-getArea: width (1)
        // getWidth-getPerimeter: width (1)
        // getHeight-getArea: height (1)
        // getHeight-getPerimeter: height (1)
        // getArea-getPerimeter: width, height (1)
        // NP = 6, NDC = 5
        // TCC = 5/6 ≈ 0.833
        self::assertEqualsWithDelta(0.833, $data->calculateTcc(), 0.01);

        // All reachable except getWidth-getHeight
        // But getWidth reaches getHeight via getArea or getPerimeter
        // LCC = 6/6 = 1.0
        self::assertSame(1.0, $data->calculateLcc());
    }

    public function testRealWorldGodClassExample(): void
    {
        // God class: methods use completely different properties
        $data = new TccLccClassData();
        $data->addMethod('findUser');
        $data->addMethod('createOrder');
        $data->addMethod('processPayment');

        $data->addPropertyAccess('findUser', 'users');
        $data->addPropertyAccess('createOrder', 'orders');
        $data->addPropertyAccess('processPayment', 'payments');

        // No shared properties
        // TCC = 0/3 = 0.0
        self::assertSame(0.0, $data->calculateTcc());
        self::assertSame(0.0, $data->calculateLcc());
    }
}

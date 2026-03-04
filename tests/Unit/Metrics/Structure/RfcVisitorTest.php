<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Structure;

use AiMessDetector\Metrics\Structure\RfcVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RfcVisitor::class)]
final class RfcVisitorTest extends TestCase
{
    /**
     * @param array<string, array{rfc: int, own: int, external: int}> $expected
     */
    #[DataProvider('provideRfcCases')]
    public function testRfcCalculation(string $code, array $expected): void
    {
        $visitor = new RfcVisitor();
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $classesData = $visitor->getClassesData();

        foreach ($expected as $classFqn => $expectedData) {
            self::assertArrayHasKey($classFqn, $classesData, "Missing data for class $classFqn");

            $data = $classesData[$classFqn];
            self::assertSame(
                $expectedData['rfc'],
                $data->getRfc(),
                "RFC mismatch for $classFqn",
            );
            self::assertSame(
                $expectedData['own'],
                $data->getOwnMethodsCount(),
                "Own methods count mismatch for $classFqn",
            );
            self::assertSame(
                $expectedData['external'],
                $data->getExternalMethodsCount(),
                "External methods count mismatch for $classFqn",
            );
        }
    }

    /**
     * @return iterable<string, array{code: string, expected: array<string, array{rfc: int, own: int, external: int}>}>
     */
    public static function provideRfcCases(): iterable
    {
        // Empty class
        yield 'empty class' => [
            'code' => <<<'PHP'
<?php
class EmptyClass {}
PHP,
            'expected' => [
                'EmptyClass' => ['rfc' => 0, 'own' => 0, 'external' => 0],
            ],
        ];

        // Simple class with only own methods, no external calls
        yield 'simple calculator' => [
            'code' => <<<'PHP'
<?php
class Calculator
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public function subtract(int $a, int $b): int
    {
        return $a - $b;
    }
}
PHP,
            'expected' => [
                'Calculator' => ['rfc' => 2, 'own' => 2, 'external' => 0],
            ],
        ];

        // Class with external method calls
        yield 'order service with external calls' => [
            'code' => <<<'PHP'
<?php
class OrderService
{
    public function createOrder(int $userId): void
    {
        $user = $this->userRepo->find($userId);          // +1 external
        $order = $this->orderFactory->create($user);     // +1 external
    }

    public function processPayment(): void
    {
        $this->paymentGateway->charge();                 // +1 external
    }
}
// M = 2, R = 3, RFC = 5
PHP,
            'expected' => [
                'OrderService' => ['rfc' => 5, 'own' => 2, 'external' => 3],
            ],
        ];

        // Duplicate external calls should be counted once
        yield 'duplicate external calls' => [
            'code' => <<<'PHP'
<?php
class Logger
{
    public function logInfo(string $message): void
    {
        $this->writer->write('INFO: ' . $message);       // +1 external
    }

    public function logError(string $message): void
    {
        $this->writer->write('ERROR: ' . $message);      // Same method, should not add +1
    }
}
// M = 2, R = 1 (write counted once), RFC = 3
PHP,
            'expected' => [
                'Logger' => ['rfc' => 3, 'own' => 2, 'external' => 1],
            ],
        ];

        // Internal calls ($this->method()) should not count as external
        yield 'internal method calls' => [
            'code' => <<<'PHP'
<?php
class InternalCalls
{
    public function publicMethod(): void
    {
        $this->privateHelper();      // Internal call, not counted
    }

    private function privateHelper(): void
    {
        // ...
    }
}
// M = 2, R = 0, RFC = 2
PHP,
            'expected' => [
                'InternalCalls' => ['rfc' => 2, 'own' => 2, 'external' => 0],
            ],
        ];

        // Static calls should be counted
        yield 'static calls' => [
            'code' => <<<'PHP'
<?php
class StaticCalls
{
    public function doSomething(): void
    {
        Factory::create();           // +1 external
        Cache::get('key');           // +1 external
    }
}
// M = 1, R = 2, RFC = 3
PHP,
            'expected' => [
                'StaticCalls' => ['rfc' => 3, 'own' => 1, 'external' => 2],
            ],
        ];

        // self::, static::, parent:: should not be counted as external
        yield 'internal static calls' => [
            'code' => <<<'PHP'
<?php
class InternalStatic
{
    public function method(): void
    {
        self::helper();              // Internal, not counted
        static::anotherHelper();     // Internal, not counted
    }

    private static function helper(): void {}
    private static function anotherHelper(): void {}
}
// M = 3, R = 0, RFC = 3
PHP,
            'expected' => [
                'InternalStatic' => ['rfc' => 3, 'own' => 3, 'external' => 0],
            ],
        ];

        // Global function calls should be counted
        yield 'global function calls' => [
            'code' => <<<'PHP'
<?php
class GlobalFunctions
{
    public function process(array $data): array
    {
        array_map(fn($x) => $x * 2, $data);    // +1 external
        strlen('test');                         // +1 external
        return $data;
    }
}
// M = 1, R = 2, RFC = 3
PHP,
            'expected' => [
                'GlobalFunctions' => ['rfc' => 3, 'own' => 1, 'external' => 2],
            ],
        ];

        // Constructor calls (new SomeClass())
        yield 'constructor calls' => [
            'code' => <<<'PHP'
<?php
class Factory
{
    public function createUser(): void
    {
        $user = new User();              // +1 external
        $order = new Order();            // +1 external
    }
}
// M = 1, R = 2, RFC = 3
PHP,
            'expected' => [
                'Factory' => ['rfc' => 3, 'own' => 1, 'external' => 2],
            ],
        ];

        // Abstract methods should not be counted
        yield 'abstract methods' => [
            'code' => <<<'PHP'
<?php
abstract class AbstractClass
{
    public function concreteMethod(): void
    {
        $this->logger->log('test');      // +1 external
    }

    abstract public function abstractMethod(): void;
}
// M = 1 (only concrete), R = 1, RFC = 2
PHP,
            'expected' => [
                'AbstractClass' => ['rfc' => 2, 'own' => 1, 'external' => 1],
            ],
        ];

        // Interface should have RFC = 0
        yield 'interface' => [
            'code' => <<<'PHP'
<?php
interface UserInterface
{
    public function getName(): string;
    public function getEmail(): string;
}
// M = 0 (interfaces don't have implementations), R = 0, RFC = 0
PHP,
            'expected' => [
                'UserInterface' => ['rfc' => 0, 'own' => 0, 'external' => 0],
            ],
        ];

        // Multiple classes in one file
        yield 'multiple classes' => [
            'code' => <<<'PHP'
<?php
namespace App;

class First
{
    public function method1(): void
    {
        Logger::log('test');         // +1 external
    }
}

class Second
{
    public function method1(): void
    {
        Cache::get('key');           // +1 external
    }

    public function method2(): void
    {
        Cache::set('key', 'value');  // +1 external
    }
}
PHP,
            'expected' => [
                'App\First' => ['rfc' => 2, 'own' => 1, 'external' => 1],
                'App\Second' => ['rfc' => 4, 'own' => 2, 'external' => 2],
            ],
        ];

        // Namespaced class
        yield 'namespaced class' => [
            'code' => <<<'PHP'
<?php
namespace App\Service;

class UserService
{
    public function getUser(int $id): void
    {
        $this->repository->find($id);    // +1 external
        Logger::info('User fetched');    // +1 external
    }
}
PHP,
            'expected' => [
                'App\Service\UserService' => ['rfc' => 3, 'own' => 1, 'external' => 2],
            ],
        ];

        // Anonymous classes should be ignored
        yield 'anonymous classes ignored' => [
            'code' => <<<'PHP'
<?php
class OuterClass
{
    public function createAnonymous()
    {
        return new class {
            public function complexMethod(): void
            {
                // This should not be tracked
                Logger::log('test');
                Cache::get('key');
            }
        };
    }

    public function normalMethod(): void
    {
        Database::query('SELECT * FROM users');  // +1 external
    }
}
// M = 2, R = 1 (only normalMethod's external call), RFC = 3
PHP,
            'expected' => [
                'OuterClass' => ['rfc' => 3, 'own' => 2, 'external' => 1],
            ],
        ];

        // Complex real-world example
        yield 'complex order processor' => [
            'code' => <<<'PHP'
<?php
namespace App\Service;

class OrderProcessor
{
    public function process($order): void
    {
        $user = $this->userRepo->find($order->userId);      // +1
        $this->validator->validate($order);                  // +1
        $items = $this->inventoryService->checkStock($order->items); // +1
        $this->priceCalculator->calculate($order);           // +1
        $this->discountService->apply($order, $user);        // +1
        $payment = $this->paymentGateway->process($order);   // +1
        $this->notificationService->notify($user, $order);   // +1
        $this->logger->info('Order processed');              // +1
        $this->eventBus->dispatch(new OrderCreated($order)); // +2 (dispatch + __construct)
    }
}
// M = 1, R = 10, RFC = 11
PHP,
            'expected' => [
                'App\Service\OrderProcessor' => ['rfc' => 11, 'own' => 1, 'external' => 10],
            ],
        ];
    }

    public function testReset(): void
    {
        $visitor = new RfcVisitor();
        $parser = (new ParserFactory())->createForHostVersion();

        $code1 = <<<'PHP'
<?php
class First
{
    public function method(): void
    {
        Logger::log('test');
    }
}
PHP;

        $ast1 = $parser->parse($code1) ?? [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast1);

        self::assertArrayHasKey('First', $visitor->getClassesData());

        // Reset
        $visitor->reset();

        $code2 = <<<'PHP'
<?php
class Second
{
    public function method(): void {}
}
PHP;

        $ast2 = $parser->parse($code2) ?? [];
        $traverser2 = new NodeTraverser();
        $traverser2->addVisitor($visitor);
        $traverser2->traverse($ast2);

        $classesData = $visitor->getClassesData();

        // Should only contain Second class
        self::assertArrayNotHasKey('First', $classesData);
        self::assertArrayHasKey('Second', $classesData);
    }
}

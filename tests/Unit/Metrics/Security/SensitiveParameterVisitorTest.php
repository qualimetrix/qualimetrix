<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Metrics\Security;

use AiMessDetector\Metrics\Security\SensitiveParameterVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SensitiveParameterVisitor::class)]
final class SensitiveParameterVisitorTest extends TestCase
{
    #[DataProvider('provideDetectionCases')]
    public function testDetection(string $code, int $expectedCount, ?string $expectedParamName = null): void
    {
        $visitor = new SensitiveParameterVisitor();

        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $locations = $visitor->getLocations();

        self::assertCount($expectedCount, $locations, \sprintf(
            'Expected %d finding(s), found %d for code: %s',
            $expectedCount,
            \count($locations),
            $code,
        ));

        if ($expectedParamName !== null && $locations !== []) {
            self::assertSame($expectedParamName, $locations[0]->paramName);
        }
    }

    /**
     * @return iterable<string, array{code: string, expectedCount: int, expectedParamName?: string}>
     */
    public static function provideDetectionCases(): iterable
    {
        // --- True positives ---

        yield 'function with $password' => [
            'code' => '<?php function login(string $password) {}',
            'expectedCount' => 1,
            'expectedParamName' => 'password',
        ];

        yield 'method with $secret' => [
            'code' => '<?php class A { public function auth(string $secret): void {} }',
            'expectedCount' => 1,
            'expectedParamName' => 'secret',
        ];

        yield 'method with $apiKey' => [
            'code' => '<?php class A { public function connect(string $apiKey): void {} }',
            'expectedCount' => 1,
            'expectedParamName' => 'apiKey',
        ];

        yield 'closure with $password' => [
            'code' => '<?php $fn = function(string $password) {};',
            'expectedCount' => 1,
            'expectedParamName' => 'password',
        ];

        yield 'arrow function with $secret' => [
            'code' => '<?php $fn = fn(string $secret) => $secret;',
            'expectedCount' => 1,
            'expectedParamName' => 'secret',
        ];

        yield 'promoted constructor property with $password' => [
            'code' => '<?php class Auth { public function __construct(private string $password) {} }',
            'expectedCount' => 1,
            'expectedParamName' => 'password',
        ];

        yield 'multiple sensitive params' => [
            'code' => '<?php function auth(string $password, string $apiKey) {}',
            'expectedCount' => 2,
        ];

        yield '$accessToken param' => [
            'code' => '<?php function call(string $accessToken) {}',
            'expectedCount' => 1,
            'expectedParamName' => 'accessToken',
        ];

        yield '$credentials param' => [
            'code' => '<?php function login(array $credentials) {}',
            'expectedCount' => 1,
            'expectedParamName' => 'credentials',
        ];

        // --- True negatives (has attribute) ---

        yield 'with SensitiveParameter attribute' => [
            'code' => '<?php function login(#[\SensitiveParameter] string $password) {}',
            'expectedCount' => 0,
        ];

        yield 'with SensitiveParameter attribute unqualified' => [
            'code' => <<<'PHP'
<?php
use SensitiveParameter;
function login(#[SensitiveParameter] string $password) {}
PHP,
            'expectedCount' => 0,
        ];

        yield 'promoted property with attribute' => [
            'code' => '<?php class Auth { public function __construct(#[\SensitiveParameter] private string $password) {} }',
            'expectedCount' => 0,
        ];

        // --- True negatives (not sensitive) ---

        yield 'normal parameter' => [
            'code' => '<?php function foo(string $name) {}',
            'expectedCount' => 0,
        ];

        yield 'passwordField is not sensitive (suffix blacklist)' => [
            'code' => '<?php function foo(string $passwordField) {}',
            'expectedCount' => 0,
        ];

        yield 'isPassword is not sensitive (prefix blacklist)' => [
            'code' => '<?php function foo(bool $isPassword) {}',
            'expectedCount' => 0,
        ];

        yield 'function with no params' => [
            'code' => '<?php function foo() {}',
            'expectedCount' => 0,
        ];
    }

    public function testResetClearsState(): void
    {
        $visitor = new SensitiveParameterVisitor();

        $code = '<?php function login(string $password) {}';
        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        self::assertCount(1, $visitor->getLocations());

        $visitor->reset();
        self::assertCount(0, $visitor->getLocations());
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Security\Unit;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Security\Credential\CredentialLiterals;
use Qualimetrix\Analysis\Evidence\Security\SensitiveNameMatcher;

#[CoversClass(CredentialLiterals::class)]
final class CredentialLiteralsTest extends TestCase
{
    #[Test]
    #[DataProvider('provideShapes')]
    public function itClassifiesEverySupportedLiteralShape(string $code, string $pattern): void
    {
        $locations = [];
        foreach ($this->nodes($code) as $node) {
            array_push($locations, ...(new CredentialLiterals(new SensitiveNameMatcher(), 4))->locations($node, 'file'));
        }

        self::assertSame([$pattern], array_column($locations, 'pattern'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideShapes(): iterable
    {
        yield 'assignment' => ['<?php $password = "secret123";', 'variable'];
        yield 'array key' => ['<?php $a = ["password" => "secret123"];', 'array_key'];
        yield 'class constant' => ['<?php class C { const PASSWORD = "secret123"; }', 'class_const'];
        yield 'define' => ['<?php define("PASSWORD", "secret123");', 'define'];
        yield 'property default' => ['<?php class C { public string $password = "secret123"; }', 'property'];
        yield 'parameter default' => ['<?php function f(string $password = "secret123") {}', 'parameter'];
        yield 'enum case' => ['<?php enum C: string { case PASSWORD = "secret123"; }', 'enum_case'];
    }

    #[Test]
    public function itRejectsNonStringAndMalformedDefineValues(): void
    {
        $locations = [];
        foreach ($this->nodes('<?php $password = getenv("PASSWORD"); define("PASSWORD");') as $node) {
            array_push($locations, ...(new CredentialLiterals(new SensitiveNameMatcher(), 4))->locations($node, 'file'));
        }

        self::assertSame([], $locations);
    }

    #[Test]
    #[DataProvider('provideSafeCredentialLikeValues')]
    public function itRejectsEveryNamedSensitiveValueExclusion(string $code): void
    {
        $locations = [];
        foreach ($this->nodes($code) as $node) {
            array_push($locations, ...(new CredentialLiterals(new SensitiveNameMatcher(), 4))->locations($node, 'file'));
        }

        self::assertSame([], $locations);
    }

    /** @return iterable<string, array{string}> */
    public static function provideSafeCredentialLikeValues(): iterable
    {
        yield 'non-sensitive name' => ['<?php $username = "secret123";'];
        yield 'empty string' => ['<?php $password = "";'];
        yield 'short string' => ['<?php $password = "abc";'];
        yield 'repeated characters' => ['<?php $password = "****";'];
        yield 'dot identifier' => ['<?php $password = "config.database.password";'];
        yield 'human message' => ['<?php $password = "The provided password is incorrect and must be changed.";'];
        yield 'non-string value' => ['<?php $password = getenv("PASSWORD");'];
        yield 'malformed define' => ['<?php define("PASSWORD");'];
        yield 'password hash' => ['<?php $passwordHash = "abc123def";'];
        yield 'token storage' => ['<?php $tokenStorage = "memory";'];
        yield 'cache key' => ['<?php $cacheKey = "users:list";'];
        yield 'bare token' => ['<?php $token = "abc123def";'];
        yield 'bare key' => ['<?php $key = "abc123def";'];
        yield 'option password constant' => ['<?php class Config { const OPTION_PASSWORD = "password"; }'];
    }

    #[Test]
    public function itTreatsFirstClassCallableCapturesAsNonInvocations(): void
    {
        $locations = [];
        foreach ($this->nodes('<?php $length = strlen(...); $type = is_string(...); $constant = define(...);') as $node) {
            array_push($locations, ...(new CredentialLiterals(new SensitiveNameMatcher(), 4))->locations($node, 'file'));
        }

        self::assertSame([], $locations);
    }

    /** @return list<Node> */
    private function nodes(string $code): array
    {
        return array_values((new NodeFinder())->findInstanceOf((new ParserFactory())->createForHostVersion()->parse($code) ?? [], Node::class));
    }
}

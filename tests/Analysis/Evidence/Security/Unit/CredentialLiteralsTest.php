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
use Qualimetrix\Analysis\Evidence\Security\Credential\CredentialValue;
use Qualimetrix\Analysis\Evidence\Security\SensitiveNameMatcher;

#[CoversClass(CredentialLiterals::class)]
#[CoversClass(CredentialValue::class)]
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
        yield 'property assignment' => ['<?php $this->apiKey = "sk0live0AAAABBBBCCCC";', 'property_assignment'];
        yield 'nullsafe-free property assignment on another object' => ['<?php $client->secret = "sk0live0AAAABBBBCCCC";', 'property_assignment'];
        yield 'static property assignment' => ['<?php self::$secret = "sk0live0AAAABBBBCCCC";', 'property_assignment'];
        yield 'array element assignment' => ['<?php $config["password"] = "sk0live0AAAABBBBCCCC";', 'array_key'];
        yield 'coalescing assignment' => ['<?php $this->password ??= "sk0live0AAAABBBBCCCC";', 'property_assignment'];
        yield 'fused name' => ['<?php $apikey = "sk0live0AAAABBBBCCCC";', 'variable'];
        yield 'uuid-shaped secret' => ['<?php $apiKey = "a1b2c3d4-e5f6-7890-abcd-ef1234567890";', 'variable'];
        yield 'slash-separated secret' => ['<?php $secret = "AKIA/IOSFODNN7/EXAMPLE/wJalrXUtnFEMI";', 'variable'];
        yield 'plus-separated secret' => ['<?php $accessKey = "aaaa+bbbb+cccc+dddd+eeee+ffff";', 'variable'];
        yield 'hyphenated key without dots' => ['<?php $apiKey = "sk-live-abc123def456";', 'variable'];
        yield 'dotted key with a segment that is not an identifier' => ['<?php $secret = "k-9f.2b-7c.x1";', 'variable'];
        yield 'jwt' => ['<?php $authToken = "eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U";', 'variable'];
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
        yield 'hyphenated human message' => ['<?php $password = "Invalid-token: please re-enter your password.";'];
        yield 'dot identifier with a word per segment' => ['<?php $password = "auth.passwordReset.subject";'];
        yield 'dot identifier with a hyphenated segment' => ['<?php $password = "auth.password-reset";'];
        yield 'short dot identifier with a hyphen' => ['<?php $secret = "a.b-c";'];
        yield 'channel name constant' => ['<?php class MetricName { const SECURITY_HARDCODED_CREDENTIALS = "security.hardcoded-credentials"; }'];
        yield 'property assignment of a non-sensitive name' => ['<?php $this->username = "sk0live0AAAABBBBCCCC";'];
        yield 'array element assignment with a variable key' => ['<?php $config[$field] = "sk0live0AAAABBBBCCCC";'];
        yield 'property assignment with a dynamic name' => ['<?php $this->{$name} = "sk0live0AAAABBBBCCCC";'];
        yield 'property assignment of a non-literal' => ['<?php $this->password = getenv("PASSWORD");'];
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

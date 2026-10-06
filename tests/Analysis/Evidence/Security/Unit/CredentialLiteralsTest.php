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
        yield 'dotted password with a capitalised hyphenated segment' => ['<?php $adminPassword = "Summer-2024.Pass";', 'variable'];
        yield 'dotted password mixing case across a hyphen' => ['<?php $dbPassword = "my-Secret.Pass";', 'variable'];
        yield 'dotted key of hyphen-joined alphanumeric groups' => ['<?php $apiKey = "sk-live.abc123-def456.ghi789-jkl012";', 'variable'];
        yield 'jwt' => ['<?php $authToken = "eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U";', 'variable'];
        yield 'capitalized dotted value' => ['<?php $secret = "App.Models.User";', 'variable'];
        yield 'camelCase dotted value' => ['<?php $password = "auth.passwordReset.subject";', 'variable'];
        yield 'sensitive accessor name' => ['<?php $config = ["setPassword" => "sk_live_1234567890"];', 'array_key'];
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
    public function itReportsEveryNamespaceAndGlobalConstantDeclarator(): void
    {
        $locations = [];
        foreach ($this->nodes('<?php namespace App; const PASSWORD = "secret123", API_KEY = "sk_live_123456";') as $node) {
            array_push($locations, ...(new CredentialLiterals(new SensitiveNameMatcher(), 4))->locations($node, 'file'));
        }

        self::assertSame(['file_const', 'file_const'], array_column($locations, 'pattern'));
    }

    #[Test]
    #[DataProvider('fixtureCredentialValues')]
    public function itClassifiesFixtureAndVendorValues(string $name, string $value, bool $credential): void
    {
        $locations = [];
        foreach ($this->nodes('<?php $' . $name . ' = ' . var_export($value, true) . ';') as $node) {
            array_push($locations, ...(new CredentialLiterals(new SensitiveNameMatcher(), 4))->locations($node, 'file'));
        }

        self::assertCount($credential ? 1 : 0, $locations);
    }

    #[Test]
    public function itJudgesTheSlackValueWhenTokenIsConfiguredAsSensitive(): void
    {
        $locations = [];
        foreach ($this->nodes('<?php $token8 = "xoxb.T012AB.B34CD";') as $node) {
            array_push($locations, ...(new CredentialLiterals(new SensitiveNameMatcher(['token']), 4))->locations($node, 'file'));
        }

        self::assertSame(['variable'], array_column($locations, 'pattern'));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function fixtureCredentialValues(): iterable
    {
        yield 'SendGrid dotted token' => ['apiSecret1', 'SG.abcdefghijklmnop.qrstuvwxyzABCDEFGH', true];
        yield 'capitalized dotted password' => ['password2', 'Admin.Pass123', true];
        yield 'lowercase dotted passphrase' => ['password3', 'correct-horse.battery-staple', false];
        yield 'hyphen and digit password' => ['password4', 'Summer-2024.x', true];
        yield 'lowercase translation key' => ['password5', 'auth.password.reset', false];
        yield 'lowercase hyphenated key' => ['password6', 'auth.password-reset', false];
        yield 'plain secret' => ['apiKey7', 'sk_live_abcdefghijklmnop', true];
        yield 'unqualified Slack token name' => ['token8', 'xoxb.T012AB.B34CD', false];
        yield 'PascalCase path' => ['secret9', 'App.Config.DatabasePassword', true];
        yield 'redacted placeholder' => ['password', '<redacted>', false];
        yield 'short vendor password' => ['password', 'pa$s', true];
        yield 'vendor type string' => ['password', 'bool', false];
        yield 'vendor accessor string' => ['openssl_get_privatekey', 'openssl_pkey_get_private', true];
    }

    #[Test]
    #[DataProvider('nativeTypeValues')]
    public function itAcceptsOnlyWholeNativeTypeSyntax(string $value, bool $credential): void
    {
        $locations = [];
        foreach ($this->nodes('<?php $password = ' . var_export($value, true) . ';') as $node) {
            array_push($locations, ...(new CredentialLiterals(new SensitiveNameMatcher(), 4))->locations($node, 'file'));
        }

        self::assertCount($credential ? 1 : 0, $locations);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function nativeTypeValues(): iterable
    {
        yield 'nullable builtin' => ['?string', false];
        yield 'union builtin' => ['int|false', false];
        yield 'named intersection union' => ['(A&B)|C', true];
        yield 'builtin intersection union' => ['(bool&int)|false', false];
        yield 'self and parent' => ['self|parent', false];
        yield 'fully qualified builtin' => ['\\int', true];
        yield 'relative builtin' => ['namespace\\int', true];
        yield 'union with qualified builtin' => ['int|\\bool', true];
        yield 'extra statement' => ['int;function f():string{}', true];
        yield 'comment' => ['int /* injected */', true];
        yield 'whole placeholder' => ['<redacted>', false];
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
        yield 'dot identifier with a hyphenated segment' => ['<?php $password = "auth.password-reset";'];
        yield 'short dot identifier with a hyphen' => ['<?php $secret = "a.b-c";'];
        yield 'dotted identifier with a snake-case segment' => ['<?php $password = "auth.password_reset.subject";'];
        yield 'dotted identifier with a digit inside a lowercase segment' => ['<?php $secretKey = "services.s3.secret";'];
        yield 'dotted value of lowercase hyphen-joined words has the shape of a key' => ['<?php $apiKey = "sk-live-abc.def";'];
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

<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Metrics\Security;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Metrics\Security\HardcodedCredentialsVisitor;
use Qualimetrix\Metrics\Security\SensitiveNameMatcher;

#[CoversClass(HardcodedCredentialsVisitor::class)]
final class HardcodedCredentialsVisitorTest extends TestCase
{
    #[DataProvider('provideDetectionCases')]
    public function testDetection(string $code, int $expectedCount, ?string $expectedPattern = null): void
    {
        $visitor = new HardcodedCredentialsVisitor(
            matcher: new SensitiveNameMatcher(),
            minValueLength: 4,
        );

        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        $locations = $visitor->getLocations();

        self::assertCount($expectedCount, $locations, \sprintf(
            'Expected %d credential(s), found %d for code: %s',
            $expectedCount,
            \count($locations),
            $code,
        ));

        if ($expectedPattern !== null) {
            self::assertSame($expectedPattern, $locations[0]->pattern);
        }
    }

    /**
     * @return iterable<string, array{code: string, expectedCount: int, expectedPattern?: string}>
     */
    public static function provideDetectionCases(): iterable
    {
        // --- True positives ---

        yield 'variable assignment' => [
            'code' => '<?php $password = "secret123";',
            'expectedCount' => 1,
            'expectedPattern' => 'variable',
        ];

        yield 'array item' => [
            'code' => '<?php $config = ["api_key" => "sk-abc123def"];',
            'expectedCount' => 1,
            'expectedPattern' => 'array_key',
        ];

        yield 'class constant' => [
            'code' => '<?php class Config { const DB_PASSWORD = "root123"; }',
            'expectedCount' => 1,
            'expectedPattern' => 'class_const',
        ];

        yield 'define call' => [
            'code' => '<?php define("API_KEY", "sk-abc123def");',
            'expectedCount' => 1,
            'expectedPattern' => 'define',
        ];

        yield 'property default' => [
            'code' => '<?php class Service { private string $apiKey = "sk-abc123"; }',
            'expectedCount' => 1,
            'expectedPattern' => 'property',
        ];

        yield 'parameter default' => [
            'code' => '<?php function connect(string $password = "root") {}',
            'expectedCount' => 1,
            'expectedPattern' => 'parameter',
        ];

        yield 'backed enum case with sensitive name' => [
            'code' => '<?php enum Credentials: string { case ApiKey = "sk-abc123def456"; }',
            'expectedCount' => 1,
            'expectedPattern' => 'enum_case',
        ];

        yield 'backed enum case with non-sensitive name' => [
            'code' => '<?php enum Status: string { case Active = "active_status"; }',
            'expectedCount' => 0,
        ];

        yield 'unit enum case (no value) ignored' => [
            'code' => '<?php enum Tokens { case ApiKey; }',
            'expectedCount' => 0,
        ];

        yield 'multiple findings in one file' => [
            'code' => '<?php $password = "admin"; $secret = "shhh!"; $apiKey = "key123";',
            'expectedCount' => 3,
        ];

        // --- False positives (should NOT detect) ---

        yield 'variable from function call' => [
            'code' => '<?php $password = getenv("DB_PASSWORD");',
            'expectedCount' => 0,
        ];

        yield 'variable from other variable' => [
            'code' => '<?php $password = $request->get("password");',
            'expectedCount' => 0,
        ];

        yield 'empty string' => [
            'code' => '<?php $password = "";',
            'expectedCount' => 0,
        ];

        yield 'short string' => [
            'code' => '<?php $password = "ab";',
            'expectedCount' => 0,
        ];

        yield 'identical chars' => [
            'code' => '<?php $password = "****";',
            'expectedCount' => 0,
        ];

        yield 'non-sensitive variable' => [
            'code' => '<?php $username = "admin";',
            'expectedCount' => 0,
        ];

        yield 'password hash' => [
            'code' => '<?php $passwordHash = "abc123def";',
            'expectedCount' => 0,
        ];

        yield 'token storage' => [
            'code' => '<?php $tokenStorage = "memory";',
            'expectedCount' => 0,
        ];

        yield 'cache key' => [
            'code' => '<?php $cacheKey = "users:list";',
            'expectedCount' => 0,
        ];

        yield 'bare token' => [
            'code' => '<?php $token = "abc123def";',
            'expectedCount' => 0,
        ];

        yield 'bare key' => [
            'code' => '<?php $key = "abc123def";',
            'expectedCount' => 0,
        ];

        yield 'option password constant' => [
            'code' => '<?php class Config { const OPTION_PASSWORD = "password"; }',
            'expectedCount' => 0,
        ];

        yield 'array with env call value' => [
            'code' => '<?php $config = ["password" => env("DB_PASSWORD")];',
            'expectedCount' => 0,
        ];

        yield 'property without default' => [
            'code' => '<?php class Service { private string $password; }',
            'expectedCount' => 0,
        ];

        yield 'translation string in array (long sentence)' => [
            'code' => '<?php return ["password" => "The provided password is incorrect."];',
            'expectedCount' => 0,
        ];

        yield 'error message in variable' => [
            'code' => '<?php $password = "The password must be at least 8 characters long.";',
            'expectedCount' => 0,
        ];

        yield 'short credential-like value in array is still flagged' => [
            'code' => '<?php $config = ["password" => "s3cr3t"];',
            'expectedCount' => 1,
            'expectedPattern' => 'array_key',
        ];

        yield 'credential with no spaces is flagged' => [
            'code' => '<?php $apiKey = "sk-abc123def456ghi789jkl012mno345";',
            'expectedCount' => 1,
            'expectedPattern' => 'variable',
        ];

        // --- Dot-notation identifiers (should NOT detect) ---

        yield 'dot-notation metric name in class constant' => [
            'code' => '<?php class MetricName { const SECURITY_HARDCODED_CREDENTIALS = "security.hardcodedCredentials"; }',
            'expectedCount' => 0,
        ];

        yield 'dot-notation config key in class constant' => [
            'code' => '<?php class Config { const DB_PASSWORD = "database.connection.host"; }',
            'expectedCount' => 0,
        ];

        yield 'dot-notation config path in class constant' => [
            'code' => '<?php class Config { const SECRET = "app.config.secretManager"; }',
            'expectedCount' => 0,
        ];

        // --- Real credentials should still be flagged ---

        yield 'API key with prefix is flagged' => [
            'code' => '<?php class Config { const API_KEY = "sk-1234567890abcdef"; }',
            'expectedCount' => 1,
            'expectedPattern' => 'class_const',
        ];

        yield 'actual password is flagged' => [
            'code' => "<?php class Config { const PASSWORD = 'myS3cretPa\$\$word'; }",
            'expectedCount' => 1,
            'expectedPattern' => 'class_const',
        ];
    }

    public function testReset(): void
    {
        $visitor = new HardcodedCredentialsVisitor(
            matcher: new SensitiveNameMatcher(),
            minValueLength: 4,
        );

        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse('<?php $password = "secret123";') ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        self::assertCount(1, $visitor->getLocations());

        $visitor->reset();

        self::assertCount(0, $visitor->getLocations());
    }
}

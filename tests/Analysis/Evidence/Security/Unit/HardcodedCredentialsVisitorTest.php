<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Security\Unit;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Security\Credential\HardcodedCredentialsVisitor;
use Qualimetrix\Analysis\Evidence\Security\SensitiveNameMatcher;

#[CoversClass(HardcodedCredentialsVisitor::class)]
final class HardcodedCredentialsVisitorTest extends TestCase
{
    #[Test]
    #[DataProvider('provideDetectionCases')]
    public function itDetectsCredentials(string $code, int $expectedCount, ?string $expectedPattern = null): void
    {
        $visitor = new HardcodedCredentialsVisitor(
            matcher: new SensitiveNameMatcher(),
            minValueLength: 4,
        );

        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $registrar = (new DeclarationRegistrarFactory())->createForFile();
        $traverser->addVisitor($registrar);
        $visitor->useDeclarationIndex($registrar->index());
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

        yield 'variable from other variable' => [
            'code' => '<?php $password = $request->get("password");',
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

    #[Test]
    public function itResetsState(): void
    {
        $visitor = new HardcodedCredentialsVisitor(
            matcher: new SensitiveNameMatcher(),
            minValueLength: 4,
        );

        $parser = (new ParserFactory())->createForHostVersion();
        $ast = $parser->parse('<?php $password = "secret123";') ?? [];

        $traverser = new NodeTraverser();
        $registrar = (new DeclarationRegistrarFactory())->createForFile();
        $traverser->addVisitor($registrar);
        $visitor->useDeclarationIndex($registrar->index());
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        self::assertCount(1, $visitor->getLocations());

        $visitor->reset();

        self::assertCount(0, $visitor->getLocations());
    }
}

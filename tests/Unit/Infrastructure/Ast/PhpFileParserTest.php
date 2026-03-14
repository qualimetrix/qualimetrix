<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Ast;

use AiMessDetector\Core\Exception\ParseException;
use AiMessDetector\Infrastructure\Ast\PhpFileParser;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Namespace_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Constraint\IsType;
use PHPUnit\Framework\NativeType;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SplFileInfo;

#[CoversClass(PhpFileParser::class)]
final class PhpFileParserTest extends TestCase
{
    private PhpFileParser $parser;
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->parser = new PhpFileParser();
        $this->fixturesPath = \dirname(__DIR__, 3) . '/Fixtures/Ast';
    }

    public function testParseValidPhpFile(): void
    {
        $file = new SplFileInfo($this->fixturesPath . '/ValidClass.php');

        $ast = $this->parser->parse($file);

        self::assertNotEmpty($ast);
        self::assertContainsOnlyInstancesOf(Node::class, $ast);
    }

    public function testParseReturnsCorrectAstStructure(): void
    {
        $file = new SplFileInfo($this->fixturesPath . '/ValidClass.php');

        $ast = $this->parser->parse($file);

        // First node should be declare(strict_types=1)
        self::assertInstanceOf(Declare_::class, $ast[0]);

        // Second node should be namespace
        self::assertInstanceOf(Namespace_::class, $ast[1]);

        // Namespace should contain the class
        $namespaceNode = $ast[1];
        self::assertInstanceOf(Namespace_::class, $namespaceNode);
        self::assertNotEmpty($namespaceNode->stmts);

        $classNode = $namespaceNode->stmts[0];
        self::assertInstanceOf(Class_::class, $classNode);
        self::assertNotNull($classNode->name);
        self::assertSame('ValidClass', $classNode->name->toString());
    }

    public function testParseMinimalPhpFile(): void
    {
        $file = new SplFileInfo($this->fixturesPath . '/empty_file.php');

        $ast = $this->parser->parse($file);

        // Minimal PHP file with only declare(strict_types=1) parses successfully
        // and produces exactly one Declare_ node
        self::assertCount(1, $ast);
        self::assertInstanceOf(Declare_::class, $ast[0]);
    }

    public function testParseThrowsExceptionForInvalidSyntax(): void
    {
        $file = new SplFileInfo($this->fixturesPath . '/invalid_syntax.php');

        $this->expectException(ParseException::class);
        $this->expectExceptionMessageMatches('/Failed to parse.*invalid_syntax\.php/');

        $this->parser->parse($file);
    }

    public function testParseThrowsExceptionForNonExistentFile(): void
    {
        $file = new SplFileInfo($this->fixturesPath . '/nonexistent.php');

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('File does not exist or is not a regular file');

        $this->parser->parse($file);
    }

    public function testParseExceptionContainsFilePath(): void
    {
        $filePath = $this->fixturesPath . '/nonexistent.php';
        $file = new SplFileInfo($filePath);

        try {
            $this->parser->parse($file);
            self::fail('Expected ParseException was not thrown');
        } catch (ParseException $e) {
            self::assertSame($filePath, $e->filePath);
            self::assertStringContainsString($filePath, $e->getMessage());
        }
    }

    public function testParserCanBeInjected(): void
    {
        // Test that a custom parser can be injected (for testing purposes)
        $mockParser = $this->createMock(\PhpParser\Parser::class);
        $mockParser->expects(self::once())
            ->method('parse')
            ->with(new IsType(NativeType::String))
            ->willReturn([]);

        $fileParser = new PhpFileParser($mockParser);
        $file = new SplFileInfo($this->fixturesPath . '/ValidClass.php');

        $ast = $fileParser->parse($file);

        self::assertSame([], $ast);
    }

    public function testParserThrowsExceptionWhenParserReturnsNull(): void
    {
        $mockParser = $this->createStub(\PhpParser\Parser::class);
        $mockParser->method('parse')
            ->willReturn(null);

        $fileParser = new PhpFileParser($mockParser);
        $file = new SplFileInfo($this->fixturesPath . '/ValidClass.php');

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Parser returned null');

        $fileParser->parse($file);
    }

    public function testParserPreservesPreviousExceptionOnError(): void
    {
        $originalException = new RuntimeException('Original error');

        $mockParser = $this->createStub(\PhpParser\Parser::class);
        $mockParser->method('parse')
            ->willThrowException($originalException);

        $fileParser = new PhpFileParser($mockParser);
        $file = new SplFileInfo($this->fixturesPath . '/ValidClass.php');

        try {
            $fileParser->parse($file);
            self::fail('Expected ParseException was not thrown');
        } catch (ParseException $e) {
            self::assertSame($originalException, $e->getPrevious());
            self::assertStringContainsString('Original error', $e->getMessage());
        }
    }
}

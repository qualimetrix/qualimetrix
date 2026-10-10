<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Security\Credential;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\UnionType;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Throwable;

/**
 * Decides whether a string literal stored under a sensitive name looks like a
 * secret rather than a placeholder, a key or a message.
 */
final readonly class CredentialValue
{
    private Parser $parser;

    private const array SCALAR_TYPES = [
        'bool' => true, 'int' => true, 'float' => true, 'string' => true,
        'array' => true, 'object' => true, 'mixed' => true, 'void' => true,
        'null' => true, 'never' => true, 'false' => true, 'true' => true,
        'iterable' => true, 'callable' => true,
    ];

    private const array CONTEXT_TYPES = ['self' => true, 'static' => true, 'parent' => true];

    public function __construct(private int $minValueLength)
    {
        $this->parser = (new ParserFactory())->createForHostVersion();
    }

    public function isCredential(string $value): bool
    {
        return $value !== ''
            && \strlen($value) >= $this->minValueLength
            && \strlen($value) !== substr_count($value, $value[0])
            && preg_match('/^<[^<>\r\n]+>$/D', $value) !== 1
            && !$this->nativeBuiltinType($value)
            && !$this->dotIdentifier($value)
            && !$this->humanMessage($value);
    }

    private function nativeBuiltinType(string $value): bool
    {
        try {
            $statements = $this->parser->parse('<?php function __qmx_credential_type(): ' . $value . ' {}');
        } catch (Throwable) {
            return false;
        }

        return $this->hasNoComments() && $this->isSingleNativeTypeFunction($statements);
    }

    private function hasNoComments(): bool
    {
        foreach ($this->parser->getTokens() as $token) {
            if ($token->id === \T_COMMENT || $token->id === \T_DOC_COMMENT) {
                return false;
            }
        }

        return true;
    }

    /** @param array<Node\Stmt>|null $statements */
    private function isSingleNativeTypeFunction(?array $statements): bool
    {
        if (\count($statements ?? []) !== 1 || !$statements[0] instanceof Function_) {
            return false;
        }

        $function = $statements[0];

        return $function->name->toString() === '__qmx_credential_type'
            && $function->params === []
            && $function->stmts === []
            && !$function->byRef
            && $function->returnType instanceof Node
            && $this->builtinTypeNode($function->returnType);
    }

    private function builtinTypeNode(Node $node): bool
    {
        if ($node instanceof Identifier) {
            return isset(self::SCALAR_TYPES[strtolower($node->toString())]);
        }

        if ($node::class === Name::class) {
            return isset(self::CONTEXT_TYPES[strtolower($node->toString())]);
        }

        if ($node instanceof NullableType) {
            return $this->builtinTypeNode($node->type);
        }

        if ($node instanceof UnionType || $node instanceof IntersectionType) {
            foreach ($node->types as $type) {
                if (!$this->builtinTypeNode($type)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }
    /**
     * A translation, configuration or channel key such as `auth.password.reset`
     * or `auth.password-reset`: every dot-separated segment is lowercase.
     * A capital or digit in a hyphenated segment (`Summer-2024`, `abc123-def456`)
     * is the shape of a password or key. A JWT has the identifier
     * shape, but its header always encodes `{"` and so starts with `eyJ`.
     */
    private function dotIdentifier(string $value): bool
    {
        $segment = '(?:[a-z_][a-z0-9_]*|[a-z]+(?:-[a-z]+)+)';

        return (bool) preg_match("/^{$segment}(?:\\.{$segment})+$/", $value) && !str_starts_with($value, 'eyJ');
    }
    /**
     * Words of a message are separated by whitespace. Hyphens, dots, slashes
     * and plus signs separate the groups of a key (UUID, AWS-style, base64),
     * so they must not count as word breaks.
     */
    private function humanMessage(string $value): bool
    {
        return \strlen($value) > 20 && preg_match_all('/(?<!\S)\S*\w{3,}\S*/', $value) >= 3;
    }
}

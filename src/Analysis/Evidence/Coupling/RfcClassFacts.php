<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use PhpParser\Node;
use Qualimetrix\Core\Ast\ResolvedName;

/** File-owned rows contain no AST references and survive metric-bag transport. */
final readonly class RfcClassFacts
{
    /** @param list<array<string, scalar>> $rows */
    public function __construct(public string $namespace, public string $className, public int $line, public int $startFilePos, public array $rows) {}

    public static function fromNode(Node\Stmt\ClassLike $node, string $namespace): self
    {
        $rows = [['tag' => 'declaration', 'kind' => $node->getType()]];
        foreach ($node->stmts as $statement) {
            if ($statement instanceof Node\Stmt\ClassMethod) {
                self::method($rows, strtolower($statement->name->toString()), $statement);
                self::promoted($rows, $statement);
            } elseif ($statement instanceof Node\Stmt\Property) {
                foreach ($statement->props as $property) {
                    self::property($rows, $property->name->toString(), $statement->type, $statement->hooks);
                }
            } elseif ($statement instanceof Node\Stmt\TraitUse) {
                self::traitUse($rows, $statement);
            }
        }

        return new self($namespace, $node->name?->toString() ?? '', $node->getStartLine(), $node->getStartFilePos(), $rows);
    }

    /** @param list<array<string, scalar>> $rows */
    private static function promoted(array &$rows, Node\Stmt\ClassMethod $statement): void
    {
        foreach ($statement->params as $parameter) {
            if ($parameter->flags !== 0 && $parameter->var instanceof Node\Expr\Variable && \is_string($parameter->var->name)) {
                self::property($rows, $parameter->var->name, $parameter->type, $parameter->hooks);
            }
        }
    }

    /** @param list<array<string, scalar>> $rows */
    private static function method(array &$rows, string $member, Node\Stmt\ClassMethod|Node\PropertyHook $method): void
    {
        $rows[] = ['tag' => 'method', 'member' => $member, 'hook' => $method instanceof Node\PropertyHook, 'visibility' => self::visibility($method), 'abstract' => $method instanceof Node\Stmt\ClassMethod ? $method->isAbstract() : $method->body === null];
        $body = self::body($method);
        $scanner = new RfcBodyResponses();
        $variables = [];
        RfcReceiverTypes::parameters($method->params, $variables);
        foreach ($scanner->collect($body, $variables) as $response) {
            $rows[] = ['tag' => 'response', 'caller' => $member, ...$response];
        }
    }

    private static function visibility(Node\Stmt\ClassMethod|Node\PropertyHook $method): string
    {
        if (!$method instanceof Node\Stmt\ClassMethod) {
            return 'public';
        }

        return $method->isPrivate() ? 'private' : ($method->isProtected() ? 'protected' : 'public');
    }

    /** @return array<Node> */
    private static function body(Node\Stmt\ClassMethod|Node\PropertyHook $method): array
    {
        if ($method instanceof Node\Stmt\ClassMethod) {
            return $method->stmts ?? [];
        }

        return $method->body instanceof Node\Expr ? [$method->body] : ($method->body ?? []);
    }

    /** @param list<array<string, scalar>> $rows
     * @param array<Node\PropertyHook> $hooks
     */
    private static function property(array &$rows, string $name, ?Node $type, array $hooks): void
    {
        $resolved = RfcReceiverTypes::typeName($type);
        $row = ['tag' => 'property', 'name' => $name];
        if ($resolved !== null) {
            $row['type'] = $resolved;
        }
        $rows[] = $row;
        foreach ($hooks as $hook) {
            self::method($rows, $name . '::' . strtolower($hook->name->toString()), $hook);
        }
    }

    /** @param list<array<string, scalar>> $rows */
    private static function traitUse(array &$rows, Node\Stmt\TraitUse $use): void
    {
        $group = $use->getStartFilePos();
        foreach ($use->traits as $trait) {
            $rows[] = ['tag' => 'trait', 'group' => $group, 'trait' => ResolvedName::className($trait) ?? $trait->toString()];
        }
        foreach ($use->adaptations as $adaptation) {
            foreach (self::adaptationRows($adaptation, $group) as $row) {
                $rows[] = $row;
            }
        }
    }    /** @return iterable<array<string, scalar>> */
    private static function adaptationRows(Node\Stmt\TraitUseAdaptation $adaptation, int $group): iterable
    {
        $row = ['tag' => 'adaptation', 'group' => $group, 'method' => $adaptation->method->toString()];
        if ($adaptation->trait !== null) {
            $row['trait'] = ResolvedName::className($adaptation->trait) ?? $adaptation->trait->toString();
        }
        if ($adaptation instanceof Node\Stmt\TraitUseAdaptation\Alias) {
            $row = self::alias($row, $adaptation);
            yield $row;
        } elseif ($adaptation instanceof Node\Stmt\TraitUseAdaptation\Precedence) {
            foreach ($adaptation->insteadof as $excluded) {
                yield [...$row, 'insteadOf' => ResolvedName::className($excluded) ?? $excluded->toString()];
            }
        }
    }

    /** @param array<string, scalar> $row
     * @return array<string, scalar>
     */
    private static function alias(array $row, Node\Stmt\TraitUseAdaptation\Alias $adaptation): array
    {
        if ($adaptation->newName !== null) {
            $row['alias'] = $adaptation->newName->toString();
        }
        $row['visibility'] = match ($adaptation->newModifier) {
            Node\Stmt\Class_::MODIFIER_PUBLIC => 'public',
            Node\Stmt\Class_::MODIFIER_PROTECTED => 'protected',
            Node\Stmt\Class_::MODIFIER_PRIVATE => 'private',
            default => '',
        };
        return $row;
    }

}

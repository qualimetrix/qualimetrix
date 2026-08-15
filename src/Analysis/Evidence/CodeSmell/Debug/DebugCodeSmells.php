<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell\Debug;

use PhpParser\Node\Expr\ConstFetch;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use Qualimetrix\Analysis\Evidence\CodeSmell\CodeSmellLocation;

/** Evaluates debug-call smells and their non-output exceptions. */
final class DebugCodeSmells
{
    private const FUNCTIONS = ['var_dump', 'print_r', 'var_export', 'dd', 'dump', 'debug_print_backtrace', 'debug_zval_dump'];
    private const API_METHODS = ['dump', 'dd', 'debug', 'dumprawsql', 'dumpsql', 'debuginfo', '__debuginfo'];

    public function location(FuncCall $node, ?string $method, string $subjectId): ?CodeSmellLocation
    {
        if (!$node->name instanceof Name || $node->isFirstClassCallable()) {
            return null;
        }

        $name = $node->name->toLowerString();
        if (!\in_array($name, self::FUNCTIONS, true) || $this->isReturnMode($node) || \in_array($method, self::API_METHODS, true)) {
            return null;
        }

        return new CodeSmellLocation('debug_code', $node->getStartLine(), $node->getStartTokenPos(), $subjectId, $name);
    }

    private function isReturnMode(FuncCall $node): bool
    {
        foreach ($node->getArgs() as $argument) {
            if ($argument->name?->toString() === 'return') {
                return $this->isTrue($argument->value);
            }
        }

        $arguments = $node->getArgs();

        return \count($arguments) > 1 && $this->isTrue($arguments[1]->value);
    }

    private function isTrue(mixed $value): bool
    {
        return $value instanceof ConstFetch && $value->name->toLowerString() === 'true';
    }
}

<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Security;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\ShellExec;
use PhpParser\Node\Name;

/**
 * Detects command injection patterns: a superglobal reaching a command
 * execution function's argument or a backtick command.
 *
 * Which wrappers carry the superglobal's value, and which (`escapeshellarg()`,
 * `escapeshellcmd()`, any other call, `(int)`/`(float)` casts) end the
 * search, is decided by {@see SuperglobalAnalyzer}.
 */
final readonly class CommandInjectionDetector
{
    /** @var list<string> Command execution functions */
    private const COMMAND_FUNCTIONS = [
        'exec',
        'system',
        'passthru',
        'shell_exec',
        'proc_open',
        'popen',
    ];

    public function __construct(
        private SuperglobalAnalyzer $superglobalAnalyzer,
    ) {}

    /**
     * Detect command injection in a function call node.
     *
     * @return list<SecurityPatternLocation>
     */
    public function detectInFuncCall(FuncCall $node): array
    {
        if (!$node->name instanceof Name || $node->isFirstClassCallable()) {
            return [];
        }

        $functionName = $node->name->toLowerString();

        if (!\in_array($functionName, self::COMMAND_FUNCTIONS, true)) {
            return [];
        }

        foreach ($node->getArgs() as $arg) {
            $varName = $this->superglobalAnalyzer->findSuperglobal($arg->value);
            if ($varName !== null) {
                return [
                    new SecurityPatternLocation(
                        type: 'command_injection',
                        line: $node->getStartLine(),
                        context: "\${$varName} in {$functionName}() call",
                    ),
                ];
            }
        }

        return [];
    }

    /**
     * Detect command injection in a backtick command, which PHP runs
     * exactly like `shell_exec()`.
     *
     * @return list<SecurityPatternLocation>
     */
    public function detectInShellExec(ShellExec $node): array
    {
        $varName = $this->superglobalAnalyzer->findSuperglobalInParts($node->parts);
        if ($varName === null) {
            return [];
        }

        return [
            new SecurityPatternLocation(
                type: 'command_injection',
                line: $node->getStartLine(),
                context: "\${$varName} in backtick command",
            ),
        ];
    }
}

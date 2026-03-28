<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Security;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;

/**
 * Detects command injection patterns: superglobals in command execution functions.
 *
 * Detection vectors:
 * - Direct superglobal usage in exec/system/passthru/shell_exec/proc_open/popen
 * - Interpolated strings containing superglobals in command function arguments
 * - Concatenations containing unsanitized superglobals in command function arguments
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

    /** @var list<string> Command injection sanitization functions */
    private const COMMAND_SANITIZERS = [
        'escapeshellarg',
        'escapeshellcmd',
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
        if (!$node->name instanceof Name) {
            return [];
        }

        $functionName = $node->name->toLowerString();

        if (!\in_array($functionName, self::COMMAND_FUNCTIONS, true)) {
            return [];
        }

        foreach ($node->getArgs() as $arg) {
            if ($this->superglobalAnalyzer->isUnsanitizedSuperglobal($arg->value, self::COMMAND_SANITIZERS)) {
                $varName = $this->superglobalAnalyzer->getSuperglobalName($arg->value);

                return [
                    new SecurityPatternLocation(
                        type: 'command_injection',
                        line: $node->getStartLine(),
                        context: "\${$varName} in {$functionName}() call",
                    ),
                ];
            }

            // Check interpolated strings for unsanitized superglobals
            if ($arg->value instanceof InterpolatedString) {
                $varName = $this->superglobalAnalyzer->findSuperglobalInInterpolatedString($arg->value);
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

            // Also check concatenation containing unsanitized superglobal
            if ($this->superglobalAnalyzer->containsUnsanitizedSuperglobalInExpr($arg->value, self::COMMAND_SANITIZERS)) {
                $varName = $this->superglobalAnalyzer->findUnsanitizedSuperglobalName($arg->value, self::COMMAND_SANITIZERS);
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
        }

        return [];
    }
}

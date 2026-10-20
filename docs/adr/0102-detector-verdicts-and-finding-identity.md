# ADR 0102: Detector Verdicts and Finding Identity

## Status

Accepted.

## Context

Detector exemptions based on a method name or an arbitrary dotted value could
hide a real finding. A file symbol with null namespace for findings produced
inside a named declaration also made class and namespace consumers attribute
them to the file.
Threshold messages said "exceeds" even when the value equalled the configured
boundary.

## Decision

Debug output calls are judged from the call itself, including calls inside
methods named `dump`, `dd`, or `debug`; intentional calls use a reasoned
`@qmx-ignore`. The named return mode of `print_r` and `var_export` is still
respected. Credential values skip lowercase dotted configuration-key shapes,
whole angle-bracket placeholders, and native built-in PHP type syntax parsed
as an exact empty function return type. This is a syntax filter, not PHP
compiler validation. An uppercase dotted value is no longer exempt. The
sensitive-name policy is unchanged, including its treatment of bare `token8`.

Detector findings inside named class, method, or function declarations carry
the exact declaration symbol. File-scope and anonymous evidence retain a file
symbol and null namespace. The selected rule threshold is compared with the
raw value: equality is worded "reaches", strict excess "exceeds". Rendering
precision cannot change this verdict.

This identity rule covers ten code-smell channels (`boolean-argument`,
`count-in-loop`, `debug-code`, `empty-catch`, `error-suppression`, `eval`, `exit`,
`goto`, `identical-subexpression`, `superglobals`) and five security channels
(`command-injection`, `hardcoded-credentials`, `sensitive-parameter`,
`sql-injection`, `xss`). Each has Callable and File publication; hardcoded
credentials also publishes Class findings. Existing named-declaration channels
remain and these File cases are additive.

## Consequences

Ranking, health, and drill-down consumers can distinguish named declaration
findings where they consume symbols. Baseline identity still uses the unchanged
subject and occurrence key, not the presentation symbol. Class ranking uses the
class's own rank; functions use the function median. This does not promise
uniform behavior for every report: file-subject namespace selection remains
to be addressed separately. Three existing namespace fallback readers also
remain. The credential filter can still miss a lowercase dotted secret phrase,
and a credential-looking alias-map value can still be flagged. An early guard
inside a `try`, after a preparatory call but before the useful work, can still
make an empty `catch` look like a valid chain of attempts.

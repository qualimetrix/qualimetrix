# Security — Vulnerability Evidence

## Overview

Security collects evidence for hardcoded credentials, direct use of
superglobals in dangerous contexts, and parameters that can expose secrets in
stack traces. Its co-located rules turn that evidence into findings without
performing AST traversal.

The direct-superglobal checks are pattern detectors, not taint analysis. They
do not follow values through variables, function calls, or object properties.
Within one expression, `SuperglobalAnalyzer` looks through the wrappers that
pass a value on — concatenation, interpolation, `??`, `?:` branches, `match`
arm results, `(string)`, `@` and assignment — and stops at every other node, so
any call (a sanitizer or not) and an `(int)`/`(float)` cast end the search.
Command injection also treats the backtick operator as a sink.

Because the search looks through concatenation and interpolation, a query
function, `sprintf()` call or concatenation sees the same read as the queries
built inside it. `SecurityPatternVisitor` therefore reports SQL injection once
per query: a query nested in a reported one is reported again only for a read
the enclosing query did not reach, such as a subquery built behind a call.

## Structure

```
Security/
├── Credential/
│   ├── CredentialDeclarations.php
│   ├── CredentialLiterals.php
│   ├── CredentialLocation.php
│   ├── CredentialValue.php
│   ├── HardcodedCredentialsCollector.php
│   └── HardcodedCredentialsVisitor.php
├── CommandInjectionDetector.php
├── CommandInjectionRule.php
├── HardcodedCredentialsOptions.php
├── HardcodedCredentialsRule.php
├── SecurityPatternCollector.php
├── SecurityPatternFinding.php
├── SecurityPatternLocation.php
├── SecurityPatternOptions.php
├── SecurityPatternVisitor.php
├── SensitiveNameMatcher.php
├── SensitiveParameterCollector.php
├── SensitiveParameterLocation.php
├── SensitiveParameterOptions.php
├── SensitiveParameterRule.php
├── SensitiveParameterVisitor.php
├── SqlInjectionDetector.php
├── SqlInjectionRule.php
├── SuperglobalAnalyzer.php
├── XssDetector.php
└── XssRule.php
```

`Credential` is a child subject: it owns literal classification and the
credential collector/visitor pair. It uses the Security-owned
`SensitiveNameMatcher`; it is not a separate capability or public contract.

## Evidence and Rules

| Collector                       | DataBag entry key                | Rule ID                          | Default severity |
| ------------------------------- | -------------------------------- | -------------------------------- | ---------------- |
| `HardcodedCredentialsCollector` | `security.hardcoded-credentials` | `security.hardcoded-credentials` | Error            |
| `SecurityPatternCollector`      | `security.sql_injection`         | `security.sql-injection`         | Error            |
| `SecurityPatternCollector`      | `security.xss`                   | `security.xss`                   | Error            |
| `SecurityPatternCollector`      | `security.command_injection`     | `security.command-injection`     | Error            |
| `SensitiveParameterCollector`   | `security.sensitive-parameter`   | `security.sensitive-parameter`   | Warning          |

All Security rule options default to `enabled: true`. The three pattern rules
share `AbstractSecurityPatternRule` and `SecurityPatternOptions`; credential
and sensitive-parameter rules retain their own evidence-to-finding mapping.

`SensitiveNameMatcher` recognizes standalone credential words (`password`,
`passwd`, `pwd`, `secret`, `credential`, `credentials`) and qualified `key`
or `token` compounds. Names are split at case changes, underscores and
letter/digit boundaries, and a segment that ends with a sensitive word is split
off from its qualifier (`apikey`, `dbpassword`). Its prefix/suffix blacklists
keep names such as `passwordHash`, `tokenStorage`, `cacheKey`, and
`OPTION_PASSWORD` out of the credential context.

`CredentialLiterals` finds a string literal stored under a name in assignments
and array items. `CredentialDeclarations` handles class, namespace and global
constants, `define()`, property and parameter defaults, and enum cases.
`CredentialValue` judges the literal itself. Lowercase dotted identifier values,
whole angle-bracket placeholders, native built-in PHP type syntax, and messages
of three or more whitespace-separated words are skipped. Type syntax is parsed
and checked against a built-in whitelist; this does not validate PHP type semantics.
Uppercase dotted values are judged as possible credentials. A lowercase dotted
secret phrase may still be skipped, while an alias-map value can be flagged.
Bare `token8` remains outside the default sensitive-name policy.

Direct superglobal sinks share Core's finite read shapes, including literal
variable-variable names and literal `$GLOBALS` keys. An unknown dynamic name
such as `$$name` cannot be identified. Each read node contributes once to an
expression's reported sink evidence. Named class, method, and function findings
carry declaration symbols; file-scope and anonymous evidence retain a file
symbol and null namespace.

## Lifecycle

```
AST node -> stateful visitor -> MetricBag entry -> stateless rule -> Finding
```

Visitors keep resettable per-file state. Collectors emit Measurement-owned
`MetricBag` entries, while rules consume Finding-owned rule and finding
contracts. Security publishes no additional contract.

## Tests

`tests/Analysis/Evidence/Security/Unit/` covers all Security collectors,
visitors, detectors, matcher cases, and rules, and specifically exercises
credential literal filtering, every expression form a security pattern is
looked for through, and sensitive-name matching.

## Definition of Done

- The five rule IDs and the three Security DataBag key families remain stable.
- `Credential` remains a Security child subject, with no `Contract/` directory.
- The focused Security PHPUnit suite, PHP syntax check, and scoped PHPStan pass.


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.

Security rules account native decoded entry occurrences at their actual subject levels before selecting severity or constructing findings. The declaration is reused once per invocation; identities come from source entries, not emitted findings. These ungated populations have no synthetic per-declaration roster.

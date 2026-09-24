# Security — Vulnerability Evidence

## Overview

Security collects file-level evidence for hardcoded credentials, direct use of
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

`CredentialLiterals` finds a string literal stored under a name and
`CredentialValue` judges the literal itself. The name comes from a variable,
property or static property assignment (also `??=`), a string-keyed array
element assignment, an array item, a class constant, `define()`, a property or
parameter default, and an enum case. Values that are dotted identifiers (each
segment is a code identifier or lowercase letter-only words joined by hyphens;
not a JWT) or messages of three or more whitespace-separated words are skipped.

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

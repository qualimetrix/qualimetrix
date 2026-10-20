# ADR 0100: Core AST Name Resolution and Superglobal Reads

## Status

Accepted.

## Context

Several detectors need the same answer to two AST questions: which class a
class-name node denotes after imports, and whether an expression is a direct
read of a PHP superglobal. Reimplementing either question in each detector made
aliases and literal variable-variable forms disagree between rules.

## Decision

`Core/Ast/NameResolution` runs php-parser's collecting name resolver over the
parsed AST before collection, retaining the original nodes. Consumers use
`ResolvedName::className()` only in class-name positions. `self`, `static`, and
`parent` are contextual and return no resolved class name. String contents and
unqualified function or constant names are not class names and do not acquire
the import map through this contract.

`Core/Ast/SuperglobalRead` recognizes a finite set of direct read shapes:
ordinary superglobal variables, literal variable-variable names (including
literal concatenation), and literal `$GLOBALS[...]` keys. It returns the name
and exact read node so sink detectors can deduplicate a read within an
expression. A dynamic name such as `$$name` remains outside this evidence.
Core's php-parser dependencies for these primitives are confined to `Core/Ast/`.

## Consequences

Code-smell and security detectors share the same finite read vocabulary while
retaining their own policies. These detectors are pattern checks within an
expression, not data-flow or taint analysis. An unknown dynamic name is a
documented false negative rather than an inferred read.

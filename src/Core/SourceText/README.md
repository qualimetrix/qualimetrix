# Core\SourceText

`SourceBytes` preserves source bytes when a consumer needs valid UTF-8. Its
subject is byte representation, independent of symbols, findings, hashes or
output formats. It imports no project types. Consumers choose the operation
appropriate to their own identity or publication contract.

## Structure

```text
SourceText/
├── SourceBytes.php
└── README.md
```

## Operations

| Operation                                       | Contract                                                                                                                                                                                                  |
| ----------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `isUtf8(string): bool`                          | Native `mb_check_encoding` judgement with explicit UTF-8 encoding.                                                                                                                                        |
| `escape(string, string $reserved = ''): string` | Encodes `%` as `%25`, each invalid byte as uppercase `%XX`, and any declared ASCII separator as `%XX`. `rawurldecode` restores the exact input.                                                           |
| `escapeInvalid(string): string`                 | Returns a valid UTF-8 string unchanged, including `%`. Otherwise uses the reversible `escape` representation for the whole value.                                                                         |
| `escapeInvalidBytes(string): string`            | Encodes only invalid bytes and leaves literal `%` unchanged. Intended for prose, where reversible identity is not promised.                                                                               |
| `framed(string): string\|array{'%': string}`    | Returns valid input unchanged, or `['%' => escape(input)]` for invalid input. The JSON shape distinguishes an invalid byte string from a valid literal representation without changing valid hash inputs. |

The reversible operation belongs on identity components before the enclosing
grammar adds its separators. For example, a declaration's file component uses
`escape($file, '#')`, so a literal filename suffix `#2` cannot become the
declaration ordinal separator. Non-ASCII bytes in `$reserved` throw
`LogicException`, even when the value itself is valid or empty.

Valid UTF-8 sequences remain byte-for-byte intact. The invalid-input path asks
mbstring to judge prefixes of one through four bytes, preserving the first valid
prefix or escaping one byte when none is valid. The primitive does not duplicate
UTF-8 leading-byte, continuation-byte, overlong, surrogate or code-point rules.
The valid-input path returns without scanning individual characters.

Publication through `escapeInvalid` is deliberately ambiguous: valid `a%FF`
and invalid `a\xFF` both display as `a%FF`. Likewise, prose preserves literal
percent signs. Use `escape` or `framed` when those inputs must remain distinct.

## Verification

`tests/Core/Unit/SourceText/SourceBytesTest.php` covers UTF-8 boundaries, malformed
and truncated sequences, reversible byte encoding, percent literals, reserved
ASCII separators, framing and component boundaries.

## Definition of Done

- UTF-8 validity comes from native mbstring with an explicit encoding.
- Every source byte survives the reversible representation and its inverse.
- Publication and framing preserve valid input, including percent literals.
- Tests and documentation remain with the byte-representation subject.

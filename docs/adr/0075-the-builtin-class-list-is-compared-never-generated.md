# 0075. The builtin-class list is compared, never generated

Date: 2026-09-21

## Status

Accepted

## Context

`PhpBuiltinClassRegistry` answers one question for the whole product: is this
name PHP's, or the analysed project's? Four call sites act on the answer.
`ExternalAncestry` and the two inheritance-depth participants stop an
inheritance walk at a builtin; `DependencyGraphBuilder` keeps an `Extends` edge
to a builtin and drops every other edge that reaches one.

The list was hand-written, 272 names, and nothing compared it to a PHP.
Measured on `ba5fdc66` against five witnesses — stock Linux 8.4.25 and 8.5.10,
a Linux 8.4 with 61 extensions, the same with `enchant`, and macOS 8.5.9 with
69 — it diverged in both directions at once:

- **59 names missing.** All four ext-session types, all of `Phar`, `SQLite3*`,
  `Soap*`, `FFI*`, `Uri\*`, `finfo`, `PhpToken`, `HashContext`, `OpenSSL*`,
  `Socket`, the System V IPC handles, `tidy`, `XSLTProcessor`, `GMP`,
  `EnchantBroker`.
- **One name that was never real.** `Pcntl\QueuedSignalInfo` exists in no
  branch of php-src.

The consequence was not confined to depth. `symfony/http-foundation`'s
`NativeFileSessionHandler extends \SessionHandler` resolved as an unresolvable
*external* parent — `BrokeAt`, depth 0 — rather than as a builtin at depth 1;
and because a missing name is read as the project's own class, every non-`Extends`
edge reaching one of the 59 stayed in the dependency graph as though it were
the project's coupling.

## Decision

**The list stays hand-written. A governance control compares it against the
running PHP and refuses on divergence; it never writes it.**

Generating the list from `get_declared_classes()` would make every metric it
feeds a function of the analysing machine: the same source would report a
different DIT on a runner without `intl` than on a box with every extension
built. That determinism is the reason the list exists, so the cure for a stale
list cannot be to make it environment-shaped.

Three decisions follow from that, and each was the alternative considered:

**Verification metadata lives in the control, not in the product.** The
obvious restructure — key the registry by extension and carry a minimum PHP
version per name — was rejected. `isBuiltin()` must not know either fact: a
class that is builtin in 8.5 is still builtin when 8.5 source is analysed on an
8.4 runtime, so a version gate inside the product would make it answer wrongly.
The same facts are legitimate in the control, where they mean something
narrower — what *this environment* is allowed to demand. An int cell there is
therefore **checked-from, not added-in**. Keeping the product a flat constant
also keeps the finding gate readable: when the lookup mechanism does not move in
the same commit as the list's contents, every gate delta is attributable to
content alone.

The cost is two hand-written enumerations that must agree. The control closes
that itself: a registered name with no attribution and an attribution with no
registered name are both refusals, reported together rather than one aborting
before the other.

**Per-extension set equality, not membership in the flat list.** The control
compares each loaded extension's declarations against *that extension's*
attribution row. Comparing against the flattened registry instead would pass a
name filed under the wrong extension — and that wrong row would silently exempt
the name from the existence check, because its supposed owner is not loaded.
The attribution is thereby measured rather than merely present.

**Scope is php-src's bundled extensions on Unix builds, and an unknown
extension is refused rather than assumed PECL.** The bundled/PECL line is a
fact about php-src's `ext/` directory, not about the runtime, and no runtime
predicate decides it: measured on 8.5.9, comparing
`ReflectionExtension::getVersion()` against `PHP_VERSION` classifies `zip` and
`dom` as PECL and would drop `ZipArchive` from scope. So the roster is a
constant sourced from that directory, PECL extensions are excused by name with
a reason, and a loaded extension in neither list reds. Assuming the unknown is
PECL is precisely the assumption that would have let `uri` — bundled in 8.5 —
pass unseen.

`com_dotnet` is outside the scope: Windows-only, and it registers its classes
in C rather than in a stub, so no witness reachable from this repository can
enumerate it.

## Consequences

- Coupling numbers **fall** for code typed on the newly recognised names, because
  those edges are now dropped as PHP's rather than counted as the project's.
  `design.dit` rises where a chain now reaches a builtin instead of breaking.
- A class extending a PECL class — `class X extends \Redis` — is by policy
  measured as extending something the project does not own and whose depth is
  unknown. That is a scope decision, not an oversight.
- Adding a name costs two edits, in the registry and in the control's
  attribution. Both are loud.
- How much of the list CI verifies is now a property of this repository rather
  than of `shivammathur/setup-php`'s defaults: the extensions the control needs
  are pinned in the workflow, for the same reason `igbinary` already was.
- The control cannot see one class of defect. PHP class names are
  case-insensitive and `isBuiltin()` is an exact-key lookup, so
  `class X extends \exception` is still measured as extending a project class.
  The census compares canonical spellings on both sides and structurally cannot
  reach it; a green census is agreement on a set of strings, not proof that
  `isBuiltin()` answers correctly for every spelling source may use.
- A name is verified wherever its extension is loaded, and nowhere else. No
  attributed extension is currently unloadable everywhere: `Pdo\Firebird` was
  recorded as such on the assumption that no runner builds `pdo_firebird`, and
  the GitHub-hosted runner does. The control refused that excuse by name rather
  than letting it stand, which is why the disposition for it no longer exists.
- Which PECL extensions must be excused is a property of the machine, not of
  php-src: the GitHub runner preinstalls eleven the developer boxes here do not.
  Each costs one row with a reason, and an unknown one reds rather than being
  assumed PECL — the assumption that would have let `uri` through in 8.5.

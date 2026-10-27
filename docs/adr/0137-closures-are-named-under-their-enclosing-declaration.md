# 0137. Closures Are Named Under Their Enclosing Declaration

**Date:** 2026-10-27
**Status:** Accepted

**Amends:** [ADR 0026](0026-assigned-declaration-ordinal.md) in four places — the
closure case of the stability statement, the sentence that a closure inside an
anonymous class "does not inherit the instability", the description of
`{closure#N}` as a number minted outside the ordinal index, and the Context
paragraph that calls unnamed declarations identity-free beyond their sequence
position. Every other decision in ADR 0026 stands, including the assigned
ordinal, the two guards and the anonymous-class case.

## Context

ADR 0026 named a closure or arrow function `{closure#N}`, where N is its rank
among the anonymous callables of the whole file in traversal order, and listed
that as one of exactly three unstable identities. The listing was accurate and
the instability was avoidable: a closure has an obvious, stable parent — the
declaration it is written in — and the file-wide rank threw that parent away.
Two costs followed (issue #201):

- A report line read `App\Foo\{closure#4}`. Nothing in the name said where the
  code was.
- Adding a closure anywhere above — in an unrelated class — renamed every closure
  below it, so their baseline entries and finding fingerprints moved and the
  rebinding described in ADR 0026 item 2 applied to all of them.

## Decision

### 1. A closure is named after its nearest enclosing frame

The member of a closure or arrow function is `{parent}::{closure#k}`, so its
logical name is `{Ns}\{parent}::{closure#k}`.

The parent is the **nearest enclosing frame of any kind, by lexical depth**: a
callable (function, method, property hook, another closure) or a class-like
declaration (named or anonymous). `k` counts the anonymous callables minted
directly inside that frame, from 1. Outside every frame the file is the parent:
`{Ns}\{closure#N}`, one counter **per namespace of the file**, continuing across
that namespace's blocks. A closure added in namespace A does not rename one in
namespace B.

The parent contributes **its own logical name and nothing above it**. For a
class-like parent that means no chain through an outer class or method:

| Closure written in                       | Logical name                                           |
| ---------------------------------------- | ------------------------------------------------------ |
| method `A::rows`                         | `App\A::rows::{closure#1}`                             |
| a closure in that closure                | `App\A::rows::{closure#1}::{closure#1}`                |
| function `f`                             | `App\f::{closure#1}`                                   |
| property hook `A::x::get`                | `App\A::x::get::{closure#1}`                           |
| a field initialiser of class `X`         | `App\X::{closure#1}`                                   |
| a named class declared inside a function | `App\X::{closure#1}` — the function is not in the name |
| a method of an anonymous class           | `App\{anonymous#0}::run::{closure#1}`                  |
| outside every frame                      | `App\{closure#1}`                                      |

Chaining applies only between callables, because a closure's name is the name of
the frame it was minted in.

### 2. Identity stays function-shaped

The composite name is the callable's *member*. Its path remains `func:` /
`SymbolType::Function_`, so it aggregates into its **namespace** and never into a
class, `--class` does not select it, and ClassRank, the HTML partitioner and the
long-parameter-list labels cannot mistake it for a method. Naming is identity,
not ownership.

One composition rule, `DeclarationNumbering::callablePath()`, builds the logical
path for the member, the logical FQN, the declaration numbering and the callable
metadata. The metric-map key a collector stores for a closure outside a class is
now that same path, `App\{closure#1}` (it was `App::{closure#1}`).

The ordinal index of ADR 0026 keeps its role and gains a stronger guarantee: the
registrar numbers the whole file in its own pre-pass, so an ordinal is the final
rank by position, independent of traversal order. This also closes a defect ADR
0026 left unstated for every declaration kind — same-named declarations in a
`new class` body and in its constructor arguments received one ordinal and the
file failed.

Two frames with the same logical name produce identical closure names: the
branches of a conditional function declaration, or a class and a function of the
same name in one namespace and file. Only the positional ordinal tells those
apart, so adding a closure to one parent may shift the ordinal of a same-named
parent's closure further down the file.

### 3. The restated stability statement

ADR 0026 listed three unstable cases. The first two change:

> - **A closure.** `{closure#k}` is its rank among the anonymous callables minted
>   directly in its parent frame. It moves only when an anonymous callable is
>   added to, removed from, or reordered within that same frame. A closure in
>   another method, class or the rest of the file cannot move it. **Renaming the
>   parent renames every closure inside it**, because the parent's name is a part
>   of theirs.
> - **An anonymous class.** Unchanged in what it counts: `{anonymous#N}` is the
>   file's N-th unnamed class-like declaration. It reaches stored keys through its
>   members, and **now also through the closures declared in those members or in
>   its own body**, whose name carries the minted class name. The earlier statement
>   that such a closure does not inherit the instability no longer holds. A
>   code-smell or security finding inside such a closure is still attributed to the
>   file subject.
> - **A named declaration sharing its logical identity** — unchanged.

ADR 0026's remark that traversal order differs from source order for
`new class (…) { … }` no longer affects any assigned ordinal, and it does not
affect closure counters inside named frames.

The silent-rebinding consequence of ADR 0026 item 2 applies exactly as before,
over the narrower set of renamings above.

### Alternatives rejected

- **Change the closure's path type to `Method`.** It would name the closure after
  its class in aggregation, so ClassRank, the HTML partitioner and the
  long-parameter-list labels would treat it as a method of that class and the
  closure's metrics would roll into the class. Name and ownership are separate
  questions.
- **Keep a file-wide counter and add the parent only for display.** The identity
  would keep the instability that motivated the issue.
- **Chain through every enclosing declaration for a class-like parent.** It makes
  a class's closure name depend on where the class sits, duplicates information
  the namespace already carries, and gains nothing for locating the code.

## Consequences

- **Stored identity changes once.** Baseline keys of closure findings move, for
  example `declaration:func:App::{closure#2}@src/A.php` becomes
  `declaration:func:App::B::f::{closure#1}@src/A.php`, and so do finding
  fingerprints and the GitLab Code Quality and SARIF fingerprints derived from
  them. Previously seen GitLab findings show once as new and closed or dismissed
  GitHub code scanning alerts reappear as open.
- **The baseline format version becomes 15.** The key grammar is the same, but
  the meaning of a closure key changed, and a file-level `App\{closure#N}` spells
  exactly like the old file-wide rank: an old entry would silently re-attach to
  whichever closure holds that number instead of going stale. The version number
  is the only marker of old keys. Version 14 still loads; its closure entries
  become inert (`retired closure identity`) under their unchanged selector, and
  retired contenders of one duplicated identity share a selector that removes all
  of them. Every command that writes version 15 from such a file
  (`baseline:update`, `baseline:cleanup --remove`) refuses while any remains;
  `baseline:generate` reads no previous file; `baseline:rename-channels` writes
  the version it read, so its output stays version 14 with the entries retired.
  Editing the number from 14 to 15 by hand is not a migration: it turns the old
  keys back into live ones and restores the silent re-attachment.
- **One migration procedure.** List the retired entries with
  `baseline:cleanup <baseline> <paths...>`; remove all of them in one call with
  `baseline:cleanup <baseline> <paths...> --remove=<selector> ...`, which writes
  version 15 and keeps every other entry; fix or accept the closure findings now
  reported as new with `baseline:update <baseline> <paths...>
  --accept-new=<channel>` (it accepts every new identity of the channel, so the
  diff is reviewed). Never `baseline:generate --force`, never a hand edit of the
  version number. The hand conversion of a version 13 file stops at 14.
- **Rejected for the version-14 file:** refusing the whole document (more
  friction, hand edits); dropping retired entries silently on write (an
  unrequested deletion); rewriting old keys (the file-wide rank cannot recover the
  parent); `baseline:generate --force` (re-captures unrelated ceilings).
- **Reports locate the code.** `App\A::rows::{closure#1}` names its method.
- **No ratchet entry of this repository names a closure**, so its own baseline is
  unaffected.

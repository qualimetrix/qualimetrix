# Stage 01 — one owner per language for the distance to the root

Lands and is proved **before** anything moves. Nothing in this stage changes
where a file lives; after it, the repository knows where the viewer is in
exactly two places instead of three, and a check refuses when either is wrong.

## Why this is not "edit two literals when we move"

A hop count is a claim about the tree that the tree does not check. The measured
failure modes differ by language and both are quiet in their own way:

- **PHP.** `\dirname(__DIR__, N)` with a stale `N` resolves to a directory
  *above* the repository. A walk of it returns nothing, and a check asserting a
  property of every member of an empty set passes. CLAUDE.md names this shape
  for governance controls; here it surfaces as a runtime refusal instead, but
  only on the one command that renders HTML.
- **JS.** Measured at the root destination: the four-hop resolved two levels
  above the repository root and produced `ENOENT`. Loud — but only because one
  test happens to call the module. Its sibling `collect-metric-keys.mjs` carries
  the same four-hop, nothing executes it, and it would have written its output
  outside the repository.

Both are the same defect: the code names a *distance* where it means a
*destination*. One owner per language turns the distance into a single fact that
one check can judge.

## Contracts

Two owners, one per language. Neither is a general-purpose utility — each
answers one question for one subject, and the check below is what keeps them
honest.

**PHP.** A single value object inside the Reporting capability that answers
"where does the viewer's shipped asset live?". It computes the package root once
and joins the asset name; no other PHP file computes a hop count toward it.

```
final readonly class <name>
{
    public function __construct(?string $packageRoot = null);   // null: derive
    public function asset(string $name): string;                // absolute path
    public function directory(): string;
    // ... implementation details: the single dirname() hop, the existence
    // refusal, and the message that names the build command
}
```

Its refusal message currently repeats the path as prose
(`HtmlFormatter.php:83` advises `cd src/Reporting/Template && npm run build`).
The message becomes the owner's business too, so a move cannot leave advice
pointing at a directory that is gone — measured as breakage A2.

**JS.** One module exporting the repository root, imported by both
`metric-key-catalog.mjs` and `collect-metric-keys.mjs`. Neither computes hops
any more.

```
// repo-root.mjs
export const REPO_ROOT;            // resolved once, from this module's own place
export function fromRoot(...parts);
```

## The check that bites

One control, and it must fail on a wrong depth rather than on a missing file —
those are different failures and only the first is the subject here.

**What it asserts.** The path each owner computes is the repository root,
established independently of the owner's own arithmetic: by locating a tracked
marker that exists exactly once at the root (`composer.json` beside
`.gitattributes`), not by counting directories. Then: every asset the PHP owner
names exists, and every PHP file the JS owner reads exists.

**Why independence matters.** A check that re-derives the root by the same hop
count it is checking agrees with itself on a wrong tree. This is the tautology
that has already cost this repository a round — the control must reach the root
by a different means than the code under test.

**Both languages, one verdict.** The PHP side runs in the `Governance` suite.
The JS side has no governance reach today, so the assertion about the JS owner's
root is made from PHP over the JS module's resolved value, obtained by executing
node — or, if node is absent, the control **refuses** rather than skips.
`scripts/init-environment.sh` installs no node (measured), so a skip here would
be permanently invisible in the web environment.

## Definition of Done

Negative checks are written as refusals, not as printed counts: `grep -c`
exits 0 when it finds the forbidden string and 1 when the file is clean, so a
gate phrased "returns 0" is green on a dirty tree. Measured on this tree.

1. `! git grep -qP 'dirname\(__DIR__' -- src/Reporting/` except inside the PHP
   owner — one hop count in the capability, named explicitly.
2. `! git grep -qP "\.\.', '\.\." -- '*.mjs' '*.js'` outside the JS owner, with
   `-P` or `-wE`: `git grep -E` does not honour `\b` here and returns nothing
   where `grep` returns matches. Measured today.
3. The control **fails on a planted wrong depth** — plant it, quote the refusal
   verbatim, restore from a copy taken *before* the plant. A control that has
   never been red is not a control.
4. The control **refuses, not skips, when node is unavailable** — proved by
   running it with node off `PATH`, and the refusal quoted.
5. `composer check` green from a clean clone with copied `vendor`,
   `website/.venv` and `node_modules`.
6. Nothing moved: `git diff --stat` names no rename.

## Test plan

One new governance control, described above. No new unit tests for the owners
themselves: their whole behaviour is the path, and the control is the assertion
about it — a unit test that recomputes the same join would be the tautology this
stage exists to remove.

The existing `composer test:js` covers the JS owner indirectly, because
`metric-key-catalog.test.js` fails loudly when the root is wrong. That is
evidence the owner works, not evidence the depth is guarded; item 3 is what
guards it.

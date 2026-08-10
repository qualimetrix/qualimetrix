# Scanner validation round 2 handoff

## Resume contract

- In the Codex UI, start the new task from the existing `codex/scanner-validation-round-2` branch and choose **New worktree**. The new physical worktree must start from this checkpoint, not from `main`.
- If resuming directly in the original worktree instead, keep its existing branch and do not switch it.
- A Codex-created task worktree may use its own generated task branch; the required invariant is that `SCANNER_VALIDATION_ROUND_2_HANDOFF.md` and the checkpoint changes are present at task start.
- Original implementation base: `ba1119a0bd87afa8f17f3ddbbfd43683c5db5ec5`.
- Resume from the `codex/scanner-validation-round-2` branch HEAD containing this file. Its previous checkpoint parent was `730b4c5b1f0a01c5d34aca88f9d316519e73b6bc`.
- The main checkout is separate and was not modified by this task.
- P1 through P4 are checkpointed on the branch. Do not push, stash, restore, checkout files, or clean the worktree without explicit user approval.
- Read `AGENTS.md`, `docs/ARCHITECTURE.md`, the relevant component READMEs, `docs/internal/SCANNER_VALIDATION_ROUND_2_PLAN.md`, and `docs/adr/0021-declaration-scoped-callable-identity-and-dependency-projections.md` before resuming.
- Load the planning, delegation, subject-cohesion, and review workflow skills as required by `AGENTS.md`.
- Work as an orchestrator; implementation packages go to subagents.

## Plan status

The plan has 9 stages.

1. Validate findings: complete.
2. Plan, ADR, and plan review: complete.
3. P1 callable contract rename: complete and reviewed.
4. P2+P3 callable production and declaration-aware transport: complete and reviewed.
5. P4 NPath semantics: complete and reviewed.
6. P5 rules, baseline, reporting, controls, and Architecture 0..N resolution: inventory complete; implementation not started.
7. P6 coupling and ClassRank semantics: not started.
8. P7 documentation, changelog, and finding statuses: not started.
9. P8 full validation and final review: not started.

## Completed implementation in P1 through P4

- Renamed the public/internal hierarchy from Method to Callable without compatibility aliases.
- Added `CallableKind`, `DeclarationPath`, `LogicalClassPath`, and `MetricSubject` contracts.
- Added final callable DTO/provider and declaration-aware repository/transport seams.
- Dependency edges now carry exact declaration sources and logical targets; graph construction has a mandatory logical-class universe.
- Implemented property-hook participation in callable metrics and applicable rules.
- Callable captures no longer count as RFC execution; Halstead distinguishes capture from invocation.
- Fixed duplicate declaration transport, exact derived MI keys, namespace declaration contributions, anonymous-class callable attribution, repository declaration guards, `line=0` presentation failures, and duplicate logical-class iteration.
- P2+P3 corrections preserve exact class/callable declarations while maintaining a single location-free logical-class projection.
- Callable source lines now remain distinct from byte offsets through visitors, collection, derived extraction, repository storage, and presentation.
- Repository typed identity is independent of `addSubject()` / `addCallable()` and merge operand order; conflicting typed metadata fails fast.
- Duplicate-FQN derived Maintainability Index values remain distinct through exact storage and class/namespace aggregation.
- Pre-P5 computed-class findings resolve a presentation location only for a unique exact class declaration; zero or multiple declarations remain location-free.
- P4 separates ordinary and nullsafe NPath contributions. Callable expression boundaries calculate `max(1, ordinary) + nullsafe`, including wrapped, arrow, property-hook, and `echo` forms without changing structural-condition semantics.
- Final P1-P4 validation: 6860 tests / 17402 assertions, one skipped; PHPStan (1163 files), CS, and `git diff --check` are green.

## Review status

The P2+P3 reviewer initially confirmed five inherited seams:

1. Duplicate class declarations collapsed in class transport.
2. Duplicate callable contributions were lost in namespace roll-up.
3. Derived maintainability metrics were keyed by logical FQN rather than exact declaration.
4. Callables nested in anonymous classes were omitted.
5. Legacy repository `add(SymbolPath)` synthesized declaration identity from a line number.

All five seams and the subsequent address-review findings are resolved. The additional resolved findings covered source-line/byte-offset confusion, typed repository order dependence, missing duplicate-FQN MI coverage, source locations leaking into logical-class projections, and the unique-declaration presentation bridge. P4's root-only nullsafe heuristic was also rejected and replaced with split ordinary/nullsafe contributions. Final address reviews found no remaining P2-P4 issues.

## Exact checkpoint state

- Branch: `codex/scanner-validation-round-2`.
- P1-P4 implementation and direct regressions are checkpointed; there are no known validation blockers.
- Full PHPUnit: 6860 tests / 17402 assertions, one skipped, green.
- PHPStan: 1163 files, green. CS and `git diff --check`: green.
- No commit has been pushed.
- P5 reconnaissance was read-only and made no production changes.

## P5 inventory decisions

- `src/Core/Violation/ViolationCollection.php` named by the original P5 plan does not exist and must be removed from the execution inventory.
- `Violation` needs one mandatory `MetricSubject`; `SymbolPath` remains a logical/display projection, while fingerprints use the canonical subject.
- The current baseline is v10 and its identity is incompatible with declaration findings. P5 needs an explicit new schema version; there is no silent v10 conversion or regeneration. The existing migrator only handles v5 to v10.
- All 29 layered violation-construction sites plus the two Architecture rules must migrate atomically or in one compile-complete wave; no compatibility fallback is allowed.
- LayerViolation projection semantics are fixed by ADR/P5: an unowned logical target produces one source-declaration finding; one or more owned target declarations produce one independently controlled finding per exact target declaration.
- Hook control precedence is `hook > property > class > config`. Property is a control scope, not a new metric-subject variant.
- GitLab and SARIF fingerprints must use the shared declaration identity. Logical dependency graph exports remain logical projections and require proof-only regressions rather than an identity-format change.

## Resume sequence

1. Inspect `git status --short`, confirm branch `codex/scanner-validation-round-2`, and verify this checkpoint is present.
2. Turn the completed P5 inventories into a reviewed, literal file-disjoint execution plan. Do not reuse the original globs.
3. Start with the shared subject-aware `Violation` contract and a compile-complete migration wave for every violation constructor.
4. Implement control ingress/precedence and Architecture 0..N declaration projection against that contract.
5. Implement the explicit baseline schema bump, then non-HTML reporting/fingerprints, HTML/health projection, and console lifecycle packages.
6. Validate and review every package; run full PHPUnit, PHPStan, CS, self-analysis, and required JS/site checks at the integrated P5 boundary.

## Known orchestration/UI behavior

- The Codex UI may display old completed recon/review agents as active for hours. Use the live agent tree as the source of truth.
- A completed agent turn does not lose its context or filesystem changes; it can be resumed with a follow-up task.
- Keep the root orchestration turn active while waiting for agents. If the root sends a final response, later agent completion does not reliably wake it automatically.

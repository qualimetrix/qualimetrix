# Scanner validation round 2 handoff

## Resume contract

- In the Codex UI, start the new task from the existing `codex/scanner-validation-round-2` branch and choose **New worktree**. The new physical worktree must start from this checkpoint, not from `main`.
- If resuming directly in the original worktree instead, keep its existing branch and do not switch it.
- A Codex-created task worktree may use its own generated task branch; the required invariant is that `SCANNER_VALIDATION_ROUND_2_HANDOFF.md` and the checkpoint changes are present at task start.
- Base HEAD: `ba1119a0bd87afa8f17f3ddbbfd43683c5db5ec5`.
- The main checkout is separate and was not modified by this task.
- All current changes are intentionally uncommitted. Do not commit, push, stash, restore, checkout files, or clean the worktree without explicit user approval.
- Read `AGENTS.md`, `docs/ARCHITECTURE.md`, the relevant component READMEs, `docs/internal/SCANNER_VALIDATION_ROUND_2_PLAN.md`, and `docs/adr/0021-declaration-scoped-callable-identity-and-dependency-projections.md` before resuming.
- Load the planning, delegation, subject-cohesion, and review workflow skills as required by `AGENTS.md`.
- Work as an orchestrator; implementation packages go to subagents.

## Plan status

The plan has 9 stages.

1. Validate findings: complete.
2. Plan, ADR, and plan review: complete.
3. P1 callable contract rename: complete and reviewed.
4. P2+P3 callable production and declaration-aware transport: in progress.
5. P4 NPath semantics: not started.
6. P5 rules, baseline, reporting, controls, and Architecture 0..N resolution: not started.
7. P6 coupling and ClassRank semantics: not started.
8. P7 documentation, changelog, and finding statuses: not started.
9. P8 full validation and final review: not started.

## Completed implementation in P1 and P2+P3

- Renamed the public/internal hierarchy from Method to Callable without compatibility aliases.
- Added `CallableKind`, `DeclarationPath`, `LogicalClassPath`, and `MetricSubject` contracts.
- Added final callable DTO/provider and declaration-aware repository/transport seams.
- Dependency edges now carry exact declaration sources and logical targets; graph construction has a mandatory logical-class universe.
- Implemented property-hook participation in callable metrics and applicable rules.
- Callable captures no longer count as RFC execution; Halstead distinguishes capture from invocation.
- Fixed duplicate declaration transport, exact derived MI keys, namespace declaration contributions, anonymous-class callable attribution, repository declaration guards, `line=0` presentation failures, and duplicate logical-class iteration.
- P1 full validation was green before P2+P3 corrections: 6801 tests / 17136 assertions, PHPStan and CS green.
- P2+P3 was once green before mandatory review corrections: 6833 tests / 17205 assertions, one skipped, PHPStan and CS green.

## Mandatory review findings being addressed

The P2+P3 reviewer confirmed five seams:

1. Duplicate class declarations collapsed in class transport.
2. Duplicate callable contributions were lost in namespace roll-up.
3. Derived maintainability metrics were keyed by logical FQN rather than exact declaration.
4. Callables nested in anonymous classes were omitted.
5. Legacy repository `add(SymbolPath)` synthesized declaration identity from a line number.

Production corrections for these findings have been implemented. The remaining work is validation and stale test-fixture migration.

## Exact current state when paused

- All agents were explicitly stopped before writing this handoff.
- Current diff: 274 files changed, approximately 3780 insertions and 2350 deletions, plus new untracked contract/tests/plan/ADR files.
- `git diff --check` is green at pause time.
- Latest complete full PHPUnit run before the last two systemic fixes had 35 errors and 28 failures.
- Root causes from that run: aggregate/logical `SymbolInfo` passed line `0` into `Location`, and `all(SymbolType::Class_)` double-counted logical classes. Both fixes are now present.
- The checkpoint pre-commit hook completed successfully after the latest corrections: PHPStan and CS are green.
- Full PHPUnit was not run by the checkpoint hook and remains the required validation blocker.
- Repeat Golden/Invariant/Architecture/full PHPUnit results have not yet been obtained after the last fixes.
- `CollectionOrchestratorTest` still needs final declaration-subject fixture verification/migration.

## Approved remaining P2+P3 test scope

The following test-only migrations are explicitly approved as part of P2+P3:

- `tests/Unit/Analysis/Collection/CollectionOrchestratorTest.php`
- `tests/Unit/Analysis/Aggregator/GlobalFunctionAggregationTest.php`
- `tests/Integration/Pipeline/AnalysisPipelineIntegrationTest.php`
- `tests/Functional/Console/Command/BaselineExplainCommandTest.php`
- `tests/Unit/Baseline/BoundaryExplanationServiceTest.php`
- `tests/Unit/Reporting/Formatter/Html/HtmlTreeBuilderTest.php`

These are mechanical migrations to typed declaration repository APIs, not authorization for new production semantics.

## Resume sequence

1. Inspect `git status --short` and confirm the expected branch and base HEAD.
2. Resume the P2+P3 correction agent in the same shared worktree. Explicitly prohibit whole-tree git operations.
3. Finish `CollectionOrchestratorTest` and any already-approved declaration-subject fixtures.
4. Run and diagnose focused Golden File Aggregation, aggregation invariant, and Architecture suites.
5. Run full `composer test` and classify the entire failure batch before editing.
6. Run `composer phpstan`, `composer cs-check`, and `git diff --check`.
7. Return the corrected P2+P3 diff to the existing reviewer for an address review of all five findings.
8. Only after reviewer approval, mark stage 4 complete and proceed to P4.

## Known orchestration/UI behavior

- The Codex UI may display old completed recon/review agents as active for hours. Use the live agent tree as the source of truth.
- A completed agent turn does not lose its context or filesystem changes; it can be resumed with a follow-up task.
- Keep the root orchestration turn active while waiting for agents. If the root sends a final response, later agent completion does not reliably wake it automatically.

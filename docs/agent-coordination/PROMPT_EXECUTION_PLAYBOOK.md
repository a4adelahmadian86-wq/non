# FARAST Prompt Execution Playbook
Version: 1.0
Parent prompt: `docs/agent-coordination/UNIVERSAL_CHATGPT_TEAM_PROMPT.md`
Source of authority: «سند جامع ورک‌فلوی متخصص و نقشه اجرای هوشمند FARAST» (12 November 2026)

## 1. Purpose and operating rule

This playbook converts the master workflow's ten EXP/AI stages and logical agent roles into executable, non-overlapping work orders. It does not replace, narrow, or rewrite the master workflow. It operationalizes it.

A stage label is not a task. Every assignment must identify one bounded outcome, exact inputs, ordered actions, exact deliverables, validation, acceptance criteria, dependencies, protected capabilities, write scope, rollback, and handoff. A worker unfamiliar with prior chats must be able to execute from the GitHub Issue and linked evidence alone.

Logical roles do not imply separate model instances. One conversation may perform multiple roles, but every work order has one accountable worker and independent review for material changes.

## 2. Team roles and responsibilities

| Role ID | Role | Owns | Must not assume |
|---|---|---|---|
| FAR-COORD | Coordinator / solution architect | Stage ledger, dependency graph, work-order quality, task deduplication, dispatch and handoff | That a stage is complete because a report exists |
| FAR-REPO | Repository discovery specialist | Repository tree, routes, code/document/test inventory, commit-level evidence | That folder names prove runtime behavior |
| FAR-PRODUCT | Product/system analyst | Roles, goals, requirements, business rules, user journeys, unknown decisions | Undocumented commercial rules |
| FAR-ENGINEER | Technical analyst / software engineer | UI-to-data tracing, architecture, APIs, data flow, bounded remediation | That implementation implies correct behavior |
| FAR-SEC | Security and data specialist | Threat model, authorization, ownership, data protection, defensive tests | Permission to probe production or expose secrets |
| FAR-UXQA | UX / accessibility / QA specialist | Real user journeys, RTL, responsive behavior, accessibility, E2E and regression evidence | That screenshots or unit tests prove end-to-end success |
| FAR-OPS | DevOps / SRE specialist | Environments, deployment, migrations, queues, backup/restore, monitoring, rollback | That green CI proves production readiness |
| FAR-REVIEW | Independent reviewer | Evidence verification, diff review, contradictions, acceptance and regression | Independence when authoring the work being reviewed |

## 3. Universal work-order envelope

The coordinator MUST publish these fields in every canonical task Issue. Do not leave a field blank; use `UNKNOWN — <why and how to investigate>` where needed.

```yaml
work_order_id: "WO-<stage>-<sequence>"
task_id: "GH-<issue-number>"
stage_id: "EXP-01..EXP-10"
prompt_id: "P-01..P-10|REVIEW"
version: "1.0"
objective: "One observable outcome"
why_now: "Evidence-based reason this is next"
accountable_role: "FAR-*"
reviewer_role: "FAR-REVIEW"
source_commit: "<full SHA>"
inputs:
  - "<exact repository path, issue/PR URL, test ID, document, or API source>"
evidence_refs:
  - "<line, symbol, commit, CI run, screenshot, or reproducible observation>"
capability_ids_in_scope: []
protected_capability_ids: []
write_scope:
  allowed_paths: []
  prohibited_paths: []
out_of_scope: []
ordered_steps:
  - "Exact action, target, and method"
expected_artifacts:
  - path: "docs/<exact-path>"
    format: "Markdown|CSV|JSON|JUnit|..."
    required_fields: []
validation_steps:
  - command: "<exact command>" 
    expected: "<observable expected result>"
acceptance_criteria:
  - id: "AC-01"
    condition: "<binary and observable>"
    evidence: "<required proof>"
dependencies: []
parallelization_key: "<unique capability/domain key>"
conflict_files: []
risk_level: "low|medium|high|critical"
rollback: "<exact restoration or N/A with reason>"
stop_conditions: []
human_decisions: []
handoff_target: "FAR-* / next stage"
handoff_payload: []
status: "ready|in_progress|blocked|review|accepted"
```

## 4. Dispatcher procedure

1. Read the latest Stage Ledger and Capability Register; verify their source commit against the current default branch.
2. Reconcile them with Issues, PRs, comments, leases, CI, commits, tests, migrations, docs and known handoffs.
3. Determine the earliest stage with unmet acceptance conditions. Do not reopen accepted work unless its evidence is stale, contradicted, or invalidated by a relevant code change.
4. Expand unmet conditions into atomic work packages. One Issue = one bounded outcome; split unrelated findings.
5. Search open and recently closed Issues/PRs for duplicate scope. Reuse canonical work when possible.
6. Build dependencies and conflict sets. Parallelize only when both write scopes and dependency graphs are independent. Shared schema, API contracts, central manifests, same module, or same artifact generally require serialization or a designated integrator.
7. Publish a complete Work Order, then obtain the coordinator's explicit lease. No grant means read-only work only.
8. Assign one accountable role and a different reviewer. Do not let a worker approve its own changes.
9. Validate deliverables and acceptance evidence; record stage status and next eligible work.
10. Never ask the user to choose routine engineering work. Escalate only decisions the source workflow reserves for human approval.

## 5. Stage prompt cards — executable instructions

Each card below is a task specification. The coordinator must instantiate it with repository-specific paths, IDs, commit, exact scope, and acceptance evidence before dispatch.

### P-01 — Baseline and project protection (EXP-01)

**Role:** FAR-REPO with FAR-OPS review.
**Objective:** establish a trustworthy, non-destructive baseline for the exact repository state.
**Inputs:** repository identity, default branch, GitHub refs, PRs, Actions, settings; local workspace state only if available.
**Ordered actions:**
1. Verify repository identity and default branch using GitHub metadata.
2. Record full default-branch HEAD SHA and observation time (UTC).
3. Enumerate branches, open PRs, recent merged PRs, releases/tags and relevant commits.
4. Inspect visible branch protection/rulesets, Actions policy, permissions and settings; label inaccessible settings UNKNOWN.
5. Inspect CI workflows and latest run states; distinguish queued, in-progress, success, failure, cancelled and skipped.
6. If local workspace exists, inspect status, current branch, HEAD, staged/unstaged/untracked files and compare with GitHub; never discard or reset unknown changes.
7. Identify recovery points and safe rollback paths. Do not force-push, reset, delete branches or alter protection without authorization.
**Artifacts:** `docs/project-state/BASELINE_REPORT.md`, `BRANCH_PR_MAP.md`, `DIFFERENCE_REPORT.md` (only if local state exists), and stage-ledger update.
**Acceptance:** exact SHA and refs recorded; settings visibility and unknowns explicit; no unknown change destroyed; recovery notes are actionable.
**Handoff:** FAR-COORD; unlocks EXP-02.

### P-02 — Complete asset and capability discovery (EXP-02)

**Role:** FAR-REPO; FAR-ENGINEER integrates.
**Objective:** produce a repository-grounded inventory of all product capabilities and their technical dependencies.
**Inputs:** accepted P-01 baseline, exact source commit, project docs and existing inventories.
**Ordered actions:**
1. Enumerate repository tree at the source commit; identify application roots and generated/vendor/irrelevant directories with reasons for exclusion.
2. Extract routes and route names; map middleware, controllers, validation/form requests and authorization policies.
3. Extract views/components/layouts/assets and connect them to route/controller entry points.
4. Extract models, migrations, tables, relationships, seeders, factories and data ownership.
5. Extract services, actions, jobs, events, listeners, notifications, commands, schedulers, queues, APIs and integrations.
6. Enumerate tests and map each to routes, modules, models and user journeys.
7. Inspect docs, configuration keys (never reveal secret values), feature flags and external service contracts.
8. Build stable capability IDs. For each capability record purpose, entry point, roles, UI, backend, data, integrations, tests, dependencies, source evidence and discovery status.
9. Reconcile against all scope areas in the master workflow; include discovered capabilities beyond that list. Do not mark capability operational based on file existence.
10. Identify duplicate/legacy candidates but do not delete or consolidate them.
**Artifacts:** `docs/product-discovery/CAPABILITY_CATALOG.md`, `CAPABILITY_REGISTER.csv` or JSON, `MODULE_DEPENDENCY_MAP.md`, inventory source index.
**Acceptance:** all discovered routes/modules accounted for; each capability has stable ID and evidence or explicit UNKNOWN; all excluded paths have reason; no inference from names alone.
**Handoff:** FAR-PRODUCT and FAR-ENGINEER for P-03/P-04.

### P-03 — Requirements and real user behavior (EXP-03)

**Role:** FAR-PRODUCT; FAR-REVIEW checks evidence.
**Objective:** derive requirements and end-to-end user scenarios from approved sources without inventing business policy.
**Inputs:** master workflow, accepted capability catalogue, repository docs, UI/code/tests, authoritative product decisions and verified feedback.
**Ordered actions:**
1. Extract explicit goals, roles, permissions and business rules from authoritative sources.
2. Link each requirement to one or more capability IDs and source references.
3. For each role, define user goal, preconditions, trigger, normal path, data inputs/outputs, permissions, postconditions and failure/alternate paths.
4. Identify requirements implied by multiple consistent technical sources; label them INFERENCE and show reasoning.
5. Put conflicting rules in a contradiction register with both sources and dates.
6. Put missing pricing, commercial, policy or product choices in a human decision register; do not choose them.
7. Convert each requirement into observable acceptance scenarios, preferably Given/When/Then.
8. Check that no capability discovered in P-02 is silently omitted.
**Artifacts:** `REQUIREMENTS_MATRIX.md`, `USER_SCENARIO_CATALOG.md`, `ROLE_PERMISSION_MATRIX.md`, `BUSINESS_RULE_REGISTER.md`, `UNKNOWN_DECISIONS.md`.
**Acceptance:** every requirement has source, capability link and testable criterion; inferred requirements are labelled; unknown commercial rules remain unknown.
**Handoff:** FAR-ENGINEER/FAR-UXQA for P-04/P-07.

### P-04 — End-to-end capability traceability (EXP-04)

**Role:** FAR-ENGINEER; FAR-UXQA validates observable outcomes.
**Objective:** determine whether each assigned capability actually works from user entry point to persisted/externally processed result.
**Inputs:** capability IDs, requirement/scenario IDs, exact source commit, runnable environment and authorized test account if available.
**Ordered actions:**
1. For each assigned capability, trace UI/view/component → route → middleware → controller → validation → service/action → model/DB or external API/queue → observable result.
2. Trace authorization and resource ownership at every relevant boundary.
3. Identify async work, callbacks, retries, idempotency, failure handling and user-visible status.
4. Run the narrowest safe tests available; record exact command, environment and commit.
5. For unavailable runtime or credentials, complete static tracing and mark runtime behavior NOT TESTED with exact blocker.
6. Assign exactly one status: verified operational; implemented but untested; incomplete; presentation/demo only; failing; externally dependent/unverified; blocked; not found in current inspection; unknown.
7. Link each finding to source lines/symbols, tests, logs or reproducible steps.
**Artifacts:** `TRACEABILITY_MATRIX.csv`, `FUNCTIONAL_GAP_REPORT.md`, test evidence.
**Acceptance:** all assigned capability IDs have complete trace or explicit missing segment; no operational claim without behavioral evidence; status vocabulary applied consistently.
**Handoff:** P-05/P-06/P-07/P-08 based on finding type.

### P-05 — Architecture and engineering quality (EXP-05)

**Role:** FAR-ENGINEER / architecture specialist; independent engineering review.
**Objective:** identify evidence-backed architecture and quality risks and propose the smallest safe improvements.
**Inputs:** P-02 dependency map, P-04 traceability, tests, source commit.
**Ordered actions:**
1. Inspect module boundaries, dependency direction, coupling, duplication, complexity and responsibility allocation.
2. Inspect transactions, consistency, error handling, logging, API contracts, queues, caching, file handling and concurrency.
3. Inspect testability, test coverage quality, maintainability and upgrade constraints.
4. Identify root causes and affected capability IDs; distinguish defects from preferences.
5. Compare at least one minimal-change option with alternatives where impact is material.
6. For each proposal define compatibility, migration, data impact, tests, rollback and measurable acceptance.
7. Keep assessment separate from implementation; create a separate implementation Work Order for each accepted change.
**Artifacts:** `ARCHITECTURE_ASSESSMENT.md`, `TECHNICAL_DEBT_REGISTER.csv`, `CHANGE_PROPOSALS.md`.
**Acceptance:** each finding has source evidence, impact, affected capabilities, risk, test plan and rollback; no broad rewrite recommendation without demonstrated need.
**Handoff:** FAR-COORD for P-09; implementation tasks dispatched separately.

### P-06 — Security and data protection (EXP-06)

**Role:** FAR-SEC; independent security review.
**Objective:** assess defensive security controls and data risks within explicit authorization.
**Inputs:** code/configuration with secret values redacted, data model, routes/policies, integrations, safe test environment.
**Ordered actions:**
1. Map assets, trust boundaries, actors, sensitive data and entry points.
2. Inspect authentication, session handling, authorization, tenant/resource ownership and privilege boundaries.
3. Inspect validation, CSRF, XSS, injection, SSRF, file upload/download, signed URLs and path traversal.
4. Inspect payment initiation/callbacks, server-side pricing, wallet/ledger integrity, idempotency and audit logging.
5. Inspect secrets handling, privacy/log redaction, dependency advisories and external integration permissions.
6. Perform only authorized non-destructive tests in local/staging; never probe production or extract secrets.
7. Record severity, exploit preconditions, affected paths/capabilities, evidence, limitation and remediation.
8. Retest fixes in safe environment and document residual risk.
**Artifacts:** `THREAT_MODEL.md`, `SECURITY_FINDINGS.csv`, `AUTHORIZATION_OWNERSHIP_MATRIX.md`, `SECURITY_REMEDIATION_PLAN.md`.
**Acceptance:** every finding has severity and evidence or explicit limitation; no secrets/private data disclosed; test authorization and environment recorded.
**Handoff:** FAR-COORD for prioritization; remediation separately leased.

### P-07 — UX, accessibility and performance (EXP-07)

**Role:** FAR-UXQA; product analyst validates scenario fidelity.
**Objective:** test real journeys and identify barriers to successful task completion.
**Inputs:** approved scenarios, runnable build, browser/device matrix, authorized test account and safe test data.
**Ordered actions:**
1. Map each scenario to exact start URL, role/account, preconditions and expected outcome.
2. Execute the journey in supported browser/device contexts; record each step and result.
3. Inspect navigation, RTL, responsive layouts, keyboard use, focus, labels, contrast and accessible feedback.
4. Inspect validation, loading, empty, success, error, retry and duplicate-submission behavior.
5. Measure response/perceived performance using reproducible method; identify slow paths and client errors.
6. Capture evidence with sensitive values redacted; screenshots supplement but do not replace interaction evidence.
7. If browser access is unavailable, perform source-level review and API/manual test alternatives; mark unexecuted journeys explicitly.
**Artifacts:** `UX_AUDIT.md`, `E2E_RESULTS.csv`, `ACCESSIBILITY_FINDINGS.md`, `PERFORMANCE_FINDINGS.md`.
**Acceptance:** every assigned scenario attempted or reason marked; browser/device/environment and commit recorded; findings prioritized by impact on task completion.
**Handoff:** FAR-COORD/P-09 and implementation owners.

### P-08 — Operations and release readiness (EXP-08)

**Role:** FAR-OPS; independent operational review.
**Objective:** establish whether deployment, data operations, monitoring and recovery are repeatable and safe.
**Inputs:** deployment docs/workflows, environment configuration (secret values excluded), staging access, infrastructure and backup evidence.
**Ordered actions:**
1. Inventory local/test/staging/production environments and identify verified vs unknown configuration.
2. Review deployment pipeline, artifact provenance, secrets injection, permissions and rollback path.
3. Review database migration order, backward compatibility, locking, backup and restore procedure.
4. Review queue workers, schedulers, cache, storage, external dependencies, TLS and health checks.
5. Review logs, metrics, alerting, incident response and capacity assumptions.
6. In staging only, rehearse deploy, migration, health verification, backup/restore and rollback where authorized.
7. Record every step not tested and why. Never claim production readiness from CI alone.
**Artifacts:** `ENVIRONMENT_INVENTORY.md`, `OPERATIONAL_READINESS_REPORT.md`, `BACKUP_RESTORE_RUNBOOK.md`, `ROLLBACK_RUNBOOK.md`.
**Acceptance:** repeatable staging evidence for deploy, migration, health, restore, monitoring and rollback; production unknowns remain explicit.
**Handoff:** FAR-COORD/P-09.

### P-09 — Evidence-based prioritization and remediation roadmap (EXP-09)

**Role:** FAR-COORD with FAR-PRODUCT, FAR-ENGINEER, FAR-SEC, FAR-OPS input.
**Objective:** convert accepted findings into a dependency-aware, executable delivery plan.
**Inputs:** accepted P-01…P-08 artifacts, open task state, risks, human decision register.
**Ordered actions:**
1. Exclude unsupported hypotheses from committed priorities; retain them in an investigation backlog.
2. Score verified findings against user impact, security/data severity, business importance, likelihood, dependencies, cost, reversibility and urgency.
3. Assign priority P0–P4 only with written rationale and evidence; do not invent business weights where policy is unknown.
4. Build dependency graph and identify critical path, independent workstreams and conflicts.
5. Break findings into atomic Issues with capability IDs, exact write scope, Given/When/Then acceptance, tests, rollback and reviewer.
6. Detect duplicate/overlapping Issues and consolidate references without deleting history.
7. Mark human-only decisions separately and continue unaffected work.
**Artifacts:** `PRIORITIZED_ROADMAP.md`, `DEPENDENCY_SCHEDULE.md`, canonical Issues and Work Orders, `HUMAN_DECISION_REGISTER.md`.
**Acceptance:** every delivery task traces to evidence; has one owner, dependency, acceptance, protected capabilities, rollback and independent reviewer; no speculative task is represented as confirmed defect.
**Handoff:** task owners and P-10.

### P-10 — Acceptance, regression and release recommendation (EXP-10)

**Role:** FAR-UXQA/FAR-REVIEW independent from implementation author; QA Lead accountable.
**Objective:** verify that the change meets requirements and preserves adjacent capabilities on the exact candidate commit.
**Inputs:** PR diff, canonical Issue, acceptance criteria, baseline, test strategy, staging environment where applicable.
**Ordered actions:**
1. Verify PR base/head SHA and that tested commit equals candidate head.
2. Map every acceptance criterion to a test/evidence result.
3. Run appropriate unit, integration, API, authorization, DB, E2E, regression, performance and accessibility tests.
4. Verify protected adjacent capabilities and migrations/backward compatibility.
5. Record pass/fail/skipped/not-run separately with exact command, environment and reason.
6. Inspect logs/output for data leakage and security regressions.
7. Decide accepted, rejected, conditionally accepted or blocked; list residual risks and required follow-up.
8. Give release recommendation only within evidence scope; production readiness requires P-08 evidence.
**Artifacts:** `ACCEPTANCE_REPORT.md`, `REGRESSION_MATRIX.csv`, `RELEASE_RECOMMENDATION.md`.
**Acceptance:** every criterion has evidence; tested commit exact; independent reviewer; residual risks and unrun tests explicit; no unsupported release claim.
**Handoff:** maintainer/release authority. Worker does not self-merge or self-close.

### REVIEW — Independent evidence and quality review

**Role:** FAR-REVIEW; must not be the author or implementer of the reviewed work.
**Objective:** determine whether the deliverable is complete, evidence-backed, consistent with the master workflow, and safe to accept.
**Ordered actions:**
1. Read Work Order, source documents, Issue, PR, exact diff, commit and CI.
2. Check every required Work Order field and acceptance criterion.
3. Reproduce key evidence where possible; distinguish reproduced, inspected, and not independently verified.
4. Search for contradictions, unsupported claims, hidden scope expansion, missing protected capabilities, data/security risks and missing rollback.
5. Verify artifacts are linked and usable by the next stage without prior chat context.
6. Return ACCEPTED only if all mandatory criteria pass; otherwise REJECTED with exact file/line/criterion and corrective action.
**Artifacts:** review record with criterion-by-criterion verdict, evidence, defects and final status.
**Acceptance:** independent review identity; no self-review; explicit verdict and traceable evidence.

## 6. Stage ledger schema

Maintain `docs/project-state/STAGE_LEDGER.md` as the human-readable view and, where useful, a machine-readable companion `docs/project-state/stage-ledger.json`.

Each stage record must contain:
- stage_id, title, status, source_commit, last_verified_at;
- prerequisites and accepted prerequisite artifact links;
- canonical_issue_ids, active_work_orders, completed_work_orders;
- deliverable paths and artifact versions;
- acceptance criteria with pass/fail/unknown and evidence refs;
- blockers and human decisions;
- next eligible work order IDs and handoff destination.

Never mark a stage accepted when mandatory criteria are UNKNOWN or failed. A stage's artifact must declare the exact commit it describes. If source changes invalidate its evidence, mark it stale and schedule only the affected re-verification.

## 7. Capability register schema

Maintain stable capability IDs in `docs/product-discovery/CAPABILITY_REGISTER.csv` (or a JSON equivalent if repository conventions require it).

Required columns:
`capability_id,capability_name,domain,description,roles,entry_routes,ui_paths,backend_paths,models_tables,integrations,jobs_queues,tests,dependencies,status,evidence_refs,source_commit,last_verified_at,owner_issue,notes`.

Rules:
- Never recycle an ID for a different capability.
- Never infer operational status from file presence.
- Use the status vocabulary in the master workflow and universal prompt.
- Record not-found separately from unknown.
- Keep all existing and newly discovered capabilities; no silent scope reduction.

## 8. Parallelization and collision control

Parallel work is permitted only if:
- prerequisite evidence is accepted/current;
- each worker has a distinct canonical Issue and active lease;
- write scopes do not overlap;
- shared schemas/API contracts have a designated owner;
- integration order and reviewer are known.

Default to serialization for migrations, shared database schema, common API contracts, central route/config manifests, shared prompt/ledger files, broad refactors and changes with uncertain blast radius.

Use one integrator to merge independently produced catalogue fragments. Contributors must not concurrently edit the same canonical register; use separate domain fragments and a serialized integration task.

## 9. Handoff contract

Every completed or blocked Work Order must leave this handoff in its Issue:

- work_order_id and task_id;
- source and final commit;
- exact artifacts and links;
- findings with evidence;
- tests and results (passed/failed/skipped/not run);
- unknowns, contradictions and limitations;
- affected and protected capability IDs;
- acceptance status and reviewer;
- remaining steps in execution order;
- dependencies and blockers;
- rollback/recovery notes;
- next eligible Work Order ID.

A new conversation must be able to resume from this handoff and repository state without asking the user to repeat context.

## 10. Human approval boundaries and stop conditions

Follow the master workflow. Human approval is required for destructive data/schema changes, public production release, production access, material spending, broad security/architecture changes, and history rewriting. Do not request approval for routine read-only discovery or reversible documentation work within an authorized lease.

Stop or narrow only the affected action if there is risk of data/capability loss, unresolved source/version conflict, missing permission, unknown migration impact, secret exposure, unsafe test environment, material evidence contradiction, or failure of a critical regression. Continue independent safe work.

## 11. Prompt and artifact quality gate

Before dispatch and before acceptance, verify:
- task is specific and bounded;
- exact inputs and source commit are named;
- ordered steps specify objects and methods, not vague verbs;
- output path/schema is defined;
- acceptance is observable and binary;
- tests and expected outcomes are defined;
- capabilities to preserve are listed;
- dependencies and conflicts are mapped;
- risk, rollback and stop conditions are explicit;
- next-stage handoff is usable;
- no duplicate task or overlapping owner exists.

A prompt/work order that fails this gate is not ready for dispatch.

# FARAST Stage Ledger

Status: INITIAL TEMPLATE — NOT YET POPULATED FROM A CURRENT VERIFIED AUDIT
Authority: `docs/agent-coordination/PROMPT_EXECUTION_PLAYBOOK.md`
Last verified source commit: UNKNOWN
Last verified at (UTC): UNKNOWN

> Do not interpret this template as evidence that any stage is complete. The first dispatcher must verify the current default branch, source commit, existing artifacts, Issues, PRs, tests and leases, then populate each row with evidence. Never overwrite a newer verified ledger with stale findings.

## Stage status

Allowed status values: `not_started`, `in_progress`, `evidence_complete_pending_review`, `accepted`, `blocked`, `stale`, `unknown`.

| Stage | Status | Prerequisites | Canonical Issues / Work Orders | Deliverables | Acceptance evidence | Blockers / decisions | Next eligible work |
|---|---|---|---|---|---|---|---|
| EXP-01 / AI-01 Baseline & protection | unknown — verify first | None | UNKNOWN | Baseline Report; Branch/PR Map; Difference Report if local workspace exists; Recovery Plan | Exact default branch/HEAD, refs, settings visibility, local diff if available, recovery evidence | UNKNOWN | Verify baseline; do not repeat if current accepted evidence exists |
| EXP-02 / AI-02 Asset & capability discovery | not_started pending EXP-01 | Accepted EXP-01 | UNKNOWN | Capability Catalogue; Capability Register; Dependency Map | All discovered routes/modules mapped with evidence or explicit UNKNOWN | UNKNOWN | Dispatch bounded discovery domains after baseline |
| EXP-03 / AI-03 Requirements & user behavior | not_started pending EXP-02 | Accepted EXP-02 | UNKNOWN | Requirements Matrix; Scenario Catalogue; Role/Permission Matrix; Business Rule Register; Unknown Decisions | Each requirement source-linked and testable; unknown commercial rules preserved | UNKNOWN | Dispatch product-analysis work |
| EXP-04 / AI-04 End-to-end traceability | not_started pending EXP-02 and EXP-03 | Accepted EXP-02, EXP-03 | UNKNOWN | Traceability Matrix; Functional Gap Report | Each capability path traced or missing segment explicitly evidenced | UNKNOWN | Dispatch capability-scoped traces |
| EXP-05 / AI-05 Architecture & engineering quality | not_started pending EXP-04 | Accepted EXP-04 | UNKNOWN | Architecture Assessment; Technical Debt Register; Change Proposals | Every proposal has cause, impact, risk, tests and rollback | UNKNOWN | Dispatch analysis-only work first |
| EXP-06 / AI-06 Security & data protection | not_started pending EXP-04 | Accepted EXP-04 | UNKNOWN | Threat Model; Security Findings; Authorization/Ownership Matrix; Remediation Plan | Authorized safe tests and evidence; secrets/private data protected | UNKNOWN | Dispatch defensive security assessments |
| EXP-07 / AI-07 UX, accessibility & performance | not_started pending EXP-03 and EXP-04 | Accepted EXP-03, EXP-04 | UNKNOWN | UX Audit; E2E Results; Accessibility and Performance Findings | Real journeys attempted or precise test limitation recorded | UNKNOWN | Dispatch scenario-scoped QA work |
| EXP-08 / AI-08 Operations & release readiness | not_started pending EXP-06 and EXP-07 | Accepted EXP-06, EXP-07 | UNKNOWN | Environment Inventory; Operational Readiness; Backup/Restore and Rollback Runbooks | Safe staging evidence for deploy, migration, health, restore, monitoring, rollback | UNKNOWN | Dispatch operations assessment |
| EXP-09 / AI-09 Prioritization & remediation roadmap | not_started pending EXP-01…EXP-08 | Accepted preceding stages | UNKNOWN | Prioritized Roadmap; Dependency Schedule; atomic Issues/Work Orders | Every task evidence-backed with owner, dependencies, acceptance, protected scope and rollback | UNKNOWN | Aggregate accepted findings |
| EXP-10 / AI-10 Acceptance & regression | not_started pending EXP-09 and completed remediation scope | Accepted EXP-09 and candidate changes | UNKNOWN | Acceptance Report; Regression Matrix; Release Recommendation | Independent review; exact tested commit; results and residual risks | UNKNOWN | Independent QA/review |

## Active work and ownership

| Work Order | Issue | Accountable role | Chat/run | Lease state | Write scope | Last heartbeat | Status |
|---|---|---|---|---|---|---|---|
| UNKNOWN — populate from live GitHub state | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN |

## Human decision register

| Decision ID | Exact decision | Why evidence cannot resolve it | Affected capabilities | Safe work that continues | Required authority | Status |
|---|---|---|---|---|---|---|
| None recorded in this template | — | — | — | All independent discovery | — | Verify from current sources |

## Ledger update rules

1. Update only from verified GitHub/source evidence and cite commit, path, Issue/PR, test or run.
2. Every artifact row must record its source commit and verification time.
3. A stage is accepted only after independent review of all mandatory acceptance criteria.
4. Mark evidence stale when relevant source code/configuration changes invalidate it.
5. Do not mark a task or stage complete merely because an Issue closed or a document exists.
6. Preserve history in Git; append a dated change note when material status changes.

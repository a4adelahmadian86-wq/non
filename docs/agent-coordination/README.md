# FARAST Multi-Chat Coordination

## Purpose

This repository is the shared coordination plane for multiple ChatGPT conversations working as one traceable team. A conversation is not authoritative because it remembers something; GitHub is authoritative because every task, lease, event, pull request, review, and CI result is durable and visible to every authorized participant.

**Canonical task record:** one GitHub Issue per work item.  
**Exclusive ownership:** a lease granted by the `FARAST multi-chat task coordinator` GitHub Actions workflow.  
**Change integration:** one isolated branch and pull request per task/chat execution.  
**Acceptance:** independent review + required CI + issue closure. A chat cannot self-accept its own work.

## Repository facts verified while adding this infrastructure

- Repository: `a4adelahmadian86-wq/non`
- Default branch at inspection: `main`
- Base commit inspected: `74cf16e297cd926286391a69ad7d0a9191ea2a7c`
- Repository is public and the connected GitHub identity had push/admin permissions at inspection time.
- `main` was **not protected** at inspection time.
- Existing FARAST application code already has an editor-agent task model and database migration. That is an application-level editor task system, not a cross-ChatGPT-conversation coordination service. This addition does not replace or modify it.
- Existing CI workflows are retained. This coordination workflow is additive.

These are point-in-time observations, not promises about future repository settings.

## One-time setup

1. Merge this infrastructure PR after review.
2. In GitHub, create a branch ruleset for `main`:
   - require pull requests before merging;
   - require the relevant existing `FARAST CI` checks; add a dedicated workflow/YAML validation check if one is introduced later;
   - require at least one approval for changes to application/runtime code;
   - dismiss stale approvals after new commits;
   - require conversation resolution;
   - block force pushes and deletion;
   - do not allow routine agent chats to bypass the ruleset.
3. Ensure GitHub Actions are enabled and the repository's Actions policy permits `actions/checkout` and `actions/github-script`.
4. Ensure the repository collaborators who will operate the agent team have at least **Triage** permission to claim/comment and **Write** permission to create branches and PRs.
5. Keep secrets out of Issues, comments, prompts, workflow logs, and commits.

Branch protection cannot be activated by this file alone; it is a repository setting and must be enabled in GitHub settings by an administrator.

## Creating a task

Create one Issue per distinct work item using the **FARAST task** issue form. The title must begin with `[FARAST-TASK]`. The coordinator adds the `agent:task` and `agent:ready` labels and creates the first machine-readable state comment.

Do not create a second Issue for the same work. Search open and recently closed Issues for the same scope first. If the scope is already represented, use that issue and add a comment.

The canonical task key is `GH-<issue-number>`. If the broader project has its own TASK-ID, include it in the issue body; the GitHub issue number remains the unique coordination key.

## Starting work from any ChatGPT conversation

Every conversation must do these steps in order:

1. Open the task Issue and read its full description, labels, comments, latest `FARAST_AGENT_STATE_V1` record, linked PRs, and CI results.
2. Confirm there is no unexpired lease.
3. Generate a unique `chat_id` for this conversation and a unique `run_id` for this attempt. Do not reuse either across independent conversations/runs.
4. Post this command as a comment on the task Issue:

   ```text
   /farast claim chat=<unique-chat-id> run=<unique-run-id> lease=60
   ```

5. Wait for the coordinator's explicit **Claim granted** response. The response includes a fencing token and UTC expiry. If denied, do not work on the task.
6. Create a dedicated branch from the latest `main`, for example:
   `agent/GH-123/chat-<short-id>`.
7. Before each substantial unit of work, re-read the Issue state and confirm the same lease is active. Never work from an old copied ledger.
8. Renew the lease before expiry:
   `/farast heartbeat chat=<same-chat-id> run=<same-run-id> lease=60`.
9. If blocked, report the reason:
   `/farast block chat=<same-chat-id> run=<same-run-id> reason=<short-reason>`.
10. When implementation is ready for independent review:
    `/farast review chat=<same-chat-id> run=<same-run-id>`.
    This releases the lease and marks the issue for review; it does **not** accept or merge the work.

Use UTC ISO-8601 timestamps in all reports. Record actual start/end times; never invent time or token/cost measurements.

## Commands

| Command | Effect | Ownership requirement |
|---|---|---|
| `/farast claim chat=… run=… lease=60` | Requests exclusive lease; lease 5–240 minutes | Authorized repo collaborator; task must be unleased/expired |
| `/farast heartbeat chat=… run=… lease=60` | Renews lease | Exact actor + chat_id + run_id |
| `/farast status` | Reports current task state and lease | Authorized repo collaborator |
| `/farast release chat=… run=…` | Releases lease without marking task complete | Exact owner |
| `/farast block chat=… run=… reason=…` | Marks blocked and releases lease | Exact owner |
| `/farast review chat=… run=…` | Marks ready for independent review and releases lease | Exact owner |

The workflow records a unique lease token for each granted lease and checks the authenticated actor, chat_id, and run_id on every owner command. This token is an audit/fencing identifier inside the coordinator state; GitHub does not cryptographically prevent a stale ChatGPT conversation from continuing to draft locally. The operational fence is: stop immediately when the lease expires or is replaced, use a unique branch per run, and require independent PR review plus protected main before integration.

## Concurrency and its boundary

The GitHub Actions workflow uses a repository-wide concurrency group keyed by Issue number, with cancellation disabled. This serializes coordinator executions for the same task Issue. The workflow then reads the latest machine state comment and checks the lease before changing it.

GitHub Actions concurrency keeps at most one running and one pending run in a group; a newer pending run can replace an older pending run. Therefore:
- submit one command and wait for its coordinator response before sending another;
- if a command receives no response, query `/farast status` and inspect Actions before retrying;
- do not interpret a missing response as a granted lease;
- different task Issues can proceed in parallel, while one Issue has one active lease.

This is a safe coordination mechanism for normal serialized requests, not a general-purpose transactional database or a guaranteed FIFO queue. For very high-frequency automated agents, replace the comment-state backend with a transactional database/queue and retain GitHub Issues/PRs as the human audit surface.

## Branch and PR rules

- One task execution works on one dedicated branch; never share a writable branch between chats.
- Branch from current `main` after the lease is granted.
- PR title must include `GH-<issue-number>`.
- PR body must include `Closes #<issue-number>` only when the task is truly ready to close after review; otherwise use `Refs #<issue-number>`.
- Include base SHA, changed paths, compatibility impact, tests run and results, evidence, actual UTC start/end, and known limitations.
- Do not merge your own PR or close the task Issue. An independent reviewer must inspect the diff and CI.
- No force-push to shared branches. Never commit secrets or generated user data.

## Required execution report

Each PR description and final Issue comment must report:

- task key, issue URL, chat_id, run_id, lease ID (public audit identifier; not a secret);
- base commit SHA and final commit SHA;
- UTC start, UTC finish, wall-clock duration, pause duration, and active duration only if measured;
- files changed and summary of each change;
- tests: passed / failed / skipped / not run, with commands and links to CI;
- defects found, blockers, retries, rework, and resolution;
- decisions and evidence references;
- whether existing capabilities were preserved, extended, changed, or removed (removal requires explicit approval);
- remaining risks and next action.

GitHub timestamps, Actions run timestamps, commits, PR reviews, and CI runs are the audit evidence. Do not claim a test passed unless a run proves it.

## State model

`ready → in_progress → review → done`

Other states: `blocked`. A lease expiry does not mean task completion. Issue closure sets the coordinator state to `done`; only an authorized human/reviewer should close after acceptance.

The latest hidden `FARAST_AGENT_STATE_V1` comment is the current machine state. Earlier state comments are an append-only audit trail. The machine state also stores run history (`attempt_no`, actor, chat/run IDs, UTC start/end, wall_seconds, terminal run status) and derived metrics (`runs_total`, `retries_total`, `completed_runs`, `average_wall_seconds`). Active work time is not inferred from wall time and remains unmeasured unless a reliable measurement source is added. Do not edit or delete them manually. If state is malformed or contradictory, stop and request maintainer repair.

## Failure and recovery

- Workflow failure: inspect the failed Actions run before retrying.
- No claim response: do not start; run status check and inspect Actions.
- Expired lease: request a new claim. The old run is fenced out.
- Duplicate task: stop and link the duplicate Issue to the canonical Issue; do not execute both.
- Conflicting PRs: stop, compare base/head commits, and have the coordinator/reviewer choose one integration path.
- Missing GitHub permissions: stop; do not simulate a lock in chat memory.
- Retry/branch/new conversation: recover from the Issue + latest state comment + PR + CI, not from assumptions about prior chat history.

## Important identity limitation

GitHub authenticates the account that posts a command. `chat_id` and `run_id` are caller-supplied unique identifiers used to distinguish conversations and attempts; GitHub does not independently prove which ChatGPT conversation generated them. The exclusive lease is enforced at the task Issue level and the authenticated GitHub actor level.

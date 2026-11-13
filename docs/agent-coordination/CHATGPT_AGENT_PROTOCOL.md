# Instructions for every FARAST ChatGPT conversation

For a complete copy-paste onboarding prompt that works across independent ChatGPT conversations and accounts, use `docs/agent-coordination/UNIVERSAL_CHATGPT_TEAM_PROMPT.md`. Each account must independently connect an authorized GitHub identity; conversation memory and account identity are not shared.

Before accepting or dispatching any EXP/AI stage work, read `docs/agent-coordination/PROMPT_EXECUTION_PLAYBOOK.md`. A stage label is not a task assignment: require a complete Work Order with exact inputs, ordered actions, output paths/schema, validation commands, binary acceptance criteria, capability IDs, protected scope, dependencies, conflict files, rollback, stop conditions and handoff target. Never ask the user to explain routine technical work; investigate missing fields from authorized sources and mark only unresolved items UNKNOWN.

You are one worker in a multi-conversation team. You do not own project truth or task state. GitHub is the shared coordination system.

Mandatory before doing any work:
1. Find the canonical GitHub Issue for the requested work. Search for duplicates.
2. Read the complete Issue, all coordinator comments, the latest `FARAST_AGENT_STATE_V1` state, linked PRs, and latest CI.
3. If no canonical Issue exists, create one using the FARAST task form; do not silently create duplicate tasks.
4. Generate a unique chat_id and run_id.
5. Post `/farast claim chat=<chat_id> run=<run_id> lease=60` and WAIT for the workflow's explicit grant. The returned lease ID is an audit identifier, not a cryptographic fencing token.
6. If claim is denied, pending, missing, expired, malformed, or contradictory: do not execute the affected implementation task and never infer a grant. Inspect Actions and `/farast status`; record the exact blocker once in the canonical Issue. **Do not stop the overall mission:** continue every independent safe read-only task (discovery, source tracing, capability inventory, requirements, test analysis, defensive review within authorization, evidence collection, and complete Work Order preparation). Revisit the claim only after coordinator state changes; do not spam retries or duplicate Issues. GitHub Actions keeps one running and one pending run per concurrency group; a newer pending run may replace an older pending run, so this is not a FIFO queue.
7. Work only on a dedicated branch and PR. Never share a writable branch with another conversation.
8. Recheck the lease before substantial work and renew it before expiry. Stop immediately if the lease is lost.
9. Record facts, decisions, UTC timestamps, tests, evidence, retries, blockers, and outputs in the Issue/PR.
10. On implementation completion, issue `/farast review chat=<chat_id> run=<run_id>`. Never self-accept, self-merge, or close the task. `/farast unblock` and `/farast reopen` are maintainer decisions and must be used only after the blocker/rework decision is confirmed.
11. Resume after Retry/new branch/new conversation from GitHub state and commit history, not from assumed chat memory.
12. Preserve all existing capabilities. Any removal or destructive change requires explicit user approval.\n13. Keep executing the next safe, dependency-ready task after each handoff. Do not end a turn with a progress-only report or ask the user whether to continue. At a genuine ChatGPT/tool limit, leave a durable exact resume point in GitHub.

Do not fabricate GitHub state, lock ownership, timestamps, CI results, tests, commits, or metrics. A chat's private memory is never a substitute for the canonical Issue.

# FARAST AI Editor Architecture

Last audited/updated: 2026-09-15

This document describes the architecture actually present on this branch. Status labels are literal: **IMPLEMENTED**, **PARTIALLY IMPLEMENTED**, **PLANNED**, **NOT AVAILABLE**.

## Current implementation — IMPLEMENTED

The existing `POST /editor/ai/assist` compatibility endpoint remains intact. `EditorAiAssistController` delegates to `EditorAiAssistService`, which uses `AiOrchestrator`.

Current flow:

`request -> operation registry -> quota -> privacy policy -> context engine -> deterministic provider registry -> provider adapter -> normalized result -> safe telemetry`

Core infrastructure:

- `AiOperationRegistry`: canonical operations, legacy aliases, scope, input ceiling and cost class.
- `AiContextEngine`: allowlisted context and fail-closed large-document handling.
- `AiPrivacyPolicy`: resolves `automatic|local|server|external`; unsupported modes are rejected rather than silently falling back.
- `AiQuotaService`: existing capability and daily-request checks.
- `AiProviderInterface`: common provider adapter contract, including provider name/model/capabilities/support/execution.
- `AiProviderResult`: normalized provider-independent result envelope.
- `AiProviderRegistry`: deterministic provider registration, capability discovery and operation-aware selection.
- `GeminiProviderAdapter`: adapts the existing `GeminiEditorProvider`; no second Gemini HTTP implementation was created.
- `AiOrchestrator`: performs validation/policy checks, provider execution, normalized response construction and privacy-safe telemetry.

## Provider architecture

Providers are selected by the registry, not by controllers. A preferred provider may be supplied explicitly through the validated optional `provider` request field; otherwise the registry selects the first registered provider that declares support for the operation. An unknown or unsupported explicitly selected provider is rejected and never silently replaced. There is no silent multi-provider fan-out or cross-company fallback.

The existing Gemini implementation remains the actual HTTP client and server-side credential owner. The new adapter only translates its existing contract into `AiProviderResult` and exposes the configured model before execution so telemetry can be created without nullable/ambiguous model state.

Future providers can implement `AiProviderInterface` without changing the editor controller. Non-text media can use capability-specific sibling contracts when their result semantics differ materially from text generation.

## Operation registry — IMPLEMENTED

Legacy aliases remain compatible: `selection` -> `selection.format_suggest`, `word` -> `selection.spellcheck`, `punctuation` -> `selection.punctuation`.

Current backend-capable operations include proofreading, spelling, punctuation, rewriting, paraphrasing, tone, shortening, expansion, summarization, explanation, translation, generation, continuation, title, outline, keywords, document summarization/improvement/analysis and formatting suggestions. The current UI exposes only a subset; backend capability is not claimed to mean finished UI.

## Normalized response contract — IMPLEMENTED

Editor AI keeps existing top-level response fields for compatibility and additionally exposes an `ai` envelope with request ID, canonical operation, provider, model, processing mode, status, result, suggestions, warnings, metadata, usage and error. Voice follows the same conceptual envelope while retaining its legacy fields.

## Context minimization — IMPLEMENTED

Only explicitly supported small context fields are transmitted for selection/text operations. An accidentally supplied `whole_document` or unrelated secret field is not forwarded. Document-wide operations must explicitly request document scope. Current oversized document requests fail validation instead of being silently truncated.

Hierarchical chunking, section-aware map/reduce and retrieval are **PLANNED**. No vector database or embeddings have been introduced because the current application does not require them.

## Privacy modes

| Mode | Status | Behavior |
|---|---|---|
| `local` | **NOT AVAILABLE** | No local model adapter exists; requests are rejected rather than uploaded externally. |
| `server` | **NOT AVAILABLE** | No self-hosted inference adapter exists; requests are rejected rather than routed to Gemini. |
| `external` | **IMPLEMENTED** | Gemini editor AI is server-mediated. Existing OCR/voice external integrations remain separate. |
| `automatic` | **IMPLEMENTED** | Deterministic policy resolution; it cannot expand context or silently fan out to another provider. |

There is no automatic cross-provider retry. A failed selected provider remains a failed request.

## Editor integration — PARTIALLY IMPLEMENTED

The real existing `contenteditable` editor is used; no parallel/demo editor was introduced. Existing voice punctuation was hardened to update only the newly inserted text node instead of rebuilding `innerHTML`, preventing unrelated rich formatting from being destroyed.

Backend proofreading responses contain structured suggestions with original/replacement/reason/category/confidence. Full rich-text accept/reject UI is **PLANNED**. Destructive full-document AI replacement is not introduced.

## Voice — IMPLEMENTED / PARTIALLY IMPLEMENTED

The existing editor records audio with `MediaRecorder` and uploads to `POST /editor/voice/transcribe`. Backend validation checks account capability, quota, maximum upload size, MIME type and locale. Current locales include Persian, English and Arabic. Google Cloud Speech is used when configured for a compatible format; otherwise Gemini is used. Provider credentials remain server-side.

Browser-local speech recognition and voice commands for editor actions are **PLANNED**.

## OCR — IMPLEMENTED / PARTIALLY IMPLEMENTED

The existing `GeminiService` provides OCR for supported images/PDFs/ZIP image collections and uses schema-constrained output. OCR remains separate from language correction.

Specialized local OCR, dedicated handwriting OCR, preprocessing and a separate optional post-OCR correction stage are **PLANNED**. Provider-side cleanup/retention for temporary Gemini Files uploads remains an open risk.

## Browser AI — NOT AVAILABLE

No large browser model dependency was added. Candidate technologies include WebGPU, WebAssembly, ONNX Runtime Web and Transformers.js, but none is falsely advertised as active local inference.

A future local adapter must be lazy-loaded after explicit user action, disclose model download size, cache immutable versions, check browser/GPU/memory capability, support cancellation and declare model licensing. A local failure must never silently transmit the same text externally.

Firefox/WebGPU support, mobile memory, first-load size and Persian model quality must be evaluated per selected model before implementation.

## Security — IMPLEMENTED / REMAINING HARDENING

Implemented protections include:

- provider credentials stay server-side;
- authenticated/CSRF-protected routes and existing throttles remain in use;
- capability/quota checks occur before provider execution;
- explicit provider selection is validated and unknown providers are rejected without fallback;
- provider bodies are not intentionally persisted in AI failure telemetry;
- document text is explicitly treated as untrusted data in prompts;
- structured output schemas are validated before use;
- formatting actions use an allowlist rather than provider-generated HTML;
- voice punctuation does not replace editor `innerHTML`;
- telemetry uses hashes, byte counts, IDs, status and bounded metadata rather than full document text;
- privileged account bootstrap is opt-in through deployment environment values; repository-owned fixed administrator phone/password/hash credentials are not used to create an account.

Remaining risks include optional user-supplied feedback text retention, Gemini Files provider-side retention/cleanup, count-based daily quota race conditions, and the need for stronger sanitization/structured transformation if future AI operations begin returning rich HTML.

No AI URL-fetching tool currently exists, so this layer does not introduce an SSRF primitive. Future URL-capable tools require explicit egress controls.

## Quota and cost control — PARTIALLY IMPLEMENTED

Operation definitions carry `low|medium|high` cost classes and the existing quota service remains centralized. Weighted token/cost accounting and atomic reservation are **PLANNED**; cost classes are currently metadata rather than billing units.

## Capability matrix

| Capability | Local | FARAST server | External | Current status |
|---|---|---|---|---|
| Formatting suggestion | rule-based only | no model | Gemini | **IMPLEMENTED** |
| Spelling/punctuation | no model | no model | Gemini | **IMPLEMENTED** |
| Proofreading | no | no | Gemini | **IMPLEMENTED backend**, UI planned |
| Rewrite/paraphrase/tone/shorten/expand | no | no | Gemini | **IMPLEMENTED backend**, UI planned |
| Summarize/explain/translate | no | no | Gemini | **IMPLEMENTED backend**, large-doc strategy planned |
| Generate/continue/title/outline/keywords | no | no | Gemini | **IMPLEMENTED backend**, UI planned |
| Document analysis/improvement | no | no | Gemini | **PARTIALLY IMPLEMENTED** |
| Speech-to-text | recording only | no engine | Google Speech/Gemini | **IMPLEMENTED** |
| Printed image/PDF OCR | no | no engine | Gemini | **IMPLEMENTED** |
| Handwriting OCR | no dedicated engine | no dedicated engine | not guaranteed | **PARTIALLY IMPLEMENTED / not guaranteed** |
| Vision understanding | no | no | no normalized provider | **PLANNED** |
| Image generation | no | no | no provider | **NOT AVAILABLE / PLANNED** |
| Sketch/shape recognition | no recognizer | no | no | **NOT AVAILABLE / PLANNED** |
| Embeddings/RAG | no | no | no | **NOT AVAILABLE / intentionally deferred** |

## Large documents — PARTIALLY IMPLEMENTED

The current system rejects requests beyond the operation's single-call ceiling rather than silently truncating. Future section-aware chunking should preserve headings, tables and lists, then aggregate results hierarchically. Retrieval/embeddings should only be introduced if actual product requirements justify them.

## Tests and CI

`tests/Unit/AiCorePolicyTest.php` covers operation aliases, deterministic privacy, context allowlisting, oversized document rejection and common provider-registry selection. Feature tests cover authorization, response compatibility, model/provider reporting, API-key non-disclosure, explicit unknown-provider rejection without fallback, local-mode no-fallback behavior, quota enforcement, capability blocking, oversized input, safe provider error persistence, malformed provider output, provider connection failure and unvalidated document-ID isolation.

`.github/workflows/ci.yml` validates Composer metadata, PHP/JavaScript syntax, MariaDB/SQLite migrations, routes, Blade compilation and Laravel tests.

## Future extensions

### Local/browser inference
Implement a real local adapter only for a feature/model that passes browser compatibility, model-size, memory, Persian-quality and licensing review.

### OCR/vision
Keep OCR extraction and language correction separate. Add a normalized vision capability only when an actual provider is selected and tested.

### Image generation
Future flow: `prompt -> policy/quota -> provider -> MIME/dimension validation -> private storage -> explicit editor insertion -> lifecycle cleanup`.

### Drawing recognition
Prefer local geometry recognition: `strokes -> geometry/features -> primitive -> editable editor object`. External vision should be opt-in for ambiguous cases.

### Additional providers
Implement `AiProviderInterface`, declare name/model/capabilities, support deterministic operation selection and return `AiProviderResult`. Providers must never receive user content through hidden fan-out or silent fallback.

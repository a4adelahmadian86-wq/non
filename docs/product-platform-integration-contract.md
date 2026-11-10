# Product platform integration contract

This document describes the cross-layer invariants that must remain true as the product catalog, file pipeline, reader, courses, AI assistance, checkout, and operations tooling evolve.

## Canonical product lifecycle

1. **Catalog draft** — an admin creates a product with a stable type (digital_file or course). Product type is immutable after creation so file payloads and entitlements cannot silently change meaning.
2. **Secure intake** — uploads are staged privately, structurally inspected with bounded limits, fingerprinted, and held for explicit confirmation. Inspection is not represented as a full antivirus guarantee.
3. **Persisted source** — confirmation creates an immutable product-file version and a processing record linked to that exact product and file. Original bytes stay on private storage.
4. **Asynchronous processing** — extraction, source-locator persistence, knowledge analysis, reader page building, and narration are separate persisted stages. Retries must verify ownership and source hashes and must not duplicate pending work.
5. **Publication gate** — publication is a fresh server-side decision. Digital files require an active, clean primary download. Reader-only files additionally require the matching document, matching SHA-256, an allowed completed/extracted state, a ready reader marker, and persisted pages. Courses require at least one publishable lesson.
6. **Commerce and access** — checkout, payment confirmation, entitlements, library records, course enrollment, and reader authorization must all resolve to the same product identity. Catalog archiving must not erase paid order or entitlement history.
7. **Operations and recovery** — health snapshots are read-only. Recovery is explicit, bounded, audited, and must never silently publish, change entitlements, or weaken file security.

## Integration ownership

| Layer | Canonical records/services | Integration invariant |
| --- | --- | --- |
| Catalog | StoreProduct, StoreCategory, StoreTag | Product identity/type is stable; drafts remain private |
| Intake/security | StoreProductIngestion, UploadPreflightService, UploadSecurityService | Quarantine is private; bounded inspection and explicit confirmation precede use |
| Document processing | StoreProductDocument, ProcessProductDocument, ProductKnowledgeService | A document belongs to one product-file version and its source hash |
| Reader | StoreProductDocumentPage, ProductPublicationReadiness, StoreController | Reader access requires entitlement and the same ready source version |
| Narration | ProductDocumentAudioService, GenerateProductDocumentAudio | One stored audio artifact per source/version; playback is range-based, not per-click synthesis |
| Courses | StoreCourse, StoreCourseSection, StoreCourseLesson, StoreCourseEnrollmentService | Lesson resources remain scoped to their lesson; enrollment derives from entitlement |
| AI assistance | ProductContentGenerationService, ProductContentPrivacyFilter | Generated copy is evidence-grounded and privacy-filtered; AI never grants security approval |
| Commerce | StoreCartService, PaymentController, EntitlementService | Payment and entitlement transitions are idempotent and transactionally consistent |
| Operations | ProductLifecycleHealthService, ProductDocumentRetryService | Diagnostics are read-only; retries are validated and audited |

## Required regression boundaries

- A reader-only product cannot become publishable because an unrelated file or document happens to exist.
- A source-hash mismatch, wrong product/file relation, unsafe/inactive/non-primary file, partial extraction, missing reader marker, or missing persisted page fails closed.
- A course cannot borrow another course's lessons or lesson resources to satisfy publication readiness.
- AI review is advisory. It cannot substitute for deterministic upload inspection or reader readiness.
- Archiving changes catalog visibility, not historical order, payment, entitlement, or enrollment records.
- A retry cannot reprocess a changed source, bypass size/queue constraints, or dispatch a duplicate for an active processing lease.
- Health pages and status endpoints expose operational metadata, not original file bytes, extracted private text, API tokens, or storage credentials.

## Operational deployment contract

- Keep originals, staging payloads, and derived reader/audio assets on private storage.
- Use one queue-consumption strategy: scheduler-managed bounded workers or a persistent supervisor, never both.
- Configure the queue retry window to exceed the maximum job timeout.
- Keep supervisor, AI provider, and document-audio credentials server-side and independently scoped.
- Treat configured-but-unreachable services as degraded, not healthy.
- Verify migrations, queue connectivity, private storage writability, service readiness, and end-to-end reader authorization after deployment.

## Scope coordination

Taxonomy administration, course-resource quarantine, product-editor navigation, and live infrastructure probes may evolve in separate pull requests. Those changes should preserve the contracts above rather than duplicate lifecycle state or create parallel file/document identities.

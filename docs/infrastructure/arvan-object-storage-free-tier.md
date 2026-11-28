# ArvanCloud Object Storage — FARAST free-tier account record

**Captured:** 2026-11-28  
**Source:** User-provided screenshot from the user's ArvanCloud account panel.  
**Repository:** `a4adelahmadian86-wq/non`  
**Purpose:** Preserve the account's displayed free-tier terms and enforce a no-unintended-spend operating policy.

> This is an account-panel snapshot, not a provider guarantee or a credential file. Never put Access Keys, Secret Keys, account IDs, wallet details, personal data, or private bucket names in Git.

## 1. Plan shown in the account screenshot

| Item | Displayed value |
|---|---:|
| Plan | پایه (Base) |
| Status | فعال (Active) |
| Base plan price | ۰ تومان |
| Billing note | «به‌ازای مصرف مازاد» — overage is billed |
| Free object storage | ۵ GB |
| Free traffic | ۲۰ GB |
| Standard storage overage | ۳٬۰۰۰ تومان per GB/month |
| Traffic overage | ۸۵۰ تومان per GB |
| HiOps storage overage | ۹٬۰۰۰ تومان per GB/month |
| Read/write request allowance | ۱۰٬۰۰۰ (as shown in plan comparison) |
| Temporary user | ۱ |
| Static website | Included |
| Custom domain | Included |
| Download box | Included |
| Versioning | Included |
| Lifecycle | Included |
| Bucket policy | Included |
| Replication | Not included |
| Access point | Not included in Base |
| Cache management | Not included |
| Firewall rules | Not included |
| CSS/JS acceleration | Not included |
| Traffic reports | Not included |
| Image optimization/resizing | Not included |
| Data catalog / query engine | Not included |

The screenshot also shows checkmarks for the included features and red crosses for excluded features. This file records only what is legible in the supplied screenshot; it does not claim that a bucket, access key, endpoint, or any data object has been created.

## 2. Important pricing discrepancy — verify in panel before enabling uploads

The live public ArvanCloud pricing page was checked on 2026-11-28:
- It confirms the Base plan is free without a time limit, with 5 GB storage, 20 GB traffic, and 10,000 read/write requests.
- It says usage above the free allowance is billed PAYG from the account wallet.
- Its currently rendered detailed table shows different overage figures from this account screenshot (for example standard storage 3,600 toman/GB-month and traffic 965 toman/GB; HiOps 10,800 toman/GB). Its FAQ also displays a different storage figure, so the provider's public page is internally inconsistent.

Therefore, the account-panel values above are preserved as the user's observed snapshot, but **do not treat any overage price as fixed**. Before production use, check the exact current price and billing behavior shown in this account's own panel or obtain written confirmation from ArvanCloud support.

References:
- https://www.arvancloud.ir/fa/pricing/cloud-storage
- https://docs.arvancloud.ir/en/object-storage/s3

## 3. Mandatory no-unintended-spend policy for FARAST

Until the user explicitly approves paid usage:

1. Use only the Base plan. Never upgrade to Growth, Professional, Enterprise, or enable paid add-ons.
2. Do not use HiOps storage, Access Point, antivirus, or any other paid feature.
3. Do not intentionally exceed 5 GB stored data, 20 GB monthly download traffic, or 10,000 read/write requests.
4. Before production use, application-side upload and request guards must be implemented and tested to stop work before the free allowance is exhausted. Proposed conservative thresholds are 4.0 GB stored, 15 GB monthly egress, and 7,000 combined operations. **These thresholds are a design requirement only; they are not yet implemented or active in the current application.**
5. Do not assume the S3 API exposes accurate traffic or request metering. Storage usage may be measurable from provider-specific headers, but traffic/request totals must be verified in the provider dashboard or another authoritative meter.
6. If usage cannot be measured reliably, fail closed: pause new uploads and nonessential reads rather than risk PAYG charges.
7. Do not run bulk migrations, repeated syncs, public downloads, public buckets, static-site hosting, or load tests against this account.
8. No production data migration starts until the bucket's region, endpoint, privacy policy, quota measurement, and a tested stop mechanism are confirmed.
9. Do not claim zero billing risk merely because the Base plan itself costs zero. The provider explicitly states that overage is PAYG and deducted from the wallet.
10. If the panel offers a way to disable PAYG/overage or set a hard spending cap, configure it to block overage before uploading anything. If no such control exists, maintain a zero/empty wallet only if the provider confirms that this prevents charges and service behavior is understood; otherwise keep uploads disabled.

## 4. FARAST integration notes

The current FARAST codebase already contains an S3-compatible adapter using AWS Signature V4 and a configurable `iran_primary` storage role. Reuse that implementation; do not introduce a second S3 client or a parallel storage subsystem.

The endpoint, region, bucket, path-style setting, and access credentials must come from the user's actual Arvan panel. Never guess an endpoint or region and never commit credentials. Store secrets only in the site's encrypted secret/configuration mechanism.

The existing product knowledge pipeline still persists chunks, facts, and extracted pages in MySQL. Connecting an Object Storage bucket alone does not migrate that knowledge. A later migration must be versioned, checksummed, reversible, and backward-compatible, and must not delete MySQL records until all readers/consumers have been switched and recovery has been tested.

## 5. Activation checklist — intentionally not yet completed

- [x] User provided the regional endpoint list; Iranian Standard zones are identified above.
- [ ] Confirm the bucket's actual region and exact endpoint after bucket creation.
- [ ] Create a private Standard bucket only; do not enable Multi-zone unless confirmed free.
- [ ] Keep Versioning and Object Lock disabled at initial creation; Object Lock is irreversible.
- [ ] Use conservative bucket name characters (lowercase letters, digits, hyphens only; 3–63 chars).
- [x] Dashboard screenshot confirms a **صندوقچه‌ها** sidebar item and a **+ صندوقچه** create button; user has not yet opened the bucket list or confirmed existing buckets.
- [ ] Confirm bucket is private.
- [ ] Confirm PAYG/overage can be disabled or establish a verified hard stop.
- [x] User confirms an access key is available. The actual key/secret are intentionally not stored in Git or chat.
- [ ] Confirm the key has least-privilege permissions limited to the intended private bucket.
- [ ] Add endpoint, region, bucket and credentials to the protected FARAST runtime configuration (secrets never enter Git).
- [ ] Test one tiny private object upload/download/delete and verify checksum.
- [ ] Verify provider usage counters and that the test did not trigger paid consumption.

**Current state (updated 2026-11-28):** User confirms an access key is available, supplied the regional endpoint/status list recorded above, and provided a dashboard screenshot showing 0/5 GB storage and 0/20 GB traffic consumed. The actual credential values are not recorded. No bucket is confirmed, no bucket name is known, and the exact bucket region is therefore not yet verified. No real connection has been tested, no user data has been uploaded, and no application-level free-tier traffic/request hard-stop has been implemented.

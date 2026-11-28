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

## 6. Iranian endpoint list reported by the user (2026-11-28)

| Panel region | Storage class | Endpoint | Status shown |
|---|---|---|---|
| Iran Central | Standard | s3.ir-central1.arvanstorage.ir | Active |
| Iran Central | Standard | s3.ir-thr-at1.arvanstorage.ir | Collecting / provisioning |
| Iran Central | HiOps (SSD) | hot.ir-central1.arvanstorage.ir | Active |
| Western Europe | Standard | s3.eu-west1.arvanstorage.ir | Active |
| Iran North-West | Standard | s3.ir-northwest1.arvanstorage.ir | Active |
| Iran North-West | Standard | s3.ir-tbz-sh1.arvanstorage.ir | Collecting / provisioning |

S3 provider configuration identifies Tehran / Simin as https://s3.ir-thr-at1.arvanstorage.ir with region ir-thr-at1, and Tabriz / Shahriar as https://s3.ir-tbz-sh1.arvanstorage.ir with region ir-tbz-sh1.

References:
- https://github.com/rclone/rclone/blob/master/backend/s3/provider/ArvanCloud.yaml
- https://docs.arvancloud.ir/fa/object-storage/limits/

Do not substitute generic region hostnames for zone-specific S3 endpoints without Arvan confirmation. Never use the European endpoint or HiOps for this free-tier Iranian knowledge store.

## 7. Dashboard screenshot and bucket navigation

The user's Object Storage dashboard screenshot shows Base plan active, 0 GB used / 5 GB remaining, 0 GB traffic used / 20 GB remaining, a sidebar item named صندوقچه‌ها (Buckets), and a top-left + صندوقچه button. This confirms the current quota meter is unused; it does not establish a hard spending cap.

The earlier Bucket Policy page asked to select a bucket. That is not the bucket creation page. The bucket list/create route is from the صندوقچه‌ها sidebar item and + صندوقچه button.

## 8. Official documentation review — exact bucket fields and policy

Official pages reviewed:
- https://docs.arvancloud.ir/fa/object-storage/buckets/
- https://docs.arvancloud.ir/fa/object-storage/buckets/object-lock/
- https://docs.arvancloud.ir/fa/object-storage/buckets/versioning/
- https://docs.arvancloud.ir/fa/object-storage/permission/
- https://docs.arvancloud.ir/fa/object-storage/limits/
- https://docs.arvancloud.ir/fa/object-storage/dashboard/
- https://docs.arvancloud.ir/fa/object-storage/s3/
- https://docs.arvancloud.ir/fa/object-storage/buckets/lifecycle/
- https://docs.arvancloud.ir/fa/object-storage/buckets/replication/
- https://docs.arvancloud.ir/fa/developer-tools/
- https://docs.arvancloud.ir/fa/developer-tools/sdk/
- https://docs.arvancloud.ir/fa/changelog/
- https://docs.arvancloud.ir/fa/cloud-server/
- https://docs.arvancloud.ir/fa/cdn/
- https://docs.arvancloud.ir/fa/cloud-container/
- https://docs.arvancloud.ir/fa/databases/
- https://docs.arvancloud.ir/fa/edge-computing/
- https://docs.arvancloud.ir/fa/drive/

### 8.1 Bucket creation fields

The official bucket guide describes:
1. Bucket name — globally unique across Arvan Object Storage.
2. Region — select an available Iranian data center (Simin or Shahriar). A bucket can only be accessed in the region where it was created and has a region-specific URL.
3. Public/private access — private is the default. Do not enable public display for FARAST knowledge.
4. Tags — key/value labels can be added during creation and later managed via bucket Actions → Manage Tags.
5. Click Create after reviewing the fields.

There is a documentation inconsistency about bucket names:
- The bucket-creation page says lowercase English letters and numbers and says special characters such as dot and question mark are not allowed.
- The Limits page says 3–63 characters, lowercase letters, digits, dots and hyphens; first/last character must be a letter or digit; no consecutive dots; cannot look like an IP address. It describes dot-containing examples as valid but not recommended except static websites.

To satisfy both descriptions, FARAST uses the stricter common subset: lowercase letters, digits and hyphens only; 3–63 characters; start/end with a letter or digit. Proposed name to check for availability: farast-knowledge-ir. This is only a suggestion and has not been created or reserved. Bucket name and region cannot be changed after creation.

### 8.2 Region and Multi-zone

The bucket guide says current region choices are Simin and Shahriar. Multi-zone is available for a bucket in Simin and stores data across multiple zones; the docs explicitly say it cannot be disabled after activation. No price or Base-plan inclusion for Multi-zone was confirmed.

Decision: do not enable Multi-zone unless the account panel explicitly shows it included at zero cost or Arvan confirms in writing that it adds no charge. Start with a single-zone Standard bucket.

### 8.3 Private access semantics

The permission and Limits docs distinguish bucket ACL from object ACL:
- Private bucket access prevents unauthorized users from listing bucket contents.
- Object permissions do not automatically inherit from the bucket.
- Changing a bucket from public to private does not change the public/private state of individual objects.
- Public objects can be read without authentication; private objects require authenticated access or a signed temporary URL.
- Dashboard → Access Management is the source of Access Key, Secret Key and Endpoint URL for API/third-party connections.

Decision: bucket must be private, and each uploaded object must explicitly be private too. Use server-side authenticated access or short-lived signed URLs only when needed. Never expose S3 credentials in the browser.

### 8.4 Tags

Tags are key/value pairs, can be added at bucket creation, and later added/edited/deleted through Actions → Manage Tags. No billing benefit is documented.

Suggested non-sensitive tags:
- project=farast
- purpose=knowledge-base
- data-class=private

Never place customer names, emails, user identifiers or other personal information in tags.

### 8.5 Versioning

The official guide says Versioning is disabled by default and can be enabled free of charge. It retains current and non-current object versions:
- Overwrite creates a new version; old version becomes non-current.
- API delete adds a Delete Marker; old versions can remain recoverable through API.
- Deleting an object through the panel removes all versions and cannot be recovered.
- Disabling Versioning does not delete old versions already retained.

Decision: leave Versioning disabled at initial creation. Although the feature is documented as free, retained versions consume the 5 GB quota and can create billable overage. Revisit only after quota metering and cleanup are implemented and tested. FARAST should implement deliberate application-level versioned bundles/checksums instead of unbounded provider versions.

### 8.6 Object Lock

Object Lock provides WORM (Write Once Read Many) protection. It must be enabled during bucket creation, requires Versioning, and cannot be disabled afterward. Retention can be increased but not reduced; locked object versions cannot be deleted/overwritten until expiry.

Decision: do not enable Object Lock. It is irreversible and would make cleanup and quota control difficult. No retention/legal requirement justifies it for this initial free-tier bucket.

### 8.7 Lifecycle and Replication

- Lifecycle rules can automatically delete current objects, non-current versions and incomplete multipart uploads. A wrong rule can remove knowledge files. Do not create lifecycle rules until retention, manifest and restore procedures are tested.
- Replication is documented as available only on Growth, Professional and Enterprise plans, not Base. It is one-way; while active it syncs new uploads but not deletions. Deleting either source/destination disables the rule. Do not enable or assume it is free.

### 8.8 Limits and reliability

The official Limits page states:
- A bucket belongs to one region and has a region-specific URL.
- Default account quota: 50 buckets.
- Default bucket object count limit: 3,700,000.
- Single-object upload request: up to 5 GB.
- Multipart: minimum part 5 MB, maximum part 400 MB, up to 10,000 parts, final object up to 5 TB.
- Multipart is recommended for files above 500 MB.
- Applications should retry transient 5xx errors.
- For many objects, use client tools such as s3cmd rather than relying on the panel.
- Object Storage has no real folders; apparent folders are prefixes in object keys.

FARAST requirements: bounded retry/backoff for transient errors; multipart only when required; incomplete multipart cleanup only after confirming no active upload; prefix-based namespaces; no high-volume tests on the free account.

### 8.9 API, SDK and tools

The developer-tools page lists API, SDK and Terraform Provider. The SDK overview page retrieved did not identify a PHP/Laravel package. The Object Storage docs mention S3-compatible tools including s3cmd, S3 Browser, rclone and Cyberduck. Dashboard → Access Management supplies Access Key, Secret Key and Endpoint URL.

Decision: reuse FARAST's existing AWS Signature V4 S3 adapter and existing iran_primary storage role. Do not add a second S3 client or a parallel integration. Validate exact region/endpoint and provider compatibility with a tiny private object only after hard-stop billing controls are implemented.

### 8.10 Other linked Arvan products — scope boundary

- Cloud Server: the guide shows datacenter, OS, CPU/RAM/disk selection and price before creation; separate billed VM service.
- Cloud Container: app creation requires resource selection (persistent storage, CPU, RAM) and shows an invoice before deployment.
- Managed Databases: separate managed MySQL/PostgreSQL cluster product; no free entitlement for this account established.
- Edge Computing: serverless platform; no free allowance for this account established.
- CDN: separate domain/DNS/HTTPS/cache configuration; not needed for a private knowledge bucket.
- ArvanDrive: collaboration product with a trial plan; not a substitute for private S3 Object Storage or a confirmed permanently free repository.

Do not provision any of these products as part of the current task. The only confirmed free entitlement is the Base Object Storage plan shown in the user's panel.

## 9. Official tutorial video inspection and limitation

The official bucket, Versioning and Replication guides expose embedded Arvan VOD players. Their player configuration and HLS master playlists were fetched:
- Bucket creation tutorial: Bucket.mov, media ID 7NQjWqZ3go.
- Versioning tutorial: versioning.mov, media ID XJkGOeomYW.
- Replication tutorial: replication.mov, media ID oBvR0LRawm.

The HLS master playlists expose six video renditions. The retrieved configs/playlists did not expose subtitle/caption tracks or a transcript. The player pages returned empty extracted content, so the actual audiovisual frames could not be reliably reviewed or transcribed with the available extraction tools. This limitation is explicit; no claim is made that the videos were fully watched. The text guides and linked screenshot sequences were reviewed and are the basis of the recorded configuration decisions.

## 10. Current activation status

- [x] User confirmed an Access Key is available (credential values not stored).
- [x] User supplied Iranian/European endpoint list and statuses.
- [x] Dashboard screenshot recorded: 0/5 GB storage and 0/20 GB traffic consumed.
- [x] Official Persian Object Storage documentation reviewed for creation fields, permissions, tags, Versioning, Object Lock, Lifecycle, Replication, limits, SDK/API and dashboard access management.
- [ ] User has not yet opened the Buckets list or confirmed whether any bucket exists.
- [ ] No bucket has been created.
- [ ] No endpoint/region is configured in FARAST.
- [ ] No S3 credentials are stored in FARAST.
- [ ] No connection test or upload has occurred.
- [ ] No application-level free-tier traffic/request hard-stop has been implemented.
- [ ] Proposed 4 GB stored / 15 GB monthly egress / 7,000 operations thresholds remain design requirements only.

Next action: user opens Object Storage → Buckets and checks the create form. Do not submit until the selected region, Standard class, private access, and all irreversible options (Multi-zone/Object Lock/Versioning) have been inspected. Do not upload or run S3 probe operations until the no-overage stop mechanism is implemented and verified.


## 11. FARAST code audit findings that affect free-tier cost (2026-11-28)

The current main branch was inspected without modifying it.

### 11.1 StorageManager full read-back after every upload

In app/Services/Storage/StorageManager.php, verifyWrittenObject() downloads the complete newly uploaded object to a temporary file and calculates SHA-256 again. This means a successful upload currently incurs a full object download immediately after upload, in addition to the write request. On a 4 GB upload, that can consume roughly another 4 GB of egress traffic and additional requests. This is not suitable for the 20 GB free monthly traffic allowance.

Before connecting Arvan, change this verification strategy to a provider-supported checksum/metadata verification that does not download the full object, after confirming Arvan's exact S3 checksum behavior. Until that is implemented and tested, do not run StorageManager upload tests against the user's account.

### 11.2 StorageConnectionService verification is not a harmless check

app/Services/StorageConnectionService.php calls S3CompatibleAdapter::probe(), which writes, reads and deletes a probe object. It also refreshes inventory on first verification and every six hours (unless configured otherwise). Inventory paginates through all objects with up to 1,000 keys per page. On a populated bucket this can create many API requests. Do not use the current verification action on the free account until request metering/guards are in place and the inventory behavior is changed to an explicitly controlled operation.

### 11.3 Current S3 adapter and quota controls

app/Services/Storage/S3CompatibleAdapter.php implements AWS Signature V4 and supports private credentials, endpoint, region and path-style addressing. The current StorageManager selectVolumes() checks configured capacity_bytes, used_bytes, max_file_bytes and object_count/file_limit, then increments internal used_bytes/object_count after registration. It does not implement a provider-account monthly traffic/request meter, nor an atomic free-tier reservation against concurrent uploads. The separate PR #761 branch's StorageManager still uses this same selectVolumes() and register() pattern; no quota/reservation implementation was found in that class.

Therefore the prior proposed 4 GB / 15 GB / 7,000-operation values are not live safeguards. They must not be described as implemented. A reliable no-overage design needs:
- atomic reservation of storage capacity before upload;
- hard per-file and total-account cap;
- explicit monthly egress and API operation accounting with a conservative safety margin;
- no automatic full-object read-back after upload;
- health checks that do not trigger full inventory scans;
- a fail-closed switch to stop writes/reads when usage cannot be verified;
- provider dashboard reconciliation and tests, including concurrent uploads.

Until this work is implemented and verified, no S3 probe, upload, bulk migration, inventory refresh or connection test should be run against the user's paid-wallet-linked account.


## 12. Focused re-review of the four priority official pages and their screenshots (2026-11-28)

The exact four priority URLs were reopened directly:
- https://docs.arvancloud.ir/fa/object-storage/buckets/#مدیریت-برچسب
- https://docs.arvancloud.ir/fa/object-storage/buckets/object-lock/
- https://docs.arvancloud.ir/fa/object-storage/buckets/versioning
- https://docs.arvancloud.ir/fa/object-storage/permission

### 12.1 Bucket creation form — exact fields visible in official screenshot

The official screenshot at `/arvandocs/object/bucket/newbucket.png` shows the following controls:
1. **نام صندوقچه** (required text field).
2. **انتخاب منطقه** with radio options **سیمین** and **شهریار**.
3. **مالتی‌زون** toggle displayed under Simin.
4. **آبجکت لاک (Object Lock)** toggle, with a retention period input shown when relevant.
5. **نسخه‌نگاری (Versioning)** toggle.
6. **دسترسی عمومی صندوقچه** toggle.
7. **برچسب‌ها** section with a **برچسب‌ها** button.
8. Bottom actions **ساخت** and **انصراف**.

The screenshot is a documentation illustration and may show some toggles in a selected visual state; do not infer a user's actual default setting from the illustration. Use the accompanying text: bucket public display is private by default; Versioning is disabled by default. Object Lock is a separately selected irreversible option and requires Versioning.

### 12.2 Exact label/tag modal fields

The official image `/arvandocs/object/bucket/tag.png` shows a **مدیریت برچسب** modal with:
- **عنوان** (label key) text input.
- **مقدار** (label value) text input.
- row number and a delete/trash control.
- **اضافه کردن برچسب جدید** button.
- **اعمال** and **انصراف** actions.

The bucket guide text says one or more labels can be added during creation; after creation use bucket row **عملیات → مدیریت برچسب**, enter title and value, add further rows if needed, then press **اعمال برچسب**. Labels are for classification/management; no cost reduction or security enforcement is documented.

For FARAST, labels are optional. If the creation form requires or the user chooses to add them, enter exactly:
- عنوان: `project` — مقدار: `farast`
- عنوان: `purpose` — مقدار: `knowledge-base`
- عنوان: `data-class` — مقدار: `private`

Do not enter personal/user data. If labels are optional and the UI makes them awkward, leave them empty; they do not affect the bucket's storage behavior.

### 12.3 Exact Object Lock screenshot and implications

The official Object Lock screenshot shows:
- Toggle **آبجکت لاک (Object Lock)**.
- **مدت زمان نگه‌داری پیش‌فرض (Retention Period)** field with a numeric value and unit **روز** (days); the illustration shows 30 days.

The official text says Object Lock must be enabled at bucket creation, requires Versioning, and cannot be disabled later. Once an object version is locked, it cannot be deleted or overwritten until retention expires. Retention can be increased but cannot be reduced or removed. The guide also states Object Lock is available only for buckets in Simin.

FARAST decision: leave this toggle OFF and do not fill Retention Period. The illustrated 30 days is an example, not a recommended value for this use case. We have no need for irreversible WORM retention on a small free-tier knowledge bucket.

### 12.4 Exact Versioning screen and consequences

The official Versioning activation image shows a dialog titled **نسخه‌نگاری**, with radio choices:
- **فعال**
- **غیرفعال**
and buttons **انتخاب** / **انصراف**.

The accompanying text says Versioning is disabled by default and can be enabled free of charge. When enabled, every new write to an object creates a new version; overwrites retain previous versions as non-current; API deletion creates a Delete Marker and previous versions may be restored through API. The official text also says deleting an object from the panel deletes all its versions and cannot be recovered. Objects that existed before activation have Version ID `null`; subsequent writes receive unique version IDs.

FARAST decision at initial setup: leave Versioning OFF. Although the feature itself is described as free, every retained old version consumes storage quota. On a 5 GB Base plan, accidental repeated overwrites could cause unexpected storage growth. We can revisit after a controlled application-level revision and quota cleanup process is implemented. Do not confuse the no-cost feature toggle with free storage capacity for retained versions.

### 12.5 Permission page — bucket access and individual object access

The official Permission page shows:
- Public bucket listing reveals bucket information, object names, sizes and last-modified times to unauthenticated visitors.
- Private bucket access prevents this public listing.
- Public objects can be fetched through the bucket/object URL without authentication.
- Private objects are accessed through authenticated requests or a signed URL with an expiry time.
- Turning bucket public display off does not automatically change the public/private state of objects already stored in the bucket.
- If an object was made public and later made private, a previously issued private signed URL remains valid only until its expiry. If an object is switched from private to public, its public URL works while an earlier signed URL remains valid until expiry.

FARAST decision: create the bucket with **دسترسی عمومی صندوقچه OFF** (private). Ensure the application also uploads each object with private ACL; do not rely on the bucket-level setting alone. Serve private files through server-side authenticated reads or short-lived signed URLs. Never place Access Key/Secret Key in client-side JavaScript or send them to a browser.

### 12.6 User-facing creation instructions — authoritative exact form values

When the user opens **فضای ابری → صندوقچه‌ها → ایجاد صندوقچه / + صندوقچه**, use:
- **نام صندوقچه:** `farast-knowledge-ir` (only if the globally unique name is accepted; otherwise stop and report the validation message before trying a different name).
- **منطقه:** **سیمین** only if its panel status is actually **فعال**; otherwise select **شهریار** only if its corresponding Standard endpoint is active. Never choose Europe or HiOps for this Iranian data store.
- **مالتی‌زون:** OFF; its activation cannot be reversed and no Base-plan zero-price guarantee has been verified.
- **آبجکت لاک:** OFF; leave Retention Period blank/untouched.
- **نسخه‌نگاری:** OFF at initial creation.
- **دسترسی عمومی صندوقچه:** OFF (private).
- **برچسب‌ها:** optional; if adding, use the exact key/value pairs above.
- Press **ساخت** only after verifying all values and confirming no additional paid option is selected.

Important: user's earlier panel list showed both zone-specific S3 endpoints `s3.ir-thr-at1.arvanstorage.ir` and `s3.ir-tbz-sh1.arvanstorage.ir` as "درحال جمع‌آوری" at that time, while generic region endpoints showed "فعال". Do not infer that the region selector's availability equals the zone-specific S3 API endpoint's readiness. Before connecting, confirm the exact bucket endpoint/region from the bucket details and the Access Management page. Do not make an S3 request until the code-side no-overage guard is implemented.



## 13. User's actual bucket-creation form screenshot reviewed (2026-11-28)

The screenshot supplied by the user shows the live create-bucket form, not merely the documentation illustration.

Observed state in the user's current form:
- Bucket name: empty (required).
- Storage class: Standard selected.
- Region: **ایران مرکز / Simin**, region code shown as `ir-thr-at1`.
- Multi-zone: appears enabled (the toggle is teal and the left summary explicitly reports `زون: مالتی‌زون`). The form text says enabling Multi-zone calculates bucket size per zone.
- Object Lock: disabled in the left summary and its toggle appears off.
- Versioning: disabled in the left summary and its toggle appears off.
- Public access: disabled in the left summary and its toggle appears off.
- Tags: zero; optional section has a **مدیریت برچسب‌ها** button.
- The lower part of the form is obscured by the browser/overlay in the supplied screenshot, so do not infer any unseen options.

**User action before creation:** turn Multi-zone OFF before creating the bucket. This is a pre-creation setting and must be OFF at creation because the official guide says it cannot be disabled after activation. The user's screenshot summary is authoritative evidence that it is currently selected in the draft form.

The selected region is Iran Central / Simin with code `ir-thr-at1`. The user's previously reported endpoint status for the zone-specific endpoint `s3.ir-thr-at1.arvanstorage.ir` was "درحال جمع‌آوری", while the generic endpoint `s3.ir-central1.arvanstorage.ir` was "فعال". Do not silently substitute one endpoint for the other. After bucket creation, the exact API endpoint and region must be taken from the bucket details / Access Management and checked against the current endpoint status before configuring FARAST.

The screenshot's side summary shows the form currently has:
- Type: Standard
- Region: Iran Central
- Zone: Multi-zone
- Object Lock: inactive
- Versioning: inactive
- Public access: inactive
- Tags: 0

No bucket has been created; no S3 request has been made.

## 14. CI refresh for the prior infrastructure fixes

On 2026-11-28, the GitHub Actions runs associated with the latest PR #760 and PR #761 head commits were fetched again:
- PR #760 head `0b5a96f3100af66b154c761b47c8ea0bb73f3fdf`: FARAST CI, Product Supervisor Service, FARAST Phase 4 Agent all completed successfully.
- PR #761 head `a4b07ecdfa833b7031ce235b81e2089ea4965e76`: FARAST CI, FARAST Phase 3 Commerce Tests, FARAST Phase 4 Agent all completed successfully.

Both PRs remain open and unmerged. This is evidence of green runs for the listed workflows only; it does not establish a live Arvan connection or production deployment.


## 15. Final verification pass against the priority guides (2026-11-28)

This section supersedes any earlier shorthand that conflated a free feature toggle with free storage/traffic, and records the details verified from the official text and linked screenshots.

### 15.1 Bucket creation screenshot and the live user form are different evidence

The official image `/arvandocs/object/bucket/newbucket.png` shows these fields:
- Required bucket name.
- Region radio buttons: Simin and Shahriar.
- Multi-zone toggle under Simin.
- Versioning toggle.
- Bucket public-display toggle.
- Tags section/button.
- Create and Cancel buttons.

The official guide text establishes private display as the default and Versioning as disabled by default. Do not infer live account state from the illustration.

The user's actual live form screenshot (captured earlier in this conversation) showed:
- Standard class selected.
- Iran Central / Simin selected; displayed region code `ir-thr-at1`.
- Multi-zone toggle appeared ON; the summary explicitly showed `زون: مالتی‌زون`.
- Object Lock OFF, Versioning OFF, Public access OFF, no tags.
- Bucket name empty.

This actual form state is stronger evidence than the generic illustration. Multi-zone must be switched OFF before Create. The official guide says it cannot be disabled once activated; no Base-plan zero-cost guarantee was found.

### 15.2 Multi-zone semantics and pricing

Official bucket guide: Multi-zone is available for Simin; it stores data automatically across multiple data centers/zones and is intended to preserve service continuity during a zone outage. Once activated it cannot be disabled. The guide says it may also be activated after creation through bucket operations.

No official Base-plan pricing statement confirming Multi-zone is included at no additional cost was found in the reviewed material. The user account screenshot / plan comparison also did not establish a free Multi-zone entitlement. Therefore keep it OFF. Do not make a claim that it is necessarily billed by zone; simply state that its zero-cost status is unverified and it is irreversible.

### 15.3 Versioning correction

Official Versioning guide explicitly states Versioning can be enabled free of charge, is OFF by default, and can be switched OFF again later. When enabled:
- overwrites create new versions and retain previous versions;
- API deletion creates a Delete Marker; previous versions can be restored via API;
- panel deletion removes all versions permanently;
- disabling Versioning does not delete already retained non-current versions; Lifecycle rules may still apply to them.

This is a free feature toggle, not free storage for old versions. Retained versions count against the account's 5 GB storage allowance and can cause overage if they push the bucket above quota.

Decision: keep Versioning OFF for initial free-tier creation because this bucket needs strict storage-capacity control and FARAST will use its own versioned immutable knowledge bundles/manifest/checksum strategy. Reconsider enabling provider Versioning only after actual storage metering, non-current-version lifecycle policy and restore tests are in place. Do not state that Versioning is irreversible; the official guide says it can be disabled.

### 15.4 Object Lock correction and exact fields

Official Object Lock guide:
- Must be selected at bucket creation.
- Requires Versioning.
- Cannot be disabled after creation.
- Retention Period is a numeric duration (the guide screenshot illustrates 30 days; this is not a recommendation).
- Retention can be increased but not reduced/removed.
- Locked object versions cannot be deleted/overwritten before expiry.

Decision: OFF; leave Retention Period untouched/blank. No WORM retention policy has been defined for FARAST and this is irreversible.

### 15.5 Permission semantics — critical security correction

Official Permission guide and Limits guide agree:
- Private bucket display blocks unauthenticated bucket listing and hides object listing metadata.
- Bucket-level privacy does NOT automatically change each object's ACL; objects do not inherit bucket ACL.
- Public object URLs can be fetched without authentication.
- Private objects require authenticated requests or a signed URL with expiry.
- Bucket privacy alone must never be treated as proof that all existing objects are private.

FARAST must set private object ACL on every upload, never use public ACL for knowledge or user files, and issue short-lived signed URLs only when browser download is required. S3 credentials must stay server-side.

### 15.6 Bucket name rules: use the safe intersection

The bucket creation guide says lowercase English letters and digits, no special characters such as dot/question mark, and globally unique. The Limits guide says 3–63 chars, lowercase letters, digits, dot and hyphen, starts/ends with letter or digit, no consecutive dots, not IP-shaped. To avoid the documentation inconsistency, proposed `farast-knowledge-ir` uses only lowercase letters and hyphens, is within 3–63 chars, and starts/ends alphanumeric. It remains unreserved and must be accepted by the panel before creation.

### 15.7 Region and endpoint readiness

The official bucket guide says buckets are created in Simin or Shahriar and are accessible only in the region where they were created; bucket region/name cannot be changed after creation. The Limits guide uses the region-specific virtual-host form `[bucket].s3.[region].arvanstorage.ir`.

The user reported these endpoint statuses from their own account panel:
- `s3.ir-central1.arvanstorage.ir`: Active.
- `s3.ir-thr-at1.arvanstorage.ir`: Collecting/provisioning.
- `s3.ir-northwest1.arvanstorage.ir`: Active.
- `s3.ir-tbz-sh1.arvanstorage.ir`: Collecting/provisioning.
- `hot.ir-central1.arvanstorage.ir` (HiOps): Active.
- `s3.eu-west1.arvanstorage.ir`: Active.

The official docs retrieved do not resolve whether the generic `ir-central1` endpoint is a valid endpoint for a Simin bucket whose form code is `ir-thr-at1`. Do not substitute endpoints. After creation, use the bucket details and Access Management's endpoint/region fields as the source of truth; if they conflict with status, request Arvan support confirmation before any API call. Do not use Europe or HiOps.

### 15.8 Tags and exact tag-modal fields

The official Manage Tags image shows two text fields per row: **عنوان** (key) and **مقدار** (value), with controls to add another tag, remove a row, Apply and Cancel. Tags are optional and only for management/classification; no security boundary or free-tier discount is documented.

If using tags:
- title `project`, value `farast`
- title `purpose`, value `knowledge-base`
- title `data-class`, value `private`

Otherwise leave them empty. Do not enter user/customer personal information.

### 15.9 Additional official limits affecting implementation

The Limits page confirms:
- Up to 50 buckets by default per account.
- Default per-bucket object count limit 3,700,000.
- Single PUT upload request up to 5 GB.
- Multipart parts: 5 MB minimum, 400 MB maximum; up to 10,000 parts; final object up to 5 TB.
- Multipart is recommended above 500 MB.
- Incomplete multipart parts should only be cleaned after confirming the upload is no longer active.
- Object Storage has no real folders; directory-like views are key prefixes.
- Retry transient 5xx errors with bounded retry/backoff.
- Buckets are region-bound and region/name cannot be changed.
- The docs state no hard technical limit on request count or traffic; the Base plan's included allowance and PAYG billing are a separate commercial limit. Do not mistake the technical service limits for unlimited free usage.

### 15.10 Lifecycle / Replication / application tools

Lifecycle can automatically delete current versions, non-current versions, and incomplete multipart uploads. Do not add any deletion rule until data retention and restore are tested.

Replication is explicitly available only on Growth, Professional and Enterprise plans, not Base. It is not part of the user's free plan and must not be enabled.

The official ArvanCloudS3 desktop app supports bucket management, ACL editing, multi-file upload/download, search, pagination and copy/sync between buckets/regions. Its guide requires an account profile, region, Access Key and Secret Key. It is an optional management tool, not required for FARAST's backend connection; do not install or run bulk Sync for this task because it can transfer large amounts of data.

### 15.11 Video and image inspection limits

The official bucket creation guide embeds an Arvan VOD player and links the actual `newbucket.png` screenshot. The player configuration was identifiable, but the player page returned no extractable text and the origin config/HLS media could not be decoded by the available browser extraction. No transcript/caption track was exposed. Thus the screenshots and written guides were inspected, but the video frames/audio have not been independently reviewed. This limitation must remain explicit; do not claim full video review.

### 15.12 Final creation decision — do not submit yet

Recommended values:
- Bucket name: `farast-knowledge-ir` (only if accepted as globally unique).
- Region: Simin / Iran Central only after endpoint ambiguity is resolved.
- Storage class: Standard.
- Multi-zone: OFF.
- Object Lock: OFF; no Retention Period.
- Versioning: OFF initially (free toggle but old versions consume storage).
- Public bucket access: OFF/private.
- Tags: optional key/value pairs above.
- Do not enable static website, CDN/custom-domain publishing, replication, lifecycle deletion or any paid add-on.

Because the region endpoint is currently ambiguous and multi-zone was ON in the user's draft, do not click Create yet. User does not need to send more screenshots; existing written endpoint statuses and screenshot evidence are already recorded. We should resolve the endpoint mapping from Arvan's own Access Management/region documentation or written support confirmation before asking for bucket creation.



## 16. Endpoint mapping cross-check and decision (2026-11-28)

A further cross-check was made against the maintained rclone ArvanCloud S3 provider configuration. It explicitly maps:
- Standard / Tehran / Simin: endpoint `s3.ir-thr-at1.arvanstorage.ir`, S3 location constraint `ir-thr-at1`.
- Standard / Tabriz / Shahriar: endpoint `s3.ir-tbz-sh1.arvanstorage.ir`, S3 location constraint `ir-tbz-sh1`.
- HiOps / Bamdad: endpoint `hot.ir-central1.arvanstorage.ir` (not suitable for the user's Standard free-tier bucket).

Source: https://github.com/rclone/rclone/blob/master/backend/s3/provider/ArvanCloud.yaml

This independently corroborates the zone-specific endpoint mapping already noted in the user's endpoint list. It does **not** establish that the endpoint is currently ready for this particular account: the user panel showed the zone-specific endpoints as "درحال جمع‌آوری" at the time of the report. The generic endpoint `s3.ir-central1.arvanstorage.ir` shown as Active in the user's panel is not the endpoint listed by the S3 provider configuration for Standard/Simin. Do not substitute it for the Simin endpoint based only on the shared region-family name.

Decision:
1. The correct S3 endpoint/region pair for a Standard/Simin bucket is `https://s3.ir-thr-at1.arvanstorage.ir` / `ir-thr-at1`.
2. Do not use `s3.ir-central1.arvanstorage.ir` as a substitute for this Standard/Simin endpoint.
3. Because the account panel reported the exact Simin endpoint as "collecting/provisioning", wait until that exact endpoint is Active or obtain written confirmation from Arvan support. This is a read-only research conclusion; no endpoint probe was sent.
4. The user's draft bucket currently has Multi-zone ON according to the screenshot already recorded. Switch it OFF before Create.
5. Do not ask the user for another screenshot; existing endpoint text and form screenshot provide the evidence. The next required human action, only after endpoint readiness is confirmed, is to turn off Multi-zone and create the bucket with the previously documented safe settings.

No user credentials, no bucket, and no S3 requests were created or used during this research.

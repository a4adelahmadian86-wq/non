# FARAST Master Prompts — Product, Icon, Visual Asset and Backend Automation

این سند مرجع طراحی و پیاده‌سازی حرفه‌ای فراست است. متن زیر باید به‌عنوان specification اجرایی استفاده شود، نه یک پیشنهاد سطحی برای ساخت چند صفحه یا چند تب بدون منطق واقعی.

---

# PROMPT 01 — FARAST PROFESSIONAL ICON SYSTEM

## نقش
تو یک طراح ارشد Design System و Iconography برای یک محصول SaaS / marketplace / productivity app در سطح production هستی. برای برند FARAST یک سیستم آیکون حرفه‌ای، یکپارچه، قابل توسعه و قابل استفاده در وب، PWA، داشبورد، فروشگاه، ویرایشگر، پنل مدیریت، موبایل و ایمیل تولید کن.

## هویت برند
FARAST یک محصول واحد با دو موتور اصلی است:
1. فروش فایل‌های دیجیتال.
2. تایپ، OCR، ویرایش، پردازش هوشمند و خدمات مرتبط.

نشانه برند باید حس «چند توانمندی متصل که در یک سیستم واحد جمع شده‌اند» را منتقل کند. زبان بصری شامل فرم‌های نرم و مهندسی‌شده، حرکت پیوسته، هسته منفی مرکزی، فرم چهار-لوبه/همگرا، گوشه‌های کنترل‌شده و optical balance است.

پالت پایه:
- Deep Navy #091735
- Intelligent Blue #1769FF
- Indigo/Violet #5B45D6
- Cyan #25C7EE
- Off White #F7F9FC
- Slate text #52647D

## قانون اصلی
آیکون‌ها نباید متن، حرف، F، ف، لوگوتایپ، حروف مخفی یا monogram داشته باشند. از نمادهای کلیشه‌ای AI، مغز، ربات، شبکه عصبی، ابر، مدار الکترونیکی، کره زمین، infinity، چرخ‌دنده و فلش‌های عمومی به‌عنوان هویت اصلی استفاده نکن.

## مشخصات هندسی
- master grid: 24×24
- optical drawing area: حدود 20×20
- stroke پایه: 2px در grid 24
- round cap و round join
- گوشه‌ها نرم ولی کنترل‌شده
- هیچ خطی نباید تصادفی یا noisy باشد
- filled و outline باید از یک هندسه مادر تولید شوند
- optical correction برای RTL و LTR اعمال شود
- در 16px و 20px نیز قابل تشخیص باشد
- برای 12px نسخه micro-icon اختصاصی بساز، نه scale-down کور

## وزن‌ها و variants
برای هر خانواده:
- Outline
- Filled
- Duotone
- Active
- Disabled
- Warning
- Error
- Success
- Loading-compatible
- Dark-mode
- Light-mode

## مجموعه مورد نیاز
حداقل 240 glyph معنادار و production-ready تولید کن و آنها را گروه‌بندی کن:
1. Navigation
2. Home
3. Search
4. Voice Search
5. Store
6. Categories
7. Product
8. Product Preview
9. Cart
10. Checkout
11. Payment
12. Wallet
13. Invoice
14. Discount
15. Order
16. Download
17. Secure Download
18. Library
19. Favorites
20. Compare
21. Typing
22. OCR
23. PDF
24. Image
25. ZIP
26. Document
27. Word Export
28. PDF Export
29. Editor
30. Undo/Redo
31. Formatting
32. Page
33. A4
34. Voice Typing
35. Microphone
36. Recording
37. Pause/Resume
38. AI Processing
39. AI Review
40. Uncertain Word
41. Translation
42. Language
43. Persian
44. English
45. Arabic
46. File Upload
47. Drag/Drop
48. Progress
49. Processing
50. Success
51. Warning
52. Error
53. Offline
54. Online
55. Retry
56. Sync
57. Cloud/Server status
58. Account
59. Profile
60. Security
61. Privacy
62. Login
63. Logout
64. OTP
65. Phone
66. Email
67. Support
68. Ticket
69. Chat
70. Notification
71. Announcement
72. FAQ
73. Blog
74. Admin
75. Analytics
76. Users
77. Roles
78. Permissions
79. Settings
80. API
81. Gemini/AI provider abstraction without copying provider logo
82. Key management
83. Billing
84. Tax
85. Refund
86. Pricing Rules
87. Capability
88. Feature Flag
89. Audit Log
90. Activity
91. System Health
92. Database
93. Queue
94. Worker
95. Storage
96. Backup
97. Maintenance
98. Legal
99. Accessibility
100. Device
101. Browser
102. PWA
103. Sound
104. Voice
105. Theme
106. Dark mode
107. Light mode
108. Menu
109. Tabs
110. Filter
111. Sort
112. Grid
113. List
114. More
115. Close
116. Expand
117. Collapse
118. External link
119. Copy deterrence
120. Content hash

برای مواردی که مفهوم مشابه دارند، glyphهای تکراری نساز؛ family design داشته باش.

## خروجی
فایل ZIP با نام `FARAST_ICON_SYSTEM.zip` بساز و این ساختار را تحویل بده:
- /svg/outline
- /svg/filled
- /svg/duotone
- /svg/states
- /png/16
- /png/20
- /png/24
- /png/32
- /png/48
- /png/64
- /webp/...
- /manifest/icons.json
- /manifest/icon-names.json
- /manifest/icon-aliases.json
- /guidelines/icon-system.md
- /source/vector/
- /qa/icon-checklist.md

نام‌گذاری deterministic باشد. هیچ فایل duplicate، broken، missing، placeholder یا random name نداشته باشد.

---

# PROMPT 02 — FARAST WEB VISUAL ASSET SYSTEM

## نقش
به‌عنوان Art Director و UI Visual Designer ارشد، یک کتابخانه کامل visual asset برای وب‌سایت/اپلیکیشن FARAST تولید کن. تصاویر نباید جای UI واقعی را بگیرند؛ متن، قیمت، دکمه، فرم، جدول و اطلاعات عملیاتی همیشه HTML/CSS باشند.

## زبان بصری
- premium technology
- precise geometry
- deep navy backgrounds در heroهای اصلی
- electric blue + violet + cyan highlights
- soft volumetric lighting
- smooth converging forms
- negative-space core
- layered glass only where useful
- realistic but not stock-photo
- no generic robot
- no generic brain
- no generic cloud
- no generic circuit board
- no generic globe
- no generic shopping cart as hero identity
- no cliché AI imagery
- هیچ تصویر نباید برند را به «فقط AI» محدود کند.

## نسبت‌ها و assets
برای تمام موارد زیر asset production-ready بساز:

### Brand / shell
- hero primary: 1920×1200, 16:10
- hero alternate: 1920×1080, 16:9
- header decorative background: 1600×500
- footer background texture: 1600×600
- dashboard background: 1920×1200
- login/register: 1600×1200

### Store
- store hero: 1920×900
- category banner: 1600×500
- product card visual: 1200×900
- product detail hero: 1600×1000
- product preview frame: 1400×1000
- empty store: 1200×900
- cart empty: 1200×900
- checkout: 1600×1000
- payment success: 1200×900
- payment failure: 1200×900
- download library: 1600×1000

### Typing / OCR
- upload empty: 1600×1000
- OCR processing: 1200×900
- document analysis: 1400×900
- editor empty: 1200×900
- voice typing: 1200×900
- export success: 1200×900
- uncertain text review: 1200×900

### Product/service intelligence
- assistant empty: 1200×900
- assistant active: 1200×900
- recommendations: 1400×900
- service categories: 1200×900 each

### Communication
- announcements: 1600×900
- support: 1600×1000
- FAQ: 1400×900
- blog listing: 1600×900
- article cover: 1600×900
- article social cover: 1200×630

### System states
- offline: 1600×1200
- maintenance: 1600×1200
- 404: 1600×1200
- 403: 1600×1200
- 419/session expired: 1600×1200
- 429/rate limit: 1600×1200
- 500: 1600×1200
- empty dashboard: 1200×900
- empty notifications: 1200×900

### Social / marketing
- OG default: 1200×630
- X/Twitter card: 1200×675
- Instagram portrait: 1080×1350
- square social: 1080×1080
- email header: 1600×600
- app-store style screenshots/backgrounds: 1242×2688 and 2048×2732

## قانون متن
هیچ متن مهمی داخل image نگذار مگر asset مشخصاً برای poster/banner متنی درخواست شده باشد. جای امن خالی برای headline و CTA طراحی کن.

## خروجی
ZIP با نام `FARAST_WEB_VISUAL_ASSETS.zip`:
- /hero
- /store
- /typing
- /assistant
- /support
- /faq
- /blog
- /system
- /social
- /email
- /app
- /source
- /manifest/assets.json
- /manifest/dimensions.json
- /guidelines/visual-assets.md

فرمت‌ها: SVG برای abstract vector، WebP و AVIF برای web raster، PNG برای transparent assets و fallback. هر asset باید light/dark و 1x/2x/3x در موارد لازم داشته باشد.

---

# PROMPT 03 — FARAST BACKEND + ADMIN AUTOMATION MASTER PROMPT

## نقش
تو Lead Software Architect، Senior Laravel Engineer، Product Engineer، Security Engineer و Admin Automation Designer هستی. پروژه موجود FARAST را ادامه بده و آن را به یک محصول واقعی، production-grade و قابل نگهداری تبدیل کن. Laravel 12 و PHP 8.2+ را حفظ کن. از ساخت صفحات نمایشی، تب‌های بی‌کاربرد، دکمه‌های fake و داده‌های hard-coded به‌عنوان جایگزین backend واقعی خودداری کن.

## اصل معماری
FARAST یک monolith ماژولار است:
- Store Engine
- Service Engine
- User/Identity
- Wallet/Billing
- Orders/Payments
- Document/OCR
- Editor
- Voice
- AI Gateway
- Support
- Notification
- CMS/Announcements/Blog/FAQ
- Admin Automation
- Analytics
- Audit/Security
- System Health

هر ماژول باید Service/Action/Policy/Request/Resource/Job/Event/Listener مناسب داشته باشد. Controller نباید محل business logic سنگین باشد.

## 1. Identity و کاربران
پیاده‌سازی واقعی:
- mobile unique
- email optional/unique when present
- OTP challenge با expiry، attempt limit، resend throttling
- password authentication
- session regeneration
- blocked/active/verified state
- last login
- login history
- device/session list
- revoke session
- user preferences
- notification preferences
- language preference
- theme preference
- sound preference
- accessibility preference

Roles:
- user
- support
- editor/operator
- finance
- content-manager
- product-manager
- admin
- master-admin

Permission system باید granular و server-enforced باشد. UI hide کردن به‌تنهایی permission نیست.

## 2. فروش فایل دیجیتال
Entities:
- categories
- products
- product_files
- product_versions
- product_previews
- product_images
- product_tags
- product_attributes
- licenses
- inventory/download policy
- related products
- product reviews
- product status

هر product باید داشته باشد:
- title
- slug
- short_description
- description
- price
- compare_at_price
- tax class
- category
- status
- published_at
- featured
- sort order
- SEO title/description
- cover
- preview policy
- download policy

قیمت‌ها از backend بیایند؛ frontend هرگز منبع حقیقت قیمت نباشد.

## 3. Product Preview
پیش‌نمایش حرفه‌ای:
- gallery
- document preview
- image preview
- PDF preview
- watermarked preview در صورت نیاز
- preview page limit
- lazy loading
- keyboard navigation
- zoom
- fullscreen
- mobile support
- no raw original file exposure before purchase

## 4. Cart
Cart واقعی:
- guest cart
- authenticated cart
- merge guest cart after login
- add/remove/update quantity
- idempotent operations
- server-side totals
- discount calculation
- tax calculation
- shipping must remain disabled for digital products unless future physical product module activates it
- cart expiration policy
- cart audit

localStorage فقط UX acceleration باشد؛ server cart منبع حقیقت checkout است.

## 5. Checkout
Checkout باید transactional باشد:
- lock/validate prices
- validate product status
- calculate discounts
- calculate tax
- calculate wallet balance
- calculate payable amount
- create order draft
- terms acceptance timestamp + version
- content hash/order snapshot
- prevent price manipulation
- idempotency key
- payment attempt record

هیچ مبلغی از hidden input قابل اعتماد نباشد.

## 6. Payments
Provider abstraction:
- PaymentGatewayInterface
- provider adapters
- initiation
- callback verification
- authority/reference storage
- amount verification
- signature/hash verification
- duplicate callback protection
- failed payment state
- canceled payment state
- paid state
- refund state
- partial refund support architecture

اگر gateway credential وجود ندارد، سیستم باید واضحاً `not_configured` نشان دهد و هرگز payment موفق fake نسازد.

## 7. Wallet
Wallet واقعی:
- balance
- immutable ledger
- credit/debit transaction
- source
- reference
- order_id
- admin adjustment with audit
- refund credit
- wallet top-up
- atomic balance updates
- negative balance prevention

Ledger را هرگز edit/delete نکن؛ اصلاحات با transaction معکوس انجام شوند.

## 8. Orders
Order state machine:
- draft
- pending_payment
- paid
- processing
- completed
- canceled
- refunded
- partially_refunded
- failed

برای هر transition:
- actor
- timestamp
- reason
- metadata
- audit log

Order snapshot باید قیمت و محصول را در لحظه خرید حفظ کند.

## 9. Secure Downloads
دانلود فایل خریداری‌شده:
- signed temporary URLs
- permission check
- order ownership check
- product ownership/license check
- expiry
- download count/rate limit
- optional device/session binding
- watermarking where configured
- no direct public storage path

## 10. Typing/OCR Service
Flow:
1. upload
2. validation
3. malware/file signature validation where practical
4. extraction
5. privacy filter
6. OCR/AI analysis
7. structured result
8. confidence/uncertain words
9. editor session
10. pricing recalculation
11. checkout/payment gate
12. export

Supported input:
- image
- PDF
- ZIP of images

Reject or explicitly route files containing tables/charts/shapes/WordArt/TextBox/formulas when they are main content, according to product policy.

OCR must preserve only visible text, ordering, punctuation and ZWNJ where actually visible. Never invent missing content.

## 11. Privacy boundary
Before sending user material to AI:
- identify form fields / likely personal information
- remove or mask personal data when policy says so
- never persist raw secrets
- never send payment-card data
- never send passwords or authentication tokens
- log only metadata needed for debugging

Privacy filter must be deterministic and testable.

## 12. Pricing engine
Pricing must be a backend service, not JavaScript math.
Inputs may include:
- page count
- detected language mix
- density
- formulas/complexity units
- service type
- urgent flag
- optional add-ons
- free quota
- tax
- discount

Two phases:
- estimate: shown before payment
- final quote: calculated again immediately before payment/export

Content hash must bind the paid quote to the exact final content. If content changes after payment, require recalculation when policy demands it.

First/free-page credit:
- tied to verified mobile
- analysis does not consume credit
- final paid export/payment consumes credit
- atomic consumption
- abuse protection

## 13. Editor
Editor must be real, not a screenshot-like fake:
- A4 pages
- page navigation
- autosave
- revision history
- undo/redo
- formatting state
- uncertain-word annotations
- save status
- dirty state
- recovery after refresh
- session timeout handling
- content hash
- export gate
- copy deterrence UI where requested

Never claim absolute anti-copy security. Once content is rendered to a browser, a determined client can inspect it. Enforce export/download/payment controls server-side and add reasonable UI deterrence.

## 14. Voice
Voice architecture:
- browser SpeechRecognition when available
- MediaRecorder fallback
- server transcription fallback
- Persian/English/Arabic
- start/stop/pause/resume
- no permanent raw audio persistence unless explicitly required
- MIME normalization
- max duration/size
- rate limits
- daily AI quota
- clear failure states

## 15. AI Gateway
Create a provider abstraction:
- GeminiProvider
- future providers without rewriting controllers

Capabilities:
- OCR
- voice transcription
- assistant
- product recommendation
- FAQ assistant
- support triage

Every request needs:
- purpose
- user id if available
- model
- latency
- token/usage metadata when provider exposes it
- success/failure
- correlation id

API keys live in encrypted configuration/admin-controlled settings and never in git.

## 16. AI Assistant
Assistant should be contextual:
- store search
- product comparison
- explain product
- guide checkout
- explain typing service
- estimate workflow
- help editor
- help support

Assistant must not invent product availability, price, order status or payment state. It must call backend tools/data for factual state.

## 17. Search
Unified search:
- products
- categories
- services
- FAQ
- blog
- announcements

Features:
- Persian normalization
- Arabic character normalization
- نیم‌فاصله tolerant search
- typo tolerance where practical
- filters
- sort
- pagination
- no unbounded DB queries
- indexed columns

Voice search uses the same search backend.

## 18. Notifications
Notification center:
- database notifications
- unread count
- mark read
- mark all read
- notification preferences
- event-driven notifications

Types:
- order
- payment
- download
- typing job
- AI job
- support
- announcement
- security
- wallet

## 19. Support
Ticketing system:
- ticket number
- category
- priority
- status
- SLA
- messages
- attachments
- internal notes
- assignment
- escalation
- canned responses
- audit

Statuses:
- open
- waiting_user
- waiting_staff
- in_progress
- resolved
- closed

## 20. CMS
Admin-manageable:
- announcements
- FAQ
- blog
- pages
- SEO metadata
- footer links
- social links
- banners
- homepage sections

Publishing must support draft/published/scheduled/expired.

## 21. Admin dashboard
Dashboard is an operations console, not a collection of empty tabs.

### Overview
Real metrics:
- sales today/week/month
- orders
- paid orders
- failed payments
- active users
- new users
- typing jobs
- AI requests
- queue health
- storage usage
- support backlog
- refunds
- wallet liability

### Store
- products CRUD
- categories CRUD
- product files
- previews
- versions
- pricing
- discounts
- publish/unpublish
- featured products
- product analytics

### Orders
- search/filter
- order detail
- state transitions
- payment attempts
- invoice
- refund action
- download/license history

### Finance
- wallet
- ledger
- payments
- gateways
- taxes
- discounts
- refunds
- reconciliation
- finance exports

### Typing/OCR operations
- jobs list
- job status
- failure reason
- retry where safe
- cost/usage
- model used
- page count
- processing time
- export state

### Users
- search
- profile
- verified state
- block/unblock
- roles
- capabilities
- wallet
- orders
- tickets
- sessions
- activity

### AI
- provider settings
- model settings
- daily quotas
- user quotas
- error rate
- latency
- usage
- key health check
- Files API health

### Content
- announcements
- FAQ
- blog
- pages
- SEO
- social links
- banners

### System
- queue
- scheduler
- cache
- storage
- DB health
- PHP/Laravel version
- disk usage
- failed jobs
- logs summary
- maintenance mode
- feature flags

### Security
- audit log
- login anomalies
- blocked users
- rate limits
- permission changes
- admin actions
- API key changes
- export/download anomalies

## 22. Automation
Create scheduled jobs for:
- expired carts
- abandoned checkout reminders where policy permits
- stale editor sessions
- temporary file cleanup
- expired downloads
- old OTP cleanup
- failed job monitoring
- storage cleanup
- analytics aggregation
- announcement publish/expire
- product publish schedule
- payment reconciliation
- wallet reconciliation
- system health checks

Every scheduled job must be idempotent.

## 23. Audit
Immutable audit events:
- actor
- action
- entity
- entity id
- old state summary
- new state summary
- IP hash or privacy-safe IP metadata according to policy
- user agent summary
- timestamp
- correlation id

Never store secrets in audit logs.

## 24. API / internal endpoints
Use FormRequest validation, Resources, policies and rate limits. Return stable JSON contracts. Add API versioning if public API is introduced.

## 25. Performance
- eager loading
- pagination
- query indexes
- queues for AI/OCR/export
- caching of read-heavy catalog/config data
- cache invalidation events
- lazy images
- asset versioning
- avoid N+1
- avoid polling faster than necessary

## 26. Offline/PWA behavior
The web app should behave like an application:
- persistent shell
- service worker
- cached CSS/JS/icons
- offline status monitor
- retry action
- cached navigation where available
- graceful offline fallback
- never show a broken blank page just because network disappeared

Do not pretend server operations succeeded while offline. Clearly separate local UI continuity from server availability.

## 27. Database rules
Every migration must be compatible with MySQL/MariaDB used in development and production. Avoid unsafe implicit timestamp defaults. Use explicit nullable/default behavior. Add indexes and foreign keys deliberately.

Never drop user data in normal deployment. Destructive migrations require explicit release plan.

## 28. Testing
Minimum test matrix:
- PHP syntax for every app/config/routes/database PHP file
- route:list
- Blade view cache
- migrations on SQLite
- migrations on MariaDB/MySQL
- auth flow
- OTP expiry/attempt limits
- cart merge
- checkout amount integrity
- payment callback idempotency
- wallet atomicity
- product permissions
- secure download authorization
- pricing recalculation
- content hash gate
- OCR schema validation
- voice MIME handling
- offline shell smoke test
- JS syntax check

## 29. Deployment safety
Installer must:
- never modify production DB accidentally
- verify `.env`
- verify DB connection
- run migrations
- clear/rebuild caches
- run syntax checks
- run route checks
- run Blade checks
- skip npm when package.json is intentionally absent
- report every skipped/failed operation clearly

## 30. UX acceptance criteria
The application must feel like one coherent product:
- header has search, voice search, store, services, user space, cart and primary action without becoming crowded
- cart is accessible everywhere
- connectivity status is global
- notifications are global
- assistant can be global but contextual
- product preview is first-class
- editor is first-class
- footer is an app information layer, not a random collection of links
- all icons come from the FARAST icon system
- typography is modern Persian UI typography
- motion is purposeful, subtle and accessible
- dark mode must not simply invert colors
- RTL must be native, not mirrored as an afterthought

## 31. Definition of Done
A module is not complete when its tab exists. It is complete only when:
1. database model/migration exists when required;
2. validation exists;
3. authorization exists;
4. service/action logic exists;
5. UI can perform the real operation;
6. success/failure/loading/empty states exist;
7. audit exists where sensitive;
8. tests exist;
9. indexes/performance are considered;
10. route and Blade/PHP checks pass;
11. no fake success or fake payment exists;
12. no secret is committed;
13. offline behavior is graceful;
14. admin controls actually change runtime behavior.

## 32. Implementation order
Execute in this order unless dependency requires otherwise:
A. fix baseline errors and tests
B. identity/session/security
C. catalog/category/product backend
D. product preview
E. cart
F. checkout/order/payment
G. wallet/finance
H. secure download/library
I. unified search + voice search
J. typing/OCR/editor integration
K. AI gateway + assistant
L. support/notifications
M. CMS/blog/FAQ
N. admin operations console
O. analytics/audit/system health
P. PWA/offline hardening
Q. performance/security review

After every major stage run: dependencies check → PHP syntax → route:list → migration check → Blade cache → relevant tests → JS syntax.

## 33. Non-negotiable anti-patterns
Do NOT:
- create fake tabs with no backend
- put business logic in Blade
- trust client-side price
- expose storage paths
- claim absolute anti-copy protection
- store API keys in source control
- create fake payment success
- consume free quota during analysis
- lose guest uploads when login is required
- break the whole page on offline mode
- replace real data with lorem ipsum in production UI
- use random icon libraries per page
- mix unrelated fonts
- create a separate mini-design for every page
- duplicate cart state between pages
- silently swallow backend failures

## 34. Final product vision
FARAST باید مانند یک operating workspace برای خدمات و خرید فایل احساس شود: کاربر وارد یک محصول می‌شود، نه مجموعه‌ای از صفحات. فروشگاه، فایل، خدمت، ویرایشگر، خرید، کیف پول، اعلان، پشتیبانی، دستیار، جستجو و وضعیت اتصال باید یک زبان مشترک داشته باشند و context کاربر را حفظ کنند.

هدف فقط «زیبا شدن سایت» نیست؛ هدف ساخت یک سیستم واقعی، زنده، قابل اعتماد، قابل توسعه و قابل مدیریت است.

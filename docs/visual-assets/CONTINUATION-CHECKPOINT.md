# FARAST visual asset work — continuation checkpoint

Last updated: 2026-11-22
Repository: a4adelahmadian86-wq/non
Target branch: main
Verified main HEAD at checkpoint: fda27d7bf5e171385a73b495d6dde7fe19785c63
Working branch: visual/site-image-library-checkpoint (created from the exact main HEAD above)

## User constraints — do not violate
- The website runs locally on the user's computer. Do not claim to have opened or visually inspected the local runtime.
- Work only from GitHub source, repository files, tests, and verifiable tool output.
- Do not create or replace the FARAST logo. Keep the existing brand mark unchanged.
- Do not guess page content, invent product/catalog imagery, or insert generic visuals where imagery does not improve the experience.
- User explicitly asked to conserve ChatGPT image-generation quota. Do not call image generation again until the user returns after the reset window they reported (tomorrow around 15:20, user's local time). Do not attempt quota workarounds.
- Preserve current structure and make narrow, tested changes. Never touch the database or unrelated features.
- Do not claim a local deployment is complete just because GitHub files changed. Local sync/run is a separate step.

## Work already merged
PR #743 — https://github.com/a4adelahmadian86-wq/non/pull/743
Merged main commit: fda27d7bf5e171385a73b495d6dde7fe19785c63
Scope: restore homepage hero artwork fallback, reduce dark overlay / improve image clarity, add homepage asset fallback test.
CI run: https://github.com/a4adelahmadian86-wq/non/actions/runs/45020736360
All active checks passed; one voice-model smoke check was skipped by design.

## Current asset inventory observed in repository
The repository's public/images directory contained only these 9 assets at the last audit:
- farast-ai-mark.webp
- farast-blog-hero.svg
- farast-home-hero.svg
- farast-home-workflow.webp
- farast-service-edit.svg
- farast-service-export.svg
- farast-service-ocr.svg
- farast-service-voice.svg
- farast-store-hero.svg

Relevant views audited:
- resources/views/home.blade.php
- resources/views/store/index.blade.php
- resources/views/blog/index.blade.php
- resources/views/pricing.blade.php
- resources/views/dashboard.blade.php
- resources/views/auth/login.blade.php
- resources/views/editor.blade.php
- resources/views/store/course-preview.blade.php
- resources/views/store/reader.blade.php
- resources/views/community/index.blade.php
- resources/views/support/index.blade.php

Observed image usage:
- Home: hero workflow image with SVG fallback; product images are dynamic; service OCR/voice/edit/export SVGs.
- Store: store hero SVG and dynamic featured product covers.
- Blog: blog hero SVG and dynamic article covers.
- Reader/course preview: page/product preview media may be data-driven.
- Pricing, dashboard, login, editor, community, support: no direct image refs found in the initial view scan. Do not automatically add images to forms or utility pages without checking the real UX and data.

## Generated image outputs from this work session
1. Photographic workspace scene:
   - Original generated file: a_bright_modern_workspace_desk_scene_in_a_clean_of.png
   - Original dimensions: 1536 x 1024
   - Optimized WebP working copy: /mnt/data/farast-home-workspace.webp
   - Optimized dimensions: 1200 x 800
   - Optimized size at quality 60: 56,858 bytes
   - This is a candidate editorial/workspace photo only. It contains no FARAST logo or textual claims.
   - IMPORTANT: This file has NOT yet been committed to GitHub and is NOT yet referenced by the website.
2. A second generated composite contains an invented logo and generated Persian text. Do NOT use it in the website, do NOT treat it as brand artwork, and do not publish it as a FARAST banner.

## Image-generation quota
The image tool reported the Free quota is exhausted until the reset window the user reported: tomorrow at approximately 15:20. Do not retry before the user explicitly returns after that window. Continue source audit, asset planning, SVG cleanup, code/tests, and checkpoint maintenance without image generation.

## Next steps when the user says "ادامه بده"
1. Re-fetch main and verify its current SHA. If it moved, inspect the intervening commits and create a fresh branch from the new exact HEAD; do not assume this checkpoint branch is still current.
2. Check this branch and current PR status before writing; avoid duplicate branches/PRs.
3. Safely transfer the optimized photographic WebP into the repository if a supported binary upload path is available. If not, say so plainly and keep it as an uncommitted artifact; do not claim it is in GitHub.
4. Continue the image audit page by page, including CSS background images, empty states, card covers, and responsive behavior. Prioritize store/category/course/blog/service visuals where actual imagery improves comprehension.
5. Generate only a coherent, purpose-built set of images after quota reset, one generation batch at a time, with user quota in mind. Avoid text/logos inside generated images; create photographic/editorial assets without embedded copy.
6. Apply only verified asset references and responsive styles. Keep existing logo files untouched.
7. Add tests for asset existence and key rendering references; run relevant CI checks.
8. Open a PR to main with a detailed inventory of assets and changed views. Merge only after required checks succeed.
9. Report precisely what was committed, what was merged, what tests ran, and what still needs the user's local sync. Never claim the local site has been visually validated.

## Current state (updated after checkpoint merge)
- PR #744 merged successfully on 2026-11-22.
- Checkpoint documentation is now on main. Merge commit: 0fcf544b3c1e5154e76cbece0840adf8bd46613f.
- Re-fetch main before the next code change; expected current HEAD at this update: 0fcf544b3c1e5154e76cbece0840adf8bd46613f.
- The optimized WebP and its original PNG have been packaged as farast-generated-visual-assets.zip for handoff, but are NOT committed to the repository and are NOT referenced by the website.
- The generated composite with the invented logo/text remains excluded.
- No new production website code or asset references were changed in PR #744. PR #743 is the only recent production visual-code change currently merged.
- The local site has not been deployed or visually tested from this session.

# Ace Crawl Enhancer — Roadmap

Canonical improvement plan, agreed 2026-07-07. Executing agents: work top-down within a
phase; phases 0–3 are the priority order, 4–6 can interleave once 1 is done. Every task must respect
the compatibility contract in [AGENTS.md](../AGENTS.md) (live consumer sites; additive-first).

**Decisions already made (don't re-litigate):**
- Schema architecture = **provider API + built-in adapters** (registry/filter any plugin can inject
  typed nodes into a single `@graph`, plus shipped auto-detect adapters for AceMedia plugins).
- AI = **multi-provider via factory** (port `ChatClientFactory`/`AnthropicClient` pattern from
  Adaptive-Customer-Engagement; keep OpenAI for DALL·E; gate WP7 `wp_ai_client()` per issue #1).
- Bot feeds: **all in scope** — llms.txt + AI-crawler controls, IndexNow, news/image/video sitemap +
  RSS enrichment, Abilities/MCP exposure.
- Refactor level = **structured, additive-first**: consolidate/split internals but keep every public
  filter, meta key, option shape and title convention stable. No big-bang v2.

**GitHub issues:** Phase 0 = #10, Phase 1 = #11, Phase 2 = #12, Phase 3 = #13, Phase 4 = #14,
Phase 5 = #1 + #7 (pre-existing), Phase 6 = #15.

## October 2026 call follow-ups

- Seasonal retention: [#32](https://github.com/AceMedia/Ace-Crawl-Enhancer/issues/32).
  A read-only calendar preview is available through
  `wp eval-file <plugin>/bin/seasonal-retention-preview.php YYYY-MM-DD > preview.csv`.
  It scans published posts in batches of 500, using the site's calendar/timezone and
  two months either side of the publication anniversary. Boundary days are included;
  month ends are clamped and 29 February uses 28 February in non-leap years.
  This is a full published-post inventory, not the older-post candidate cohort or a
  traffic assessment. It makes no report, spreadsheet, publication-date or indexing
  changes. In-season rows still need verified traffic coverage before any recommendation.
  Live integration, explicit overrides, scoped history and UI remain open; worker
  recovery in #30 is a prerequisite for trusting a replacement report.
  Run `php tests/seasonal-window-test.php` for the calendar boundary checks.
- Optional ChatGPT sign-in and eligible plan usage:
  [#33](https://github.com/AceMedia/Ace-Crawl-Enhancer/issues/33), alongside provider
  work in #14. Confirm the supported deployment/registration route and explicit
  inference consent before implementing the connection; identity alone is insufficient.

## Phase 0 — Hygiene & safety (small, independent tasks) — issue #10

- [x] Remove `test-background-optimization.php`, `test-homepage-sync.php`, `fix-slashes.php` from the
  plugin root. (2026-07-07)
- [x] Sync versions — all bumped to 1.0.6 (plugin header/constant, `package.json`, README badges).
- [x] Autoload audit: all admin-only bookkeeping options (`ace_seo_performance_monitoring`, db
  optimisation flags, sitemap notices, version/activation) now written with `autoload=false`, plus a
  `maybe_upgrade()` routine (on `admin_init`, keyed off `ace_seo_version`) that fixes the flags on
  existing installs via `wp_set_option_autoload_values()`. `ace_seo_options` and
  `ace_sitemap_powertools_options` stay autoloaded (front-end reads). Uninstall now also cleans
  `ace_seo_options` + the bookkeeping/sitemap options it previously missed.
- [x] AI endpoint cost control: shared `guard()` on all 14 AI AJAX endpoints — nonce + filterable
  capability (`ace_seo_ai_capability`, default `edit_posts`) + per-user hourly rate limit
  (`ace_seo_ai_rate_limit`, default 60/h, admins exempt, 0 disables).
- [x] Double-brand title fix — resolved differently from the original plan: upstream `5740dc8`
  cleared site/tagline unconditionally, which would have stripped the brand from the live sites'
  brand-free manual titles; refined to clear site/tagline **only when the title came from a
  template** (templates contain `{site_name}`). Manual `_ace_seo_title`/`_yoast_wpseo_title` keep
  the theme-append behaviour. No setting needed. Verified with a stub harness (manual/template/
  Yoast paths).
- [x] `.agents/`/`.codex/` empty dirs removed (AGENTS.md symlink covers it).

## Phase 1 — Schema engine: one graph, declare everything (the big one) — issue #11

Goal: a single `@graph` per page, every node `@id`-linked, nothing double-emitted, and a public API.

- [x] **Consolidated to one graph builder** (2026-07-07): new `AceSeoSchemaGraph`
  (`includes/frontend/class-ace-seo-schema-graph.php`) — provider registry, `@id` de-dupe/merge,
  per-node `@context` stripping, single printer. Investigation showed only ONE emitter was actually
  live; the schema class's LocalBusiness/enhancer/Product/FAQ were dead code and the performance
  footer emitter was never hooked (removed — it was a double-emit trap, not a live bug).
- [x] **BreadcrumbList JSON-LD** from `ACE_SEO_Breadcrumbs::get_items()` on every non-front page
  with a ≥2-item trail; referenced from the WebPage node; current page omits `item` per Google.
- [x] **Orphaned builders wired as providers**: LocalBusiness (front page, settings-gated), Product
  (`ace_seo_product_schema_enabled` — defaults off when WooCommerce core emits its own), FAQPage
  (opt-in via `_ace_seo_faq-schema` post meta or `ace_seo_faq_schema_enabled`; metabox toggle UI
  still to add). The dead `ace_seo_schema_article` enhancer (word count/reading time/section) is
  now actually applied.
- [x] **Provider API**: `ace_seo_register_schema_provider()` + `ace_seo_schema_graph` filter with
  context array; nodes merged by `@id` so providers can enrich core nodes. Documented with
  examples and well-known `@id` table in `docs/schema-api.md`.
- [x] **Type coverage** (partial): WebPage node on every page (`isPartOf`/`mainEntityOfPage`
  `@id`-linked), author Person as separate `@id` node, `ace_seo_article_schema_type` filter for
  NewsArticle/BlogPosting mapping (per-site/adapter wiring is Phase 2).
  - [x] VideoObject when singular content leads with a video block or YouTube/Vimeo/VideoPress/
    Dailymotion embed (first 5 top-level blocks; needs a featured image per Google's requirements).
  - [x] FAQ opt-in metabox toggle (`faq-schema` checkbox in the Advanced tab, saved via the
    standard meta-fields loop to `_ace_seo_faq-schema`).
- [x] **Verification harness**: `bin/schema-check.php <url>…` — lists blocks/types, warns on
  duplicates, per-node contexts and missing BreadcrumbList. Baselined against two live
  consumer sites still on the old code (which show exactly the issues fixed here).
  Verified new engine via stub harness: 28/28 assertions (single block, @id links, breadcrumbs,
  provider injection + merge, LocalBusiness gating, front-page shape).

## Phase 2 — Ecosystem detection & adapters — issue #12

Detection framework: `includes/integrations/class-ace-seo-integrations.php` — each adapter declares
`is_active()` + registers schema providers / sitemap tweaks. Replaces scattered `class_exists` checks.

- [x] **Framework** (2026-07-07): `includes/integrations/class-ace-seo-integrations.php` — adapters
  run once on `template_redirect` (after all plugins register, before `wp_head`).
- [x] **Ace-Community-Events**: IMPORTANT — the current ACE-CE (the events site's submodule
  copy, far ahead of the stale canonical checkout) already emits its OWN Event JSON-LD +
  BreadcrumbList via its `ACE_SEO` class, and already has IndexNow. The adapter therefore: removes
  its duplicate BreadcrumbList emitter (the graph now covers every page), keeps its Event emitter
  (it knows its recurrence/offers/performers meta best), and adds the missing types —
  `businesses` → LocalBusiness, `job_listings` → JobPosting (hiringOrganization = linked
  business or site publisher `@id`), `locations` → Place; address/geo mapped from its
  `address`/`geo_location` meta convention.
  - [x] Follow-up **done + LIVE** (2026-07-07): Ace-Community-Events `6afe3eb` registers its Event
    node into the graph via `ace_seo_register_schema_provider()` (standalone fallback kept for
    non-Ace-SEO sites). Deployed to the events consumer site; event pages verified
    serving ONE `@graph` (WebPage+BreadcrumbList+Organization+Event), down from 2 blocks + a stray
    Article. Also shipped ace-crawl `12f8ed7`: Article gets an `@id`, and an `ace_seo_emit_article`
    filter lets typed CPTs (events/businesses/jobs/locations) skip the redundant Article node.
- [x] **WooCommerce**: Product provider shipped in Phase 1 (`ace_seo_product_schema_enabled`,
  defaults off while Woo core emits its own); `ItemList` now emitted on ALL archives (incl.
  product-category) from the main query — new `ace-seo/archive-items` provider, capped at 10.
  - [ ] Active suppression of Woo core's per-listing Product blocks when Ace SEO owns product
    schema (needs testing on the WooCommerce consumer sites before flipping).
- [x] **NewsArticle mapping** (news sites): per-post-type settings key
  `schema.article_type_{post_type}` / `schema.article_type` feeding the
  `ace_seo_article_schema_type` filter — set `NewsArticle` in `ace_seo_options` on news sites. No
  settings UI yet (option/filter only).
  - [ ] `ace-tournament`/`ace-event-hub` → SportsEvent adapter: their plugin already emits its own
    JSON-LD; adapt when the news site bumps the submodule (or have them self-register via the API).
- [ ] **Ace-Image-Enhancer**: ensure og:image / ImageObject point at the optimised rendition
  (needs a look at how it rewrites attachment URLs first).
- [x] The `ace_event` sitemap exclusion was already option-driven + filterable
  (`ace_sitemap_powertools_excluded_sitemap_taxonomies`) — no change needed.
- [x] **SportsClub**: `local.business_type` in `ace_seo_options` is free-form and the
  front-page LocalBusiness provider emits whatever type is configured (harness-tested with
  SportsClub) — the sports club site can move its theme-injected schema to config once it takes 1.0.6.
  - [ ] Settings UI for the `local` section (currently config-only via options).

## Phase 3 — Bots, feeds & instant indexing — issue #13

- [ ] **llms.txt**: serve `/llms.txt` (and optional `/llms-full.txt`) — site summary from settings +
  key pages/CPT archives + recent posts, cached, filterable (`ace_seo_llms_txt_sections`).
- [ ] **AI crawler controls**: settings matrix for GPTBot, ClaudeBot, Claude-SearchBot, PerplexityBot,
  Google-Extended, CCBot, Bytespider → robots.txt allow/disallow lines (default: allow all; these
  sites WANT bot traffic). Ensure dynamic robots.txt emits `Sitemap:` lines everywhere.
- [ ] **IndexNow**: key generation + `/{key}.txt` endpoint, ping on publish/update/delete (queued,
  batched, respects noindex), log of recent pings in dashboard. Complements Site Kit/Google.
- [ ] **News sitemap hardening** (news sites): validate against Google News requirements (48h window,
  `<news:publication>`), per-post-type opt-in.
- [ ] **Image/video sitemap entries** on existing providers; **RSS enrichment**: full content +
  `media:content` + featured image in feeds for aggregators.

## Phase 4 — AI modernisation — issue #14

- [ ] Port the provider factory: `includes/ai/` with `ChatClientFactory`, `OpenAIClient`,
  `AnthropicClient` (raw `wp_remote_post`, no SDK — copy the working pattern from
  Adaptive-Customer-Engagement `includes/AI/`). Settings gain provider + Anthropic key fields;
  existing OpenAI keys keep working untouched. DALL·E stays OpenAI-only.
- [ ] Refresh model defaults (gpt-3.5-turbo chain is stale; use current small/cheap models per
  provider, filterable).
- [ ] WP7 gating per issue #1: prefer `wp_ai_client()` + Settings→Connectors when available
  (`function_exists` checks), fall back to the factory on WP6.

## Phase 5 — WP 7.0 & Site Kit — issues #1 + #7

- [ ] Issue #1 remaining items: Abilities API registration (`ace-crawl/analyze-content`,
  `generate-meta-title`, `generate-meta-description`, `check-image-alt-coverage`) — this is also the
  MCP exposure path via mcp-adapter (installed on two consumer sites); core Breadcrumbs block
  deprecation path; `wp_get_image_alttext()` in alt coverage; Interactivity `watch()` for editor
  scores; DataForms evaluation. All version-gated, WP6 unchanged.
- [ ] Issue #7: Site Kit indexing/sitemap surfaces — scope audit, read-only endpoints, clear
  unavailable-state messaging, no tokens to JS.

## Phase 6 — Structural refactor (ongoing, behind the features) — issue #15

- [ ] Single meta-description resolver (currently triplicated: frontend, schema, main class).
- [ ] Replace the full-page output-buffer OG regex de-dupe with ordered suppression at source
  (remove competing emitters before output instead of regexing the page).
- [ ] Split the god files as they're touched: main file → title engine / meta output / migration /
  options classes; sitemap powertools → provider/cache/routes modules. No public rename.
- [ ] Consider libsodium-encrypted storage for API keys in `ace_seo_options`.

## Per-task protocol for agents

1. Work on `main` in the canonical repo; commit/push only when asked. British English everywhere; no AI/co-author trailers.
2. `npm run build` if `src/` touched; verify with the phase-1 harness or curl head-checks on a local
   consumer mirror before declaring done.
3. Never close a GitHub issue for a partially-shipped or gated feature — comment status, keep open.
4. Tick the box here + note the commit hash when a task lands; keep issues #1/#7 in sync.

## Strict timing policy and Analytics coverage start — 7 October 2026 (#32)

1.0.63: `timing_policy` (`estimate` default | `strict`) in `ace_seo_retention_options` → `AceSeoRetentionReport::settings()['timing_policy']` → `Ace_SEO_Retention_Evidence::timing_hold( $relevance, $period, $strict )`. Strict holds any non-retained post whose relevance is not verified. `AceSeoRetentionReport::ga4_first_day()` (one cached GA4 request ordered by date) gives `signals['ga4_from']`; a window starting before it holds quiet posts with the coverage reason, and the dated-traffic provider marks such periods incomplete. The client site's property has data from mid-2025, so its 365-day window was covered; the volume of Dormant there was real low readership, which is why the strict policy exists as a choice rather than a default.

## Timing holds in the saved report — 7 October 2026 (#32)

1.0.62: `Ace_SEO_Retention_Evidence::timing_hold( $relevance, $period )` is pure and shared by the preview and the scorer. `phase_score()` works out the build's period (started minus `days`), reads `base_context()` for each post (editor fields, linked events, anniversary; no API calls) and, unless the post is retained, sets tier `unknown` / bucket `no-signal` with `reason` "Not ready to judge. …" and `hold` when the period did not contain the relevant dates. `phase_ga4()` records `ga4_capped`; a capped Analytics list makes absent pages `null` views. `do_action( 'ace_seo_retention_built', $p )` fires once a build completes; `AceSeoSheetsSchedule::after_build()` (setting `after_build`) starts a manual-type refresh so the sheet follows the report. The first live run of 1.0.59 showed a 365-day window judging posts whose relevant dates were simply unknown — those are not held (the period stands on its own); only known dates outside the period hold.

## Timing columns in exports — 7 October 2026 (#25, #32)

1.0.61: `AceSeoExport::timing_context()` → `Ace_SEO_Retention_Evidence_View::timing_columns()` appends *When it matters*, *Relevant window*, *Timing basis*, *Linked events*, *Assessed in season?* to every CSV/Sheets row. It reads `base_context()` (editor fields, linked `ace_event` terms, anniversary estimate) and never runs the evidence-context filter, so an export of 40k rows makes no Analytics or Search Console calls. Verdicts stay out of the sheet: the client decides. Checks in `tests/retention-event-provider-test.php`.

## Dated traffic and editorial timing — 7 October 2026 (#32)

1.0.60 adds `includes/class-ace-seo-retention-dated-traffic.php`, a built-in `ace_seo_retention_evidence_context` provider: for a finished period it fetches, once and cached, Analytics page views by path (`AceSeoRetentionReport::ga4_report( $start, $end )`), Search Console rows by URL (`AceSEOSearchConsole::pages_between()`), or the plugin's own tracking by post ID (`AceSeoViewTracker::views_between()` bounded by `coverage_start()`), and sets `metrics`, `metric_period` and `coverage` {complete, capped, sources, notes}. `Ace_SEO_Retention_Evidence_View::prepare()` swaps the exact-date figures into the row the rules judge; the saved row is never changed. Per-post Advanced-tab fields `_ace_seo_retention_timing` (evergreen|dates|auto), `_ace_seo_relevant_from`, `_ace_seo_relevant_to` feed `override`/`content_type`. `tests/retention-dated-traffic-test.php` covers unfinished periods, no source, both sources complete, measured zeros, capped Analytics, mid-period tracking, search-only, the 16-month Search Console limit, the editorial fields and provider override order. Still open on #32: a verified event-occurrence picker bound to Ace Teams & Events editions (today an editor types the dates), the three extra suggestions, and reporting the preview's verdicts beyond the preview/snapshot.

## Build worker recovery — 7 October 2026 (#30)

1.0.59 makes the retention build self-healing: heartbeat and consecutive-failure count in the progress record, a lease row (`ace_seo_retention_lock`, INSERT IGNORE + compare-and-swap) so a second worker stands down, a shutdown handler that treats a fatal mid-step as a failed attempt and queues the next tick, `worker_state()` (idle/queued/running/interrupted/error/done), `resume()`/`recover()` on an hourly `ace_seo_retention_watch` event, on `admin_init` and inside `run_weekly()`. `tests/retention-worker-recovery-test.php` covers the lost tick, a live lease, an expired lease, repeated failures, the fatal path, the admin action and `run_all()`. Still open from #30: a durable staged generation with an atomic last-complete result; today a resumed build keeps writing rows in place, which is what it did before.

## Retention evidence preview — 7 October 2026

Local implementation for #32 adds a counts-free bucket/suggestion reference table, explicit event-edition/coverage guards, separate expandable evidence timelines and a private resumable baseline/preview CSV runner. Read [RETENTION-EVIDENCE-PREVIEW.md](RETENTION-EVIDENCE-PREVIEW.md) for the provider contract and precise remaining work. The old report worker is not yet generation-safe (#30), and real dated traffic collection/occurrence controls remain prerequisites for live seasonal assessment. Curated automatic management (#34) is shown as planned, not implemented or enabled. No live assessments or Sheets were rewritten.

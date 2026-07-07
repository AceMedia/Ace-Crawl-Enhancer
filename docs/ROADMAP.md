# Ace Crawl Enhancer — Roadmap

Canonical improvement plan, agreed with Shane 2026-07-07. Executing agents: work top-down within a
phase; phases 0–3 are the priority order, 4–6 can interleave once 1 is done. Every task must respect
the compatibility contract in [CLAUDE.md](../CLAUDE.md) (6 live consumer sites; additive-first).

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
  duplicates, per-node contexts and missing BreadcrumbList. Baselined against live
  sheffieldparkour.org + uni-carts.com (old code shows exactly the issues fixed here).
  Verified new engine via stub harness: 28/28 assertions (single block, @id links, breadcrumbs,
  provider injection + merge, LocalBusiness gating, front-page shape).

## Phase 2 — Ecosystem detection & adapters — issue #12

Detection framework: `includes/integrations/class-ace-seo-integrations.php` — each adapter declares
`is_active()` + registers schema providers / sitemap tweaks. Replaces scattered `class_exists` checks.

- [ ] **Ace-Community-Events** (SheffEvents): `events` CPT → `Event` (uses `geo_location`, `address`,
  `ticket_url`, `price_from`, `location_id` meta from its v1.1 data model); `businesses` →
  `LocalBusiness`; `job_listings` → `JobPosting`; `locations` → `Place`. This is what makes
  sheff.events rank for events.
- [ ] **WooCommerce** (IEG, unicarts, william): wire Product schema (phase 1 task) + Offer price
  validity, `ItemList` on product category archives, suppress duplicate Woo-core schema if present.
- [ ] **ppnews plugins**: `ace-tournament` / `ace-event-hub` → `SportsEvent` (lift the existing
  JSON-LD pattern from `ppnews/assets/plugins/ace-tournament/tournament.php` into an adapter);
  posts in news categories → `NewsArticle`.
- [ ] **Ace-Image-Enhancer**: ensure og:image / ImageObject point at the optimised rendition.
- [ ] Replace the hardcoded `ace_event` sitemap exclusion with adapter-driven registration.
- [ ] **SportsClub/LocalBusiness settings**: SPKF currently injects SportsClub schema via theme
  functions.php — make the org type list cover it so config replaces theme code.

## Phase 3 — Bots, feeds & instant indexing — issue #13

- [ ] **llms.txt**: serve `/llms.txt` (and optional `/llms-full.txt`) — site summary from settings +
  key pages/CPT archives + recent posts, cached, filterable (`ace_seo_llms_txt_sections`).
- [ ] **AI crawler controls**: settings matrix for GPTBot, ClaudeBot, Claude-SearchBot, PerplexityBot,
  Google-Extended, CCBot, Bytespider → robots.txt allow/disallow lines (default: allow all; these
  sites WANT bot traffic). Ensure dynamic robots.txt emits `Sitemap:` lines everywhere.
- [ ] **IndexNow**: key generation + `/{key}.txt` endpoint, ping on publish/update/delete (queued,
  batched, respects noindex), log of recent pings in dashboard. Complements Site Kit/Google.
- [ ] **News sitemap hardening** (ppnews): validate against Google News requirements (48h window,
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
  MCP exposure path via mcp-adapter (installed on IEG + SheffEvents); core Breadcrumbs block
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

1. Work on `main` in the canonical repo (`/var/www/html/plugins/ace-crawl-enhancer`); commit/push
   only when Shane asks. British English everywhere; no AI/co-author trailers.
2. `npm run build` if `src/` touched; verify with the phase-1 harness or curl head-checks on a local
   consumer mirror before declaring done.
3. Never close a GitHub issue for a partially-shipped or gated feature — comment status, keep open.
4. Tick the box here + note the commit hash when a task lands; keep issues #1/#7 in sync.

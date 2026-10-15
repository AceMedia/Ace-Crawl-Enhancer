# Retention redirects

Available in Ace Crawl Enhancer 1.0.91 under **Ace SEO → Redirects → Auto** on any site. No development flag is needed. Installing the release does not enable processing or create redirects.

## First test on a live site

1. Leave the queue paused. Check the site's connected Analytics/Search Console or own tracking and rebuild the retention report if it predates this release. Assessments explain missing, partial or stale data; a staging site's lack of traffic is irrelevant to the live site's measurements.
2. Choose a source and a suitable replacement post/page. Search by title or enter its URL. Confirm that it preserves the subject, intent and historical/event information; record the reason and completed observation dates.
3. If a link provider is absent, review both external backlinks and internal links yourself. Only tick the zero-link confirmation if both checks support it, and record where/how you checked. This does not override known positive links and expires within 24 hours.
4. Save the assessment and inspect every hold. This writes only a private assessment. Unknown values do not become zero. Search-engine discouragement blocks application but not review. Existing redirects, keeps, recent readership, uncertain timing or unsafe destinations also hold application.
5. When ready to test an actual redirect, save a scope containing only the chosen source, a daily limit of **1**, and enable the queue. Then create/recheck a fresh assessment and approve the pair. Configuration changes invalidate previous approvals, so configure before approving. The worker runs at most one approved pair per invocation; use **Run one guarded check** for a controlled first run.
6. Confirm the source returns the intended 301 and `no-store`, and inspect the audit record and Existing redirects. Pause the queue after the test. **Undo if unchanged** restores the original empty redirect only if no intervening edits occurred. External crawler/browser behaviour may take time to reflect an undo.

The source remains published; no Trash, deletion or redirect expiry is scheduled. A later report or seasonal change does not remove an applied redirect. Pausing prevents new applications; it does not remove existing redirects.

## Safeguards and portability

Processing is opt-in per site. Saved controls include the canonical home URL and WordPress blog ID, so a database copied to another site cannot reuse active scope. The site needs transactional InnoDB WordPress/queue tables, normal WP-Cron delivery, and permission to create the two plugin queue/audit tables. An administrator must still have source/target edit permission when a queued action runs.

Every application rechecks the evidence, snapshot integrity, permissions, source and destination. An expiring DB lease serialises workers; transaction locks protect settings/articles/permissions. Redirect and audit writes commit together. Cache invalidation is retried if completion cannot be recorded. Keep active object-cache drop-ins in sync with the installed cache plugin, and check web/CLI invalidation when releasing to a consumer.

An optional provider can return `backlinks` and `internal` entries from `ace_seo_retention_link_evidence`, each with `count`, `source`, `complete`, `capped` and Unix `checked_at`. Complete, uncapped, zero-count evidence must be no more than 24 hours old. Provider entries take precedence over manual attestations. The review workflow remains usable without a provider through the explicit administrator check.

This release handles approved pairs. It does not discover replacements or sweep categories automatically. Existing archive redirects remain independently configured in their archive editors.
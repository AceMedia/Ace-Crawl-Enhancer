# Event-aware retention: local preview

The dashboard now has a **Preview event timing and suggestion overlaps** link. This is a read-only review surface: it does not replace saved assessments, update Google Sheets, change publication dates, redirect anything or alter search visibility.

The reference table explains which suggestions fit each readership group. It deliberately has **no counts or drilldowns**. Its shared visual key distinguishes useful content, ordinary review, uncertain timing/evidence and combinations that need no change. Strong orange is reserved for a verified urgent problem; low traffic alone never earns that status. Evidence confidence is separate from attention.

Blue badges identify existing administrator-triggered 301/noindex tools, not enabled or applied automation. Purple badges describe planned curated automatic management, which is not implemented. The separate evidence section previews up to 100 saved posts per page and provides expandable event/coverage/snapshot date bars. Missing coverage has no bar, rather than an invented zero.

## Rules implemented in the preview

- Explicit editorial dates take precedence, then one verified event occurrence with a stable occurrence identifier. Evergreen content uses its chosen observation period. A publication-anniversary window remains a labelled estimate.
- A generic `ace_seo_retention_evidence_context` filter allows integrations to supply exact occurrence, coverage and metric-period provenance. The optional Ace Teams & Events reader discovers linked event terms and modern or legacy date fields. Those mutable term dates **do not prove which edition an old article covered**, so they are surfaced as unverified until an editor or a provider binds an occurrence.
- Several verified events remain ambiguous until explicitly resolved; a mutable next-event date cannot silently replace an old edition.
- Adverse suggestions require a finished relevant period, explicit complete uncapped coverage, and traffic totals for exactly the chosen dates. Missing visitor data is unknown, not zero. Positive evidence can still justify keeping or refreshing an article while a negative judgement waits.
- Combining articles additionally needs verified editorial overlap. Neither a low click count nor an AI match chooses a redirect target.
- The old scorer now returns `unknown` / “Not ready to judge” when visitor data is absent and there are no positive clicks. It does not suggest noindex from missing visitor data. Existing saved rows are not rewritten by this change.

### Provider contract

The context filter receives the context, post ID and saved report row. Providers can fill these fields without changing the saved row:

```php
array(
    'period' => array( 'start' => '2026-01-01', 'end' => '2026-04-30' ),
    'as_of' => '2026-05-01',
    'metric_period' => array( 'start' => '2026-01-01', 'end' => '2026-04-30' ),
    'coverage' => array(
        'start' => '2026-01-01', 'end' => '2026-04-30',
        'complete' => true, 'capped' => false,
    ),
    'events' => array( array(
        'start' => '2026-03-10', 'end' => '2026-03-13',
        'occurrence_id' => 'provider-stable-edition-id',
        'verified' => true,
    ) ),
);
```

Alternatively provide `content_type = evergreen` or explicit `override` start/end dates. A provider must attest completeness from real source collection and supply totals for that exact period; it must never mark an existing undated aggregate complete merely to make the preview ready. Batch/cache provider lookups; no per-post external API requests.

## Repeatable snapshots and CSV

The standalone WP-CLI runner captures a frozen post-ID scope and its settings/date/rule-version provenance. Each record keeps the **unchanged saved assessment** beside its new preview and evidence context. It uses private files outside the served tree; completion is published only after every frozen ID is captured. Completed snapshots and existing CSVs cannot be overwritten.

```bash
wp eval-file assets/plugins/Ace-Crawl-Enhancer/bin/retention-evidence-snapshot.php \
  /private/new-run-directory 2026-01-01 2026-04-30 2026-10-07
```

- Repeat with exactly the same directory and arguments to resume interrupted batches or confirm a completed run. A file lock prevents two owners. Any uncheckpointed tail is discarded before resuming, avoiding duplicate rows.
- Use a new directory to restart or assess another period/rule version. Previous snapshots remain intact.
- `records.jsonl` preserves baseline, preview and context; `manifest.json` records count, hash and provenance; `assessment-comparison.csv` is a readable side-by-side export with formula-like cell text neutralised.
- A failed run never replaces a complete snapshot or the live report. The runner does not modify the current main Sheets tab or remove out-of-scope report metadata.
- This captures source rows batch by batch and records their capture times. It is **not** a transaction-wide database backup. Take the consistent live backup separately before any future report rewrite; that backup was captured for PP News on 7 October.

## Still required before live seasonal assessment

1. Repair the legacy report worker (#30) with a database lease, durable generation staging, recovery and an atomic last-complete result. The new snapshot runner does not retrofit those semantics into the legacy worker.
2. Retrieve traffic by explicit dates with honest per-source coverage/capping metadata. Existing saved report rows do not establish that provenance and therefore wait for review in this preview.
3. Add editorial controls for evergreen/seasonal/event classification and verified occurrence overrides, plus comparable-season selection. Current generic provider support is not an editor-facing occurrence picker.
4. Review the reference wording and wire the remaining proposed suggestions (post-event reassessment, historical usefulness and internal-link improvement) to actual evidence.
5. Implement optional curated management separately (#34): review only, approve once, or automatic action under explicitly configured action/scope rules. Require exclusions/manual keep, complete relevant snapshots, a verified relevant indexable canonical 200 replacement, no self redirects/chains/cycles, a dry run, capped batches, ledger/reason/rollback and stops on errors. A 301 is a persistent decision, never a seasonal on/off switch. No automatic management is enabled here.

## Verification

`tests/retention-evidence-test.php`, `tests/retention-event-provider-test.php`, `tests/retention-snapshot-test.php` and the existing seasonal-window checks exercise coverage gaps, capped data, period mismatches, unknown visitors, multi-event ambiguity, reference semantics, interrupted writes, concurrent ownership, immutable completion and export integrity. Existing settings/export tests remain applicable. The UI is server-rendered with native accessible tables/details and a contained horizontal scroll on small screens.

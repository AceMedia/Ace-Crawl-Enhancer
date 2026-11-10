# Retention timeline on the dashboard (plan)

Status: planned. Not built. Relates to [#32](https://github.com/AceMedia/Ace-Crawl-Enhancer/issues/32)
(seasonal retention) and [#34](https://github.com/AceMedia/Ace-Crawl-Enhancer/issues/34) (curated
automatic management with a dry-run action queue).

A visual on the Retention dashboard that shows where older posts go: the branches a post can take,
real posts from the site riding those branches, the difference accepting the suggested timing rules
would make, and an optional twelve-month playback of what would be judged and what would only ever be
suggested. It was prototyped as a stand-alone page first; this document is how it moves into wp-admin.

## What it has to answer

1. Where is every older post today, and why? (Still read, worth an update, held, no recorded readers.)
2. What changes if we accept the suggested timing rules, and what does not? (Nothing is redirected,
   hidden or removed automatically, either way.)
3. Over the next year, when does each held post become judgeable, how many posts age into the check,
   which seasons open and close, and how many possible 301s would reach an editor?

## Where it sits

- A new section, **Where older posts go**, between `render_summary()` and
  `Ace_SEO_Retention_Insights::render()` in `AceSeoRetentionReport::render()`. The summary cards stay
  the first thing on the page; the groups-by-options cross-reference and the seasons view follow it.
- Loaded on request, like the post-by-post rows: a static comparison table renders with the page, and
  the diagram loads its data over AJAX when the section is opened or scrolled into view.
- Inside the section, three controls:
  - **View:** How it flows (the branch diagram) | On the calendar (sections on a date axis).
  - **Scenario:** Today | Recommended rules accepted | Compare (ghosts where held posts sit today,
    trails to where they would go).
  - **Next 12 months:** play, pause, scrub month by month, 1x or 2x, close.
- Section chips filter both views to one category or tag that has a suggested rule.

## Data

### Today
Already stored: `progress()['tiers']`, `['counts']`, `['ranks']`, the timing suggestions, and per-post
meta (`_ace_seo_ret_tier`, `_ace_seo_ret_rank`, `_ace_seo_retention` row with bucket and reason).

### Recommended and the next twelve months: `Ace_SEO_Retention_Projection`
A new class, `includes/class-ace-seo-retention-projection.php`.

- `compute()` takes every held post (tier `unknown`) and, for months 0 to 12 from today, works out
  the first month in which `Ace_SEO_Retention_Evidence::timing_hold()` returns `''`, using:
  - the period the weekly check would use that month (`as_of - days` to `as_of - 1`);
  - `AceSeoRetentionActions::merge_timing_suggestions()` with every suggestion accepted;
  - the current `timing_policy`.
  It does the same with today's saved rules, so "as things stand" is computed, not assumed.
- To pass a rule set without touching the option, `timing_rule_for( $post_id, ?array $rules = null )`
  and `Ace_SEO_Retention_Evidence_View::base_context( ..., ?array $rules = null )` gain an optional
  argument. Production callers are unchanged. The prototype filtered the option in memory; the plugin
  should not.
- A post released from its hold is projected on today's evidence: no recorded readers means it joins
  "No recorded readers" (short or not by `thin_words`). The projection shows timing, not traffic, and
  the screen says so.
- **Possible 301s**: `Ace_SEO_Retention_Replacements::find( $post, $rule_key )`, a newer published post
  in the rule's term with at least two shared title words (stop words and years removed), a Jaccard
  score of at least 0.3 and published at least 180 days later. These are candidates for an editor,
  never decisions. The same matcher feeds the #34 queue so the two cannot disagree.
- **Ageing in**: posts that pass `older_than_years` in each month (one `COUNT(*)` per month).
- **Seasons**: opening and closing per month, from the suggested and saved season rules.
- **Examples**: up to six real posts per month and three per branch, with their replacement if any.

### Cost and when it runs
On an archive with about 3,000 held posts the full projection takes around two and a half minutes of
CLI time, so it cannot run on a page view.

- Run it after a build: on `ace_seo_retention_built`, queue a single `ace_seo_retention_project`
  event that uses the same lease and heartbeat pattern as the build worker (#30), batching 250 posts a
  tick and saving its cursor.
- Store the result in `ace_seo_retention_projection` (not autoloaded) with `computed`, the build stamp
  and a hash of the rule set. When the saved rules or the suggestions change, mark it stale and queue
  a recompute; the screen shows the last result with "updating" rather than nothing.
- `wp ace-crawl retention projection [--format=json]` prints it, for checks and for the Sheet.
- `wp_ajax_ace_seo_retention_timeline` (nonce `ace_seo_retention_live`) returns the JSON the screen
  needs: today's figures, the projection, the diagram's branch counts and the examples.

## Rendering in wp-admin

- Vanilla JS and SVG in `assets/js/retention-timeline.js`, no build step, like the rest of the
  retention screens. The diagram geometry (branch paths, stops, label positions) ships as a JSON
  constant so the layout and the data stay separate.
- The comparison table is real server-rendered markup (`<table class="widefat">`): it works without
  JavaScript and for screen readers, and the bars are progressive enhancement.
- Contrast for wp-admin instead of the prototype's dark page:
  - White and `#f6f7f7` surfaces, text `#1d2327`, muted `#50575e`, rules `#dcdcde`. No glows.
  - Branch colours from the WordPress palette at 3:1 or better against white for lines and 4.5:1 for
    text: still read `#00a32a` (text `#007017`), worth an update `#dba617` (text `#996800`), held
    `#7e3bd0`, no recorded readers `#d63638` (text `#b32d2e`), planned `#646970`, the main line
    `var(--wp-admin-theme-color, #2271b1)`.
  - Same line language as the prototype: solid runs automatically, dashed is done by hand, dotted is
    planned. The legend says so in words.
  - Controls use core classes (`.button`, `.button-primary`, `.button-group`), focus rings follow the
    admin colour scheme, and the range input keeps native styling.
- Width: inside the admin content area, not full-bleed. Below 960px the diagram scrolls sideways in
  its own frame and the page never does. The right-hand notes aside already collapses at that width.
- Hover and focus show the same data as the prototype (counts per branch and stop, a post's dates,
  group, views, clicks and where it would go). Nothing is hover-only: clicking pins a post or month to
  the panel under the diagram.
- `prefers-reduced-motion`: the player steps month by month without moving particles.

## Guardrails on the screen

- "Recommended" means accepting timing rules. It never switches on redirects, noindex or removals.
- The comparison always includes a row for "Changed automatically: redirects, noindex, removals",
  which reads 0 in both columns.
- Possible 301s link to the #34 dry-run queue once it exists; until then they link to the post rows
  filtered to those posts. No one-click redirect from this screen.
- **Accept the suggested rules** opens the existing timing rules dialog (`render_modal()`), so
  accepting stays a reviewed choice per section rather than a single "accept all".
- On a site set to assess only (no reader features, no redirects), the screen says that the
  automatic branches are switched off there.

## Checks

- `tests/retention-projection-test.php`: a season straddling today, a season wholly inside the
  period, a finished one-off event, an evergreen rule, no rule under strict and estimate policies, a
  post with editor dates, and the month a post is first judgeable.
- `tests/retention-replacements-test.php`: stop words, years, the 180-day gap, ties, a term with no
  newer posts.
- A manual pass on a large archive comparing the CLI output with the screen's figures.

## Rollout

1. Projection class, matcher, CLI and tests.
2. The comparison table on the dashboard (server-rendered, no diagram yet).
3. The diagram, calendar and player.
4. #34 queue wiring for possible 301s.

Each step goes to a staging site that assesses only before it reaches a production site.

## Open questions

- The batch cap for the #34 queue, and whether the player should show approved batches as an
  illustration.
- Whether reader-feature changes (older-article notice, lighter page, keep reading card) belong in
  the Recommended scenario or stay as their own settings.
- Whether the calendar view earns its place in wp-admin, or the flow view and the month log are enough.

# Ace Crawl Enhancer 1.0.94 package checks

I checked the final archive on 30 October 2026, including the REST and editor permission changes in `9320b66`.

- Archive: `ace-crawl-enhancer-1.0.94.zip`
- SHA-256: `fac06f035134516b8b3c639bd15812fa688184e81511891de2069a269ebf3f28`
- Minimum-version test: WordPress **6.0.18**, PHP **7.4.33**, using an isolated SQLite-backed WordPress Playground instance.

I compared every extracted file with its archive entry before mounting the extracted plugin. The Blueprint explicitly selected WordPress 6.0 and PHP 7.4, and the PHP check asserted both runtime versions. This tested the declared minimum release family, rather than assuming command-line version arguments had taken effect.

Activation completed without an error or unexpected output. The `wp_ace_seo_analytics` table existed afterwards, repeat activation succeeded, and the HTTP interception counter remained **zero**. The CLI completed with exit code 0. The saved JSON result records the archive hash, actual runtime versions, activation result, request count and table list.

Local evidence from this run:

- `/tmp/ace-crawl-rollout/playground-release-minimum.json`
- `/tmp/ace-crawl-rollout/playground-release-minimum.log`
- `/tmp/ace-crawl-rollout/playground-final-package-result-7.4.json`

This establishes isolated installation compatibility for this exact archive. It does not establish MySQL behaviour, paid AI-provider access, every optional integration, or deployment to any website. The wider review and compatibility limits are recorded in `wordpress-org-20261030.md`. WordPress.org submission and all live deployment claims remain separate from these package checks.

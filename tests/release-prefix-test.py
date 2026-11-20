#!/usr/bin/env python3
"""Check the actual upload archive, including namespace and packaging regressions."""
import re
import sys
from pathlib import PurePosixPath
from zipfile import ZipFile

archive = sys.argv[1] if len(sys.argv) > 1 else None
if not archive:
    raise SystemExit('Usage: python3 tests/release-prefix-test.py dist/ace-crawl-enhancer-VERSION.zip')
errors = []
with ZipFile(archive) as release:
    entries = {str(PurePosixPath(name).relative_to('ace-crawl-enhancer')): release.read(name) for name in release.namelist()}
    for name, content in entries.items():
        if name.startswith(('compat/', 'tests/', 'docs/')):
            errors.append(f'{name}: source-only file in release')
        if not name.endswith('.php'):
            continue
        text = content.decode()
        if 'acecrawl-source-only' in text or 'compat/legacy-names.php' in text or 'AceCrawlLegacyNames' in text:
            errors.append(f'{name}: source-only compatibility code in release')
        for match in re.finditer(r'''\b(?:class|interface|trait)\s+((?:ACE_|AceSeo|AceSEO)[A-Za-z0-9_]*)''', text):
            errors.append(f'{name}: old class declaration {match[1]}')
        for match in re.finditer(r'''\bfunction\s+(ace_[A-Za-z0-9_]+)\s*\(''', text):
            errors.append(f'{name}: old function declaration {match[1]}')
        for match in re.finditer(r'''\b(?:define|get_option|add_option|update_option|delete_option|get_site_option|add_site_option|update_site_option|register_setting|add_settings_section|add_settings_field)\s*\(\s*['"]((?:ace_|ACE_)[^'"]*)['"]''', text):
            errors.append(f'{name}: non-distinct registry name {match[1]}')
        for match in re.finditer(r'''\b(?:wp_(?:enqueue|dequeue|deregister|register|localize|add_inline)_(?:script|style)|wp_(?:script|style)_is|(?:AceCrawlAssets|\\AceCrawlAssets)::(?:script|style|inline_script|inline_style))\s*\(\s*['"](ace-[^'"]+)['"]''', text):
            errors.append(f'{name}: old asset handle {match[1]}')
        for match in re.finditer(r'''\b(?:wp_create_nonce|wp_verify_nonce|wp_nonce_field|check_ajax_referer|check_admin_referer)\s*\([^;\n]*?['"](ace_(?:seo|sitemap)_[^'"]+)['"]''', text):
            errors.append(f'{name}: old nonce identifier {match[1]}')
        for match in re.finditer(r'''['"]((?:assets|includes)/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\.(?:php|js|css))['"]''', text):
            if match[1] not in entries:
                errors.append(f'{name}: missing packaged asset {match[1]}')
        for legacy in ('ace_sitemap', 'ace_sitemap_meta', 'ace_sitemap_locks', 'ace_sitemap_stats'):
            if re.search(r'''['"]''' + legacy + r'''['"]''', text):
                errors.append(f'{name}: old cache group {legacy}')
    # These are persisted data contracts, intentionally distinct from disposable registry/cache names.
    runtime = entries['includes/plugin-runtime.php'].decode()
    if not re.search(r'''define\(\s*['"]ACECRAWL_META_PREFIX['"]\s*,\s*['"]_ace_seo_['"]''', runtime):
        errors.append('Stored SEO metadata prefix changed')
    if "'ace_retention_plan'" not in entries['includes/class-ace-seo-retention-prototype.php'].decode():
        errors.append('Stored retention plan post type changed')
    if 'ACE_SEO_Performance' in runtime:
        errors.append('Frontend cached metadata still calls the old class')
if errors:
    raise SystemExit('\n'.join(errors))
print(f'PASS release namespace, nonce, asset reference, compatibility exclusion and persisted data contracts ({len(entries)} files).')

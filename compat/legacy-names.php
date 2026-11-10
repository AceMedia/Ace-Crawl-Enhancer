<?php
/**
 * Legacy names, for installs that ran Ace Crawl Enhancer before its names took the acecrawl prefix.
 *
 * This file lives in the source repository only; the WordPress.org package does not include it, and
 * the plugin loads it only when it is present. It lets themes and other plugins written against the
 * old names keep working while they move across:
 *
 * - old function, class and constant names point at the new ones;
 * - code hooked to an old filter or action still runs when the new one fires, and firing an old
 *   hook the plugin listens to still reaches it;
 * - stored options and scheduled events move to their new names once per site;
 * - constants set in wp-config.php under the old names still apply.
 *
 * Remove it once nothing uses the old names.
 */
defined( 'ABSPATH' ) || exit;

final class AceCrawlLegacyNames {
	const MIGRATED = 'acecrawl_legacy_migrated';
	const VERSION  = 1;

	/** Old function => new function. */
	const FUNCS = array(
		'ace_seo_disable_robots_file' => 'acecrawl_disable_robots_file',
		'ace_seo_discourage_directives' => 'acecrawl_discourage_directives',
		'ace_seo_discourage_mode' => 'acecrawl_discourage_mode',
		'ace_seo_discouraged_admin_notice' => 'acecrawl_discouraged_admin_notice',
		'ace_seo_dismiss_robots_file_info' => 'acecrawl_dismiss_robots_file_info',
		'ace_seo_display_name' => 'acecrawl_display_name',
		'ace_seo_filter_robots_txt' => 'acecrawl_filter_robots_txt',
		'ace_seo_force_noindex_robots' => 'acecrawl_force_noindex_robots',
		'ace_seo_register_schema_provider' => 'acecrawl_register_schema_provider',
		'ace_seo_robots_file_notice' => 'acecrawl_robots_file_notice',
		'ace_seo_robots_file_result_notice' => 'acecrawl_robots_file_result_notice',
		'ace_seo_safe_placeholder' => 'acecrawl_safe_placeholder',
		'ace_seo_send_noindex_header' => 'acecrawl_send_noindex_header',
		'ace_seo_shadowing_robots_file' => 'acecrawl_shadowing_robots_file',
		'ace_seo_site_is_discouraged' => 'acecrawl_site_is_discouraged',
		'ace_sitemap_powertools_add_rewrite_rules' => 'acecrawl_sitemap_add_rewrite_rules',
		'ace_sitemap_powertools_admin_notices' => 'acecrawl_sitemap_admin_notices',
		'ace_sitemap_powertools_ajax_purge_cache' => 'acecrawl_sitemap_ajax_purge_cache',
		'ace_sitemap_powertools_build_custom_url' => 'acecrawl_sitemap_build_custom_url',
		'ace_sitemap_powertools_build_posts_sitemap_url_list' => 'acecrawl_sitemap_build_posts_sitemap_url_list',
		'ace_sitemap_powertools_bump_cache_version' => 'acecrawl_sitemap_bump_cache_version',
		'ace_sitemap_powertools_bump_cache_version_debounced' => 'acecrawl_sitemap_bump_cache_version_debounced',
		'ace_sitemap_powertools_cache_acquire_lock' => 'acecrawl_sitemap_cache_acquire_lock',
		'ace_sitemap_powertools_cache_enabled' => 'acecrawl_sitemap_cache_enabled',
		'ace_sitemap_powertools_cache_get' => 'acecrawl_sitemap_cache_get',
		'ace_sitemap_powertools_cache_get_stale' => 'acecrawl_sitemap_cache_get_stale',
		'ace_sitemap_powertools_cache_lock_key' => 'acecrawl_sitemap_cache_lock_key',
		'ace_sitemap_powertools_cache_option_key' => 'acecrawl_sitemap_cache_option_key',
		'ace_sitemap_powertools_cache_release_lock' => 'acecrawl_sitemap_cache_release_lock',
		'ace_sitemap_powertools_cache_remember' => 'acecrawl_sitemap_cache_remember',
		'ace_sitemap_powertools_cache_set' => 'acecrawl_sitemap_cache_set',
		'ace_sitemap_powertools_can_use_root_level_tag_slug' => 'acecrawl_sitemap_can_use_root_level_tag_slug',
		'ace_sitemap_powertools_clean_urls_description' => 'acecrawl_sitemap_clean_urls_description',
		'ace_sitemap_powertools_cli_check' => 'acecrawl_sitemap_cli_check',
		'ace_sitemap_powertools_custom_loc_for_entry' => 'acecrawl_sitemap_custom_loc_for_entry',
		'ace_sitemap_powertools_custom_routes' => 'acecrawl_sitemap_custom_routes',
		'ace_sitemap_powertools_default_options' => 'acecrawl_sitemap_default_options',
		'ace_sitemap_powertools_detected_custom_post_types' => 'acecrawl_sitemap_detected_custom_post_types',
		'ace_sitemap_powertools_detected_custom_providers' => 'acecrawl_sitemap_detected_custom_providers',
		'ace_sitemap_powertools_detected_custom_taxonomies' => 'acecrawl_sitemap_detected_custom_taxonomies',
		'ace_sitemap_powertools_detected_sitemap_taxonomies' => 'acecrawl_sitemap_detected_sitemap_taxonomies',
		'ace_sitemap_powertools_disable_canonical_redirects' => 'acecrawl_sitemap_disable_canonical_redirects',
		'ace_sitemap_powertools_disable_excluded_providers' => 'acecrawl_sitemap_disable_excluded_providers',
		'ace_sitemap_powertools_disable_users_provider' => 'acecrawl_sitemap_disable_users_provider',
		'ace_sitemap_powertools_effective_post_max_urls' => 'acecrawl_sitemap_effective_post_max_urls',
		'ace_sitemap_powertools_excluded_sitemap_post_types' => 'acecrawl_sitemap_excluded_sitemap_post_types',
		'ace_sitemap_powertools_excluded_sitemap_providers' => 'acecrawl_sitemap_excluded_sitemap_providers',
		'ace_sitemap_powertools_excluded_sitemap_taxonomies' => 'acecrawl_sitemap_excluded_sitemap_taxonomies',
		'ace_sitemap_powertools_extract_lastmods' => 'acecrawl_sitemap_extract_lastmods',
		'ace_sitemap_powertools_fetch_xml' => 'acecrawl_sitemap_fetch_xml',
		'ace_sitemap_powertools_field_checkbox' => 'acecrawl_sitemap_field_checkbox',
		'ace_sitemap_powertools_field_detected_sitemaps' => 'acecrawl_sitemap_field_detected_sitemaps',
		'ace_sitemap_powertools_field_number' => 'acecrawl_sitemap_field_number',
		'ace_sitemap_powertools_filter_redis_cache_exclusions' => 'acecrawl_sitemap_filter_redis_cache_exclusions',
		'ace_sitemap_powertools_filter_redis_sitemap_prime_delay' => 'acecrawl_sitemap_filter_redis_sitemap_prime_delay',
		'ace_sitemap_powertools_fix_stylesheet_namespaces' => 'acecrawl_sitemap_fix_stylesheet_namespaces',
		'ace_sitemap_powertools_gc_option_cache' => 'acecrawl_sitemap_gc_option_cache',
		'ace_sitemap_gen_artifact_required_seq' => 'acecrawl_sitemap_gen_artifact_required_seq',
		'ace_sitemap_gen_build' => 'acecrawl_sitemap_gen_build',
		'ace_sitemap_gen_builder_for' => 'acecrawl_sitemap_gen_builder_for',
		'ace_sitemap_gen_clear_failure' => 'acecrawl_sitemap_gen_clear_failure',
		'ace_sitemap_gen_dir' => 'acecrawl_sitemap_gen_dir',
		'ace_sitemap_gen_enabled' => 'acecrawl_sitemap_gen_enabled',
		'ace_sitemap_gen_file' => 'acecrawl_sitemap_gen_file',
		'ace_sitemap_gen_filter_redis_prime_urls' => 'acecrawl_sitemap_gen_filter_redis_prime_urls',
		'ace_sitemap_gen_filter_withheld' => 'acecrawl_sitemap_gen_filter_withheld',
		'ace_sitemap_gen_get' => 'acecrawl_sitemap_gen_get',
		'ace_sitemap_gen_handle_admin_action' => 'acecrawl_sitemap_gen_handle_admin_action',
		'ace_sitemap_gen_index_key' => 'acecrawl_sitemap_gen_index_key',
		'ace_sitemap_gen_is_building' => 'acecrawl_sitemap_gen_is_building',
		'ace_sitemap_gen_is_stale' => 'acecrawl_sitemap_gen_is_stale',
		'ace_sitemap_gen_is_valid_list' => 'acecrawl_sitemap_gen_is_valid_list',
		'ace_sitemap_gen_keyed_scopes' => 'acecrawl_sitemap_gen_keyed_scopes',
		'ace_sitemap_gen_lock' => 'acecrawl_sitemap_gen_lock',
		'ace_sitemap_gen_mark_dirty' => 'acecrawl_sitemap_gen_mark_dirty',
		'ace_sitemap_gen_mark_post_dirty' => 'acecrawl_sitemap_gen_mark_post_dirty',
		'ace_sitemap_gen_meta_get' => 'acecrawl_sitemap_gen_meta_get',
		'ace_sitemap_gen_meta_update' => 'acecrawl_sitemap_gen_meta_update',
		'ace_sitemap_gen_normalise_loc' => 'acecrawl_sitemap_gen_normalise_loc',
		'ace_sitemap_gen_note' => 'acecrawl_sitemap_gen_note',
		'ace_sitemap_gen_on_before_delete' => 'acecrawl_sitemap_gen_on_before_delete',
		'ace_sitemap_gen_on_noindex_meta' => 'acecrawl_sitemap_gen_on_noindex_meta',
		'ace_sitemap_gen_on_post_change' => 'acecrawl_sitemap_gen_on_post_change',
		'ace_sitemap_gen_on_post_save' => 'acecrawl_sitemap_gen_on_post_save',
		'ace_sitemap_gen_on_post_transition' => 'acecrawl_sitemap_gen_on_post_transition',
		'ace_sitemap_gen_on_pre_delete_term' => 'acecrawl_sitemap_gen_on_pre_delete_term',
		'ace_sitemap_gen_on_term_change' => 'acecrawl_sitemap_gen_on_term_change',
		'ace_sitemap_gen_on_transition' => 'acecrawl_sitemap_gen_on_transition',
		'ace_sitemap_gen_on_user_change' => 'acecrawl_sitemap_gen_on_user_change',
		'ace_sitemap_gen_public_urls' => 'acecrawl_sitemap_gen_public_urls',
		'ace_sitemap_gen_published_permalink' => 'acecrawl_sitemap_gen_published_permalink',
		'ace_sitemap_gen_purge_downstream' => 'acecrawl_sitemap_gen_purge_downstream',
		'ace_sitemap_gen_read' => 'acecrawl_sitemap_gen_read',
		'ace_sitemap_gen_read_file' => 'acecrawl_sitemap_gen_read_file',
		'ace_sitemap_gen_read_header' => 'acecrawl_sitemap_gen_read_header',
		'ace_sitemap_gen_record_failure' => 'acecrawl_sitemap_gen_record_failure',
		'ace_sitemap_gen_release' => 'acecrawl_sitemap_gen_release',
		'ace_sitemap_gen_render_status_panel' => 'acecrawl_sitemap_gen_render_status_panel',
		'ace_sitemap_gen_required_seq' => 'acecrawl_sitemap_gen_required_seq',
		'ace_sitemap_gen_retry_failures' => 'acecrawl_sitemap_gen_retry_failures',
		'ace_sitemap_gen_run' => 'acecrawl_sitemap_gen_run',
		'ace_sitemap_gen_scan' => 'acecrawl_sitemap_gen_scan',
		'ace_sitemap_gen_schedule' => 'acecrawl_sitemap_gen_schedule',
		'ace_sitemap_gen_scopes_for' => 'acecrawl_sitemap_gen_scopes_for',
		'ace_sitemap_gen_seqs' => 'acecrawl_sitemap_gen_seqs',
		'ace_sitemap_gen_set_paused' => 'acecrawl_sitemap_gen_set_paused',
		'ace_sitemap_gen_state' => 'acecrawl_sitemap_gen_state',
		'ace_sitemap_gen_status' => 'acecrawl_sitemap_gen_status',
		'ace_sitemap_gen_unlock' => 'acecrawl_sitemap_gen_unlock',
		'ace_sitemap_gen_update_state' => 'acecrawl_sitemap_gen_update_state',
		'ace_sitemap_gen_url_key' => 'acecrawl_sitemap_gen_url_key',
		'ace_sitemap_gen_withhold' => 'acecrawl_sitemap_gen_withhold',
		'ace_sitemap_gen_write' => 'acecrawl_sitemap_gen_write',
		'ace_sitemap_powertools_get_authors_provider' => 'acecrawl_sitemap_get_authors_provider',
		'ace_sitemap_powertools_get_cache_ttl' => 'acecrawl_sitemap_get_cache_ttl',
		'ace_sitemap_powertools_get_cache_version' => 'acecrawl_sitemap_get_cache_version',
		'ace_sitemap_powertools_get_cached_index_list' => 'acecrawl_sitemap_get_cached_index_list',
		'ace_sitemap_powertools_get_cached_url_list' => 'acecrawl_sitemap_get_cached_url_list',
		'ace_sitemap_powertools_get_human_font_face_css' => 'acecrawl_sitemap_get_human_font_face_css',
		'ace_sitemap_powertools_get_human_font_family' => 'acecrawl_sitemap_get_human_font_family',
		'ace_sitemap_powertools_get_human_theme_global_styles' => 'acecrawl_sitemap_get_human_theme_global_styles',
		'ace_sitemap_powertools_get_human_theme_override_css' => 'acecrawl_sitemap_get_human_theme_override_css',
		'ace_sitemap_powertools_get_human_theme_stylesheet_links' => 'acecrawl_sitemap_get_human_theme_stylesheet_links',
		'ace_sitemap_powertools_get_human_view_css' => 'acecrawl_sitemap_get_human_view_css',
		'ace_sitemap_powertools_get_index_entry_lastmod' => 'acecrawl_sitemap_get_index_entry_lastmod',
		'ace_sitemap_powertools_get_max_pages_for_route' => 'acecrawl_sitemap_get_max_pages_for_route',
		'ace_sitemap_powertools_get_noindex_meta_map' => 'acecrawl_sitemap_get_noindex_meta_map',
		'ace_sitemap_powertools_get_option' => 'acecrawl_sitemap_get_option',
		'ace_sitemap_powertools_get_options' => 'acecrawl_sitemap_get_options',
		'ace_sitemap_powertools_get_provider' => 'acecrawl_sitemap_get_provider',
		'ace_sitemap_powertools_get_visible_post_count' => 'acecrawl_sitemap_get_visible_post_count',
		'ace_sitemap_powertools_get_visible_post_ids_for_page' => 'acecrawl_sitemap_get_visible_post_ids_for_page',
		'ace_sitemap_powertools_handle_legacy_gone' => 'acecrawl_sitemap_handle_legacy_gone',
		'ace_sitemap_powertools_handle_purge_cache_request' => 'acecrawl_sitemap_handle_purge_cache_request',
		'ace_sitemap_powertools_has_redis_cache_plugin' => 'acecrawl_sitemap_has_redis_cache_plugin',
		'ace_sitemap_powertools_html_should_mark_recent' => 'acecrawl_sitemap_html_should_mark_recent',
		'ace_sitemap_powertools_index_entry_lastmod' => 'acecrawl_sitemap_index_entry_lastmod',
		'ace_sitemap_powertools_index_mark_recent' => 'acecrawl_sitemap_index_mark_recent',
		'ace_sitemap_powertools_is_enabled' => 'acecrawl_sitemap_is_enabled',
		'ace_sitemap_powertools_is_page_one_loc' => 'acecrawl_sitemap_is_page_one_loc',
		'ace_sitemap_powertools_is_probable_bot_request' => 'acecrawl_sitemap_is_probable_bot_request',
		'ace_sitemap_powertools_is_public_sitemap_post' => 'acecrawl_sitemap_is_public_sitemap_post',
		'ace_sitemap_powertools_maintain_page_map' => 'acecrawl_sitemap_maintain_page_map',
		'ace_sitemap_powertools_mark_valid_core_sitemap_success' => 'acecrawl_sitemap_mark_valid_core_sitemap_success',
		'ace_sitemap_powertools_max_urls' => 'acecrawl_sitemap_max_urls',
		'ace_sitemap_powertools_maybe_bump_cache_version_on_delete' => 'acecrawl_sitemap_maybe_bump_cache_version_on_delete',
		'ace_sitemap_powertools_maybe_bump_cache_version_on_save' => 'acecrawl_sitemap_maybe_bump_cache_version_on_save',
		'ace_sitemap_powertools_maybe_bump_cache_version_on_transition' => 'acecrawl_sitemap_maybe_bump_cache_version_on_transition',
		'ace_sitemap_powertools_maybe_purge_legacy_cache' => 'acecrawl_sitemap_maybe_purge_legacy_cache',
		'ace_sitemap_powertools_normalise_taxonomy_entry' => 'acecrawl_sitemap_normalise_taxonomy_entry',
		'ace_sitemap_powertools_normalise_term_link' => 'acecrawl_sitemap_normalise_term_link',
		'ace_sitemap_powertools_normalise_url' => 'acecrawl_sitemap_normalise_url',
		'ace_sitemap_powertools_page_counts' => 'acecrawl_sitemap_page_counts',
		'ace_sitemap_powertools_page_for_id' => 'acecrawl_sitemap_page_for_id',
		'ace_sitemap_powertools_page_map' => 'acecrawl_sitemap_page_map',
		'ace_sitemap_powertools_page_map_keyed_scopes' => 'acecrawl_sitemap_page_map_keyed_scopes',
		'ace_sitemap_powertools_page_map_post_key' => 'acecrawl_sitemap_page_map_post_key',
		'ace_sitemap_powertools_page_maps' => 'acecrawl_sitemap_page_maps',
		'ace_sitemap_powertools_page_maps_before_pass' => 'acecrawl_sitemap_page_maps_before_pass',
		'ace_sitemap_powertools_page_range' => 'acecrawl_sitemap_page_range',
		'ace_sitemap_powertools_parse_loc' => 'acecrawl_sitemap_parse_loc',
		'ace_sitemap_powertools_post_types_filter' => 'acecrawl_sitemap_post_types_filter',
		'ace_sitemap_powertools_posts_order_sql' => 'acecrawl_sitemap_posts_order_sql',
		'ace_sitemap_powertools_posts_pre_max_num_pages' => 'acecrawl_sitemap_posts_pre_max_num_pages',
		'ace_sitemap_powertools_posts_pre_url_list' => 'acecrawl_sitemap_posts_pre_url_list',
		'ace_sitemap_powertools_posts_query_args' => 'acecrawl_sitemap_posts_query_args',
		'ace_sitemap_purge_cache' => 'acecrawl_sitemap_purge_cache',
		'ace_sitemap_powertools_rebuild_page_map' => 'acecrawl_sitemap_rebuild_page_map',
		'ace_sitemap_powertools_recent_prefix_from_loc' => 'acecrawl_sitemap_recent_prefix_from_loc',
		'ace_sitemap_powertools_recommended_excluded_taxonomies' => 'acecrawl_sitemap_recommended_excluded_taxonomies',
		'ace_sitemap_powertools_redirect_legacy_urls' => 'acecrawl_sitemap_redirect_legacy_urls',
		'ace_sitemap_powertools_redirect_tag_base_to_root_slug' => 'acecrawl_sitemap_redirect_tag_base_to_root_slug',
		'ace_sitemap_powertools_redis_cache_available' => 'acecrawl_sitemap_redis_cache_available',
		'ace_sitemap_powertools_register_authors_provider' => 'acecrawl_sitemap_register_authors_provider',
		'ace_sitemap_powertools_register_news_provider' => 'acecrawl_sitemap_register_news_provider',
		'ace_sitemap_powertools_register_settings' => 'acecrawl_sitemap_register_settings',
		'ace_sitemap_powertools_render_html_footer' => 'acecrawl_sitemap_render_html_footer',
		'ace_sitemap_powertools_render_html_header' => 'acecrawl_sitemap_render_html_header',
		'ace_sitemap_powertools_render_index_html' => 'acecrawl_sitemap_render_index_html',
		'ace_sitemap_powertools_render_index_xml' => 'acecrawl_sitemap_render_index_xml',
		'ace_sitemap_powertools_render_settings_page' => 'acecrawl_sitemap_render_settings_page',
		'ace_sitemap_powertools_render_sitemap_html' => 'acecrawl_sitemap_render_sitemap_html',
		'ace_sitemap_powertools_render_sitemap_xml' => 'acecrawl_sitemap_render_sitemap_xml',
		'ace_sitemap_powertools_replace_stylesheet_header' => 'acecrawl_sitemap_replace_stylesheet_header',
		'ace_sitemap_powertools_replace_stylesheet_header_for_context' => 'acecrawl_sitemap_replace_stylesheet_header_for_context',
		'ace_sitemap_powertools_replace_stylesheet_index_header' => 'acecrawl_sitemap_replace_stylesheet_index_header',
		'ace_sitemap_powertools_resolve_root_slug_to_tag_archive' => 'acecrawl_sitemap_resolve_root_slug_to_tag_archive',
		'ace_sitemap_powertools_root_tag_archives_enabled' => 'acecrawl_sitemap_root_tag_archives_enabled',
		'ace_sitemap_powertools_root_tag_links_enabled' => 'acecrawl_sitemap_root_tag_links_enabled',
		'ace_sitemap_powertools_route_description' => 'acecrawl_sitemap_route_description',
		'ace_sitemap_powertools_route_lookup' => 'acecrawl_sitemap_route_lookup',
		'ace_sitemap_powertools_sanitize_options' => 'acecrawl_sitemap_sanitize_options',
		'ace_sitemap_powertools_send_response_cache_headers' => 'acecrawl_sitemap_send_response_cache_headers',
		'ace_sitemap_powertools_send_success_status' => 'acecrawl_sitemap_send_success_status',
		'ace_sitemap_powertools_send_unavailable' => 'acecrawl_sitemap_send_unavailable',
		'ace_sitemap_powertools_serve_custom_routes' => 'acecrawl_sitemap_serve_custom_routes',
		'ace_sitemap_powertools_should_render_human_view' => 'acecrawl_sitemap_should_render_human_view',
		'ace_sitemap_powertools_should_set_index_lastmod' => 'acecrawl_sitemap_should_set_index_lastmod',
		'ace_sitemap_powertools_should_short_circuit_posts_sitemap' => 'acecrawl_sitemap_should_short_circuit_posts_sitemap',
		'ace_sitemap_powertools_stable_pages_supported' => 'acecrawl_sitemap_stable_pages_supported',
		'ace_sitemap_powertools_stored_page_map' => 'acecrawl_sitemap_stored_page_map',
		'ace_sitemap_powertools_stylesheet_css' => 'acecrawl_sitemap_stylesheet_css',
		'ace_sitemap_powertools_stylesheet_header_html' => 'acecrawl_sitemap_stylesheet_header_html',
		'ace_sitemap_powertools_stylesheet_index_url' => 'acecrawl_sitemap_stylesheet_index_url',
		'ace_sitemap_powertools_stylesheet_url' => 'acecrawl_sitemap_stylesheet_url',
		'ace_sitemap_powertools_stylesheet_urls' => 'acecrawl_sitemap_stylesheet_urls',
		'ace_sitemap_powertools_tag_base_redirect_enabled' => 'acecrawl_sitemap_tag_base_redirect_enabled',
		'ace_sitemap_powertools_taxonomies_filter' => 'acecrawl_sitemap_taxonomies_filter',
		'ace_sitemap_powertools_taxonomy_count_changed' => 'acecrawl_sitemap_taxonomy_count_changed',
		'ace_sitemap_powertools_taxonomy_min_posts' => 'acecrawl_sitemap_taxonomy_min_posts',
		'ace_sitemap_powertools_taxonomy_min_posts_clause' => 'acecrawl_sitemap_taxonomy_min_posts_clause',
		'ace_sitemap_powertools_taxonomy_query_args' => 'acecrawl_sitemap_taxonomy_query_args',
		'ace_sitemap_powertools_to_timestamps' => 'acecrawl_sitemap_to_timestamps',
		'ace_sitemap_powertools_use_root_level_tag_links' => 'acecrawl_sitemap_use_root_level_tag_links',
		'ace_sitemap_powertools_visible_ids_in_range' => 'acecrawl_sitemap_visible_ids_in_range',
		'ace_seo_visibility_changed' => 'acecrawl_visibility_changed',
	);

	/** Old class => new class. */
	const CLASSES = array(
		'ACE_Authors_Sitemaps_Provider' => 'AceCrawl_Authors_Sitemaps_Provider',
		'ACE_News_Posts_Sitemaps_Provider' => 'AceCrawl_News_Posts_Sitemaps_Provider',
		'ACE_SEO_Breadcrumbs' => 'AceCrawl_Breadcrumbs',
		'ACE_SEO_Dashboard_Ajax' => 'AceCrawl_Dashboard_Ajax',
		'ACE_SEO_Dashboard_Cache' => 'AceCrawl_Dashboard_Cache',
		'ACE_SEO_Database_Optimizer' => 'AceCrawl_Database_Optimizer',
		'ACE_SEO_Frontend_Performance' => 'AceCrawl_Frontend_Performance',
		'ACE_SEO_Term_Redirect' => 'AceCrawl_Term_Redirect',
		'AceSEOActivator' => 'AceCrawlActivator',
		'AceSeoAdmin' => 'AceCrawlAdmin',
		'AceSeoAggregateViews' => 'AceCrawlAggregateViews',
		'AceSEOAiAssistant' => 'AceCrawlAiAssistant',
		'AceSEOApiHelper' => 'AceCrawlApiHelper',
		'AceSeoAssets' => 'AceCrawlAssets',
		'AceSEODashboard' => 'AceCrawlDashboard',
		'AceSEODeactivator' => 'AceCrawlDeactivator',
		'AceSeoDigest' => 'AceCrawlDigest',
		'AceSeoExport' => 'AceCrawlExport',
		'AceSeoFrontend' => 'AceCrawlFrontend',
		'AceSEOGoogleData' => 'AceCrawlGoogleData',
		'AceSeoInput' => 'AceCrawlInput',
		'AceSeoIntegrations' => 'AceCrawlIntegrations',
		'AceSEOMetabox' => 'AceCrawlMetabox',
		'AceSeoOrphanReport' => 'AceCrawlOrphanReport',
		'AceSEOPageSpeed' => 'AceCrawlPageSpeed',
		'AceSeoPostColumns' => 'AceCrawlPostColumns',
		'AceSeoRedirectDiscovery' => 'AceCrawlRedirectDiscovery',
		'AceSeoRedirects' => 'AceCrawlRedirects',
		'AceSeoRetentionActions' => 'AceCrawlRetentionActions',
		'AceSeoRetentionFresh' => 'AceCrawlRetentionFresh',
		'AceSeoRetentionFront' => 'AceCrawlRetentionFront',
		'AceSeoRetentionPanel' => 'AceCrawlRetentionPanel',
		'AceSeoRetentionPrototype' => 'AceCrawlRetentionPrototype',
		'AceSeoRetentionQueue' => 'AceCrawlRetentionQueue',
		'AceSeoRetentionReport' => 'AceCrawlRetentionReport',
		'AceSeoSchema' => 'AceCrawlSchema',
		'AceSeoSchemaGraph' => 'AceCrawlSchemaGraph',
		'AceSEOSearchConsole' => 'AceCrawlSearchConsole',
		'AceSEOSearchConsoleCli' => 'AceCrawlSearchConsoleCli',
		'AceSEOSettings' => 'AceCrawlSettings',
		'AceSeoSheets' => 'AceCrawlSheets',
		'AceSeoSheetsSchedule' => 'AceCrawlSheetsSchedule',
		'AceSEOSiteKit' => 'AceCrawlSiteKit',
		'AceSeoTracking' => 'AceCrawlTracking',
		'AceSeoTrackingClarity' => 'AceCrawlTrackingClarity',
		'AceSeoTrackingCommerce' => 'AceCrawlTrackingCommerce',
		'AceSeoTrackingGoogle' => 'AceCrawlTrackingGoogle',
		'AceSeoTrackingUI' => 'AceCrawlTrackingUI',
		'AceSeoTrending' => 'AceCrawlTrending',
		'AceSEOUninstaller' => 'AceCrawlUninstaller',
		'AceSeoViewTracker' => 'AceCrawlViewTracker',
		'AceSeoWhiteHat' => 'AceCrawlWhiteHat',
		'Ace_SEO_Retention_Dated_Traffic' => 'AceCrawl_Retention_Dated_Traffic',
		'Ace_SEO_Retention_Evidence' => 'AceCrawl_Retention_Evidence',
		'Ace_SEO_Retention_Evidence_View' => 'AceCrawl_Retention_Evidence_View',
		'Ace_SEO_Retention_Insights' => 'AceCrawl_Retention_Insights',
		'Ace_SEO_Retention_Snapshot' => 'AceCrawl_Retention_Snapshot',
		'Ace_SEO_Seasonal_Window' => 'AceCrawl_Seasonal_Window',
		'Ace_SEO_Timing_Suggestions' => 'AceCrawl_Timing_Suggestions',
	);

	/** Old constant => new constant, defined by the plugin. */
	const CONSTS = array(
		'ACE_SEO_BASENAME' => 'ACECRAWL_BASENAME',
		'ACE_SEO_FILE' => 'ACECRAWL_FILE',
		'ACE_SEO_FORM_PREFIX' => 'ACECRAWL_FORM_PREFIX',
		'ACE_SEO_META_PREFIX' => 'ACECRAWL_META_PREFIX',
		'ACE_SEO_PATH' => 'ACECRAWL_PATH',
		'ACE_SITEMAP_GEN_FORMAT' => 'ACECRAWL_SITEMAP_GEN_FORMAT',
		'ACE_SITEMAP_GEN_HOOK' => 'ACECRAWL_SITEMAP_GEN_HOOK',
		'ACE_SITEMAP_GEN_SEQ_OPTION' => 'ACECRAWL_SITEMAP_GEN_SEQ_OPTION',
		'ACE_SITEMAP_GEN_STATE' => 'ACECRAWL_SITEMAP_GEN_STATE',
		'ACE_SITEMAP_GEN_WITHHELD' => 'ACECRAWL_SITEMAP_GEN_WITHHELD',
		'ACE_SITEMAP_POWERTOOLS_VERSION' => 'ACECRAWL_SITEMAP_POWERTOOLS_VERSION',
		'ACE_SEO_URL' => 'ACECRAWL_URL',
		'ACE_SEO_VERSION' => 'ACECRAWL_VERSION',
	);

	/** Old constant => new constant, set by the site in wp-config.php. */
	const INPUT_CONSTS = array(
		'ACE_SEO_DISCOURAGE_MODE' => 'ACECRAWL_DISCOURAGE_MODE',
		'ACE_SEO_SHEETS_KEY_FILE' => 'ACECRAWL_SHEETS_KEY_FILE',
		'ACE_SITEMAP_SHARED_LOCK' => 'ACECRAWL_SITEMAP_SHARED_LOCK',
		'ACE_SEO_WHITEHAT_API_KEY' => 'ACECRAWL_WHITEHAT_API_KEY',
	);

	/** Old filter => new filter the plugin applies. */
	const FILTERS = array(
		'ace_seo_breadcrumb_blog_label' => 'acecrawl_breadcrumb_blog_label',
		'ace_seo_breadcrumb_home_label' => 'acecrawl_breadcrumb_home_label',
		'ace_seo_canonical_query_args' => 'acecrawl_canonical_query_args',
		'ace_sitemap_powertools_excluded_clean_slugs' => 'acecrawl_sitemap_excluded_clean_slugs',
		'ace_sitemap_powertools_legacy_gone_patterns' => 'acecrawl_sitemap_legacy_gone_patterns',
		'ace_sitemap_powertools_response_cache_ttl' => 'acecrawl_sitemap_response_cache_ttl',
		'pre_ace_seo_whitehat_ai' => 'acecrawl_pre_whitehat_ai',
		'ace_seo_admin_column_post_types' => 'acecrawl_admin_column_post_types',
		'ace_seo_admin_sort_max_posts' => 'acecrawl_admin_sort_max_posts',
		'ace_seo_aggregate_views_show_privacy' => 'acecrawl_aggregate_views_show_privacy',
		'ace_seo_ai_capability' => 'acecrawl_ai_capability',
		'ace_seo_ai_rate_limit' => 'acecrawl_ai_rate_limit',
		'ace_seo_archive_og_description' => 'acecrawl_archive_og_description',
		'ace_seo_archive_og_image' => 'acecrawl_archive_og_image',
		'ace_seo_archive_og_title' => 'acecrawl_archive_og_title',
		'ace_seo_archive_twitter_title' => 'acecrawl_archive_twitter_title',
		'ace_seo_article_schema_type' => 'acecrawl_article_schema_type',
		'ace_seo_breadcrumb_items' => 'acecrawl_breadcrumb_items',
		'ace_seo_breadcrumb_taxonomy_base' => 'acecrawl_breadcrumb_taxonomy_base',
		'ace_seo_breadcrumbs_markup' => 'acecrawl_breadcrumbs_markup',
		'ace_seo_breadcrumbs_placeholder' => 'acecrawl_breadcrumbs_placeholder',
		'ace_seo_canonical' => 'acecrawl_canonical',
		'ace_seo_directory_post_types' => 'acecrawl_directory_post_types',
		'ace_seo_discourage_mode' => 'acecrawl_discourage_mode',
		'ace_seo_emit_article' => 'acecrawl_emit_article',
		'ace_seo_faq_schema_enabled' => 'acecrawl_faq_schema_enabled',
		'ace_seo_force_noindex' => 'acecrawl_force_noindex',
		'ace_seo_home_canonical' => 'acecrawl_home_canonical',
		'ace_seo_home_meta_description' => 'acecrawl_home_meta_description',
		'ace_seo_home_og_description' => 'acecrawl_home_og_description',
		'ace_seo_home_og_image' => 'acecrawl_home_og_image',
		'ace_seo_home_og_tags' => 'acecrawl_home_og_tags',
		'ace_seo_home_og_title' => 'acecrawl_home_og_title',
		'ace_seo_home_og_type' => 'acecrawl_home_og_type',
		'ace_seo_home_og_url' => 'acecrawl_home_og_url',
		'ace_seo_home_twitter_card' => 'acecrawl_home_twitter_card',
		'ace_seo_home_twitter_description' => 'acecrawl_home_twitter_description',
		'ace_seo_home_twitter_image' => 'acecrawl_home_twitter_image',
		'ace_seo_home_twitter_title' => 'acecrawl_home_twitter_title',
		'ace_seo_is_bot_user_agent' => 'acecrawl_is_bot_user_agent',
		'ace_seo_list_export_header' => 'acecrawl_list_export_header',
		'ace_seo_list_export_row' => 'acecrawl_list_export_row',
		'ace_seo_meta_description' => 'acecrawl_meta_description',
		'ace_seo_non_canonical_query_args' => 'acecrawl_non_canonical_query_args',
		'ace_seo_organization_schema' => 'acecrawl_organization_schema',
		'ace_seo_orphan_ignored_taxonomies' => 'acecrawl_orphan_ignored_taxonomies',
		'ace_seo_orphan_post_types' => 'acecrawl_orphan_post_types',
		'ace_seo_orphan_reachable_ids' => 'acecrawl_orphan_reachable_ids',
		'ace_seo_person_schema' => 'acecrawl_person_schema',
		'ace_seo_product_schema_enabled' => 'acecrawl_product_schema_enabled',
		'ace_seo_publisher_person_schema' => 'acecrawl_publisher_person_schema',
		'ace_seo_publisher_schema' => 'acecrawl_publisher_schema',
		'ace_seo_query_loop_page_canonical' => 'acecrawl_query_loop_page_canonical',
		'ace_seo_referrer_source' => 'acecrawl_referrer_source',
		'ace_seo_retention_backlinks' => 'acecrawl_retention_backlinks',
		'ace_seo_retention_continue_html' => 'acecrawl_retention_continue_html',
		'ace_seo_retention_evidence_context' => 'acecrawl_retention_evidence_context',
		'ace_seo_retention_gone_message' => 'acecrawl_retention_gone_message',
		'ace_seo_retention_internal_hosts' => 'acecrawl_retention_internal_hosts',
		'ace_seo_retention_is_retained' => 'acecrawl_retention_is_retained',
		'ace_seo_retention_lifetime_days' => 'acecrawl_retention_lifetime_days',
		'ace_seo_retention_light_applies' => 'acecrawl_retention_light_applies',
		'ace_seo_retention_link_evidence' => 'acecrawl_retention_link_evidence',
		'ace_seo_retention_next_post_id' => 'acecrawl_retention_next_post_id',
		'ace_seo_retention_notice_html' => 'acecrawl_retention_notice_html',
		'ace_seo_retention_notice_post_types' => 'acecrawl_retention_notice_post_types',
		'ace_seo_retention_options' => 'acecrawl_retention_options',
		'ace_seo_retention_pageviews' => 'acecrawl_retention_pageviews',
		'ace_seo_retention_redirect_url' => 'acecrawl_retention_redirect_url',
		'ace_seo_retention_row' => 'acecrawl_retention_row',
		'ace_seo_retention_settings' => 'acecrawl_retention_settings',
		'ace_seo_retention_shareable_links' => 'acecrawl_retention_shareable_links',
		'ace_seo_robots_directives' => 'acecrawl_robots_directives',
		'ace_seo_schema_article' => 'acecrawl_schema_article',
		'ace_seo_schema_graph' => 'acecrawl_schema_graph',
		'ace_seo_singular_canonical' => 'acecrawl_singular_canonical',
		'ace_seo_singular_meta_description' => 'acecrawl_singular_meta_description',
		'ace_seo_singular_og_description' => 'acecrawl_singular_og_description',
		'ace_seo_singular_og_image' => 'acecrawl_singular_og_image',
		'ace_seo_singular_og_title' => 'acecrawl_singular_og_title',
		'ace_seo_singular_og_type' => 'acecrawl_singular_og_type',
		'ace_seo_singular_og_url' => 'acecrawl_singular_og_url',
		'ace_seo_singular_title' => 'acecrawl_singular_title',
		'ace_seo_site_is_discouraged' => 'acecrawl_site_is_discouraged',
		'ace_sitemap_powertools_cache_ttl' => 'acecrawl_sitemap_cache_ttl',
		'ace_sitemap_powertools_custom_routes' => 'acecrawl_sitemap_custom_routes',
		'ace_sitemap_powertools_excluded_post_ids' => 'acecrawl_sitemap_excluded_post_ids',
		'ace_sitemap_powertools_excluded_sitemap_post_types' => 'acecrawl_sitemap_excluded_sitemap_post_types',
		'ace_sitemap_powertools_excluded_sitemap_providers' => 'acecrawl_sitemap_excluded_sitemap_providers',
		'ace_sitemap_powertools_excluded_sitemap_taxonomies' => 'acecrawl_sitemap_excluded_sitemap_taxonomies',
		'ace_sitemap_generation_batch_size' => 'acecrawl_sitemap_generation_batch_size',
		'ace_sitemap_generation_delay' => 'acecrawl_sitemap_generation_delay',
		'ace_sitemap_generation_dir' => 'acecrawl_sitemap_generation_dir',
		'ace_sitemap_generation_gone_grace' => 'acecrawl_sitemap_generation_gone_grace',
		'ace_sitemap_generation_index_min_age' => 'acecrawl_sitemap_generation_index_min_age',
		'ace_sitemap_generation_keyed_scopes' => 'acecrawl_sitemap_generation_keyed_scopes',
		'ace_sitemap_generation_max_age' => 'acecrawl_sitemap_generation_max_age',
		'ace_sitemap_generation_max_keys' => 'acecrawl_sitemap_generation_max_keys',
		'ace_sitemap_generation_min_age' => 'acecrawl_sitemap_generation_min_age',
		'ace_sitemap_generation_post_key' => 'acecrawl_sitemap_generation_post_key',
		'ace_sitemap_generation_provider_scopes' => 'acecrawl_sitemap_generation_provider_scopes',
		'ace_sitemap_generation_public_urls' => 'acecrawl_sitemap_generation_public_urls',
		'ace_sitemap_generation_shared_lock' => 'acecrawl_sitemap_generation_shared_lock',
		'ace_sitemap_generation_time_budget' => 'acecrawl_sitemap_generation_time_budget',
		'ace_sitemap_generation_yield' => 'acecrawl_sitemap_generation_yield',
		'ace_sitemap_powertools_human_theme_css' => 'acecrawl_sitemap_human_theme_css',
		'ace_sitemap_powertools_is_enabled' => 'acecrawl_sitemap_is_enabled',
		'ace_sitemap_powertools_large_site_threshold' => 'acecrawl_sitemap_large_site_threshold',
		'ace_sitemap_powertools_news_query_args' => 'acecrawl_sitemap_news_query_args',
		'ace_sitemap_powertools_recommended_excluded_taxonomies' => 'acecrawl_sitemap_recommended_excluded_taxonomies',
		'ace_sitemap_powertools_sanity_url' => 'acecrawl_sitemap_sanity_url',
		'ace_sitemap_powertools_stable_pages' => 'acecrawl_sitemap_stable_pages',
		'ace_sitemap_powertools_stable_pages_check_interval' => 'acecrawl_sitemap_stable_pages_check_interval',
		'ace_sitemap_powertools_stable_pages_min_fill' => 'acecrawl_sitemap_stable_pages_min_fill',
		'ace_sitemap_powertools_taxonomy_min_posts' => 'acecrawl_sitemap_taxonomy_min_posts',
		'ace_seo_skip_admin_columns' => 'acecrawl_skip_admin_columns',
		'ace_seo_skip_rest_api' => 'acecrawl_skip_rest_api',
		'ace_seo_template_variables' => 'acecrawl_template_variables',
		'ace_seo_term_redirect_target' => 'acecrawl_term_redirect_target',
		'ace_seo_timing_suggestion_taxonomies' => 'acecrawl_timing_suggestion_taxonomies',
		'ace_seo_title_separator' => 'acecrawl_title_separator',
		'ace_seo_token_replacements' => 'acecrawl_token_replacements',
		'ace_seo_track_keep_days' => 'acecrawl_track_keep_days',
		'ace_seo_track_post' => 'acecrawl_track_post',
		'ace_seo_trending_post_title' => 'acecrawl_trending_post_title',
		'ace_seo_trending_topics' => 'acecrawl_trending_topics',
		'ace_seo_twitter_description' => 'acecrawl_twitter_description',
		'ace_seo_twitter_title' => 'acecrawl_twitter_title',
		'ace_seo_whitehat_event_time' => 'acecrawl_whitehat_event_time',
		'ace_seo_whitehat_instruction' => 'acecrawl_whitehat_instruction',
		'ace_seo_whitehat_options' => 'acecrawl_whitehat_options',
		'ace_seo_whitehat_result' => 'acecrawl_whitehat_result',
		'ace_seo_whitehat_sweep_batch' => 'acecrawl_whitehat_sweep_batch',
		'ace_ai_codex_runner' => 'acemedia_ai_codex_runner',
	);

	/** Old action => new action the plugin fires. */
	const ACTIONS = array(
		'ace_seo_hits_rolled_up' => 'acecrawl_hits_rolled_up',
		'ace_seo_retention_action_applied' => 'acecrawl_retention_action_applied',
		'ace_seo_retention_built' => 'acecrawl_retention_built',
		'ace_seo_retention_light_request' => 'acecrawl_retention_light_request',
		'ace_seo_retention_post_changed' => 'acecrawl_retention_post_changed',
		'ace_sitemap_generation_artifact_changed' => 'acecrawl_sitemap_generation_artifact_changed',
		'ace_sitemap_generation_before_pass' => 'acecrawl_sitemap_generation_before_pass',
		'ace_sitemap_generation_published' => 'acecrawl_sitemap_generation_published',
		'ace_seo_tracked_hit' => 'acecrawl_tracked_hit',
		'ace_seo_visibility_changed' => 'acecrawl_visibility_changed',
	);

	/** Old hook => new hook the plugin listens to but others fire. */
	const EXTERNAL = array(
		'ace_seo_analysis_data' => 'acecrawl_analysis_data',
	);

	/** Old option => new option. */
	const OPTIONS = array(
		'ace_ai_connection_v1' => 'acemedia_ai_connection_v1',
		'ace_ai_host_id_v1' => 'acemedia_ai_host_id_v1',
		'ace_retention_queue_schema' => 'acecrawl_retention_queue_schema',
		'ace_seo_activation_date' => 'acecrawl_activation_date',
		'ace_seo_advanced_settings' => 'acecrawl_advanced_settings',
		'ace_seo_db_optimization_pending' => 'acecrawl_db_optimization_pending',
		'ace_seo_db_optimization_results' => 'acecrawl_db_optimization_results',
		'ace_seo_db_optimization_scheduled' => 'acecrawl_db_optimization_scheduled',
		'ace_seo_db_optimized' => 'acecrawl_db_optimized',
		'ace_seo_digest_latest' => 'acecrawl_digest_latest',
		'ace_seo_optimization_progress' => 'acecrawl_optimization_progress',
		'ace_seo_options' => 'acecrawl_options',
		'ace_seo_orphan_scan_progress' => 'acecrawl_orphan_scan_progress',
		'ace_seo_retention_actions_log' => 'acecrawl_retention_actions_log',
		'ace_seo_retention_options' => 'acecrawl_retention_options',
		'ace_seo_retention_progress' => 'acecrawl_retention_progress',
		'ace_seo_settings' => 'acecrawl_settings',
		'ace_seo_social_settings' => 'acecrawl_social_settings',
		'ace_seo_timing_suggestions' => 'acecrawl_timing_suggestions',
		'ace_seo_tracking_alerts' => 'acecrawl_tracking_alerts',
		'ace_seo_trending_latest' => 'acecrawl_trending_latest',
		'ace_seo_version' => 'acecrawl_version',
		'ace_sitemap_cache_version' => 'acecrawl_sitemap_cache_version',
		'ace_sitemap_powertools_legacy_cache_purged' => 'acecrawl_sitemap_legacy_cache_purged',
		'ace_sitemap_powertools_options' => 'acecrawl_sitemap_options',
	);

	/** Old cron hook => new cron hook. */
	const CRONS = array(
		'ace_seo_cleanup_analytics' => 'acecrawl_cleanup_analytics',
		'ace_seo_daily_performance_check' => 'acecrawl_daily_performance_check',
		'ace_seo_digest' => 'acecrawl_digest',
		'ace_seo_generate_sitemap' => 'acecrawl_generate_sitemap',
		'ace_seo_optimize_database' => 'acecrawl_optimize_database',
		'ace_seo_orphan_daily' => 'acecrawl_orphan_daily',
		'ace_seo_orphan_scan' => 'acecrawl_orphan_scan',
		'ace_sitemap_regenerate' => 'acecrawl_sitemap_regenerate',
		'ace_seo_tracking_check' => 'acecrawl_tracking_check',
		'ace_seo_trending_weekly' => 'acecrawl_trending_weekly',
	);

	/** @var bool Whether the layer runs on this request; off once a site is converted. */
	public static $active = false;

	/** Switch the layer on, unless `wp acecrawl convert-names` has marked this site converted. */
	public static function start() {
		if ( get_option( 'acecrawl_names_converted' ) ) {
			return;
		}
		self::$active = true;
		self::early();
	}

	/** @var array Hooks being bridged right now, so a bridge never re-enters itself. */
	private static $running = array();

	/** Before any plugin code runs: site constants, then stored data, so the plugin never reads a missing option. */
	public static function early() {
		foreach ( self::INPUT_CONSTS as $old => $new ) {
			if ( defined( $old ) && ! defined( $new ) ) {
				define( $new, constant( $old ) );
			}
		}
		if ( (int) get_option( self::MIGRATED ) < self::VERSION ) {
			self::migrate();
		}
		// Every load, as running an older copy again reschedules its events under the old names.
		self::move_events();
	}

	/** Everything else, once the plugin's own code has loaded. */
	public static function boot() {
		if ( ! self::$active ) {
			return;
		}
		self::names();
		// Some files load later (admin screens, CLI), so give their names a second pass.
		add_action( 'plugins_loaded', array( __CLASS__, 'names' ), PHP_INT_MAX );
		// Front-end and admin files load on the plugin's own init (priority 10), so follow straight after it.
		add_action( 'init', array( __CLASS__, 'names' ), 11 );
		add_action( 'admin_init', array( __CLASS__, 'names' ), 0 );
		spl_autoload_register( array( __CLASS__, 'alias_class' ) );
		self::hooks();
	}

	/** Old constants, classes and functions, for whatever has loaded so far. */
	public static function names() {
		foreach ( self::CONSTS as $old => $new ) {
			if ( defined( $new ) && ! defined( $old ) ) {
				define( $old, constant( $new ) );
			}
		}
		foreach ( self::CLASSES as $old => $new ) {
			if ( class_exists( $new, false ) && ! class_exists( $old, false ) ) {
				class_alias( $new, $old );
			}
		}
		foreach ( self::FUNCS as $old => $new ) {
			if ( ! function_exists( $old ) && function_exists( $new ) ) {
				eval( 'function ' . $old . '( ...$args ) { return ' . $new . '( ...$args ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- names come from the constant map above, never from input.
			}
		}
	}

	/** Old hooks and option names. */
	private static function hooks() {
		foreach ( self::FILTERS as $old => $new ) {
			add_filter( $new, static function ( ...$args ) use ( $old ) {
				return self::bridge( $old, $args, true );
			}, 10, 20 );
		}
		foreach ( self::ACTIONS as $old => $new ) {
			add_action( $new, static function ( ...$args ) use ( $old ) {
				self::bridge( $old, $args, false );
			}, 10, 20 );
		}
		foreach ( self::EXTERNAL + self::CRONS as $old => $new ) {
			add_action( $old, static function ( ...$args ) use ( $new ) {
				self::bridge( $new, $args, false );
			}, 10, 20 );
		}
		foreach ( self::OPTIONS as $old => $new ) {
			add_filter( 'pre_option_' . $old, static function () use ( $new ) {
				return get_option( $new );
			} );
			add_filter( 'pre_update_option_' . $old, static function ( $value, $old_value ) use ( $new ) {
				update_option( $new, $value );
				return $old_value;
			}, 10, 2 );
		}
	}

	/** Old class names autoload as aliases of the new ones. */
	public static function alias_class( $old ) {
		if ( isset( self::CLASSES[ $old ] ) && class_exists( self::CLASSES[ $old ] ) && ! class_exists( $old, false ) ) {
			class_alias( self::CLASSES[ $old ], $old );
		}
	}

	/** Run whatever is hooked to $hook with the same arguments. */
	private static function bridge( $hook, array $args, $filter ) {
		if ( isset( self::$running[ $hook ] ) || ! has_filter( $hook ) ) {
			return $filter ? ( $args[0] ?? null ) : null;
		}
		self::$running[ $hook ] = true;
		$result = $filter ? apply_filters_ref_array( $hook, $args ) : do_action_ref_array( $hook, $args );
		unset( self::$running[ $hook ] );
		return $result;
	}

	/** Move stored options and scheduled events to their new names, once per site. */
	public static function migrate() {
		global $wpdb;
		foreach ( self::OPTIONS as $old => $new ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $row || null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $new ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				continue;
			}
			// The old row stays put, so going back to an earlier version loses nothing.
			add_option( $new, maybe_unserialize( $row->option_value ), '', in_array( $row->autoload, array( 'no', 'off', 'auto-off' ), true ) ? 'no' : 'yes' );
		}
		if ( is_multisite() && ! get_site_option( self::MIGRATED ) ) {
			foreach ( self::OPTIONS as $old => $new ) {
				$value = get_site_option( $old, null );
				if ( null !== $value && null === get_site_option( $new, null ) ) {
					add_site_option( $new, $value );
				}
			}
			update_site_option( self::MIGRATED, self::VERSION );
		}
		update_option( self::MIGRATED, self::VERSION, false );
	}

	/** Move events scheduled under an old hook name to the new one. Writes nothing when there are none. */
	public static function move_events() {
		$cron = _get_cron_array();
		foreach ( is_array( $cron ) ? $cron : array() as $timestamp => $hooks ) {
			foreach ( self::CRONS as $old => $new ) {
				foreach ( $hooks[ $old ] ?? array() as $event ) {
					$args = $event['args'] ?? array();
					wp_unschedule_event( $timestamp, $old, $args );
					if ( ! wp_next_scheduled( $new, $args ) ) {
						if ( empty( $event['schedule'] ) ) {
							wp_schedule_single_event( $timestamp, $new, $args );
						} else {
							wp_schedule_event( $timestamp, $event['schedule'], $new, $args );
						}
					}
				}
			}
		}
	}
}


<?php
/**
 * Ecosystem integrations — detect companion plugins and register schema
 * providers / suppress duplicate output for them.
 *
 * Each adapter is a static method guarded by its own is-active check, run
 * once on template_redirect (after every plugin has registered its hooks,
 * before wp_head fires). Adapters for plugins that already register into
 * the graph themselves (via ace_seo_register_schema_provider) are skipped
 * automatically because provider ids are unique.
 *
 * @since 1.0.6
 */

if (!defined('ABSPATH')) {
    exit;
}

class AceSeoIntegrations {

    public static function init() {
        add_action('template_redirect', [__CLASS__, 'run_adapters'], 1);
    }

    public static function run_adapters() {
        self::directory_adapter();
    }

    /**
     * Optional directory/listings adapter (off by default).
     *
     * A theme, plugin or MU plugin opts in by mapping roles to its post types:
     *
     *     add_filter( 'ace_seo_directory_post_types', fn() => array(
     *         'business' => 'businesses',     // LocalBusiness
     *         'job'      => 'job_listings',   // JobPosting
     *         'location' => 'locations',      // Place
     *         'event'    => 'events',         // owns its own Event schema
     *     ) );
     *
     * Every role is optional. Mapped post types stop being marked up as Article
     * and, for business/job/location, get a schema provider. Address and
     * coordinates are read from the `address` (string) and `geo_location`
     * ({lat,lng}) post meta; a job's hiring organisation from `business_id`.
     */
    private static function directory_adapter() {
        $types = apply_filters('ace_seo_directory_post_types', array());
        if (!is_array($types) || empty($types)) {
            return;
        }
        $types = array_filter(array_map('sanitize_key', array_intersect_key($types, array_flip(array('business', 'job', 'location', 'event')))), 'post_type_exists');
        if (empty($types)) {
            return;
        }

        // These post types have their own primary schema type, so don't also
        // mark them up as Article.
        add_filter('ace_seo_emit_article', function ($emit, $post) use ($types) {
            if (in_array($post->post_type, $types, true)) {
                return false;
            }
            return $emit;
        }, 10, 2);

        ace_seo_register_schema_provider('directory/business', function ($context) use ($types) {
            if (empty($types['business']) || !is_singular($types['business'])) {
                return null;
            }
            $post_id = get_the_ID();
            $node = [
                '@type' => 'LocalBusiness',
                '@id'   => get_permalink($post_id) . '#business',
                'name'  => get_the_title($post_id),
                'url'   => get_permalink($post_id),
            ];
            $excerpt = get_the_excerpt($post_id);
            if (!empty($excerpt)) {
                $node['description'] = wp_strip_all_tags($excerpt);
            }
            if (has_post_thumbnail($post_id)) {
                $node['image'] = get_the_post_thumbnail_url($post_id, 'large');
            }
            return self::add_location_details($node, $post_id);
        });

        ace_seo_register_schema_provider('directory/job', function ($context) use ($types) {
            if (empty($types['job']) || !is_singular($types['job'])) {
                return null;
            }
            $post_id = get_the_ID();
            $node = [
                '@type' => 'JobPosting',
                '@id'   => get_permalink($post_id) . '#job',
                'title' => get_the_title($post_id),
                'description' => wp_kses_post(get_post_field('post_content', $post_id)),
                'datePosted'  => get_the_date('c', $post_id),
                'directApply' => true,
            ];
            // Hiring organisation: the linked business if set, else the site publisher.
            $business_id = (int) get_post_meta($post_id, 'business_id', true);
            if ($business_id) {
                $node['hiringOrganization'] = [
                    '@type' => 'Organization',
                    'name'  => get_the_title($business_id),
                    'sameAs' => get_permalink($business_id),
                ];
            } elseif (!empty($context['publisher_id'])) {
                $node['hiringOrganization'] = ['@id' => $context['publisher_id']];
            }
            $place = self::build_place($post_id);
            if ($place) {
                $node['jobLocation'] = $place;
            }
            return $node;
        });

        ace_seo_register_schema_provider('directory/location', function ($context) use ($types) {
            if (empty($types['location']) || !is_singular($types['location'])) {
                return null;
            }
            $post_id = get_the_ID();
            $node = [
                '@type' => 'Place',
                '@id'   => get_permalink($post_id) . '#place',
                'name'  => get_the_title($post_id),
                'url'   => get_permalink($post_id),
            ];
            if (has_post_thumbnail($post_id)) {
                $node['image'] = get_the_post_thumbnail_url($post_id, 'large');
            }
            return self::add_location_details($node, $post_id);
        });
    }

    /**
     * Attach address/geo from the directory meta convention (string `address`,
     * object `geo_location` {lat,lng}) to a node.
     */
    private static function add_location_details(array $node, $post_id) {
        $address = get_post_meta($post_id, 'address', true);
        if (!empty($address) && is_string($address)) {
            $node['address'] = [
                '@type' => 'PostalAddress',
                'streetAddress' => wp_strip_all_tags($address),
            ];
        }

        $geo = get_post_meta($post_id, 'geo_location', true);
        if (is_array($geo) && isset($geo['lat'], $geo['lng'])) {
            $node['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude'  => (float) $geo['lat'],
                'longitude' => (float) $geo['lng'],
            ];
        }

        return $node;
    }

    /**
     * Place node (not @id-anchored) for jobLocation etc.
     */
    private static function build_place($post_id) {
        $address = get_post_meta($post_id, 'address', true);
        $geo = get_post_meta($post_id, 'geo_location', true);

        if (empty($address) && !is_array($geo)) {
            return null;
        }

        $place = ['@type' => 'Place'];
        if (!empty($address) && is_string($address)) {
            $place['address'] = [
                '@type' => 'PostalAddress',
                'streetAddress' => wp_strip_all_tags($address),
            ];
        }
        if (is_array($geo) && isset($geo['lat'], $geo['lng'])) {
            $place['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude'  => (float) $geo['lat'],
                'longitude' => (float) $geo['lng'],
            ];
        }

        return $place;
    }
}

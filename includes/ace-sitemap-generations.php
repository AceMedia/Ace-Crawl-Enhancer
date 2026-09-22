<?php
/**
 * Sitemap generations: durable last-good sitemap lists, rebuilt in the background.
 *
 * Every sitemap list (the index and each provider/subtype/page) is kept as a small JSON
 * artifact on disk. Content changes only mark the affected scopes dirty; readers keep being
 * served the last good artifact while one background worker rebuilds the dirty ones in
 * bounded batches. Redis is not required and a Redis flush loses nothing.
 *
 * - Dirty tracking: a sequence number per scope (`posts:<type>`, `taxonomies:<tax>`,
 *   `users`, `all`) in one small record in the store. An artifact records the sequence it
 *   was built at and is stale when a scope it depends on has moved past it.
 * - Writes are same-directory temp file + rename, so readers never see half-written JSON.
 *   A newer artifact is never replaced by an older build (fencing on the sequence).
 * - Locks are flock()s on files in the store: owned by the process holding them and freed by
 *   the kernel if that process dies, so there is no expiry to tune and no foreign unlock.
 * - Urgent removals (unpublish, trash, delete, noindex) are withheld from served lists at
 *   once, whatever the age of the artifact.
 *
 * Nothing here knows about any particular site. Providers declare what they depend on with
 * the `ace_sitemap_generation_provider_scopes` filter; unknown providers rebuild on any change.
 *
 * @package AceCrawlEnhancer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const ACE_SITEMAP_GEN_HOOK        = 'ace_sitemap_regenerate';
const ACE_SITEMAP_GEN_SEQ_OPTION  = 'seq';
const ACE_SITEMAP_GEN_STATE       = 'state';
const ACE_SITEMAP_GEN_WITHHELD    = 'withheld';
const ACE_SITEMAP_GEN_FORMAT      = 1;

/* ------------------------------------------------------------------ Store */

/**
 * Directory holding this site's artifacts, or '' when no usable store exists.
 *
 * Defaults to uploads, which normally survives deploys. On multi-webhead hosting local disk
 * is not shared: point `ace_sitemap_generation_dir` at shared storage or return '' to fall
 * back to the previous object-cache behaviour.
 */
function ace_sitemap_gen_dir() {
    static $dir = null;
    if ( null !== $dir ) {
        return $dir;
    }

    $dir = '';
    if ( ! ace_sitemap_powertools_is_enabled( 'enable_sitemap_cache' ) ) {
        return $dir;
    }

    $uploads = wp_upload_dir( null, false );
    $default = empty( $uploads['error'] ) && ! empty( $uploads['basedir'] )
        ? trailingslashit( $uploads['basedir'] ) . 'ace-sitemaps/' . get_current_blog_id()
        : '';
    $path    = (string) apply_filters( 'ace_sitemap_generation_dir', $default );

    if ( '' === $path ) {
        return $dir;
    }

    if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
        return $dir;
    }

    if ( ! is_writable( $path ) ) {
        return $dir;
    }

    // The lists are public sitemap data, but there is no reason to serve the raw files.
    $parent = dirname( $path );
    if ( ! file_exists( $parent . '/index.php' ) ) {
        @file_put_contents( $parent . '/index.php', "<?php\n// Silence is golden.\n" );
        @file_put_contents( $parent . '/.htaccess', "Require all denied\nDeny from all\n" );
    }

    $dir = untrailingslashit( $path );
    return $dir;
}

function ace_sitemap_gen_enabled() {
    return '' !== ace_sitemap_gen_dir();
}

/** Artifacts are keyed by home URL too, so a site answering on several hosts never mixes them. */
function ace_sitemap_gen_file( $key, $home = null ) {
    $home = null === $home ? home_url( '/' ) : $home;
    return ace_sitemap_gen_dir() . '/' . sha1( $home . '|' . $key ) . '.json';
}

function ace_sitemap_gen_read_file( $file ) {
    if ( ! is_readable( $file ) ) {
        return null;
    }

    $raw = @file_get_contents( $file );
    if ( false === $raw || '' === $raw ) {
        return null;
    }

    $artifact = json_decode( $raw, true );
    if ( ! is_array( $artifact ) || ( $artifact['format'] ?? 0 ) !== ACE_SITEMAP_GEN_FORMAT || ! isset( $artifact['data'] ) || ! is_array( $artifact['data'] ) ) {
        return null;
    }

    return $artifact;
}

/**
 * Just the small fields of an artifact, without decoding its list. `data` is always written
 * last, so everything before it is a complete JSON object once closed.
 */
function ace_sitemap_gen_read_header( $file ) {
    $fh = @fopen( $file, 'r' );
    if ( ! $fh ) {
        return null;
    }
    $head = (string) fread( $fh, 4096 );
    fclose( $fh );

    $cut = strpos( $head, ',"data":' );
    if ( false === $cut ) {
        return null;
    }

    $header = json_decode( substr( $head, 0, $cut ) . '}', true );
    if ( ! is_array( $header ) || ( $header['format'] ?? 0 ) !== ACE_SITEMAP_GEN_FORMAT || ! isset( $header['key'], $header['meta'], $header['home'] ) ) {
        return null;
    }
    return $header;
}

function ace_sitemap_gen_read( $key ) {
    return ace_sitemap_gen_read_file( ace_sitemap_gen_file( $key ) );
}

/**
 * Publish an artifact atomically. Refuses to replace an artifact built at a later sequence,
 * so a slow or resumed builder can never overwrite newer output.
 *
 * Callers must hold the key lock.
 */
function ace_sitemap_gen_write( array $artifact ) {
    $file     = ace_sitemap_gen_file( $artifact['key'], $artifact['home'] );
    $existing = ace_sitemap_gen_read_file( $file );
    if ( $existing && (int) $existing['seq'] > (int) $artifact['seq'] ) {
        return false;
    }

    $json = wp_json_encode( $artifact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    if ( false === $json ) {
        return false;
    }

    $tmp = dirname( $file ) . '/.' . basename( $file ) . '.' . wp_generate_password( 8, false ) . '.tmp';
    if ( strlen( $json ) !== (int) @file_put_contents( $tmp, $json, LOCK_EX ) ) {
        @unlink( $tmp );
        return false;
    }

    if ( ! @rename( $tmp, $file ) ) {
        @unlink( $tmp );
        return false;
    }

    do_action( 'ace_sitemap_generation_published', $artifact['key'], $artifact['meta'] );
    return true;
}

/**
 * Take a non-blocking exclusive lock. Returns the handle (keep it to hold the lock) or false.
 * The kernel releases it if the process dies, so a crashed builder never wedges the key.
 */
function ace_sitemap_gen_lock( $name, $path = null ) {
    $path = $path ? $path : ace_sitemap_gen_dir() . '/.lock-' . sanitize_key( $name );
    // flock() works on a read-only handle too, so a lock file created by another system user
    // (web server vs WP-CLI) can still be locked.
    $fh = @fopen( $path, 'c' );
    if ( ! $fh ) {
        $fh = @fopen( $path, 'r' );
    }
    if ( ! $fh ) {
        return false;
    }

    if ( ! flock( $fh, LOCK_EX | LOCK_NB ) ) {
        fclose( $fh );
        return false;
    }

    return $fh;
}

function ace_sitemap_gen_unlock( $fh ) {
    if ( $fh ) {
        flock( $fh, LOCK_UN );
        fclose( $fh );
    }
}

/**
 * Small coordination records (sequence, withheld URLs, worker state) live in the store as
 * well, not in wp_options: web requests and a WP-CLI/system-cron worker may use different
 * object caches (or none), and a cached option in one process would never see the other's
 * write. Files in the shared store are coherent for both.
 */
function ace_sitemap_gen_meta_get( $name ) {
    $dir = ace_sitemap_gen_dir();
    if ( '' === $dir ) {
        return array();
    }
    $raw   = @file_get_contents( $dir . '/' . $name . '.meta' );
    $value = $raw ? json_decode( $raw, true ) : null;
    return is_array( $value ) ? $value : array();
}

/** Read-modify-write a record under a blocking lock, so concurrent writers never lose updates. */
function ace_sitemap_gen_meta_update( $name, callable $mutate ) {
    $dir = ace_sitemap_gen_dir();
    if ( '' === $dir ) {
        return array();
    }

    $fh = @fopen( $dir . '/.lock-meta', 'c' );
    if ( ! $fh ) {
        $fh = @fopen( $dir . '/.lock-meta', 'r' );
    }
    if ( $fh ) {
        flock( $fh, LOCK_EX );
    }

    try {
        $value = (array) $mutate( ace_sitemap_gen_meta_get( $name ) );
        $file  = $dir . '/' . $name . '.meta';
        $tmp   = $dir . '/.' . $name . '.' . wp_generate_password( 8, false ) . '.tmp';
        if ( false !== @file_put_contents( $tmp, wp_json_encode( $value, JSON_UNESCAPED_SLASHES ) ) ) {
            @rename( $tmp, $file ) || @unlink( $tmp );
        }
    } finally {
        if ( $fh ) {
            flock( $fh, LOCK_UN );
            fclose( $fh );
        }
    }

    return $value;
}

/* ------------------------------------------------------------ Dirty tracking */

function ace_sitemap_gen_seqs() {
    $seqs = ace_sitemap_gen_meta_get( ACE_SITEMAP_GEN_SEQ_OPTION );
    $seqs['seq']    = (int) ( $seqs['seq'] ?? 0 );
    $seqs['scopes'] = isset( $seqs['scopes'] ) && is_array( $seqs['scopes'] ) ? $seqs['scopes'] : array();
    return $seqs;
}

/**
 * Mark scopes dirty. Collected per request and written once at shutdown, so an import that
 * saves a thousand posts costs one option write and one scheduled job.
 */
function ace_sitemap_gen_mark_dirty( $scope = 'all', $flush_now = false ) {
    static $pending = array();
    static $hooked  = false;

    if ( ! ace_sitemap_gen_enabled() ) {
        return;
    }

    if ( null !== $scope ) {
        $pending[ (string) $scope ] = true;
    }

    if ( ! $flush_now ) {
        if ( ! $hooked ) {
            $hooked = true;
            add_action( 'shutdown', function () {
                ace_sitemap_gen_mark_dirty( null, true );
            }, 1 );
        }
        return;
    }

    if ( empty( $pending ) ) {
        return;
    }

    $names   = array_keys( $pending );
    $pending = array();

    ace_sitemap_gen_meta_update( ACE_SITEMAP_GEN_SEQ_OPTION, function () use ( $names ) {
        $seqs = ace_sitemap_gen_seqs();
        // Millisecond clock as a floor keeps the sequence moving forward even if this record
        // is lost while older artifacts survive, so they can never look current by accident.
        $seqs['seq'] = max( $seqs['seq'] + 1, (int) floor( microtime( true ) * 1000 ) );
        foreach ( $names as $name ) {
            $seqs['scopes'][ $name ] = $seqs['seq'];
        }
        // Scopes accumulate slowly (one per post type/taxonomy); keep the record small.
        if ( count( $seqs['scopes'] ) > 200 ) {
            arsort( $seqs['scopes'] );
            $seqs['scopes'] = array_slice( $seqs['scopes'], 0, 200, true );
        }
        return $seqs;
    } );
    ace_sitemap_gen_schedule();
}

/**
 * The scopes a provider's lists depend on. Core providers are mapped exactly; anything else
 * is assumed to depend on everything unless its owner says otherwise through the filter.
 */
function ace_sitemap_gen_scopes_for( $provider, $subtype ) {
    if ( 'index' === $provider ) {
        $scopes = array( '*' );
    } elseif ( 'posts' === $provider ) {
        $scopes = array( 'posts:' . ( $subtype ? $subtype : 'post' ) );
    } elseif ( 'news' === $provider ) {
        $scopes = array( 'posts:post' );
    } elseif ( 'taxonomies' === $provider ) {
        $scopes = array( 'taxonomies:' . $subtype );
    } elseif ( 'users' === $provider || 'authors' === $provider ) {
        $scopes = array( 'users' );
    } else {
        $scopes = array( '*' );
    }

    $scopes   = (array) apply_filters( 'ace_sitemap_generation_provider_scopes', $scopes, $provider, $subtype );
    $scopes[] = 'all';
    return array_values( array_unique( $scopes ) );
}

/** The sequence an artifact must have reached to be current. */
function ace_sitemap_gen_required_seq( array $scopes, array $seqs = null ) {
    $seqs = $seqs ? $seqs : ace_sitemap_gen_seqs();
    if ( in_array( '*', $scopes, true ) ) {
        return $seqs['seq'];
    }

    $required = 0;
    foreach ( $scopes as $scope ) {
        $required = max( $required, (int) ( $seqs['scopes'][ $scope ] ?? 0 ) );
    }
    return $required;
}

/* --------------------------------------------------------- Urgent removals */

/** Remove a URL from every served list straight away, stale or not. */
function ace_sitemap_gen_withhold( $url ) {
    $url = ace_sitemap_gen_normalise_loc( $url );
    if ( '' === $url || ! ace_sitemap_gen_enabled() ) {
        return;
    }

    ace_sitemap_gen_meta_update( ACE_SITEMAP_GEN_WITHHELD, function ( $withheld ) use ( $url ) {
        $withheld[ $url ] = ace_sitemap_gen_seqs()['seq'] + 1;
        if ( count( $withheld ) > 2000 ) {
            asort( $withheld );
            $withheld = array_slice( $withheld, -2000, null, true );
        }
        return $withheld;
    } );
}

function ace_sitemap_gen_release( $url ) {
    $url = ace_sitemap_gen_normalise_loc( $url );
    if ( '' !== $url && ace_sitemap_gen_enabled() && isset( ace_sitemap_gen_meta_get( ACE_SITEMAP_GEN_WITHHELD )[ $url ] ) ) {
        ace_sitemap_gen_meta_update( ACE_SITEMAP_GEN_WITHHELD, function ( $withheld ) use ( $url ) {
            unset( $withheld[ $url ] );
            return $withheld;
        } );
    }
}

function ace_sitemap_gen_normalise_loc( $url ) {
    $url = trim( (string) $url );
    return '' === $url ? '' : untrailingslashit( strtolower( preg_replace( '~^https?://~i', '', $url ) ) );
}

function ace_sitemap_gen_filter_withheld( array $list ) {
    $withheld = ace_sitemap_gen_meta_get( ACE_SITEMAP_GEN_WITHHELD );
    if ( empty( $withheld ) ) {
        return $list;
    }

    return array_values( array_filter( $list, function ( $entry ) use ( $withheld ) {
        return ! isset( $entry['loc'], $withheld[ ace_sitemap_gen_normalise_loc( $entry['loc'] ) ] );
    } ) );
}

/** A post's public URL as it was while published (works after the status has changed). */
function ace_sitemap_gen_published_permalink( $post ) {
    $copy              = clone $post;
    $copy->post_status = 'publish';
    $copy->post_name   = preg_replace( '/__trashed(-\d+)?$/', '', (string) $copy->post_name );
    return get_permalink( $copy );
}

function ace_sitemap_gen_on_transition( $new_status, $old_status, $post ) {
    if ( ! ( $post instanceof WP_Post ) || $new_status === $old_status || ! ace_sitemap_powertools_is_public_sitemap_post( $post ) ) {
        return;
    }

    if ( 'publish' === $old_status ) {
        ace_sitemap_gen_withhold( ace_sitemap_gen_published_permalink( $post ) );
    } elseif ( 'publish' === $new_status ) {
        ace_sitemap_gen_release( get_permalink( $post ) );
    }
}
add_action( 'transition_post_status', 'ace_sitemap_gen_on_transition', 5, 3 );

function ace_sitemap_gen_on_before_delete( $post_id ) {
    $post = get_post( $post_id );
    if ( $post && 'publish' === $post->post_status && ace_sitemap_powertools_is_public_sitemap_post( $post ) ) {
        ace_sitemap_gen_withhold( get_permalink( $post ) );
        ace_sitemap_gen_mark_dirty( 'posts:' . $post->post_type );
    }
}
add_action( 'before_delete_post', 'ace_sitemap_gen_on_before_delete', 5 );
add_action( 'wp_trash_post', 'ace_sitemap_gen_on_before_delete', 5 );

function ace_sitemap_gen_on_noindex_meta( $meta_id, $post_id, $meta_key, $meta_value ) {
    if ( ! in_array( $meta_key, array( '_ace_seo_meta-robots-noindex', '_yoast_wpseo_meta-robots-noindex' ), true ) ) {
        return;
    }

    $post = get_post( $post_id );
    if ( ! $post || 'publish' !== $post->post_status ) {
        return;
    }

    $noindex = 'deleted_post_meta' !== current_action() && '1' === (string) $meta_value;
    if ( $noindex ) {
        ace_sitemap_gen_withhold( get_permalink( $post ) );
    } else {
        ace_sitemap_gen_release( get_permalink( $post ) );
    }
    ace_sitemap_gen_mark_dirty( 'posts:' . $post->post_type );
}
add_action( 'added_post_meta', 'ace_sitemap_gen_on_noindex_meta', 10, 4 );
add_action( 'updated_post_meta', 'ace_sitemap_gen_on_noindex_meta', 10, 4 );
add_action( 'deleted_post_meta', 'ace_sitemap_gen_on_noindex_meta', 10, 4 );

function ace_sitemap_gen_on_pre_delete_term( $term_id, $taxonomy ) {
    $link = get_term_link( (int) $term_id, $taxonomy );
    if ( ! is_wp_error( $link ) ) {
        ace_sitemap_gen_withhold( $link );
    }
    ace_sitemap_gen_mark_dirty( 'taxonomies:' . $taxonomy );
}
add_action( 'pre_delete_term', 'ace_sitemap_gen_on_pre_delete_term', 5, 2 );

function ace_sitemap_gen_on_term_change( $term_id, $tt_id, $taxonomy ) {
    ace_sitemap_gen_mark_dirty( 'taxonomies:' . $taxonomy );
}
add_action( 'created_term', 'ace_sitemap_gen_on_term_change', 20, 3 );
add_action( 'edited_term', 'ace_sitemap_gen_on_term_change', 20, 3 );

function ace_sitemap_gen_on_post_change( $post_id, $post = null ) {
    $post = $post instanceof WP_Post ? $post : get_post( $post_id );
    if ( ! $post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || ! ace_sitemap_powertools_is_public_sitemap_post( $post ) ) {
        return;
    }
    ace_sitemap_gen_mark_dirty( 'posts:' . $post->post_type );
}

function ace_sitemap_gen_on_post_transition( $new_status, $old_status, $post ) {
    if ( $new_status !== $old_status && ( 'publish' === $new_status || 'publish' === $old_status ) ) {
        ace_sitemap_gen_on_post_change( $post->ID, $post );
    }
}
add_action( 'transition_post_status', 'ace_sitemap_gen_on_post_transition', 20, 3 );

function ace_sitemap_gen_on_post_save( $post_id, $post, $update ) {
    if ( $update && $post instanceof WP_Post && 'publish' === $post->post_status ) {
        ace_sitemap_gen_on_post_change( $post_id, $post );
    }
}
add_action( 'save_post', 'ace_sitemap_gen_on_post_save', 20, 3 );

function ace_sitemap_gen_on_user_change() {
    ace_sitemap_gen_mark_dirty( 'users' );
}
add_action( 'user_register', 'ace_sitemap_gen_on_user_change', 20, 0 );
add_action( 'profile_update', 'ace_sitemap_gen_on_user_change', 20, 0 );
add_action( 'deleted_user', 'ace_sitemap_gen_on_user_change', 20, 0 );

// Settings or permalink changes alter every URL.
add_action( 'update_option_permalink_structure', function () { ace_sitemap_gen_mark_dirty( 'all' ); } );
add_action( 'update_option_ace_sitemap_powertools_options', function () { ace_sitemap_gen_mark_dirty( 'all' ); } );

/* ------------------------------------------------------------------ Reading */

/**
 * The list for a key, served from the last good artifact.
 *
 * Stale artifacts are served as they are and the worker is scheduled; only a key that has
 * never been built is built in the foreground, by one request at a time. Returns null when
 * nothing can be served yet (the caller answers 503), which is never confused with an
 * empty sitemap.
 *
 * @param string   $key     Artifact key.
 * @param array    $meta    provider, subtype, page.
 * @param callable $builder Builds the list.
 * @return array|null
 */
function ace_sitemap_gen_get( $key, array $meta, callable $builder ) {
    $artifact = ace_sitemap_gen_read( $key );

    if ( $artifact ) {
        $grace = (int) apply_filters( 'ace_sitemap_generation_gone_grace', HOUR_IN_SECONDS );
        if ( ! empty( $artifact['gone'] ) && time() - (int) $artifact['gone'] > $grace ) {
            ace_sitemap_gen_note( 'gone' );
            return array();
        }

        $scopes = ace_sitemap_gen_scopes_for( $meta['provider'], $meta['subtype'] );
        if ( (int) $artifact['seq'] < ace_sitemap_gen_required_seq( $scopes ) ) {
            ace_sitemap_gen_note( 'stale' );
            $worker_home = ace_sitemap_gen_state()['worker_home'] ?? home_url( '/' );
            if ( $worker_home === home_url( '/' ) ) {
                ace_sitemap_gen_schedule();
            } else {
                // This host's lists are not the worker's to rebuild (it runs under the main
                // home URL), so one request refreshes it; the rest keep getting the stale copy.
                $lock = ace_sitemap_gen_lock( 'key-' . md5( $key ) );
                if ( $lock ) {
                    try {
                        $artifact = ace_sitemap_gen_build( $key, $meta, $builder ) ?: $artifact;
                    } finally {
                        ace_sitemap_gen_unlock( $lock );
                    }
                }
            }
        } else {
            ace_sitemap_gen_note( 'fresh' );
        }

        return ace_sitemap_gen_filter_withheld( $artifact['data'] );
    }

    // Never built: one foreground builder, everyone else waits briefly for its result.
    $lock = ace_sitemap_gen_lock( 'key-' . md5( $key ) );
    if ( ! $lock ) {
        for ( $i = 0; $i < 12; $i++ ) {
            usleep( 250000 );
            clearstatcache();
            $artifact = ace_sitemap_gen_read( $key );
            if ( $artifact ) {
                ace_sitemap_gen_note( 'waited' );
                return ace_sitemap_gen_filter_withheld( $artifact['data'] );
            }
        }
        ace_sitemap_gen_note( 'unavailable' );
        return null;
    }

    try {
        clearstatcache();
        $artifact = ace_sitemap_gen_read( $key );
        if ( ! $artifact ) {
            $artifact = ace_sitemap_gen_build( $key, $meta, $builder );
        }
    } finally {
        ace_sitemap_gen_unlock( $lock );
    }

    ace_sitemap_gen_note( 'cold' );
    return $artifact ? ace_sitemap_gen_filter_withheld( $artifact['data'] ) : null;
}

/**
 * Build and publish one artifact. Callers hold the key lock. Returns the artifact now in
 * place (which may be the previous one when the build failed) or null.
 */
function ace_sitemap_gen_build( $key, array $meta, callable $builder ) {
    $seqs     = ace_sitemap_gen_seqs();
    $previous = ace_sitemap_gen_read( $key );
    $started  = microtime( true );
    $queries  = function_exists( 'get_num_queries' ) ? get_num_queries() : 0;

    try {
        $data = call_user_func( $builder );
    } catch ( Throwable $e ) {
        ace_sitemap_gen_record_failure( $key, 'exception', get_class( $e ) );
        return $previous;
    }

    if ( ! ace_sitemap_gen_is_valid_list( $data, $meta ) ) {
        ace_sitemap_gen_record_failure( $key, 'invalid' );
        return $previous;
    }

    // Nothing to keep for a page that never had content (a mistyped or out-of-range page
    // number): answer empty without storing it, so requests cannot fill the disk.
    if ( empty( $data ) && ! $previous ) {
        return array( 'seq' => $seqs['seq'], 'gone' => 0, 'data' => array() );
    }

    $artifact = array(
        'format' => ACE_SITEMAP_GEN_FORMAT,
        'seq'    => $seqs['seq'],
        'key'    => $key,
        'home'   => home_url( '/' ),
        'meta'   => $meta,
        'built'  => time(),
        'ms'     => (int) round( ( microtime( true ) - $started ) * 1000 ),
        'q'      => ( function_exists( 'get_num_queries' ) ? get_num_queries() : 0 ) - $queries,
        'gone'   => 0,
        'data'   => array_values( $data ),
    );

    // A list that has emptied keeps its last contents for a grace period, so an index a
    // crawler fetched a moment ago never points at a page that has already vanished.
    if ( empty( $data ) && $previous && ! empty( $previous['data'] ) ) {
        $artifact['gone'] = ! empty( $previous['gone'] ) ? (int) $previous['gone'] : time();
        $artifact['data'] = $previous['data'];
    }

    if ( ! ace_sitemap_gen_write( $artifact ) ) {
        ace_sitemap_gen_record_failure( $key, 'write' );
        clearstatcache();
        return ace_sitemap_gen_read( $key );
    }

    ace_sitemap_gen_clear_failure( $key );
    return $artifact;
}

function ace_sitemap_gen_is_valid_list( $data, array $meta ) {
    if ( ! is_array( $data ) ) {
        return false;
    }

    foreach ( $data as $entry ) {
        if ( ! is_array( $entry ) || empty( $entry['loc'] ) || ! is_string( $entry['loc'] ) ) {
            return false;
        }
    }

    // The index is never legitimately empty while sitemaps are enabled.
    return ! ( 'index' === $meta['provider'] && empty( $data ) );
}

function ace_sitemap_gen_index_key() {
    return 'index';
}

function ace_sitemap_gen_url_key( $provider, $subtype, $page ) {
    return sprintf( 'url|%s|%s|%d', sanitize_key( (string) $provider ), sanitize_key( (string) $subtype ), (int) $page );
}

/** Build a list for stored meta. Used by the worker, which has no request context. */
function ace_sitemap_gen_builder_for( array $meta ) {
    $server = wp_sitemaps_get_server();
    if ( 'index' === $meta['provider'] ) {
        return function () use ( $server ) {
            return $server->index->get_sitemap_list();
        };
    }

    $provider = $server->registry->get_provider( $meta['provider'] );
    if ( ! $provider ) {
        return null;
    }

    return function () use ( $provider, $meta ) {
        return $provider->get_url_list( (int) $meta['page'], (string) $meta['subtype'] );
    };
}

/* ------------------------------------------------------------------- Worker */

function ace_sitemap_gen_schedule( $delay = null ) {
    if ( wp_next_scheduled( ACE_SITEMAP_GEN_HOOK ) ) {
        return;
    }

    // Coalesce a burst of edits into one pass. The first dirty mark sets the time and later
    // ones never push it back, so continuous edits cannot starve publication.
    $delay = null === $delay ? (int) apply_filters( 'ace_sitemap_generation_delay', MINUTE_IN_SECONDS ) : (int) $delay;
    wp_schedule_single_event( time() + max( 1, $delay ), ACE_SITEMAP_GEN_HOOK );
}

function ace_sitemap_gen_state() {
    return ace_sitemap_gen_meta_get( ACE_SITEMAP_GEN_STATE );
}

/** Apply a change to the worker state under the meta lock. */
function ace_sitemap_gen_update_state( callable $mutate ) {
    return ace_sitemap_gen_meta_update( ACE_SITEMAP_GEN_STATE, $mutate );
}

function ace_sitemap_gen_record_failure( $key, $category, $detail = '' ) {
    $wait = 0;
    ace_sitemap_gen_update_state( function ( $state ) use ( $key, $category, $detail, &$wait ) {
        $failures = isset( $state['failures'] ) && is_array( $state['failures'] ) ? $state['failures'] : array();
        $count    = (int) ( $failures[ $key ]['n'] ?? 0 ) + 1;
        // Exponential backoff with jitter, capped at an hour.
        $wait     = min( HOUR_IN_SECONDS, MINUTE_IN_SECONDS * ( 2 ** min( $count - 1, 6 ) ) ) + wp_rand( 0, 30 );

        $failures[ $key ] = array( 'n' => $count, 'next' => time() + $wait, 'cat' => $category );
        if ( count( $failures ) > 200 ) {
            $failures = array_slice( $failures, -200, null, true );
        }

        $state['failures']     = $failures;
        $state['last_failure'] = array( 'at' => time(), 'cat' => $category, 'detail' => substr( (string) $detail, 0, 80 ) );
        return $state;
    } );

    static $logged = 0;
    if ( $logged++ < 3 ) {
        error_log( sprintf( 'Ace-Crawl-Enhancer sitemap build failed: %s (%s%s), retry in %ds', $category, strtok( $key, '|' ), $detail ? ' ' . $detail : '', $wait ) );
    }
}

function ace_sitemap_gen_clear_failure( $key ) {
    if ( isset( ace_sitemap_gen_state()['failures'][ $key ] ) ) {
        ace_sitemap_gen_update_state( function ( $state ) use ( $key ) {
            unset( $state['failures'][ $key ] );
            return $state;
        } );
    }
}

/**
 * Scan the store for stale artifacts belonging to this home URL.
 *
 * @return array{dirty: array, total: int, min_seq: int}
 */
function ace_sitemap_gen_scan() {
    $seqs     = ace_sitemap_gen_seqs();
    $state    = ace_sitemap_gen_state();
    $home     = home_url( '/' );
    $dirty    = array();
    $total    = 0;
    $min_seq  = PHP_INT_MAX;
    $grace    = (int) apply_filters( 'ace_sitemap_generation_gone_grace', HOUR_IN_SECONDS );

    foreach ( (array) glob( ace_sitemap_gen_dir() . '/*.json' ) as $file ) {
        $artifact = ace_sitemap_gen_read_header( $file );
        if ( ! $artifact ) {
            continue;
        }

        if ( $artifact['home'] !== $home ) {
            continue; // Another host's artifacts; rebuilt by its own requests.
        }

        if ( ! empty( $artifact['gone'] ) && time() - (int) $artifact['gone'] > $grace * 2 ) {
            @unlink( $file );
            continue;
        }

        $total++;
        $min_seq  = min( $min_seq, (int) $artifact['seq'] );
        $meta     = $artifact['meta'];
        $required = ace_sitemap_gen_required_seq( ace_sitemap_gen_scopes_for( $meta['provider'], $meta['subtype'] ), $seqs );
        if ( (int) $artifact['seq'] >= $required ) {
            continue;
        }

        // The index depends on every change, and on some sites it is the costliest list to
        // build, so it is refreshed at most every few minutes however busy the site is. Other
        // expensive providers can ask for the same through ace_sitemap_generation_min_age.
        $min_age = 'index' === $meta['provider']
            ? (int) apply_filters( 'ace_sitemap_generation_index_min_age', 5 * MINUTE_IN_SECONDS )
            : 0;
        $min_age    = (int) apply_filters( 'ace_sitemap_generation_min_age', $min_age, $meta['provider'], $meta['subtype'] );
        $not_before = $min_age > 0 ? (int) $artifact['built'] + $min_age : 0;

        $failure = $state['failures'][ $artifact['key'] ] ?? null;
        if ( $failure ) {
            $not_before = max( $not_before, (int) $failure['next'] );
        }

        $dirty[] = array(
            'key'        => $artifact['key'],
            'meta'       => $meta,
            'waiting'    => $not_before > time(),
            'not_before' => $not_before,
            'order'      => 'index' === $meta['provider'] ? 1 : 0,
        );
    }

    // Pages first, the index last, so a new index only ever points at rebuilt pages.
    usort( $dirty, function ( $a, $b ) {
        return $a['order'] <=> $b['order'] ?: strcmp( $a['key'], $b['key'] );
    } );

    return array( 'dirty' => $dirty, 'total' => $total, 'min_seq' => PHP_INT_MAX === $min_seq ? 0 : $min_seq );
}

/**
 * One bounded pass of the worker: rebuild stale artifacts until the time, memory or count
 * budget runs out, then yield and reschedule. One worker per site; an optional host-wide
 * lock serialises several sites on one machine.
 *
 * @return array Summary of the pass.
 */
function ace_sitemap_gen_run( $args = array() ) {
    $summary = array( 'built' => 0, 'failed' => 0, 'remaining' => 0, 'skipped' => '' );

    if ( ! ace_sitemap_gen_enabled() ) {
        $summary['skipped'] = 'disabled';
        return $summary;
    }

    $state = ace_sitemap_gen_state();
    if ( ! empty( $state['paused'] ) && empty( $args['force'] ) ) {
        $summary['skipped'] = 'paused';
        return $summary;
    }

    $worker = ace_sitemap_gen_lock( 'worker' );
    if ( ! $worker ) {
        $summary['skipped'] = 'busy';
        return $summary;
    }

    // Optional host-wide budget: an operator points every site at one shared lock file.
    $shared      = null;
    $shared_path = (string) apply_filters( 'ace_sitemap_generation_shared_lock', defined( 'ACE_SITEMAP_SHARED_LOCK' ) ? ACE_SITEMAP_SHARED_LOCK : '' );
    if ( '' !== $shared_path ) {
        $shared = ace_sitemap_gen_lock( 'shared', $shared_path );
        if ( ! $shared ) {
            ace_sitemap_gen_unlock( $worker );
            ace_sitemap_gen_schedule( wp_rand( 30, 90 ) );
            $summary['skipped'] = 'host-busy';
            return $summary;
        }
    }

    $next_due     = 0;
    $time_budget  = (float) apply_filters( 'ace_sitemap_generation_time_budget', 20 );
    $max_builds   = (int) apply_filters( 'ace_sitemap_generation_batch_size', 50 );
    $memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
    $started      = microtime( true );

    try {
        $scan = ace_sitemap_gen_scan();
        foreach ( $scan['dirty'] as $item ) {
            if ( $item['waiting'] && empty( $args['force'] ) ) {
                $next_due = $next_due ? min( $next_due, $item['not_before'] ) : $item['not_before'];
                $summary['deferred'] = ( $summary['deferred'] ?? 0 ) + 1;
                continue;
            }

            if ( $summary['built'] + $summary['failed'] >= $max_builds
                || microtime( true ) - $started > $time_budget
                || ( $memory_limit > 0 && memory_get_usage( true ) > 0.8 * $memory_limit ) ) {
                $summary['remaining']++;
                continue;
            }

            $builder = ace_sitemap_gen_builder_for( $item['meta'] );
            if ( ! $builder ) {
                @unlink( ace_sitemap_gen_file( $item['key'] ) ); // Provider gone or disabled.
                continue;
            }

            $lock = ace_sitemap_gen_lock( 'key-' . md5( $item['key'] ) );
            if ( ! $lock ) {
                $summary['remaining']++;
                continue;
            }

            try {
                $before = ace_sitemap_gen_read( $item['key'] );
                $after  = ace_sitemap_gen_build( $item['key'], $item['meta'], $builder );
                if ( $after && ( ! $before || (int) $after['seq'] > (int) $before['seq'] ) ) {
                    $summary['built']++;
                } else {
                    $summary['failed']++;
                }
            } finally {
                ace_sitemap_gen_unlock( $lock );
            }
        }

        // Withheld URLs can go once every artifact was rebuilt after they were withheld.
        if ( ace_sitemap_gen_meta_get( ACE_SITEMAP_GEN_WITHHELD ) && 0 === $summary['remaining'] && 0 === $summary['failed'] && empty( $summary['deferred'] ) ) {
            $min_seq = ace_sitemap_gen_scan()['min_seq'];
            ace_sitemap_gen_meta_update( ACE_SITEMAP_GEN_WITHHELD, function ( $withheld ) use ( $min_seq ) {
                return array_filter( $withheld, function ( $seq ) use ( $min_seq ) {
                    return (int) $seq >= $min_seq;
                } );
            } );
        }

        // Tidy temp files left by a process that died mid-write.
        foreach ( (array) glob( ace_sitemap_gen_dir() . '/.*.tmp' ) as $tmp ) {
            if ( @filemtime( $tmp ) < time() - HOUR_IN_SECONDS ) {
                @unlink( $tmp );
            }
        }
    } finally {
        $state = ace_sitemap_gen_update_state( function ( $state ) use ( $summary, $started ) {
            $state['worker_home'] = home_url( '/' );
            $state['last_run'] = array(
                'at'        => time(),
                'ms'        => (int) round( ( microtime( true ) - $started ) * 1000 ),
                'built'     => $summary['built'],
                'failed'    => $summary['failed'],
                'remaining' => $summary['remaining'],
                'peak_mb'   => round( memory_get_peak_usage( true ) / 1048576, 1 ),
            );
            if ( $summary['built'] && ! $summary['failed'] ) {
                $state['last_success'] = time();
            }
            return $state;
        } );

        ace_sitemap_gen_unlock( $shared );
        ace_sitemap_gen_unlock( $worker );
    }

    if ( $summary['remaining'] > 0 ) {
        ace_sitemap_gen_schedule( (int) apply_filters( 'ace_sitemap_generation_yield', 30 ) );
    } elseif ( $next_due ) {
        // Only held-back work (index minimum age, retry backoff): come back when it is due.
        ace_sitemap_gen_schedule( max( 1, $next_due - time() ) );
    }

    if ( $summary['built'] > 0 || $summary['failed'] > 0 ) {
        error_log( sprintf( 'Ace-Crawl-Enhancer sitemap pass: built %d, failed %d, remaining %d, %dms', $summary['built'], $summary['failed'], $summary['remaining'], $state['last_run']['ms'] ) );
    }

    return $summary;
}
add_action( ACE_SITEMAP_GEN_HOOK, 'ace_sitemap_gen_run' );

/* ------------------------------------------------------------- Diagnostics */

/** Per-request serve outcome, as a response header and an object-cache counter. */
function ace_sitemap_gen_note( $outcome ) {
    if ( ! headers_sent() ) {
        header( 'X-Ace-Sitemap: ' . $outcome, false );
    }
    if ( wp_using_ext_object_cache() ) {
        // get + set: some object-cache drop-ins cannot incr their wrapped values. Approximate
        // under concurrency, which is fine for a diagnostic count.
        wp_cache_set( 'serve_' . $outcome, (int) wp_cache_get( 'serve_' . $outcome, 'ace_sitemap_stats' ) + 1, 'ace_sitemap_stats', DAY_IN_SECONDS );
    }
}

function ace_sitemap_gen_status() {
    $state = ace_sitemap_gen_state();
    $scan  = ace_sitemap_gen_enabled() ? ace_sitemap_gen_scan() : array( 'dirty' => array(), 'total' => 0 );
    $seqs  = ace_sitemap_gen_seqs();

    $counts = array();
    foreach ( array( 'fresh', 'stale', 'cold', 'waited', 'unavailable', 'gone' ) as $outcome ) {
        $counts[ $outcome ] = wp_using_ext_object_cache() ? (int) wp_cache_get( 'serve_' . $outcome, 'ace_sitemap_stats' ) : null;
    }

    $worker_busy = false;
    if ( ace_sitemap_gen_enabled() ) {
        $probe = ace_sitemap_gen_lock( 'worker' );
        $worker_busy = ! $probe;
        ace_sitemap_gen_unlock( $probe );
    }

    return array(
        'enabled'      => ace_sitemap_gen_enabled(),
        'store'        => ace_sitemap_gen_enabled() ? 'uploads/' . basename( dirname( ace_sitemap_gen_dir() ) ) . '/' . basename( ace_sitemap_gen_dir() ) : 'unavailable (object cache fallback)',
        'paused'       => ! empty( $state['paused'] ),
        'sequence'     => $seqs['seq'],
        'artifacts'    => $scan['total'],
        'stale'        => count( $scan['dirty'] ),
        'backing_off'  => count( array_filter( $scan['dirty'], function ( $d ) { return $d['waiting']; } ) ),
        'withheld'     => count( ace_sitemap_gen_meta_get( ACE_SITEMAP_GEN_WITHHELD ) ),
        'worker_busy'  => $worker_busy,
        'next_run'     => wp_next_scheduled( ACE_SITEMAP_GEN_HOOK ),
        'last_run'     => $state['last_run'] ?? null,
        'last_success' => $state['last_success'] ?? null,
        'last_failure' => $state['last_failure'] ?? null,
        'host_lock'    => (string) apply_filters( 'ace_sitemap_generation_shared_lock', defined( 'ACE_SITEMAP_SHARED_LOCK' ) ? ACE_SITEMAP_SHARED_LOCK : '' ) !== '' ? 'configured' : 'off (per-site only)',
        'served'       => $counts,
    );
}

function ace_sitemap_gen_set_paused( $paused ) {
    ace_sitemap_gen_update_state( function ( $state ) use ( $paused ) {
        $state['paused'] = (bool) $paused;
        return $state;
    } );
    if ( ! $paused ) {
        ace_sitemap_gen_schedule( 1 );
    }
}

function ace_sitemap_gen_retry_failures() {
    ace_sitemap_gen_update_state( function ( $state ) {
        $state['failures'] = array();
        return $state;
    } );
    ace_sitemap_gen_schedule( 1 );
}

function ace_sitemap_gen_render_status_panel() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $s    = ace_sitemap_gen_status();
    $when = function ( $ts ) {
        return $ts ? esc_html( human_time_diff( (int) $ts ) . ( $ts > time() ? ' from now' : ' ago' ) ) : '&ndash;';
    };

    echo '<h2>Background regeneration</h2>';
    if ( ! $s['enabled'] ) {
        echo '<p>No writable sitemap store, so sitemaps use the object cache only. Check that uploads is writable or set <code>ace_sitemap_generation_dir</code>.</p>';
        return;
    }

    $rows = array(
        'Status'          => $s['paused'] ? 'Paused' : ( $s['worker_busy'] ? 'Rebuilding now' : 'Idle' ),
        'Lists stored'    => (int) $s['artifacts'],
        'Waiting rebuild' => (int) $s['stale'] . ( $s['backing_off'] ? ' (' . (int) $s['backing_off'] . ' retrying later)' : '' ),
        'Withheld URLs'   => (int) $s['withheld'],
        'Next pass'       => $s['next_run'] && $s['next_run'] <= time() ? 'Due now (runs on the next WP-Cron tick)' : $when( $s['next_run'] ),
        'Last pass'       => $s['last_run'] ? $when( $s['last_run']['at'] ) . sprintf( ' &middot; built %d, failed %d, %d ms, %s MB peak', $s['last_run']['built'], $s['last_run']['failed'], $s['last_run']['ms'], $s['last_run']['peak_mb'] ) : '&ndash;',
        'Last success'    => $when( $s['last_success'] ),
        'Last failure'    => $s['last_failure'] ? $when( $s['last_failure']['at'] ) . ' &middot; ' . esc_html( $s['last_failure']['cat'] ) : '&ndash;',
        'Host-wide limit' => esc_html( $s['host_lock'] ),
    );

    echo '<table class="widefat striped" style="max-width:720px"><tbody>';
    foreach ( $rows as $label => $value ) {
        echo '<tr><th scope="row" style="width:180px">' . esc_html( $label ) . '</th><td>' . $value . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above.
    }
    echo '</tbody></table>';

    echo '<form method="post" style="margin-top:12px">';
    wp_nonce_field( 'ace_sitemap_gen_action', 'ace_sitemap_gen_nonce' );
    submit_button( $s['paused'] ? 'Resume' : 'Pause', 'secondary', 'ace_sitemap_gen_do[' . ( $s['paused'] ? 'resume' : 'pause' ) . ']', false );
    echo ' ';
    submit_button( 'Queue a full rebuild', 'secondary', 'ace_sitemap_gen_do[rebuild]', false );
    echo ' ';
    submit_button( 'Retry failures now', 'secondary', 'ace_sitemap_gen_do[retry]', false );
    echo '<p class="description">Rebuilds run in the background, a few lists at a time. The current sitemaps stay online throughout.</p>';
    echo '</form>';
}

function ace_sitemap_gen_handle_admin_action() {
    if ( empty( $_POST['ace_sitemap_gen_do'] ) || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    check_admin_referer( 'ace_sitemap_gen_action', 'ace_sitemap_gen_nonce' );

    $action = sanitize_key( (string) key( (array) $_POST['ace_sitemap_gen_do'] ) );
    if ( 'pause' === $action ) {
        ace_sitemap_gen_set_paused( true );
    } elseif ( 'resume' === $action ) {
        ace_sitemap_gen_set_paused( false );
    } elseif ( 'rebuild' === $action ) {
        ace_sitemap_gen_mark_dirty( 'all', true );
    } elseif ( 'retry' === $action ) {
        ace_sitemap_gen_retry_failures();
    }

    wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
    exit;
}
add_action( 'admin_init', 'ace_sitemap_gen_handle_admin_action' );

/* -------------------------------------------------- Redis Cache integration */

/**
 * Ace Redis Cache warms sitemap URLs after saves by requesting them over HTTP. With the
 * store active that request would only duplicate the queued rebuild, so sitemap URLs are
 * taken out of its queue through its public filter. Other warm-up URLs are untouched.
 */
function ace_sitemap_gen_filter_redis_prime_urls( $urls ) {
    if ( ! ace_sitemap_gen_enabled() ) {
        return $urls;
    }

    return array_values( array_filter( (array) $urls, function ( $url ) {
        $path = trim( (string) wp_parse_url( (string) $url, PHP_URL_PATH ), '/' );
        if ( '' === $path ) {
            return true;
        }
        if ( 'sitemap.xml' === $path || 0 === strpos( $path, 'wp-sitemap' ) ) {
            return false;
        }
        foreach ( array_keys( ace_sitemap_powertools_custom_routes() ) as $slug ) {
            if ( preg_match( '~^' . preg_quote( $slug, '~' ) . '(-\d+)?(\.xml)?$~', $path ) ) {
                return false;
            }
        }
        return true;
    } ) );
}
add_filter( 'ace_rc_sitemap_prime_urls', 'ace_sitemap_gen_filter_redis_prime_urls', 20 );

/* ---------------------------------------------------------------- WP-CLI */

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    /**
     * Inspect and drive background sitemap regeneration.
     *
     * ## EXAMPLES
     *
     *     wp ace-crawl sitemaps status
     *     wp ace-crawl sitemaps run            # one bounded pass, e.g. from system cron
     *     wp ace-crawl sitemaps mark-dirty --scope=all
     *     wp ace-crawl sitemaps pause | resume | retry
     */
    WP_CLI::add_command( 'ace-crawl sitemaps', function ( $args, $assoc ) {
        $sub = $args[0] ?? 'status';
        switch ( $sub ) {
            case 'run':
                WP_CLI::log( wp_json_encode( ace_sitemap_gen_run( array( 'force' => ! empty( $assoc['force'] ) ) ) ) );
                break;
            case 'mark-dirty':
                ace_sitemap_gen_mark_dirty( $assoc['scope'] ?? 'all', true );
                WP_CLI::success( 'Marked dirty; a background pass is scheduled.' );
                break;
            case 'pause':
            case 'resume':
                ace_sitemap_gen_set_paused( 'pause' === $sub );
                WP_CLI::success( ucfirst( $sub ) . 'd.' );
                break;
            case 'retry':
                ace_sitemap_gen_retry_failures();
                WP_CLI::success( 'Failures cleared; a pass is scheduled.' );
                break;
            default:
                WP_CLI::log( wp_json_encode( ace_sitemap_gen_status(), JSON_PRETTY_PRINT ) );
        }
    } );
}

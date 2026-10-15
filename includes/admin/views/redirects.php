<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$edit = absint( $_GET['post'] ?? 0 );
$search = sanitize_text_field( wp_unslash( $_GET['source_search'] ?? '' ) );
$filter = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
$query = new WP_Query( array( 'post_type' => $types, 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ), 'posts_per_page' => 30, 'paged' => $page, 's' => $filter,
    'meta_query' => array( array( 'key' => AceSeoRetentionActions::META_REDIRECT, 'value' => '', 'compare' => '!=' ) ), 'orderby' => 'ID', 'order' => 'DESC' ) );
?>
<div class="wrap ace-redirect-manager">
    <h1>Redirect Manager</h1>
    <p>Send readers from an old post to a relevant replacement. Choose a permanent move or a temporary redirect, with an optional stop date.</p>
    <div class="ace-redirect-layout">
    <section class="ace-redirect-card" aria-labelledby="ace-redirect-editor-title">
        <h2 id="ace-redirect-editor-title"><?php echo $edit ? 'Edit redirect' : 'Add redirects'; ?></h2>
        <?php if ( ! $edit ) : ?>
        <form method="get">
            <input type="hidden" name="page" value="ace-seo-redirects">
            <label for="source-search">Find source posts by title or content</label>
            <p><input type="search" class="regular-text" id="source-search" name="source_search" value="<?php echo esc_attr( $search ); ?>" required> <button class="button">Find posts</button></p>
        </form>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'ace_seo_redirect_save' ); ?>
            <input type="hidden" name="action" value="ace_seo_redirect_save">
            <?php if ( $edit && current_user_can( 'edit_post', $edit ) ) : ?>
                <input type="hidden" name="ids[]" value="<?php echo (int) $edit; ?>">
                <p><strong><?php echo esc_html( get_the_title( $edit ) ); ?></strong><br><code><?php echo esc_html( get_permalink( $edit ) ); ?></code></p>
            <?php elseif ( $search ) :
                $sources = new WP_Query( array( 'post_type' => $types, 'post_status' => 'publish', 's' => $search, 'posts_per_page' => 50, 'orderby' => 'title', 'order' => 'ASC' ) ); ?>
                <fieldset class="ace-redirect-sources"><legend>Select source posts (up to 50)</legend>
                <?php foreach ( $sources->posts as $source ) : if ( ! current_user_can( 'edit_post', $source->ID ) ) { continue; } ?>
                    <label><input type="checkbox" name="ids[]" value="<?php echo (int) $source->ID; ?>"> <?php echo esc_html( $source->post_title ?: '#' . $source->ID ); ?><br><small><?php echo esc_html( wp_make_link_relative( get_permalink( $source ) ) ); ?><?php echo get_post_meta( $source->ID, AceSeoRetentionActions::META_REDIRECT, true ) ? ' (has a rule; saving replaces it)' : ''; ?></small></label>
                <?php endforeach; ?>
                <?php if ( ! $sources->posts ) : ?><p>No matching published posts.</p><?php endif; ?>
                </fieldset>
                <?php if ( $sources->found_posts > 50 ) : ?><p>Showing the first 50 matches. Narrow the search to find more.</p><?php endif; ?>
            <?php else : ?><p class="description">Find and select posts above, or enter a source post ID below.</p><?php endif; ?>
            <?php if ( ! $edit && ! $search ) : ?><p><label for="source-id">Source post ID</label><br><input type="number" min="1" id="source-id" name="ids[]" required></p><?php endif; ?>
            <?php AceSeoRedirects::fields( $edit ); ?>
            <p><button class="button button-primary" name="operation" value="save">Save redirects</button>
            <?php if ( $edit ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ace-seo-redirects' ) ); ?>">Cancel</a><?php endif; ?></p>
        </form>
    </section>
    <section class="ace-redirect-card ace-redirect-list" aria-labelledby="ace-redirect-list-title">
        <h2 id="ace-redirect-list-title">Existing redirects (<?php echo (int) $query->found_posts; ?>)</h2>
        <form method="get"><input type="hidden" name="page" value="ace-seo-redirects"><p><label for="redirect-search" class="screen-reader-text">Search existing source posts</label><input type="search" id="redirect-search" name="s" value="<?php echo esc_attr( $filter ); ?>" placeholder="Search source posts"> <button class="button">Search redirects</button></p></form>
        <div class="ace-redirect-table"><table class="widefat striped"><thead><tr><th>Source / destination</th><th>Type / state</th><th>Age / expiry</th><th>Manage</th></tr></thead><tbody>
        <?php foreach ( $query->posts as $post ) : $s = AceSeoRedirects::settings( $post->ID ); ?>
        <tr>
            <td><strong><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( $post->post_title ?: '#' . $post->ID ); ?></a></strong><br><small><?php echo esc_html( wp_make_link_relative( get_permalink( $post ) ) ); ?></small><br>→ <?php echo esc_html( 'gone' === $s['target'] ? '410 Gone' : $s['target'] ); ?></td>
            <td><?php echo 'gone' === $s['target'] ? '410' : (int) $s['code']; ?><br><strong><?php echo esc_html( AceSeoRedirects::state( $s ) ); ?></strong><?php if ( 'publish' !== $post->post_status ) : ?><br><?php echo esc_html( $post->post_status ); ?> (not served)<?php endif; ?></td>
            <td><?php echo esc_html( $s['created'] ? human_time_diff( $s['created'] ) . ' since added' : 'Start date unknown (existing rule)' ); ?><br><?php echo esc_html( $s['expires'] ? wp_date( 'j M Y, H:i T', $s['expires'] ) : 'No expiry' ); ?><?php if ( $s['trash'] ) : ?><br>Trash at expiry<?php endif; ?><?php if ( $s['error'] ) : ?><p class="ace-redirect-error"><?php echo esc_html( $s['error'] ); ?></p><?php endif; ?></td>
            <td><a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ace-seo-redirects', 'post' => $post->ID ), admin_url( 'admin.php' ) ) ); ?>">Edit</a>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'ace_seo_redirect_save' ); ?><input type="hidden" name="action" value="ace_seo_redirect_save"><input type="hidden" name="ids[]" value="<?php echo (int) $post->ID; ?>">
                <?php if ( 'Expired' !== AceSeoRedirects::state( $s ) && 'publish' === $post->post_status ) : ?><button class="button button-small" name="operation" value="<?php echo $s['paused'] ? 'resume' : 'pause'; ?>"><?php echo $s['paused'] ? 'Resume' : 'Pause'; ?></button><?php endif; ?>
                <button class="button button-small" name="operation" value="clear">Clear rule</button></form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if ( ! $query->posts ) : ?><tr><td colspan="4">No redirects found.</td></tr><?php endif; ?>
        </tbody></table></div>
        <p><?php echo wp_kses_post( (string) paginate_links( array( 'base' => add_query_arg( array( 'page' => 'ace-seo-redirects', 's' => $filter, 'paged' => '%#%' ), admin_url( 'admin.php' ) ), 'format' => '', 'current' => $page, 'total' => $query->max_num_pages ) ) ); ?></p>
        <p class="description">Clearing a rule leaves the post untouched. Expired rules stay listed for review. Restore a trashed post separately if you want it published again.</p>
    </section>
    </div>
</div>

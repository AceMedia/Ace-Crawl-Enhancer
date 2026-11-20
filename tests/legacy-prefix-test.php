<?php
/** Standalone source-only upgrade regression: no WordPress database is used. */
define( 'ABSPATH', __DIR__ );
$values = array( 'ace_seo_settings' => array( 'site_title' => 'Saved title', 'nested' => array( 'preserve' => true ) ), 'ace_seo_social_settings' => array( 'keep' => 'old' ), 'acecrawl_social_settings' => array( 'keep' => 'new' ), 'ace_seo_unrelated_plugin' => 'untouched' );
$autoloads = array();
function get_option( $key, $default = false ) { global $values; return $values[ $key ] ?? $default; }
function add_option( $key, $value, $deprecated = '', $autoload = null ) { global $values, $autoloads; if ( array_key_exists( $key, $values ) ) { return false; } $values[ $key ] = $value; $autoloads[ $key ] = $autoload; return true; }
function update_option( $key, $value, $autoload = null ) { global $values; $values[ $key ] = $value; return true; }
function maybe_unserialize( $value ) { return unserialize( $value ); }
function is_multisite() { return false; }
class AceCrawlMigrationDatabase {
    public $options = 'options';
    public function get_col( $query ) { global $values; return array_keys( $values ); }
    public function prepare( $query, $key ) { return array( $query, $key ); }
    public function get_row( $prepared ) { global $values; return isset( $values[ $prepared[1] ] ) ? (object) array( 'option_value' => serialize( $values[ $prepared[1] ] ), 'autoload' => 'off' ) : null; }
    public function get_var( $prepared ) { global $values; return array_key_exists( $prepared[1], $values ) ? 1 : null; }
}
$wpdb = new AceCrawlMigrationDatabase();
require dirname( __DIR__ ) . '/compat/legacy-names.php';
$check = static function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } echo "PASS $message\n"; };
$original = $values;
AceCrawlLegacyNames::migrate();
$check( $values['acecrawl_settings'] === $original['ace_seo_settings'], 'Saved nested settings survive the source-only prefix migration.' );
$check( $autoloads['acecrawl_settings'] === 'no', 'Migration preserves non-autoloaded storage.' );
$check( $values['acecrawl_social_settings'] === $original['acecrawl_social_settings'], 'Migration preserves an existing setting under the new name.' );
foreach ( $original as $key => $value ) { $check( $values[ $key ] === $value, "Migration retains original $key for rollback." ); }
$once = $values;
AceCrawlLegacyNames::migrate();
$check( $once === $values, 'Repeating migration is idempotent.' );
class AceCrawlAssetRegistryFixture {
    public $registered = array( 'acecrawl-savebar' => true, 'acecrawl-admin' => true, 'ace-seo-admin' => 'another owner' );
    public function add( $handle, $source, $dependencies, $version ) { $this->registered[ $handle ] = $dependencies; }
}
$GLOBALS['wp_scripts'] = new AceCrawlAssetRegistryFixture();
AceCrawlLegacyNames::assets();
$check( array( 'acecrawl-savebar' ) === $GLOBALS['wp_scripts']->registered['ace-savebar'], 'Old suite savebar enqueues the new handle without loading another script.' );
$check( 'another owner' === $GLOBALS['wp_scripts']->registered['ace-seo-admin'], 'Source aliases never overwrite another registered handle.' );

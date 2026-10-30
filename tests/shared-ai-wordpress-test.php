<?php
/** Core AI routing, consent and failures; no credentials or network. */
require __DIR__ . '/shared-ai-connection-test.php';
$GLOBALS['multisite'] = false;
$GLOBALS['site_records'] = array();
$GLOBALS['blog'] = 1;
$s = new AceMedia\SharedAI\V1\Service();
check( 'unmanaged' === $s->status()['status'], 'No automatic connection or consent on upgrade' );
check( true === $s->save( 'wordpress', '', array( 'text' ) ), 'Explicit core connection saves without a plugin key' );
$messages = array( array( 'role' => 'system', 'content' => 'Write plainly.' ), array( 'role' => 'user', 'content' => 'A title' ) );
check( 'ace_ai_wordpress_unavailable' === $s->subscription_text( $messages )->code, 'Older WordPress fails safely without direct fallback' );
if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
    function wp_ai_client_prompt( $messages ) { $GLOBALS['core_messages'] = $messages; return new Ace_Core_Prompt_Test(); }
    function wp_supports_ai() { return $GLOBALS['core_enabled']; }
}
class Ace_Core_Prompt_Test {
    public function using_system_instruction( $text ) { $GLOBALS['core_instruction'] = $text; return $this; }
    public function is_supported_for_text_generation() { return $GLOBALS['core_supported']; }
    public function generate_text() { ++$GLOBALS['core_calls']; if ( $GLOBALS['core_throw'] ) { throw new RuntimeException( 'secret provider error' ); } return $GLOBALS['core_result']; }
}
$GLOBALS['core_enabled'] = true; $GLOBALS['core_supported'] = false; $GLOBALS['core_calls'] = 0; $GLOBALS['core_throw'] = false;
check( 'ace_ai_wordpress_unconfigured' === $s->subscription_text( $messages )->code, 'Missing provider does not generate or fall back' );
check( 0 === $GLOBALS['core_calls'], 'Support check makes no generation request' );
$GLOBALS['core_supported'] = true; $GLOBALS['core_result'] = 'Useful title';
check( 'Useful title' === $s->subscription_text( $messages )['message'], 'Configured core provider supplies text' );
check( 'Write plainly.' === $GLOBALS['core_instruction'], 'System instruction uses core API rather than unsupported message role' );
check( array( array( 'role' => 'user', 'parts' => array( array( 'type' => 'text', 'text' => 'A title' ) ) ) ) === $GLOBALS['core_messages'], 'OpenAI message content is converted to the core parts schema' );
$GLOBALS['core_result'] = new WP_Error( 'provider_failed', 'Failed' );
check( 'provider_failed' === $s->subscription_text( $messages )->code, 'Core provider error is returned without another provider' );
$GLOBALS['core_throw'] = true;
check( 'ace_ai_wordpress_failed' === $s->subscription_text( $messages )->code, 'Provider exception becomes a safe error' );
$GLOBALS['core_throw'] = false; $GLOBALS['core_result'] = '';
check( 'ace_ai_response_failed' === $s->subscription_text( $messages )->code, 'Empty success is rejected' );
$GLOBALS['core_enabled'] = false;
check( 'ace_ai_wordpress_unavailable' === $s->subscription_text( $messages )->code, 'Core environment disable is honoured' );
$GLOBALS['core_enabled'] = true;
check( true === $s->save( 'wordpress', '', array( 'images' ) ), 'Unsupported feature does not imply text consent' );
check( 'ace_ai_feature_disabled' === $s->subscription_text( $messages )->code, 'Unticked text prevents generation' );
check( is_wp_error( $s->api_key( 'images', 'legacy-key' ) ), 'Core mode cannot leak into an old image API connection' );
check( true === $s->save( 'disabled', '', array() ), 'Disabled remains an explicit stop' );
check( ! $s->subscription_selected(), 'Disabled never selects core automatically' );
check( true === $s->save( 'own', 'sk-existing', array( 'text' ) ), 'Existing explicit API mode stays available' );
check( ! $s->subscription_selected() && 'sk-existing' === $s->api_key( 'text' ), 'Explicit API connection is not replaced by core availability' );
echo "WordPress AI checks passed ($count total)\n";

function ace_ai_connection_service() { return $GLOBALS['core_service']; }
function wp_strip_all_tags( $text ) { return strip_tags( $text ); }
function apply_filters( $hook, $value, ...$args ) { return $value; }
class WP_Post { public $post_title = 'A sample post'; }
$GLOBALS['core_service'] = $s;
require __DIR__ . '/../includes/class-ace-seo-whitehat.php';
$s->save( 'wordpress', '', array( 'text' ) );
$GLOBALS['core_result'] = '{"white_hat":true,"reasons":["Informational"]}';
$method = new ReflectionMethod( 'AceSeoWhiteHat', 'ask_ai' );
$method->setAccessible( true );
$answer = $method->invoke( null, new WP_Post(), 'A factual excerpt.', array( 'provider' => 'wordpress', 'model' => '', 'api_key' => '' ) );
check( ! is_wp_error( $answer ) && true === $answer['white_hat'], 'White hat core selection works without direct model or key' );
$s->save( 'disabled', '', array() );
check( is_wp_error( $method->invoke( null, new WP_Post(), 'A factual excerpt.', array( 'provider' => 'wordpress', 'model' => '', 'api_key' => '' ) ) ), 'White hat selection honours shared connection disable' );
echo "White hat core integration checks passed\n";

$s->save( 'wordpress', '', array( 'text' ) );
$adopt = new ReflectionMethod( AceMedia\SharedAI\V1\Service::class, 'codex_adopt' );
$adopt->setAccessible( true );
$adopt->invoke( $s, array( 'connected' => true, 'account' => 'stale session' ), false );
check( 'wordpress' === $s->status()['provider'], 'Polling an older Codex session cannot replace the selected core provider' );
check( is_wp_error( $s->codex_login_start() ), 'Switching away from core requires changing the explicit connection first' );
echo "Core selection protection checks passed\n";
function add_action( ...$args ) {}
require __DIR__ . '/../includes/admin/class-ace-seo-api-helper.php';
$GLOBALS['core_result'] = 'Editor suggestion';
check( 'Editor suggestion' === AceSEOApiHelper::make_openai_request( 'Suggest a title' ), 'Editor helper routes through core without direct HTTP' );
$GLOBALS['core_supported'] = false;
check( is_wp_error( AceSEOApiHelper::make_openai_request( 'Suggest a title' ) ), 'Editor helper returns unavailable core provider without direct HTTP' );
echo "Editor core integration checks passed\n";

$s->save( 'disabled', '', array() );
$adopt->invoke( $s, array( 'connected' => true, 'account' => 'stale session' ), false );
check( 'disabled' === $s->status()['status'], 'Polling an old Codex session cannot undo explicit AI disable' );
echo "Disabled connection consent checks passed\n";

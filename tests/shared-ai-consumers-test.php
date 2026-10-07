<?php
require __DIR__ . '/shared-ai-connection-test.php';
function add_action(...$args) {}
function ace_ai_connection_service() { return $GLOBALS['svc']; }
function sanitize_key($s) { return $s; }
function sanitize_text_field($s) { return $s; }
function sanitize_textarea_field($s) { return $s; }
function __($s,$domain='') { return $s; }
function absint($v) { return abs((int)$v); }
function wp_json_encode($v) { return json_encode($v); }
function wp_remote_request($url,$args) { $GLOBALS['http'][]=array($url,$args); return array('body'=>'{"choices":[{"message":{"content":"mock reply"}}]}','response'=>array('code'=>200)); }
function wp_remote_retrieve_response_code($r) { return $r['response']['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
$GLOBALS['svc'] = new AceMedia\SharedAI\V1\Service();
$engagement = $argv[1] ?? '';
if ( ! is_dir( $engagement . '/includes/AI' ) ) { throw new RuntimeException( 'Pass the local Adaptive Customer Engagement plugin directory.' ); }
$base = rtrim( $engagement, '/' ) . '/includes/AI/';
foreach(array('ChatCompletionClient','OpenAIClient','AnthropicClient','ChatClientFactory') as $file) require $base.$file.'.php';
require __DIR__ . '/../includes/admin/class-ace-seo-api-helper.php';
use ACE\AdaptiveCustomerEngagement\AI\ChatClientFactory;
use ACE\AdaptiveCustomerEngagement\AI\OpenAIClient;
$GLOBALS['http']=array();
$r=ChatClientFactory::resolve(array('provider'=>'anthropic','anthropic_api_key'=>'legacy-paid'));
check($r['provider']==='openai' && $r['api_key']==='sk-single','Engagement resolves same managed shared key');
check(AceSEOApiHelper::get_openai_key()==='sk-single','SEO resolves same shared key');
check(AceSEOApiHelper::get_openai_key('images')==='','SEO images require explicit feature permission');
$GLOBALS['svc']->save('disabled','',array());
$r=(new OpenAIClient())->create_chat_completion(array(array('role'=>'user','content'=>'hello')),array('api_key'=>'legacy-paid'));
check(is_wp_error($r),'Direct Engagement client cannot bypass disabled policy');
check(count($GLOBALS['http'])===0,'Disabled produces no outbound HTTP');
check(AceSEOApiHelper::get_openai_key()==='','Disabled SEO produces no credential');
$GLOBALS['svc']->save('own','sk-single',array('text'));
$r=(new OpenAIClient())->create_chat_completion(array(array('role'=>'user','content'=>'hello')),array('api_key'=>'legacy-paid'));
check(!is_wp_error($r),'Mocked text completion succeeds');
check($GLOBALS['http'][0][1]['headers']['Authorization']==='Bearer sk-single','Outbound request uses shared scope not caller legacy key');
echo "8 cross-plugin integration checks passed; all HTTP mocked\n";

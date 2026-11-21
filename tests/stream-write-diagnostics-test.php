<?php
/** Native offline filesystem checks. No WordPress database, HTTP, mail or cron. */
define('ABSPATH', __DIR__ . '/');
function add_action(...$args) {}
function add_filter(...$args) {}
function wp_delete_file($path) { if (is_file($path)) unlink($path); }
require dirname(__DIR__) . '/includes/ace-sitemap-generations.php';
$dir = sys_get_temp_dir() . '/ace-stream-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$log = $dir . '/diagnostic.log';
$oldLog = ini_set('error_log', $log);
$checks = 0;
function check_stream($ok, $label) {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    ++$checks;
    echo 'PASS ', $label, "\n";
}
try {
    $file = $dir . '/last-good';
    check_stream(acecrawl_sitemap_gen_save_bytes($file, 'old') && file_get_contents($file) === 'old', 'atomic save publishes complete bytes');
    check_stream(acecrawl_sitemap_gen_write_bytes($dir . '/empty', '') === 0, 'empty successful write is distinct from false');
    if (PHP_VERSION_ID >= 80600) {
        $context = stream_context_create(['stream'=>['error_mode'=>StreamErrorMode::Silent, 'error_store'=>StreamErrorStore::All]]);
        file_get_contents($dir . '/seed-private-token', false, $context);
        // Each retrieval returns fresh StreamError objects: compare their values, not identity.
        $before = stream_last_errors();
        check_stream(count($before) > 0, 'fixture seeds unrelated global stream errors');
    }
    check_stream(acecrawl_sitemap_gen_write_bytes($dir . '/missing/private-token', 'new') === false, 'native open failure preserves false return');
    check_stream(file_get_contents($file) === 'old', 'failure leaves the last good artifact intact');
    $message = file_get_contents($log);
    check_stream(strpos($message, 'write_failed') !== false && strpos($message, $dir) === false && strpos($message, 'private-token') === false, 'diagnostic retains reason without path or payload');
    if (PHP_VERSION_ID >= 80600) {
        check_stream(strpos($message, 'stream_errors=OpenFailed') !== false, 'native PHP 8.6 callback reports the bounded error code');
        check_stream(stream_last_errors() == $before, 'failed write does not replace or clear unrelated global errors');
    } else {
        check_stream(strpos($message, 'stream_errors=') === false, 'older PHP uses safe generic fallback');
    }
    check_stream(acecrawl_sitemap_gen_write_bytes($dir . '/good', 'later') === 5, 'successful operation after failure still writes full bytes');
    check_stream(file_get_contents($log) === $message, 'successful operation never reports stale prior errors');
    if (PHP_VERSION_ID >= 80600) check_stream(stream_last_errors() == $before, 'successful write also preserves global errors');
    acecrawl_sitemap_gen_storage_error('redaction_probe', ['stream_error_codes'=>['OpenFailed', 'private-token/path', str_repeat('A', 100), 123]]);
    $message = file_get_contents($log);
    check_stream(strpos($message, 'redaction_probe stream_errors=OpenFailed') !== false && strpos($message, 'private-token') === false && strpos($message, str_repeat('A', 100)) === false, 'diagnostic validates and bounds codes before logging');
    echo "Completed $checks native stream checks on PHP ", PHP_VERSION, "\n";
} finally {
    ini_set('error_log', $oldLog);
    foreach (glob($dir . '/*') as $path) unlink($path);
    rmdir($dir);
}

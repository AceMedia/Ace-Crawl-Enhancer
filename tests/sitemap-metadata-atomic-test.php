<?php
/** Offline filesystem fault injection. No WordPress data, HTTP or scheduled jobs. */
namespace AceCrawl_Metadata_Test;
\define('ABSPATH', __DIR__ . '/');
$root = \sys_get_temp_dir() . '/ace-sitemap-meta-' . \bin2hex(\random_bytes(6));
\mkdir($root, 0700); $mode = 'normal'; $checks = 0; $messages = []; $temporary = [];
function add_action(...$args) {}
function add_filter(...$args) {}
function acecrawl_sitemap_is_enabled($setting) { return true; }
function wp_upload_dir(...$args) { global $root; return ['basedir'=>$root . '/uploads']; }
function get_current_blog_id() { return 1; }
function trailingslashit($value) { return \rtrim($value, '/') . '/'; }
function untrailingslashit($value) { return \rtrim($value, '/'); }
function apply_filters($name, $value) { return $value; }
function wp_mkdir_p($path) { return \is_dir($path) || \mkdir($path, 0755, true); }
function wp_json_encode($value, $flags = 0) { global $mode; return $mode === 'encode' ? false : \json_encode($value, $flags); }
function wp_generate_password(...$args) { return \bin2hex(\random_bytes(4)); }
function wp_delete_file($path) { if (\is_file($path)) \unlink($path); }
function error_log($message) { global $messages; $messages[] = $message; return true; }
function fopen($path, $mode) { return $GLOBALS['mode'] === 'lock-open' && \basename($path) === '.lock-meta' ? false : \fopen($path, $mode); }
function flock($handle, $operation) { global $mode; return $mode === 'lock' && $operation === LOCK_EX ? false : \flock($handle, $operation); }
function tempnam($dir, $prefix) {
    global $mode, $temporary;
    if ($mode === 'temp') return false;
    $path = \tempnam($mode === 'outside' ? \sys_get_temp_dir() : $dir, $prefix); $temporary[] = $path; return $path;
}
function file_put_contents($path, $bytes, $flags = 0) {
    global $mode;
    if ($mode === 'write') return false;
    $result = \file_put_contents($path, $mode === 'short' ? \substr($bytes, 0, -1) : $bytes, $flags);
    return $mode === 'close' ? false : $result;
}
function chmod($path, $permissions) { global $mode; return $mode === 'chmod' ? false : \chmod($path, $permissions); }
function rename($from, $to) { global $mode; return $mode === 'rename' ? false : \rename($from, $to); }
function check($ok, $label) {
    global $checks;
    if (!$ok) throw new \RuntimeException('FAIL ' . $label);
    ++$checks; echo 'PASS ', $label, "\n";
}
function remove_tree($path) {
    if (\is_dir($path)) { foreach (\scandir($path) as $name) if ($name !== '.' && $name !== '..') remove_tree($path . '/' . $name); \rmdir($path); }
    elseif (\file_exists($path)) \unlink($path);
}
try {
    $source = \getenv('ACECRAWL_METADATA_SOURCE') ?: \dirname(__DIR__) . '/includes/ace-sitemap-generations.php';
    $code = \preg_replace('/^<\?php\s*/', '', \file_get_contents($source), 1);
    require_once \dirname(__DIR__) . '/includes/ace-json.php';
    $code = \str_replace("require_once __DIR__ . '/ace-json.php';", '', $code);
    eval('namespace AceCrawl_Metadata_Test;' . $code);
    \mkdir($root . '/uploads/ace-sitemaps', 0755, true);
    \file_put_contents($root . '/uploads/ace-sitemaps/index.php', '<?php // Existing protective file.');
    $dir = acecrawl_sitemap_gen_dir();
    check(\file_get_contents($root . '/uploads/ace-sitemaps/.htaccess') === "Require all denied\nDeny from all\n", 'missing access protection is installed even when index.php already exists');
    check(\file_get_contents($root . '/uploads/ace-sitemaps/index.php') === '<?php // Existing protective file.', 'existing protective file is preserved');
    $temporary = [];
    $file = $dir . '/state.meta'; $old = ['seq'=>4,'last_success'=>123]; $oldBytes = \json_encode($old);
    foreach (['short','close','write','rename','chmod','temp','outside','lock-open','lock','encode'] as $mode) {
        \file_put_contents($file, $oldBytes); \chmod($file, 0640); $mutations = 0; $committed = null;
        $result = acecrawl_sitemap_gen_meta_update('state', static function ($value) use (&$mutations) { ++$mutations; $value['seq']++; return $value; }, $committed);
        check($result === $old && \file_get_contents($file) === $oldBytes, $mode . ' failure returns and preserves the committed record');
        check($committed === false, $mode . ' failure reports no commit');
        check((\fileperms($file) & 0777) === 0640, $mode . ' failure preserves file permissions');
        foreach ($temporary as $path) check(!\file_exists($path), $mode . ' failure cleans temporary files');
        $temporary = [];
        if ($mode === 'lock-open' || $mode === 'lock') check($mutations === 0, $mode . ' failure never calls the mutator');
    }
    $mode = 'normal';
    try { acecrawl_sitemap_gen_meta_update('state', static function ($value) { throw new \RuntimeException('Synthetic mutator failure'); }); check(false, 'mutator should throw'); }
    catch (\RuntimeException $e) { check($e->getMessage() === 'Synthetic mutator failure', 'mutator exceptions propagate after unlocking'); }
    $committed = null;
    $result = acecrawl_sitemap_gen_meta_update('state', static function ($value) { $value['seq']++; return $value; }, $committed);
    check($committed === true && $result === ['seq'=>5,'last_success'=>123] && acecrawl_sitemap_gen_meta_get('state') === $result, 'complete save returns only the newly committed value');
    check((\fileperms($file) & 0777) === 0640, 'complete save preserves existing permissions');
    $result = acecrawl_sitemap_gen_meta_update('empty', static function ($value) { return []; }, $committed);
    check($committed === true && $result === [] && \file_get_contents($dir . '/empty.meta') === '[]', 'missing record safely bootstraps a legitimate empty value');
    foreach (['{broken','null','42','"wrong schema"'] as $bad) {
        \file_put_contents($file, $bad); $mutations = 0;
        $result = acecrawl_sitemap_gen_meta_update('state', static function ($value) use (&$mutations) { ++$mutations; return ['seq'=>900]; }, $committed);
        check($committed === false && $result === [] && $mutations === 0 && \file_get_contents($file) === $bad, 'corrupt/scalar record is retained and never advertised as a new generation');
    }
    check($messages && !\preg_match('/seq|last_success|\.meta|\/tmp\//', \implode("\n", $messages)), 'failure diagnostics contain bounded reasons without paths or raw metadata');
    check(\strpos(\implode("\n", $messages), 'json_error_code=4') !== false, 'invalid metadata reports the safe JSON parser code');
    if (PHP_VERSION_ID >= 80600) check((bool) \preg_match('/json_error_code=4 line=[1-9][0-9]* column=[1-9][0-9]*/', \implode("\n", $messages)), 'PHP 8.6 metadata diagnostic includes native location');
    if (\function_exists('pcntl_fork')) {
        \file_put_contents($file, '{"seq":0}'); $children = [];
        for ($i=0; $i<2; ++$i) {
            $pid = \pcntl_fork(); if ($pid === -1) throw new \RuntimeException('fork failed');
            if ($pid === 0) {
                for ($n=0; $n<10; ++$n) {
                    acecrawl_sitemap_gen_meta_update('state', static function ($value) { $value['seq']++; return $value; }, $ok);
                    if (!$ok) exit(1);
                }
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) { \pcntl_waitpid($pid, $status); check(\pcntl_wifexited($status) && \pcntl_wexitstatus($status) === 0, 'concurrent metadata writer completes'); }
        check(acecrawl_sitemap_gen_meta_get('state') === ['seq'=>20], 'blocking lock preserves both writers without lost increments');
    } else { echo "SKIP concurrent writers: pcntl unavailable\n"; }
    echo "Completed $checks metadata checks on PHP ", PHP_VERSION, "\n";
} finally { foreach ($temporary as $path) if (\is_file($path)) \unlink($path); remove_tree($root); }

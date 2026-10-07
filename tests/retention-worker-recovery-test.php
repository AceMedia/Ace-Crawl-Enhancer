<?php
/**
 * Retention build worker: lease, heartbeat, failure handling and recovery, with isolated WordPress stubs.
 * No database, cron daemon or network. Run: php tests/retention-worker-recovery-test.php
 */
if ( PHP_SAPI !== 'cli' ) {
    http_response_code( 404 );
    exit;
}

define('ABSPATH',__DIR__.'/'); define('HOUR_IN_SECONDS',3600);
$root=$argv[1]??dirname(__DIR__);
$GLOBALS['options']=array(); $GLOBALS['transients']=array(); $GLOBALS['cron']=array(); $GLOBALS['admin']=true; $GLOBALS['nonce_ok']=true;
class WorkerRedirect extends Exception {public $url;function __construct($url){$this->url=$url;}}
function get_option($key,$fallback=false){return $GLOBALS['options'][$key]??$fallback;}
function update_option($key,$value,$autoload=null){$GLOBALS['options'][$key]=$value;}
function delete_option($key){unset($GLOBALS['options'][$key]);}
function get_transient($key){return $GLOBALS['transients'][$key]??false;}
function set_transient($key,$value,$ttl){$GLOBALS['transients'][$key]=$value;}
function delete_transient($key){unset($GLOBALS['transients'][$key]);}
function apply_filters($key,$value){return $value;}
function current_user_can($cap){return $GLOBALS['admin'];}
function check_admin_referer($action){return $GLOBALS['nonce_ok'];}
function wp_die($message){throw new RuntimeException($message);}
function wp_safe_redirect($url){throw new WorkerRedirect($url);}
function admin_url($path){return 'https://ordinary-wordpress.test/wp-admin/'.$path;}
function get_current_user_id(){return 1;}
function wp_unslash($value){return $value;}
function sanitize_text_field($value){return trim(strip_tags($value));}
function sanitize_key($value){return preg_replace('/[^a-z0-9_-]/','',strtolower($value));}
function __($value,$domain=null){return $value;}
function number_format_i18n($value){return number_format($value);}
function get_post_types($args=array(),$output='names'){return $output==='objects'?array('post'=>(object)array('name'=>'post','labels'=>(object)array('name'=>'Posts'))):array('post');}
function post_type_exists($type){return 'post'===$type;}
function taxonomy_exists($type){return false;}
function current_time($type,$gmt=false){return '2026-10-07 14:00:00';}
function get_term_by($field,$value,$taxonomy){return false;}
/* In-memory WP-Cron: one entry per hook. */
function wp_next_scheduled($hook){return $GLOBALS['cron'][$hook]['when']??false;}
function wp_schedule_single_event($when,$hook){$GLOBALS['cron'][$hook]=array('when'=>$when,'recurrence'=>null);}
function wp_schedule_event($when,$recurrence,$hook){$GLOBALS['cron'][$hook]=array('when'=>$when,'recurrence'=>$recurrence);}
function wp_clear_scheduled_hook($hook){unset($GLOBALS['cron'][$hook]);}
$wpdb=new class{public $posts='wp_posts';public $options='wp_options';public $postmeta='wp_postmeta';function prepare($query,...$args){return $query;}function get_row($query){return(object)array('total'=>0,'expired'=>0);}function get_var($q){return null;}function query($q){return 0;}function esc_like($s){return $s;}function delete($t,$w){return 0;}};
require $root.'/includes/class-ace-seo-retention-actions.php';
require $root.'/includes/admin/class-ace-seo-retention-report.php';

/** The real class with the lease rows and the build steps replaced by scripted stand-ins. */
class WorkerStub extends AceSeoRetentionReport {
    public static $lock = null;
    public static $script = array();
    public static $steps = 0;
    public static $fake_error = null;
    protected static function lock_get() { if ( ! self::$lock ) { return null; } list( $o, $u ) = explode( '|', self::$lock, 2 ); return array( 'owner' => $o, 'until' => (int) $u, 'raw' => self::$lock ); }
    protected static function lock_insert( $owner, $until ) { if ( self::$lock ) { return false; } self::$lock = $owner . '|' . $until; return true; }
    protected static function lock_replace( $expected, $value ) { if ( self::$lock !== $expected ) { return false; } self::$lock = $value; return true; }
    protected static function lock_renew( $owner, $until ) { if ( self::$lock && 0 === strpos( self::$lock, $owner . '|' ) ) { self::$lock = $owner . '|' . $until; } }
    protected static function lock_delete( $owner ) { if ( '' === $owner || ( self::$lock && 0 === strpos( self::$lock, $owner . '|' ) ) ) { self::$lock = null; } }
    protected static function last_error() { return self::$fake_error; }
    protected static function tick_budget() { return 0; } // one step per tick, so each check controls the loop
    protected static function run_step() {
        self::$steps++;
        $p = self::progress();
        $what = array_shift( self::$script );
        if ( 'throw' === $what ) { throw new RuntimeException( 'simulated step failure' ); }
        $p['offset'] += 300;
        if ( 'finish' === $what ) { $p['phase'] = 'done'; $p['finished'] = time(); }
        update_option( self::PROGRESS_OPTION, $p, false );
    }
    public static function pretend_in_tick( $owner ) { self::$in_tick = true; self::$worker = $owner; }
    public static function forget_worker() { self::$worker = ''; self::$in_tick = false; }
}

$failures=0;$checks=0;
$check=static function($label,$ok)use(&$failures,&$checks){$checks++;echo($ok?'PASS ':'FAIL ').$label."\n";if(!$ok)$failures++;};
$reset=static function(){ $GLOBALS['options']=array(); $GLOBALS['cron']=array(); WorkerStub::$lock=null; WorkerStub::$script=array(); WorkerStub::$steps=0; WorkerStub::$fake_error=null; WorkerStub::forget_worker(); };
$progress=static function(){ return get_option( WorkerStub::PROGRESS_OPTION, array() ); };

/* 1. A fresh build queues a tick and the hourly watchdog. */
$reset();
WorkerStub::start();
$check( 'start queues the first tick', false !== wp_next_scheduled( WorkerStub::CRON_HOOK ) );
$check( 'start queues the hourly watchdog', 'hourly' === ( $GLOBALS['cron'][ WorkerStub::WATCH_HOOK ]['recurrence'] ?? null ) );
$check( 'fresh build is queued, not interrupted', 'queued' === WorkerStub::worker_state() );
$check( 'progress starts with a heartbeat and no failures', 0 === $progress()['tick_at'] && 0 === $progress()['failures'] );

/* 2. A tick runs steps, records a heartbeat, queues the next tick and releases its lease. */
unset( $GLOBALS['cron'][ WorkerStub::CRON_HOOK ] ); // cron removes the single event before running it
WorkerStub::$script = array( 'ok', 'ok', 'ok', 'finish' );
$p = $progress(); $p['phase'] = 'score'; $p['total'] = 1200; update_option( WorkerStub::PROGRESS_OPTION, $p );
$before = time();
for ( $i = 0; $i < 4; $i++ ) { unset( $GLOBALS['cron'][ WorkerStub::CRON_HOOK ] ); WorkerStub::run_tick(); }
$p = $progress();
$check( 'tick ran the scripted steps to completion', 'done' === $p['phase'] && 1200 === $p['offset'] && 4 === WorkerStub::$steps );
$check( 'heartbeat recorded', $p['tick_at'] >= $before && 0 === $p['failures'] && ! empty( $p['worker'] ) );
$check( 'lease released after the tick', null === WorkerStub::$lock );
$check( 'finished build queues nothing and clears the watchdog', false === wp_next_scheduled( WorkerStub::CRON_HOOK ) && false === wp_next_scheduled( WorkerStub::WATCH_HOOK ) );
$check( 'state is done', 'done' === WorkerStub::worker_state() );

/* 3. The tick that should follow a step is lost: the build is interrupted and recover() queues it again. */
$reset();
WorkerStub::start();
unset( $GLOBALS['cron'][ WorkerStub::CRON_HOOK ] );
WorkerStub::$script = array( 'ok' );
$p = $progress(); $p['phase'] = 'score'; $p['total'] = 33673; $p['offset'] = 23100; update_option( WorkerStub::PROGRESS_OPTION, $p );
WorkerStub::run_tick();
$check( 'a normal tick queues its successor before releasing the lease', false !== wp_next_scheduled( WorkerStub::CRON_HOOK ) && null === WorkerStub::$lock );
unset( $GLOBALS['cron'][ WorkerStub::CRON_HOOK ] ); // the lost continuation seen on a live site
$check( 'no tick and no lease straight after a step reads as queued (handing over), not interrupted', 'queued' === WorkerStub::worker_state() );
$check( 'recover() leaves a hand-over alone', false === WorkerStub::recover() );
$p = $progress(); $p['tick_at'] = time() - WorkerStub::LEASE_SECONDS - 1; $p['started'] = $p['tick_at'] - 60; update_option( WorkerStub::PROGRESS_OPTION, $p );
$check( 'no tick, no lease and no step for a lease period reads as interrupted', 'interrupted' === WorkerStub::worker_state() );
$check( 'recover() queues the build again', true === WorkerStub::recover() && false !== wp_next_scheduled( WorkerStub::CRON_HOOK ) );
$p = $progress();
$check( 'resume keeps the offset and the start time', 23400 === $p['offset'] && 'score' === $p['phase'] );
$check( 'resume leaves a note saying where it stopped', false !== strpos( end( $p['notes'] ), '23,400' ) );
$check( 'recover() is a no-op while queued', false === WorkerStub::recover() );
$check( 'state back to queued', 'queued' === WorkerStub::worker_state() );

/* 4. A live lease held by another worker: this tick stands down and nothing is resumed over it. */
$reset();
WorkerStub::start();
unset( $GLOBALS['cron'][ WorkerStub::CRON_HOOK ] );
$p = $progress(); $p['started'] = time() - 3600; update_option( WorkerStub::PROGRESS_OPTION, $p );
WorkerStub::$lock = 'other-host:999:abc|' . ( time() + 200 );
WorkerStub::$script = array( 'ok' );
WorkerStub::run_tick();
$check( 'a tick does not run steps over a live lease', 0 === WorkerStub::$steps );
$check( 'the other worker keeps its lease', 0 === strpos( (string) WorkerStub::$lock, 'other-host:999:abc|' ) );
$check( 'state is running while the lease is live', 'running' === WorkerStub::worker_state() );
$check( 'recover() does not queue over a live lease', false === WorkerStub::recover() && false === wp_next_scheduled( WorkerStub::CRON_HOOK ) );

/* 5. An expired lease (a worker that died) is taken over. */
WorkerStub::$lock = 'other-host:999:abc|' . ( time() - 5 );
$check( 'an expired lease reads as interrupted', 'interrupted' === WorkerStub::worker_state() );
WorkerStub::$script = array( 'ok' );
WorkerStub::run_tick();
$check( 'an expired lease is taken over and the step runs', 1 === WorkerStub::$steps && null === WorkerStub::$lock );

/* 6. Failed steps: counted, retried, and the build stops after MAX_FAILURES in a row. */
$reset();
WorkerStub::start();
unset( $GLOBALS['cron'][ WorkerStub::CRON_HOOK ] );
$p = $progress(); $p['phase'] = 'score'; $p['total'] = 900; update_option( WorkerStub::PROGRESS_OPTION, $p );
WorkerStub::$script = array( 'throw' );
WorkerStub::run_tick();
$p = $progress();
$check( 'one failed step is counted and the tick is queued again', 1 === $p['failures'] && 'score' === $p['phase'] && false !== wp_next_scheduled( WorkerStub::CRON_HOOK ) );
$check( 'the failure is described with phase and offset', false !== strpos( $p['last_error'], 'score 0/900' ) && false !== strpos( $p['last_error'], 'simulated step failure' ) );
$check( 'lease released after a failed tick', null === WorkerStub::$lock );
unset( $GLOBALS['cron'][ WorkerStub::CRON_HOOK ] );
WorkerStub::$script = array( 'ok' ); WorkerStub::run_tick();
$check( 'a successful step resets the failure count', 0 === $progress()['failures'] && 300 === $progress()['offset'] );
unset( $GLOBALS['cron'][ WorkerStub::CRON_HOOK ] );
WorkerStub::$script = array( 'throw' ); WorkerStub::run_tick();
$check( 'the count starts again from the next failure', 1 === $progress()['failures'] );
unset( $GLOBALS['cron'][ WorkerStub::CRON_HOOK ] );
WorkerStub::$script = array( 'throw' ); WorkerStub::run_tick(); unset( $GLOBALS['cron'][ WorkerStub::CRON_HOOK ] );
WorkerStub::$script = array( 'throw' ); WorkerStub::run_tick();
$p = $progress();
$check( 'three consecutive failures stop the build', 'error' === $p['phase'] && 3 === $p['failures'] );
$check( 'a stopped build queues nothing', false === wp_next_scheduled( WorkerStub::CRON_HOOK ) && false === wp_next_scheduled( WorkerStub::WATCH_HOOK ) );
$check( 'a stopped build explains itself', false !== strpos( end( $p['notes'] ), 'stopped after 3 failed attempts' ) );
$check( 'state is error and not building', 'error' === WorkerStub::worker_state() && ! WorkerStub::is_building() );
$check( 'the stopped build keeps the rows scored so far', 300 === $p['offset'] );

/* 7. The weekly job resumes an interrupted build rather than skipping it, and starts afresh after a stop. */
$reset();
WorkerStub::start();
$started = time() - 86400;
unset( $GLOBALS['cron'][ WorkerStub::CRON_HOOK ] ); unset( $GLOBALS['cron'][ WorkerStub::WATCH_HOOK ] );
$p = $progress(); $p['phase'] = 'links'; $p['offset'] = 9000; $p['started'] = $started; $p['tick_at'] = $started; update_option( WorkerStub::PROGRESS_OPTION, $p );
WorkerStub::run_weekly();
$p = $progress();
$check( 'weekly resumes the interrupted build in place', 'links' === $p['phase'] && 9000 === $p['offset'] && $started === $p['started'] && false !== wp_next_scheduled( WorkerStub::CRON_HOOK ) );
$check( 'weekly resume re-arms the watchdog', false !== wp_next_scheduled( WorkerStub::WATCH_HOOK ) );
$p['phase'] = 'error'; update_option( WorkerStub::PROGRESS_OPTION, $p ); unset( $GLOBALS['cron'][ WorkerStub::CRON_HOOK ] );
WorkerStub::run_weekly();
$check( 'weekly starts a new build after a stopped one', 'gsc' === $progress()['phase'] && 0 === $progress()['offset'] );

/* 8. A fatal error mid-tick: the shutdown handler counts it, queues the next tick and drops the lease. */
$reset();
WorkerStub::start();
unset( $GLOBALS['cron'][ WorkerStub::CRON_HOOK ] );
$p = $progress(); $p['phase'] = 'score'; $p['total'] = 33673; $p['offset'] = 23400; update_option( WorkerStub::PROGRESS_OPTION, $p );
WorkerStub::$lock = 'this-host:1:xyz|' . ( time() + 200 );
WorkerStub::pretend_in_tick( 'this-host:1:xyz' );
WorkerStub::$fake_error = array( 'type' => E_ERROR, 'message' => 'Allowed memory size exhausted', 'file' => '/srv/x.php', 'line' => 12 );
WorkerStub::on_shutdown();
$p = $progress();
$check( 'a fatal mid-step is recorded as a failure', 1 === $p['failures'] && false !== strpos( $p['last_error'], 'memory size' ) );
$check( 'a fatal mid-step queues the next tick from the saved offset', false !== wp_next_scheduled( WorkerStub::CRON_HOOK ) && 23400 === $p['offset'] );
$check( 'a fatal mid-step releases the lease', null === WorkerStub::$lock );
WorkerStub::forget_worker();
WorkerStub::$fake_error = array( 'type' => E_WARNING, 'message' => 'noise', 'file' => 'x', 'line' => 1 );
WorkerStub::pretend_in_tick( 'this-host:1:xyz' ); WorkerStub::on_shutdown();
$check( 'a warning at shutdown is not a failure', 1 === $progress()['failures'] );
WorkerStub::forget_worker();
WorkerStub::$fake_error = array( 'type' => E_ERROR, 'message' => 'late', 'file' => 'x', 'line' => 1 );
WorkerStub::on_shutdown();
$check( 'a fatal outside a tick is ignored', 1 === $progress()['failures'] );

/* 9. The admin resume action and clear(). */
$reset();
WorkerStub::start(); unset( $GLOBALS['cron'][ WorkerStub::CRON_HOOK ] );
$p = $progress(); $p['started'] = time() - 3600; update_option( WorkerStub::PROGRESS_OPTION, $p );
try { WorkerStub::handle_resume(); $check( 'resume handler redirects', false ); } catch ( WorkerRedirect $r ) { $check( 'resume handler queues the tick and redirects to the dashboard', false !== wp_next_scheduled( WorkerStub::CRON_HOOK ) && false !== strpos( $r->url, 'ace-seo-retention' ) ); }
$check( 'resume handler leaves a message', false !== strpos( (string) get_transient( 'ace_seo_retention_msg_1' ), 'continue from where it stopped' ) );
$GLOBALS['nonce_ok'] = false;
try { WorkerStub::handle_resume(); $check( 'resume refuses a bad nonce', false ); } catch ( RuntimeException $e ) { $check( 'resume refuses a bad nonce', 'Not allowed.' === $e->getMessage() ); }
$GLOBALS['nonce_ok'] = true;
WorkerStub::$lock = 'x|' . ( time() + 100 );
WorkerStub::clear();
$check( 'clear() drops the lease and both cron hooks', null === WorkerStub::$lock && false === wp_next_scheduled( WorkerStub::CRON_HOOK ) && false === wp_next_scheduled( WorkerStub::WATCH_HOOK ) && 'idle' === WorkerStub::worker_state() );

/* 10. run_all() under WP-CLI drives every step itself with one stable worker identity. */
$reset();
WorkerStub::start();
WorkerStub::$script = array_fill( 0, 9, 'ok' ); WorkerStub::$script[] = 'finish';
$p = $progress(); $p['phase'] = 'score'; $p['total'] = 3000; update_option( WorkerStub::PROGRESS_OPTION, $p );
$out = WorkerStub::run_all();
$check( 'run_all drives the build to completion', 'done' === $out['phase'] && 10 === WorkerStub::$steps );
$check( 'run_all leaves no lease or tick behind', null === WorkerStub::$lock && false === wp_next_scheduled( WorkerStub::CRON_HOOK ) );

echo "\n", $checks - $failures, ' of ', $checks, " worker recovery checks passed.\n";
exit( $failures ? 1 : 0 );

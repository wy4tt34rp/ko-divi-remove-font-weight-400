<?php
// Local SQL integration harness. WordPress HTML parser/hooks are real; site APIs
// are test doubles, and SQLite adapts MySQL BINARY comparisons and LIKE escaping.
require __DIR__ . '/verify.php';
require dirname( __DIR__ ) . '/includes/admin.php';

class TestDatabase {
	public $posts = 'posts';
	public $options = 'options';
	public $last_error = '';
	public $db;
	public $fail_backup = false;
	public $fail_update = false;
	public $race = false;
	public function __construct() {
		$this->db = new PDO( 'sqlite::memory:' );
		$this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->db->exec( 'CREATE TABLE posts (ID INTEGER PRIMARY KEY, post_title TEXT, post_content TEXT, post_excerpt TEXT, post_type TEXT, post_status TEXT)' );
		$this->db->exec( 'CREATE TABLE options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT UNIQUE, option_value TEXT, autoload INTEGER)' );
	}
	public function esc_like( $text ) { return addcslashes( $text, '_%\\' ); }
	public function prepare( $sql, ...$args ) {
		$i = 0;
		$sql = preg_replace_callback( '/%[sd]/', function ( $m ) use ( $args, &$i ) {
			$value = $args[ $i++ ];
			return '%d' === $m[0] ? (string) (int) $value : $this->db->quote( $value );
		}, $sql );
		$sql = str_replace( 'BINARY ', '', $sql );
		return preg_replace( "/(LIKE '(?:[^']|'')*')/", "$1 ESCAPE '\\'", $sql );
	}
	public function query( $sql ) {
		if ( 0 === strpos( $sql, 'UPDATE posts' ) ) {
			if ( $this->fail_update ) { return false; }
			if ( $this->race ) {
				$this->db->exec( "UPDATE posts SET post_content = 'Concurrent edit' WHERE ID = 1" );
				$this->race = false;
			}
		}
		return $this->db->exec( $sql );
	}
	public function get_results( $sql ) { return $this->db->query( $sql )->fetchAll( PDO::FETCH_OBJ ); }
	public function option( $key, $default ) {
		$q = $this->db->prepare( 'SELECT option_value FROM options WHERE option_name = ?' );
		$q->execute( array( $key ) );
		$v = $q->fetchColumn();
		return false === $v ? $default : maybe_unserialize( $v );
	}
	public function insert( $id, $type = 'post', $status = 'publish', $content = null ) {
		$q = $this->db->prepare( 'INSERT INTO posts VALUES (?, ?, ?, ?, ?, ?)' );
		$q->execute( array( $id, 'Record ' . $id, $content ?? '<p style="font-weight:400">Text</p>', '<span style="font-weight:400;color:red">Excerpt</span>', $type, $status ) );
	}
	public function content( $id ) { return $this->db->query( 'SELECT post_content FROM posts WHERE ID = ' . (int) $id )->fetchColumn(); }
}
function maybe_unserialize( $v ) { $r = @unserialize( $v ); return false === $r && 'b:0;' !== $v ? $v : $r; }
function add_option( $key, $value, $unused = '', $autoload = false ) {
	global $wpdb;
	if ( $wpdb->fail_backup && 0 === strpos( $key, KO_Font_Weight_400_Admin::PREFIX ) ) { return false; }
	$q = $wpdb->db->prepare( 'INSERT OR IGNORE INTO options(option_name, option_value, autoload) VALUES (?, ?, ?)' );
	$q->execute( array( $key, is_scalar( $value ) ? (string) $value : serialize( $value ), (int) $autoload ) );
	return 1 === $q->rowCount();
}
function update_option( $key, $value, $autoload = false ) {
	global $wpdb;
	$q = $wpdb->db->prepare( 'INSERT INTO options(option_name, option_value, autoload) VALUES (?, ?, ?) ON CONFLICT(option_name) DO UPDATE SET option_value=excluded.option_value, autoload=excluded.autoload' );
	return $q->execute( array( $key, is_scalar( $value ) ? (string) $value : serialize( $value ), (int) $autoload ) );
}
function delete_option( $key ) { global $wpdb; $q = $wpdb->db->prepare( 'DELETE FROM options WHERE option_name = ?' ); return $q->execute( array( $key ) ); }
function current_user_can( $cap, $id = null ) { return ! in_array( $id, $GLOBALS['denied'], true ) && 1 === get_current_user_id(); }
function get_current_user_id() { return $GLOBALS['user']; }
function wp_set_current_user( $id ) { $GLOBALS['user'] = $id; }
function clean_post_cache( $id ) { $GLOBALS['cleared'][] = $id; }
function wp_cache_delete( $key, $group ) {}
function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }
function wp_date( $format, $timestamp = null ) { return date( $format, $timestamp ?? time() ); }
function wp_timezone() { return new DateTimeZone( 'America/New_York' ); }
function wp_next_scheduled( $hook ) { return $GLOBALS['events'][ $hook ] ?? false; }
function wp_schedule_single_event( $timestamp, $hook, $args = array(), $error = false ) { $GLOBALS['events'][ $hook ] = $timestamp; return true; }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['events'][ $hook ] ); }
function is_wp_error( $result ) { return false; }
function ok( $condition, $message ) { global $count; ++$count; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function reset_site() {
	$GLOBALS['wpdb'] = new TestDatabase();
	$GLOBALS['user'] = 1;
	$GLOBALS['denied'] = array();
	$GLOBALS['cleared'] = array();
	$GLOBALS['events'] = array();
}
function run_all( $mode, $run ) {
	$cursor = $changed = 0;
	do {
		$r = KO_Font_Weight_400_Admin::batch( $mode, $cursor, $run );
		$cursor = $r['cursor']; $changed += $r['changed'];
	} while ( ! $r['done'] );
	return $changed;
}

reset_site();
$wpdb->insert( 1, 'page' );
$wpdb->insert( 2, 'et_pb_layout', 'draft', '[et_pb_text]<h2 style="font-weight:400">Heading</h2>[/et_pb_text]' );
$wpdb->insert( 3, 'revision', 'inherit' );
$wpdb->insert( 4, 'post', 'trash' );
$wpdb->insert( 5, 'attachment', 'inherit' );
$wpdb->insert( 6, 'post', 'auto-draft' );
$wpdb->insert( 7, 'product', 'private' );
$GLOBALS['denied'] = array( 7 );
$before = $wpdb->content( 1 );
ok( 2 === run_all( 'preview', '' ), 'Preview scope' );
ok( $before === $wpdb->content( 1 ), 'Preview must not write' );
ok( 0 === (int) $wpdb->db->query( 'SELECT COUNT(*) FROM options' )->fetchColumn(), 'Preview creates no backups' );
$run = KO_Font_Weight_400_Admin::new_run( 'Test' );
ok( 2 === run_all( 'clean', $run ), 'Cleanup scope' );
ok( false === strpos( $wpdb->content( 1 ), 'font-weight' ), 'Permanent content cleanup' );
ok( '[et_pb_text]<h2 >Heading</h2>[/et_pb_text]' === $wpdb->content( 2 ), 'Divi shortcodes preserved' );
ok( $before === $wpdb->content( 3 ) && $before === $wpdb->content( 4 ) && $before === $wpdb->content( 5 ) && $before === $wpdb->content( 6 ) && $before === $wpdb->content( 7 ), 'Excluded content unchanged' );
ok( array( 1, 2 ) === $GLOBALS['cleared'], 'Post caches invalidated' );
ok( 0 === (int) $wpdb->db->query( 'SELECT SUM(autoload) FROM options' )->fetchColumn(), 'Backups and run history never autoload' );
ok( 0 === run_all( 'clean', $run ), 'Repeated cleanup is idempotent' );
$wpdb->db->exec( "UPDATE posts SET post_content='Later edit' WHERE ID=2" );
ok( 1 === run_all( 'restore', $run ), 'Restore only matching cleaned records' );
ok( $before === $wpdb->content( 1 ), 'Restore originals' );
ok( 'Later edit' === $wpdb->content( 2 ), 'Restore preserves later edits' );
ok( 0 === run_all( 'restore', $run ), 'Repeat restore is safe' );

reset_site(); $wpdb->insert( 1 ); $wpdb->fail_backup = true;
try { run_all( 'clean', 'fail' ); throw new LogicException( 'Expected backup failure' ); } catch ( RuntimeException $e ) {}
ok( false !== strpos( $wpdb->content( 1 ), 'font-weight' ), 'Backup failure blocks write' );
$wpdb->fail_backup = false; $wpdb->fail_update = true;
try { run_all( 'clean', 'fail' ); throw new LogicException( 'Expected update failure' ); } catch ( RuntimeException $e ) {}
ok( (bool) get_option( KO_Font_Weight_400_Admin::PREFIX . 'fail_1' ), 'Write failure retains backup' );
$wpdb->fail_update = false; $wpdb->race = true;
ok( 0 === run_all( 'clean', 'fail' ), 'Concurrent edit skips update' );
ok( 'Concurrent edit' === $wpdb->content( 1 ), 'Concurrent edit retained' );

reset_site();
for ( $i = 1; $i <= 61; ++$i ) { $wpdb->insert( $i ); }
ok( 61 === run_all( 'clean', 'many' ), 'Multiple batches clean all records' );
ok( 61 === run_all( 'restore', 'many' ), 'Multiple batches restore all records' );
$first = KO_Font_Weight_400_Admin::acquire_lock();
ok( false !== $first && false === KO_Font_Weight_400_Admin::acquire_lock(), 'Mutual exclusion' );
KO_Font_Weight_400_Admin::release_lock( 'wrong-owner' );
ok( false === KO_Font_Weight_400_Admin::acquire_lock(), 'Only lock owner may release' );
KO_Font_Weight_400_Admin::release_lock( $first );
update_option( 'ko_fw400_lock', ( time() - 1000 ) . ':stale' );
$next = KO_Font_Weight_400_Admin::acquire_lock();
ok( false !== $next, 'Stale lock recovery' );
KO_Font_Weight_400_Admin::release_lock( $next );

foreach ( array( '2026-03-08 00:00:00' => 23, '2026-11-01 00:00:00' => 25 ) as $date => $hours ) {
	$now = ( new DateTimeImmutable( $date, wp_timezone() ) )->getTimestamp();
	ok( $hours * 3600 === KO_Font_Weight_400_Admin::next_midnight( $now ) - $now, 'DST midnight follows site timezone' );
}
reset_site(); $wpdb->insert( 1 );
KO_Font_Weight_400_Admin::cron();
ok( false !== strpos( $wpdb->content( 1 ), 'font-weight' ), 'Disabled cron does nothing' );
update_option( KO_Font_Weight_400_Admin::SETTINGS, array( 'enabled' => true, 'user' => 1 ) );
$GLOBALS['user'] = 0;
KO_Font_Weight_400_Admin::cron();
ok( false === strpos( $wpdb->content( 1 ), 'font-weight' ), 'Enabled cron performs cleanup' );
ok( 0 === get_current_user_id(), 'Cron restores previous user' );
ok( wp_next_scheduled( KO_Font_Weight_400_Admin::DAILY ) > time(), 'Next midnight queued' );
ok( ! get_option( KO_Font_Weight_400_Admin::STATE ), 'Completed cron clears state' );
KO_Font_Weight_400_Admin::deactivate();
ok( ! $GLOBALS['events'] && ! get_option( KO_Font_Weight_400_Admin::SETTINGS ), 'Deactivation cancels cron' );
ok( false === strpos( $wpdb->content( 1 ), 'font-weight' ), 'Deactivation leaves permanent changes' );

reset_site();
for ( $i = 1; $i <= 30; ++$i ) { $wpdb->insert( $i ); }
update_option( KO_Font_Weight_400_Admin::SETTINGS, array( 'enabled' => true, 'user' => 1 ) );
KO_Font_Weight_400_Admin::cron();
ok( (bool) get_option( KO_Font_Weight_400_Admin::STATE ) && (bool) wp_next_scheduled( KO_Font_Weight_400_Admin::STEP ), 'Cron continues large sites in batches' );
unset( $GLOBALS['events'][ KO_Font_Weight_400_Admin::STEP ] );
KO_Font_Weight_400_Admin::cron();
ok( ! get_option( KO_Font_Weight_400_Admin::STATE ) && false === strpos( $wpdb->content( 30 ), 'font-weight' ), 'Cron completes next batch' );
echo "$count total checks passed including database workflow and scheduling.\n";

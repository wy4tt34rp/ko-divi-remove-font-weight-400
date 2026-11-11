<?php
// Run against an extracted WordPress core tree, without installing a database.
define( 'ABSPATH', rtrim( $argv[1], '/' ) . '/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_PLUGIN_DIR', dirname( __DIR__, 2 ) );
define( 'WPMU_PLUGIN_DIR', '/tmp/ko-fw400-mu' );
$GLOBALS['wp_plugin_paths'] = array();
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function get_option( $name, $default = false ) {
	if ( 'blog_charset' === $name ) { return 'UTF-8'; }
	return isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb']->option( $name, $default ) : $default;
}
function is_admin() { return false; }
function wp_doing_cron() { return false; }
function is_utf8_charset( $charset = null ) { return _is_utf8_charset( $charset ?? get_option( 'blog_charset' ) ); }
require ABSPATH . WPINC . '/plugin.php';
require ABSPATH . WPINC . '/compat.php';
require ABSPATH . WPINC . '/formatting.php';
require ABSPATH . WPINC . '/kses.php';
require ABSPATH . WPINC . '/class-wp-token-map.php';
foreach ( glob( ABSPATH . WPINC . '/html-api/*.php' ) as $file ) {
	if ( false === strpos( $file, 'class-wp-html-processor.php' ) ) {
		require_once $file;
	}
}
require dirname( __DIR__ ) . '/ko-divi-remove-font-weight-400.php';
$cases = array(
	'<p style="font-weight: 400;">Text</p>' => '<p>Text</p>',
	"<h2 style=' FONT-WEIGHT : 400 !important '>Heading</h2>" => '<h2>Heading</h2>',
	'<li style="color:red;font-weight:400;margin:0">Item</li>' => '<li style="color:red;margin:0">Item</li>',
	'<span style="font-weight:700">Bold</span>' => '<span style="font-weight:700">Bold</span>',
	'<div style="font-weight:4000">Other</div>' => '<div style="font-weight:4000">Other</div>',
	'<p style="font-weight:400">MP<sup class="ko-divi-superscript">®</sup></p>' => '<p>MP<sup class="ko-divi-superscript">®</sup></p>',
	'<script>const x = \'<p style="font-weight:400">\';</script>' => '<script>const x = \'<p style="font-weight:400">\';</script>',
	'<!-- <p style="font-weight:400"> -->' => '<!-- <p style="font-weight:400"> -->',
	'<p data-copy="font-weight:400" style="font-weight:400">x</p>' => '<p data-copy="font-weight:400" >x</p>',
);
$count = 0;
function check_equal( $actual, $expected ) {
	global $count;
	++$count;
	// Core intentionally preserves whitespace when removing an attribute.
	$actual = preg_replace( '/<([a-z0-9]+)\s+>/i', '<$1>', $actual );
	$expected = preg_replace( '/<([a-z0-9]+)\s+>/i', '<$1>', $expected );
	if ( $actual !== $expected ) {
		fwrite( STDERR, "FAIL $count\nExpected: $expected\nActual: $actual\n" );
		exit( 1 );
	}
}
foreach ( $cases as $input => $expected ) {
	check_equal( KO_Divi_Remove_Font_Weight_400::clean_html( $input ), $expected );
	check_equal( KO_Divi_Remove_Font_Weight_400::clean_html( $expected ), $expected );
}
$styles = array(
	'background:url(data:image/svg+xml;base64,abcd);font-weight:400;' => 'background:url(data:image/svg+xml;base64,abcd);',
	'--copy:"a;font-weight:400;";font-weight:400;color:red' => '--copy:"a;font-weight:400;";color:red',
	'/* a;b */font-weight:400; padding:0;' => ' padding:0;',
	'font-weight:400;font-weight:700;font-weight:400!important;' => 'font-weight:700;',
	'--x:{font-weight:400;};font-weight:400;' => '--x:{font-weight:400;};',
	'font-weight:400;content:"unclosed' => 'font-weight:400;content:"unclosed',
	'font-weight:400;/* unclosed' => 'font-weight:400;/* unclosed',
	'font-weight:400;--x:(unclosed' => 'font-weight:400;--x:(unclosed',
);
foreach ( $styles as $input => $expected ) {
	check_equal( KO_Divi_Remove_Font_Weight_400::clean_style( $input ), $expected );
}
foreach ( array( 'the_content', 'et_module_shortcode_output', 'widget_text_content', 'widget_custom_html_content' ) as $hook ) {
	check_equal( apply_filters( $hook, '<h2 style="font-weight:400">Title</h2>' ), '<h2 style="font-weight:400">Title</h2>' );
}
echo "$count checks passed.\n";

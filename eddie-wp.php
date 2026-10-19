<?php
/**
 * Plugin Name:       Eddie – Frontend Editing for Clients
 * Description:       Lets a dedicated user or role edit texts, images and custom fields directly on the website while logged in. Works with Gutenberg, Bricks and ACF; everything else links straight to the right place in the backend.
 * Version:           0.1.0
 * Requires at least: 7.1
 * Requires PHP:      7.4
 * Author:            Kasoria
 * Text Domain:       eddie-frontend-editing
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Eddie
 */

namespace Eddie;

defined( 'ABSPATH' ) || exit;

define( 'EDDIE_VERSION', '0.1.0' );
define( 'EDDIE_FILE', __FILE__ );
define( 'EDDIE_DIR', plugin_dir_path( __FILE__ ) );
define( 'EDDIE_URL', plugin_dir_url( __FILE__ ) );
define( 'EDDIE_DB_VERSION', '2' );

/**
 * Autoloader: Eddie\Foo_Bar => includes/class-foo-bar.php, Eddie\Sources\Core => includes/sources/class-core.php.
 */
spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'Eddie\\' ) ) {
			return;
		}
		$segments  = explode( '\\', substr( $class_name, 6 ) );
		$file_name = 'class-' . strtolower( str_replace( '_', '-', array_pop( $segments ) ) ) . '.php';
		$directory = EDDIE_DIR . 'includes/' . ( $segments ? strtolower( implode( '/', $segments ) ) . '/' : '' );
		if ( is_readable( $directory . $file_name ) ) {
			require_once $directory . $file_name;
		}
	}
);

require_once EDDIE_DIR . 'includes/functions.php';

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Plugin::class, 'instance' ) );

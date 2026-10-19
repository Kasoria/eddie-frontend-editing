<?php
/**
 * Plugin bootstrap.
 *
 * @package Eddie
 */

namespace Eddie;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/**
	 * Registered content sources, keyed by source id.
	 *
	 * @var Source[]
	 */
	private $sources = array();

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'maybe_upgrade' ) );
		add_action( 'eddie_prune_log', array( Changes::class, 'prune' ) );

		Access::init();
		Rest::init();

		add_shortcode( 'eddie_field', array( $this, 'shortcode_field' ) );

		if ( is_admin() ) {
			Admin::init();
			Acf_Highlight::init();
		}

		// Sources and the frontend UI are only wired up for people who can actually edit.
		add_action( 'wp', array( $this, 'boot_frontend' ) );
	}

	/**
	 * [eddie_field field="headline" post_id="12" tag="span"]: always registered, so the shortcode never leaks as text.
	 *
	 * @param array|string $atts Shortcode attributes.
	 */
	public function shortcode_field( $atts ): string {
		$acf = $this->source( 'acf' );
		return $acf instanceof Sources\Acf ? $acf->shortcode( $atts ) : '';
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'eddie-frontend-editing', false, dirname( plugin_basename( EDDIE_FILE ) ) . '/languages' );
	}

	public static function activate(): void {
		Changes::install_table();
		Access::install_role();
		update_option( 'eddie_db_version', EDDIE_DB_VERSION );
		if ( ! wp_next_scheduled( 'eddie_prune_log' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'eddie_prune_log' );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'eddie_prune_log' );
	}

	public function maybe_upgrade(): void {
		if ( get_option( 'eddie_db_version' ) !== EDDIE_DB_VERSION ) {
			self::activate();
		}
	}

	/**
	 * All source classes. Third parties can add their own through the filter.
	 *
	 * @return Source[]
	 */
	public function sources(): array {
		if ( $this->sources ) {
			return $this->sources;
		}

		$candidates = array(
			new Sources\Core(),
			new Sources\Gutenberg(),
			new Sources\Bricks(),
			new Sources\Acf(),
		);

		/**
		 * Filters the list of content sources. Each item must extend Eddie\Source.
		 *
		 * @param Source[] $candidates Source instances.
		 */
		$candidates = apply_filters( 'eddie/sources', $candidates );

		foreach ( $candidates as $source ) {
			if ( $source instanceof Source ) {
				$this->sources[ $source->id() ] = $source;
			}
		}
		return $this->sources;
	}

	public function source( string $source_id ): ?Source {
		$sources = $this->sources();
		return $sources[ $source_id ] ?? null;
	}

	/**
	 * Whether the source is installed and switched on in the settings.
	 */
	public function source_enabled( Source $source ): bool {
		$enabled = Settings::get( 'sources' );
		return $source->is_available() && ( ! is_array( $enabled ) || in_array( $source->id(), $enabled, true ) );
	}

	public function boot_frontend(): void {
		if ( ! Frontend::is_editing_context() ) {
			return;
		}

		// The page carries per-user tokens: never let a page cache store or serve it.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Shared constant that page cache plugins read.
		}

		foreach ( $this->sources() as $source ) {
			if ( $this->source_enabled( $source ) ) {
				$source->init();
			}
		}

		Frontend::init();
	}
}

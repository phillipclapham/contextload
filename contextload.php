<?php
/**
 * ContextLoad — Context-aware plugin loading for WooCommerce
 *
 * Suppresses unnecessary plugin bootstrap based on request context (checkout, cart,
 * frontend, etc.) by filtering the active_plugins option before WordPress loads them.
 * Plugins not needed for the current context never get require_once'd — their PHP bootstrap cost is eliminated.
 *
 * Deploy: Copy contextload.php + contextload-config.json to wp-content/mu-plugins/
 *
 * Kill switch: define('CONTEXTLOAD_DISABLED', true) in wp-config.php
 * Debug mode:  define('CONTEXTLOAD_DEBUG', true) in wp-config.php
 *
 * @version 1.0.0
 */

if ( defined( 'CONTEXTLOAD_DISABLED' ) && CONTEXTLOAD_DISABLED ) {
	return;
}

if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
	return;
}

// v1: Single-site only. Multisite has different plugin storage (active_sitewide_plugins)
// and shared WP_CONTENT_DIR would cause cache file collisions across sites.
if ( function_exists( 'is_multisite' ) && is_multisite() ) {
	return;
}

final class ContextLoad {

	const VERSION    = '1.0.0';
	const CONFIG_FILE = 'contextload-config.json';
	const CACHE_FILE  = 'contextload-wc-cache.php';

	private static $instance;
	private $config;
	private $context;
	private $suppressed      = array();
	private $original_count  = 0;
	private $filtered_cache  = null;
	private $mode            = 'active';
	private $is_cli          = false;
	private $config_path;
	private $cache_path;

	/**
	 * Boot the loader. Called once at mu-plugin load time.
	 */
	public static function boot() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->config_path = __DIR__ . '/' . self::CONFIG_FILE;
		$this->cache_path  = WP_CONTENT_DIR . '/' . self::CACHE_FILE;
		$this->is_cli      = defined( 'WP_CLI' ) && WP_CLI;

		// Load config always (CLI commands need it too)
		$this->config = $this->load_config();
		if ( ! $this->config ) {
			return; // No config or invalid JSON = load everything (fail-open)
		}

		$this->mode = $this->config['mode'] ?? 'active';

		// Validate mode — unknown mode defaults to DISABLED (not active).
		// A config typo must fail-safe, not silently activate suppression.
		$valid_modes = array( 'active', 'dryrun', 'disabled' );
		if ( ! in_array( $this->mode, $valid_modes, true ) ) {
			$this->log( 'Unknown mode "' . $this->mode . '" — defaulting to disabled. Valid: active, dryrun, disabled.' );
			$this->mode = 'disabled';
		}

		// CLI: class defined, config loaded, but no plugin filtering (CLI needs full stack)
		if ( $this->is_cli ) {
			return;
		}

		if ( 'disabled' === $this->mode ) {
			return;
		}

		$this->context = $this->detect_context();

		// The core filter — intercepts plugin list before WordPress require_once's them
		add_filter( 'option_active_plugins', array( $this, 'filter_plugins' ), 1 );

		// After plugins load: register WC cache hooks + debug output
		add_action( 'plugins_loaded', array( $this, 'register_hooks' ), 1 );

		// Debug logging at end of request
		if ( $this->is_debug() ) {
			add_action( 'shutdown', array( $this, 'log_report' ) );
		}
	}

	// -----------------------------------------------------------------------
	// Config
	// -----------------------------------------------------------------------

	/**
	 * Load and validate the JSON config file.
	 */
	private function load_config() {
		if ( ! file_exists( $this->config_path ) ) {
			return null;
		}

		$json   = file_get_contents( $this->config_path );
		$config = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$this->log( 'Invalid config JSON: ' . json_last_error_msg() );
			return null;
		}

		if ( empty( $config['contexts'] ) || ! is_array( $config['contexts'] ) ) {
			$this->log( 'Config missing "contexts" — loading all plugins.' );
			return null;
		}

		return $config;
	}

	// -----------------------------------------------------------------------
	// Context Detection
	// -----------------------------------------------------------------------

	/**
	 * Determine request context from URL and server variables.
	 * Runs before plugins load — only raw PHP and $_SERVER available.
	 */
	private function detect_context() {
		$uri    = $_SERVER['REQUEST_URI'] ?? '';
		$script = $_SERVER['SCRIPT_NAME'] ?? '';
		$path   = parse_url( $uri, PHP_URL_PATH );
		$path   = '/' . trim( $path ?? '', '/' ) . '/'; // Normalize: /checkout/ not /checkout

		// Cron (check before admin — wp-cron.php doesn't need filtering)
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return 'cron';
		}
		if ( false !== strpos( $script, 'wp-cron.php' ) ) {
			return 'cron';
		}

		// AJAX — check before admin because admin-ajax.php sets WP_ADMIN
		if ( false !== strpos( $script, 'admin-ajax.php' ) ) {
			$action = $_REQUEST['action'] ?? '';
			return 'ajax' . ( $action ? ':' . $action : '' );
		}

		// REST API
		if ( false !== strpos( $path, '/wp-json/' ) || isset( $_GET['rest_route'] ) ) {
			return 'rest';
		}

		// WP Admin — NOT using is_admin() because WP_ADMIN may not be defined yet at
		// mu-plugin load time. SCRIPT_NAME inspection is reliable at this stage.
		if ( ( defined( 'WP_ADMIN' ) && WP_ADMIN ) || false !== strpos( $script, '/wp-admin/' ) ) {
			return 'admin';
		}

		// WooCommerce page contexts (from cached slugs or defaults)
		$slugs = $this->get_wc_slugs();

		// Split path into segments for exact matching (prevents /checkout-old/ matching /checkout/)
		$segments = array_filter( explode( '/', $path ) );

		// Order matters: checkout before cart (some setups nest checkout under cart)
		foreach ( array( 'checkout', 'cart', 'account', 'shop' ) as $wc_context ) {
			if ( ! empty( $slugs[ $wc_context ] ) ) {
				$slug = trim( $slugs[ $wc_context ], '/' );
				if ( in_array( $slug, $segments, true ) ) {
					return $wc_context;
				}
			}
		}

		// Product pages (permalink base detection)
		if ( ! empty( $slugs['product_base'] ) ) {
			$base = trim( $slugs['product_base'], '/' );
			if ( in_array( $base, $segments, true ) ) {
				return 'product';
			}
		}

		return 'frontend';
	}

	/**
	 * Get WooCommerce page slugs from cache file or fall back to defaults.
	 */
	private function get_wc_slugs() {
		if ( file_exists( $this->cache_path ) ) {
			$cached = include $this->cache_path;
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		return $this->default_slugs();
	}

	private function default_slugs() {
		return array(
			'checkout'     => 'checkout',
			'cart'         => 'cart',
			'account'      => 'my-account',
			'shop'         => 'shop',
			'product_base' => 'product',
		);
	}

	// -----------------------------------------------------------------------
	// Plugin Filtering (the core mechanism)
	// -----------------------------------------------------------------------

	/**
	 * Filter the active_plugins option value before WordPress loads them.
	 * Runs on every get_option('active_plugins') call — must be fast and idempotent.
	 */
	public function filter_plugins( $plugins ) {
		if ( ! is_array( $plugins ) || ! $this->config || ! $this->context ) {
			return $plugins;
		}

		// Return cached result on repeated calls (WordPress calls get_option('active_plugins') multiple times)
		if ( null !== $this->filtered_cache ) {
			return $this->filtered_cache;
		}

		$this->original_count = count( $plugins );

		// Get suppress rules for current context
		$context_config = $this->resolve_context_config();
		if ( ! $context_config || empty( $context_config['suppress'] ) ) {
			$this->filtered_cache = $plugins;
			return $plugins; // No rules for this context = load everything
		}

		$suppress_patterns = $context_config['suppress'];
		$never_patterns    = $this->resolve_never_suppress( $context_config );

		$filtered = array();
		foreach ( $plugins as $plugin ) {
			if ( $this->should_suppress( $plugin, $suppress_patterns, $never_patterns ) ) {
				$this->suppressed[] = $plugin;
				if ( 'dryrun' !== $this->mode ) {
					continue; // Actually suppress
				}
			}
			$filtered[] = $plugin;
		}

		$this->filtered_cache = $filtered;
		return $filtered;
	}

	/**
	 * Resolve which context config block applies to the current request.
	 * Handles AJAX action-specific rules with wildcard fallback.
	 */
	private function resolve_context_config() {
		$contexts = $this->config['contexts'];

		// For AJAX: check action-specific rules, then generic ajax rules
		if ( 0 === strpos( $this->context, 'ajax:' ) ) {
			$action = substr( $this->context, 5 );

			// Check for action-specific override in ajax.actions config
			if ( isset( $contexts['ajax']['actions'] ) && $action ) {
				// Exact action match
				if ( isset( $contexts['ajax']['actions'][ $action ] ) ) {
					return $contexts['ajax']['actions'][ $action ];
				}
				// Wildcard action match (e.g., woocommerce_*)
				foreach ( $contexts['ajax']['actions'] as $pattern => $rules ) {
					if ( $this->glob_match( $pattern, $action ) ) {
						return $rules;
					}
				}
			}

			// Fall back to generic ajax config
			return $contexts['ajax'] ?? null;
		}

		return $contexts[ $this->context ] ?? null;
	}

	/**
	 * Merge global and context-level never_suppress lists.
	 */
	private function resolve_never_suppress( $context_config ) {
		$global  = $this->config['never_suppress'] ?? array();
		$context = $context_config['never_suppress'] ?? array();
		return array_merge( $global, $context );
	}

	/**
	 * Determine whether a specific plugin should be suppressed.
	 * never_suppress always wins over suppress.
	 */
	private function should_suppress( $plugin, $suppress_patterns, $never_patterns ) {
		// Safety rail: never_suppress overrides everything
		foreach ( $never_patterns as $pattern ) {
			if ( $this->matches_plugin( $pattern, $plugin ) ) {
				return false;
			}
		}

		// Check suppress list
		foreach ( $suppress_patterns as $pattern ) {
			if ( $this->matches_plugin( $pattern, $plugin ) ) {
				return true;
			}
		}

		return false; // Default: load (fail-open)
	}

	/**
	 * Match a config pattern against a plugin path.
	 *
	 * Pattern types:
	 *   "wordpress-seo"                  -> matches directory name (most common)
	 *   "wordpress-seo/wp-seo.php"       -> matches exact plugin path
	 *   "woocommerce-gateway-*"          -> glob match on directory name
	 *
	 * Plugin paths are always "directory/file.php" format.
	 */
	private function matches_plugin( $pattern, $plugin ) {
		// Extract the directory portion of the plugin path
		$plugin_dir = dirname( $plugin );
		if ( '.' === $plugin_dir ) {
			$plugin_dir = $plugin; // Single-file plugin (rare)
		}

		// Pattern contains / = match against full path
		if ( false !== strpos( $pattern, '/' ) ) {
			return $pattern === $plugin || $this->glob_match( $pattern, $plugin );
		}

		// Pattern is directory-only = match against plugin directory
		if ( false !== strpos( $pattern, '*' ) || false !== strpos( $pattern, '?' ) ) {
			return $this->glob_match( $pattern, $plugin_dir );
		}

		// Exact directory match
		return $pattern === $plugin_dir;
	}

	/**
	 * Simple glob matching: * matches any chars except /, ? matches single char.
	 */
	private function glob_match( $pattern, $string ) {
		$regex = '';
		$len   = strlen( $pattern );
		for ( $i = 0; $i < $len; $i++ ) {
			$char = $pattern[ $i ];
			if ( '*' === $char ) {
				$regex .= '[^/]*';
			} elseif ( '?' === $char ) {
				$regex .= '[^/]';
			} else {
				$regex .= preg_quote( $char, '#' );
			}
		}
		return (bool) preg_match( '#^' . $regex . '$#', $string );
	}

	// -----------------------------------------------------------------------
	// WooCommerce Context Cache
	// -----------------------------------------------------------------------

	/**
	 * Register hooks that run after plugins load (WC available).
	 */
	public function register_hooks() {
		// Build WC slug cache if it doesn't exist and WooCommerce is active
		if ( ! file_exists( $this->cache_path ) && class_exists( 'WooCommerce' ) ) {
			$this->build_wc_cache();
		}

		// Invalidate cache when WC page settings change
		$wc_options = array(
			'woocommerce_checkout_page_id',
			'woocommerce_cart_page_id',
			'woocommerce_myaccount_page_id',
			'woocommerce_shop_page_id',
			'woocommerce_permalinks',
		);
		foreach ( $wc_options as $option ) {
			add_action( "update_option_{$option}", array( $this, 'invalidate_cache' ) );
		}
	}

	/**
	 * Build the WC slug cache file from current WooCommerce settings.
	 * Requires WooCommerce to be loaded (call from plugins_loaded or later).
	 */
	public function build_wc_cache() {
		$slugs = array();

		$page_map = array(
			'checkout' => 'woocommerce_checkout_page_id',
			'cart'     => 'woocommerce_cart_page_id',
			'account'  => 'woocommerce_myaccount_page_id',
			'shop'     => 'woocommerce_shop_page_id',
		);

		foreach ( $page_map as $context => $option ) {
			$page_id = get_option( $option );
			if ( $page_id ) {
				$slug = get_post_field( 'post_name', (int) $page_id );
				if ( $slug && ! is_wp_error( $slug ) ) {
					$slugs[ $context ] = $slug;
				}
			}
		}

		// Product permalink base — strip rewrite tags (e.g., shop/%product_cat% → shop)
		$permalinks   = get_option( 'woocommerce_permalinks', array() );
		$product_base = ! empty( $permalinks['product_base'] )
			? trim( $permalinks['product_base'], '/' )
			: 'product';
		$product_base = preg_replace( '/%[^%]+%.*$/', '', $product_base );
		$product_base = trim( $product_base, '/' );
		$slugs['product_base'] = $product_base ?: 'product';

		// Fall back to defaults for anything missing
		$defaults = $this->default_slugs();
		$slugs    = array_merge( $defaults, $slugs );

		// Write cache file
		$content = "<?php\n"
			. "// Auto-generated by ContextLoad " . self::VERSION . ". Do not edit.\n"
			. '// Generated: ' . gmdate( 'c' ) . "\n"
			. 'return ' . var_export( $slugs, true ) . ";\n";

		file_put_contents( $this->cache_path, $content, LOCK_EX );
	}

	/**
	 * Delete cache file so it gets rebuilt on next request.
	 */
	public function invalidate_cache() {
		if ( file_exists( $this->cache_path ) ) {
			unlink( $this->cache_path );
		}
	}

	// -----------------------------------------------------------------------
	// Debug & Logging
	// -----------------------------------------------------------------------

	private function is_debug() {
		return 'dryrun' === $this->mode
			|| ( defined( 'CONTEXTLOAD_DEBUG' ) && CONTEXTLOAD_DEBUG );
	}

	/**
	 * Log suppression report at end of request.
	 */
	public function log_report() {
		$prefix = 'dryrun' === $this->mode ? '[DRYRUN] ' : '';
		$count  = count( $this->suppressed );

		$this->log( sprintf(
			'%sContext: %s | Plugins: %d/%d loaded | Suppressed: %d%s',
			$prefix,
			$this->context,
			$this->original_count - ( 'dryrun' !== $this->mode ? $count : 0 ),
			$this->original_count,
			$count,
			$count ? ' (' . implode( ', ', $this->suppressed ) . ')' : ''
		) );
	}

	private function log( $message ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( 'ContextLoad: ' . $message );
		}
	}

	// -----------------------------------------------------------------------
	// WP-CLI Commands
	// -----------------------------------------------------------------------

	/**
	 * Register CLI commands for cache management and diagnostics.
	 */
	public static function register_cli() {
		WP_CLI::add_command( 'contextload', 'ContextLoad_CLI' );
	}

	// -----------------------------------------------------------------------
	// Public accessors (for CLI and diagnostics)
	// -----------------------------------------------------------------------

	public function get_context() {
		return $this->context;
	}

	public function get_suppressed() {
		return $this->suppressed;
	}

	public function get_config() {
		return $this->config;
	}

	public function get_mode() {
		return $this->mode;
	}
}

// ---------------------------------------------------------------------------
// WP-CLI command class
// ---------------------------------------------------------------------------

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	class ContextLoad_CLI {

		/**
		 * Build the WooCommerce context cache.
		 *
		 * Reads WooCommerce page settings and writes a cache file
		 * so ContextLoad can detect checkout/cart/account/shop contexts
		 * without querying the database on every request.
		 *
		 * ## EXAMPLES
		 *     wp contextload build-cache
		 *
		 * @subcommand build-cache
		 */
		public function build_cache( $args, $assoc_args ) {
			if ( ! class_exists( 'WooCommerce' ) ) {
				WP_CLI::error( 'WooCommerce is not active.' );
			}

			$loader = ContextLoad::boot();
			$loader->build_wc_cache();

			$cache_path = WP_CONTENT_DIR . '/' . ContextLoad::CACHE_FILE;
			if ( file_exists( $cache_path ) ) {
				$slugs = include $cache_path;
				WP_CLI::success( 'Cache built:' );
				foreach ( $slugs as $context => $slug ) {
					WP_CLI::log( "  {$context}: /{$slug}/" );
				}
			} else {
				WP_CLI::error( 'Cache file was not created.' );
			}
		}

		/**
		 * Show current config and detected context for a given URL.
		 *
		 * ## OPTIONS
		 *
		 * [--url=<url>]
		 * : Simulate detection for this URL path (e.g. /checkout/)
		 *
		 * ## EXAMPLES
		 *     wp contextload status
		 *     wp contextload status --url=/checkout/
		 *
		 * @subcommand status
		 */
		public function status( $args, $assoc_args ) {
			$config_path = __DIR__ . '/' . ContextLoad::CONFIG_FILE;

			if ( ! file_exists( $config_path ) ) {
				WP_CLI::error( 'Config file not found: ' . $config_path );
			}

			$config = json_decode( file_get_contents( $config_path ), true );
			WP_CLI::log( 'ContextLoad v' . ContextLoad::VERSION );
			WP_CLI::log( 'Mode: ' . ( $config['mode'] ?? 'active' ) );
			WP_CLI::log( 'Contexts configured: ' . implode( ', ', array_keys( $config['contexts'] ?? array() ) ) );

			$never = $config['never_suppress'] ?? array();
			WP_CLI::log( 'Global never_suppress: ' . implode( ', ', $never ) );

			foreach ( $config['contexts'] as $ctx => $rules ) {
				$count = count( $rules['suppress'] ?? array() );
				WP_CLI::log( "  {$ctx}: {$count} suppress rules" );
			}

			// Cache status
			$cache_path = WP_CONTENT_DIR . '/' . ContextLoad::CACHE_FILE;
			if ( file_exists( $cache_path ) ) {
				$slugs = include $cache_path;
				WP_CLI::log( "\nWC slug cache: BUILT" );
				foreach ( $slugs as $context => $slug ) {
					WP_CLI::log( "  {$context}: /{$slug}/" );
				}
			} else {
				WP_CLI::log( "\nWC slug cache: NOT BUILT (using defaults)" );
			}
		}

		/**
		 * Simulate what would be suppressed for a specific URL.
		 *
		 * ## OPTIONS
		 *
		 * <url>
		 * : The URL path to simulate (e.g. /checkout/)
		 *
		 * ## EXAMPLES
		 *     wp contextload simulate /checkout/
		 *     wp contextload simulate /cart/
		 *     wp contextload simulate /blog/my-post/
		 *
		 * @subcommand simulate
		 */
		public function simulate( $args, $assoc_args ) {
			$url = $args[0] ?? '/';

			// Temporarily override REQUEST_URI for context detection
			$original_uri              = $_SERVER['REQUEST_URI'] ?? '';
			$original_script           = $_SERVER['SCRIPT_NAME'] ?? '';
			$_SERVER['REQUEST_URI']    = $url;
			$_SERVER['SCRIPT_NAME']    = 'index.php';

			// Fresh detection
			$reflect = new ReflectionClass( 'ContextLoad' );
			$method  = $reflect->getMethod( 'detect_context' );
			$method->setAccessible( true );

			$loader  = ContextLoad::boot();
			$context = $method->invoke( $loader );

			$_SERVER['REQUEST_URI']  = $original_uri;
			$_SERVER['SCRIPT_NAME'] = $original_script;

			WP_CLI::log( "URL: {$url}" );
			WP_CLI::log( "Detected context: {$context}" );

			$config = $loader->get_config();
			if ( ! $config ) {
				WP_CLI::warning( 'No config loaded.' );
				return;
			}

			$context_rules = $config['contexts'][ $context ] ?? null;
			if ( ! $context_rules || empty( $context_rules['suppress'] ) ) {
				WP_CLI::log( 'No suppress rules for this context. All plugins load.' );
				return;
			}

			$plugins = get_option( 'active_plugins', array() );
			$never   = array_merge(
				$config['never_suppress'] ?? array(),
				$context_rules['never_suppress'] ?? array()
			);

			WP_CLI::log( "\nActive plugins: " . count( $plugins ) );
			WP_CLI::log( "Suppress rules: " . count( $context_rules['suppress'] ) );
			WP_CLI::log( '' );

			$would_suppress = 0;
			$would_load     = 0;

			foreach ( $plugins as $plugin ) {
				$dir       = dirname( $plugin );
				$protected = false;

				foreach ( $never as $pattern ) {
					if ( $dir === $pattern || fnmatch( $pattern, $dir ) ) {
						$protected = true;
						break;
					}
				}

				$suppressed = false;
				if ( ! $protected ) {
					foreach ( $context_rules['suppress'] as $pattern ) {
						if ( $dir === $pattern || $pattern === $plugin || fnmatch( $pattern, $dir ) ) {
							$suppressed = true;
							break;
						}
					}
				}

				if ( $suppressed ) {
					WP_CLI::log( "  SUPPRESS: {$plugin}" );
					$would_suppress++;
				} elseif ( $protected ) {
					WP_CLI::log( "  PROTECT:  {$plugin}" );
					$would_load++;
				} else {
					WP_CLI::log( "  LOAD:     {$plugin}" );
					$would_load++;
				}
			}

			WP_CLI::log( '' );
			WP_CLI::success( "Would load {$would_load}, suppress {$would_suppress} of " . count( $plugins ) . ' plugins.' );
		}
	}
}

// ---------------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------------

ContextLoad::boot();

// CLI: register commands (class is defined, config loaded, but no plugin filtering)
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action( 'cli_init', array( 'ContextLoad', 'register_cli' ) );
}

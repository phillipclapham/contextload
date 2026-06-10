<?php
/**
 * ContextLoad — Context-aware plugin loading for WooCommerce
 *
 * Suppresses unnecessary plugin bootstrap based on request context (checkout, cart,
 * rest:namespace, ajax:action, etc.) by filtering the active_plugins option before
 * WordPress loads them. Plugins not needed for the current context never get
 * require_once'd — their PHP bootstrap cost is eliminated.
 *
 * Deploy: Copy contextload.php + contextload-config.json (and optionally a
 * default base config referenced via "extends") to wp-content/mu-plugins/
 *
 * Kill switch: define('CONTEXTLOAD_DISABLED', true) in wp-config.php
 * Debug mode:  define('CONTEXTLOAD_DEBUG', true) in wp-config.php
 *
 * @version 1.2.0
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

	const VERSION    = '1.2.1';
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

		// CRITICAL write-guard (v1.2.1): the read filter above is runtime-only, but if
		// ANY code does a read-modify-write of active_plugins during a request where we
		// have suppressed plugins, it reads our FILTERED list, modifies it, and persists
		// it — permanently deactivating every plugin we suppressed this request. This was
		// observed in production: Rank Math Pro's activate_plugin() (fired from its
		// are_requirements_met() constructor path) and the Freemius SDK's
		// fs_newest_sdk_plugin_first() both read-modify-write active_plugins on checkout
		// and AJAX requests, baking the suppression into the database. This guard
		// re-injects the suppressed plugins into any write so suppression can never leak
		// into the stored option. PHP_INT_MAX priority so it has the final say.
		add_filter( 'pre_update_option_active_plugins', array( $this, 'guard_persisted_write' ), PHP_INT_MAX, 2 );

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
	 * Load and validate the JSON config file. Supports `extends` for inheritance.
	 */
	private function load_config() {
		$config = $this->load_json_file( $this->config_path );
		if ( ! $config ) {
			return null;
		}

		// v1.2: chase `extends` chain (single-level by default; nested extends supported).
		$config = $this->resolve_extends( $config, $this->config_path, array() );

		if ( empty( $config['contexts'] ) || ! is_array( $config['contexts'] ) ) {
			$this->log( 'Config missing "contexts" — loading all plugins.' );
			return null;
		}

		return $config;
	}

	/**
	 * Load and JSON-decode a single config file. Returns null on failure.
	 */
	private function load_json_file( $path ) {
		if ( ! file_exists( $path ) ) {
			return null;
		}

		$json   = file_get_contents( $path );
		$config = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$this->log( 'Invalid JSON in ' . basename( $path ) . ': ' . json_last_error_msg() );
			return null;
		}

		return $config;
	}

	/**
	 * Resolve "extends" chain recursively. Child deep-merges over parent.
	 * Cycle-safe via $seen path tracking. Parent path resolved relative to child file.
	 */
	private function resolve_extends( $config, $config_path, $seen ) {
		if ( empty( $config['extends'] ) ) {
			return $config;
		}

		$canonical = realpath( $config_path ) ?: $config_path;
		if ( in_array( $canonical, $seen, true ) ) {
			$this->log( 'extends cycle detected at ' . basename( $config_path ) );
			unset( $config['extends'] );
			return $config;
		}
		$seen[] = $canonical;

		$parent_ref = $config['extends'];
		// Resolve relative to current config file's directory.
		$parent_path = ( '/' === substr( $parent_ref, 0, 1 ) )
			? $parent_ref
			: dirname( $config_path ) . '/' . $parent_ref;

		$parent = $this->load_json_file( $parent_path );
		if ( ! $parent ) {
			$this->log( 'extends parent not loadable: ' . $parent_ref );
			unset( $config['extends'] );
			return $config;
		}

		// Recurse so grandparents resolve before merge.
		$parent = $this->resolve_extends( $parent, $parent_path, $seen );

		unset( $config['extends'] );
		return $this->deep_merge( $parent, $config );
	}

	/**
	 * Deep-merge child over parent:
	 *  - Scalars in child override parent
	 *  - Numeric (list) arrays append + dedupe
	 *  - Associative arrays recursively merge
	 */
	private function deep_merge( $parent, $child ) {
		if ( ! is_array( $parent ) || ! is_array( $child ) ) {
			return $child;
		}
		foreach ( $child as $key => $value ) {
			if ( ! array_key_exists( $key, $parent ) ) {
				$parent[ $key ] = $value;
				continue;
			}
			if ( is_array( $value ) && is_array( $parent[ $key ] ) ) {
				if ( $this->is_list( $parent[ $key ] ) && $this->is_list( $value ) ) {
					$parent[ $key ] = array_values( array_unique(
						array_merge( $parent[ $key ], $value ),
						SORT_STRING
					) );
				} else {
					$parent[ $key ] = $this->deep_merge( $parent[ $key ], $value );
				}
			} else {
				$parent[ $key ] = $value;
			}
		}
		return $parent;
	}

	/**
	 * Detect numeric/list array (vs associative). PHP 8.1+ has array_is_list().
	 */
	private function is_list( $arr ) {
		if ( empty( $arr ) ) {
			return true;
		}
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $arr );
		}
		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}

	// -----------------------------------------------------------------------
	// Context Detection
	// -----------------------------------------------------------------------

	/**
	 * Determine request context from URL and server variables.
	 * Runs before plugins load — only raw PHP and $_SERVER available.
	 *
	 * Returned context format:
	 *  - 'cron' / 'admin' / 'checkout' / 'cart' / 'account' / 'shop' / 'product' / 'frontend'
	 *  - 'ajax' OR 'ajax:<action>'
	 *  - 'rest' OR 'rest:<namespace/version>' (v1.2)
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

		// REST API (v1.2: extract namespace for sub-routing)
		if ( false !== strpos( $path, '/wp-json/' ) || isset( $_GET['rest_route'] ) ) {
			$namespace = $this->extract_rest_namespace( $uri );
			return 'rest' . ( $namespace ? ':' . $namespace : '' );
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
	 * v1.2: Extract the REST namespace (vendor/version) from the request URI.
	 * Returns empty string if not parseable.
	 */
	private function extract_rest_namespace( $uri ) {
		if ( false !== strpos( $uri, 'rest_route=' ) ) {
			$query = parse_url( $uri, PHP_URL_QUERY );
			parse_str( $query ?? '', $params );
			$rest_path = $params['rest_route'] ?? '';
		} else {
			$pos       = strpos( $uri, '/wp-json/' );
			$rest_path = ( false !== $pos ) ? substr( $uri, $pos + 9 ) : '';
		}

		$rest_path = strtok( ltrim( $rest_path, '/' ), '?' );
		if ( false === $rest_path || '' === $rest_path ) {
			return '';
		}

		$segments = explode( '/', $rest_path );
		if ( count( $segments ) >= 2 ) {
			return $segments[0] . '/' . $segments[1];
		}
		return $segments[0] ?? '';
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

		// v1.2: optional safety bypass for logged-in users on this context.
		if ( $this->should_bypass_logged_in( $context_config ) ) {
			$this->filtered_cache = $plugins;
			return $plugins;
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
	 * Write-guard for the active_plugins option (v1.2.1).
	 *
	 * Hooked on pre_update_option_active_plugins at PHP_INT_MAX. Any time WordPress is
	 * about to persist active_plugins, we re-inject the plugins we suppressed this
	 * request. Without this, a read-modify-write by another plugin (which reads our
	 * filtered list via the option_active_plugins filter) bakes the suppression into the
	 * DB and permanently deactivates everything we hid for the current context.
	 *
	 * Real-world triggers observed: Rank Math Pro activate_plugin() and the Freemius SDK
	 * fs_newest_sdk_plugin_first(), both firing on checkout / AJAX requests.
	 *
	 * We only ADD BACK our own suppressed entries; we never remove what the caller
	 * intended to write, so legitimate activations and deactivations are preserved.
	 *
	 * @param mixed $value     The new active_plugins value about to be written.
	 * @param mixed $old_value The previous value (unused; kept for filter signature).
	 * @return mixed The value with suppressed plugins re-injected.
	 */
	public function guard_persisted_write( $value, $old_value ) {
		// Nothing suppressed this request, or a malformed write: pass through untouched.
		if ( empty( $this->suppressed ) || ! is_array( $value ) ) {
			return $value;
		}

		// Re-inject any suppressed plugin missing from the write, preserving the
		// caller's ordering and any plugins they legitimately added.
		$missing = array_diff( $this->suppressed, $value );
		if ( ! empty( $missing ) ) {
			$value = array_values( array_merge( $value, $missing ) );
			$this->log( 'guard_persisted_write: re-injected ' . count( $missing ) . ' suppressed plugin(s) to prevent persisted deactivation: ' . implode( ', ', $missing ) );
		}

		return $value;
	}

	/**
	 * Resolve which context config block applies to the current request.
	 *
	 * v1.2: Handles both AJAX action sub-routing AND REST namespace sub-routing
	 * via the same pattern (exact then wildcard then fallback to parent context).
	 *
	 * Public so the simulate CLI can reuse the exact resolution path.
	 */
	public function resolve_context_config() {
		return $this->resolve_context_config_for( $this->context );
	}

	/**
	 * Resolve the config block for an arbitrary context string. Used by simulate.
	 */
	public function resolve_context_config_for( $context ) {
		if ( ! $context ) {
			return null;
		}
		$contexts = $this->config['contexts'] ?? array();

		// AJAX: ajax:<action> → contexts.ajax.actions[<action>] → contexts.ajax
		if ( 0 === strpos( $context, 'ajax:' ) ) {
			$action = substr( $context, 5 );
			$sub    = $this->lookup_sub_route( $contexts, 'ajax', 'actions', $action );
			if ( null !== $sub ) {
				return $sub;
			}
			return $contexts['ajax'] ?? null;
		}

		// REST: rest:<namespace> → contexts.rest.namespaces[<namespace>] → contexts.rest
		if ( 0 === strpos( $context, 'rest:' ) ) {
			$ns  = substr( $context, 5 );
			$sub = $this->lookup_sub_route( $contexts, 'rest', 'namespaces', $ns );
			if ( null !== $sub ) {
				return $sub;
			}
			return $contexts['rest'] ?? null;
		}

		return $contexts[ $context ] ?? null;
	}

	/**
	 * Shared sub-route resolver for AJAX actions and REST namespaces.
	 * Returns matched rule block or null. Does NOT fall back to parent context here
	 * (caller handles fallback) so an empty rule block can intentionally opt out.
	 */
	private function lookup_sub_route( $contexts, $parent_key, $sub_map_key, $needle ) {
		if ( ! $needle || ! isset( $contexts[ $parent_key ][ $sub_map_key ] ) ) {
			return null;
		}
		$sub_map = $contexts[ $parent_key ][ $sub_map_key ];

		// Exact match first
		if ( isset( $sub_map[ $needle ] ) ) {
			return $sub_map[ $needle ];
		}
		// Wildcard match (longest pattern wins for determinism)
		$matches = array();
		foreach ( $sub_map as $pattern => $rules ) {
			if ( $this->glob_match( $pattern, $needle ) ) {
				$matches[ $pattern ] = $rules;
			}
		}
		if ( $matches ) {
			uksort( $matches, function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			} );
			return reset( $matches );
		}
		return null;
	}

	/**
	 * v1.2: Check whether this request should bypass suppression because user is logged in.
	 * Generalizable to any context — opt-in via context-level "bypass_if_logged_in": true.
	 * Mirrors the wpjson-opt safety pattern for protecting editors hitting REST/admin paths.
	 */
	private function should_bypass_logged_in( $context_config ) {
		if ( empty( $context_config['bypass_if_logged_in'] ) ) {
			return false;
		}
		foreach ( array_keys( $_COOKIE ) as $cookie ) {
			if ( 0 === strpos( $cookie, 'wordpress_logged_in_' )
				|| 0 === strpos( $cookie, 'wordpress_sec_' ) ) {
				return true;
			}
		}
		return false;
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
	 *
	 * Public so the simulate CLI can reuse the exact matching path.
	 */
	public function should_suppress( $plugin, $suppress_patterns, $never_patterns ) {
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
		 * Show current config (after extends merging) and counts per context.
		 *
		 * ## EXAMPLES
		 *     wp contextload status
		 *
		 * @subcommand status
		 */
		public function status( $args, $assoc_args ) {
			$loader = ContextLoad::boot();
			$config = $loader->get_config();

			if ( ! $config ) {
				WP_CLI::error( 'No valid config loaded.' );
			}

			WP_CLI::log( 'ContextLoad v' . ContextLoad::VERSION );
			WP_CLI::log( 'Mode: ' . ( $config['mode'] ?? 'active' ) );
			WP_CLI::log( 'Contexts configured: ' . implode( ', ', array_keys( $config['contexts'] ?? array() ) ) );

			$never = $config['never_suppress'] ?? array();
			WP_CLI::log( 'Global never_suppress (' . count( $never ) . '): ' . implode( ', ', $never ) );

			foreach ( $config['contexts'] as $ctx => $rules ) {
				$count   = count( $rules['suppress'] ?? array() );
				$bypass  = ! empty( $rules['bypass_if_logged_in'] ) ? ' [bypass-if-logged-in]' : '';
				$subkey  = isset( $rules['actions'] ) ? 'actions' : ( isset( $rules['namespaces'] ) ? 'namespaces' : null );
				$subs    = $subkey ? ' (' . count( $rules[ $subkey ] ) . ' ' . $subkey . ')' : '';
				WP_CLI::log( "  {$ctx}: {$count} suppress rules{$subs}{$bypass}" );
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
		 * v1.2: Honors REST namespace sub-routing and AJAX action sub-routing.
		 *       Does NOT simulate bypass_if_logged_in (CLI has no cookies).
		 *
		 * ## OPTIONS
		 *
		 * <url>
		 * : The URL path to simulate (e.g. /checkout/, /wp-json/pys-facebook/v1/event, /wp-admin/admin-ajax.php?action=pys_get_pbid)
		 *
		 * ## EXAMPLES
		 *     wp contextload simulate /checkout/
		 *     wp contextload simulate /wp-json/pys-facebook/v1/event
		 *     wp contextload simulate '/wp-admin/admin-ajax.php?action=pys_get_pbid'
		 *
		 * @subcommand simulate
		 */
		public function simulate( $args, $assoc_args ) {
			$url = $args[0] ?? '/';

			// Save and override request env so detect_context sees the simulated request.
			$original_uri    = $_SERVER['REQUEST_URI'] ?? '';
			$original_script = $_SERVER['SCRIPT_NAME'] ?? '';
			$original_get    = $_GET;
			$original_req    = $_REQUEST;

			$_SERVER['REQUEST_URI'] = $url;

			// Reproduce how PHP/WP set SCRIPT_NAME + populate $_GET for the URL.
			$path  = parse_url( $url, PHP_URL_PATH ) ?? '/';
			$query = parse_url( $url, PHP_URL_QUERY ) ?? '';
			parse_str( $query, $parsed_query );

			if ( false !== strpos( $path, 'admin-ajax.php' ) ) {
				$_SERVER['SCRIPT_NAME'] = '/wp-admin/admin-ajax.php';
			} elseif ( false !== strpos( $path, 'wp-cron.php' ) ) {
				$_SERVER['SCRIPT_NAME'] = '/wp-cron.php';
			} else {
				$_SERVER['SCRIPT_NAME'] = '/index.php';
			}

			$_GET     = $parsed_query;
			$_REQUEST = array_merge( $original_req, $parsed_query );

			$loader  = ContextLoad::boot();
			$reflect = new ReflectionClass( 'ContextLoad' );
			$method  = $reflect->getMethod( 'detect_context' );
			$method->setAccessible( true );
			$context = $method->invoke( $loader );

			// Restore env
			$_SERVER['REQUEST_URI']  = $original_uri;
			$_SERVER['SCRIPT_NAME'] = $original_script;
			$_GET                    = $original_get;
			$_REQUEST                = $original_req;

			WP_CLI::log( "URL: {$url}" );
			WP_CLI::log( "Detected context: {$context}" );

			$config = $loader->get_config();
			if ( ! $config ) {
				WP_CLI::warning( 'No config loaded.' );
				return;
			}

			// Use the same resolution path as production (handles ajax:* + rest:* sub-routing).
			$context_rules = $loader->resolve_context_config_for( $context );
			if ( ! $context_rules || empty( $context_rules['suppress'] ) ) {
				WP_CLI::log( 'No suppress rules for this context. All plugins load.' );
				return;
			}

			$bypass = ! empty( $context_rules['bypass_if_logged_in'] );
			if ( $bypass ) {
				WP_CLI::log( 'NOTE: bypass_if_logged_in=true on this context. Logged-in users will skip suppression in production.' );
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
			$would_protect  = 0;

			foreach ( $plugins as $plugin ) {
				// PROTECT first (never_suppress wins)
				$protected = false;
				foreach ( $never as $pattern ) {
					if ( $this->cli_matches( $pattern, $plugin ) ) {
						$protected = true;
						break;
					}
				}
				if ( $protected ) {
					WP_CLI::log( "  PROTECT:  {$plugin}" );
					$would_load++;
					$would_protect++;
					continue;
				}

				$suppressed = false;
				foreach ( $context_rules['suppress'] as $pattern ) {
					if ( $this->cli_matches( $pattern, $plugin ) ) {
						$suppressed = true;
						break;
					}
				}

				if ( $suppressed ) {
					WP_CLI::log( "  SUPPRESS: {$plugin}" );
					$would_suppress++;
				} else {
					WP_CLI::log( "  LOAD:     {$plugin}" );
					$would_load++;
				}
			}

			WP_CLI::log( '' );
			WP_CLI::success( sprintf(
				'Would load %d (incl. %d protected), suppress %d of %d plugins.',
				$would_load,
				$would_protect,
				$would_suppress,
				count( $plugins )
			) );
		}

		/**
		 * Pattern matcher mirroring ContextLoad::matches_plugin() (private).
		 * Kept in CLI to avoid exposing the internal matcher.
		 */
		private function cli_matches( $pattern, $plugin ) {
			$dir = dirname( $plugin );
			if ( '.' === $dir ) {
				$dir = $plugin;
			}
			if ( false !== strpos( $pattern, '/' ) ) {
				return $pattern === $plugin || fnmatch( $pattern, $plugin );
			}
			if ( false !== strpos( $pattern, '*' ) || false !== strpos( $pattern, '?' ) ) {
				return fnmatch( $pattern, $dir );
			}
			return $pattern === $dir;
		}

		/**
		 * Validate the loaded config and surface common mistakes.
		 *
		 * v1.2: New command. Checks for: never_suppress vs suppress conflicts,
		 * unknown context keys, malformed sub-routing maps.
		 *
		 * ## EXAMPLES
		 *     wp contextload validate
		 *
		 * @subcommand validate
		 */
		public function validate( $args, $assoc_args ) {
			$loader = ContextLoad::boot();
			$config = $loader->get_config();
			if ( ! $config ) {
				WP_CLI::error( 'No valid config loaded.' );
			}

			$known_contexts = array(
				'checkout', 'cart', 'account', 'shop', 'product',
				'frontend', 'admin', 'cron', 'ajax', 'rest',
			);
			$warnings = 0;

			foreach ( $config['contexts'] as $ctx => $rules ) {
				if ( ! in_array( $ctx, $known_contexts, true ) ) {
					WP_CLI::warning( "Unknown context key: {$ctx}. detect_context() never returns this." );
					$warnings++;
				}
				$suppress = $rules['suppress'] ?? array();
				$never    = array_merge(
					$config['never_suppress'] ?? array(),
					$rules['never_suppress'] ?? array()
				);
				foreach ( $suppress as $pat ) {
					if ( in_array( $pat, $never, true ) ) {
						WP_CLI::warning( "{$ctx}: '{$pat}' appears in BOTH suppress and never_suppress. never_suppress wins." );
						$warnings++;
					}
				}
			}

			if ( 0 === $warnings ) {
				WP_CLI::success( 'Config valid. No conflicts detected.' );
			} else {
				WP_CLI::log( "Validation complete with {$warnings} warning(s)." );
			}
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

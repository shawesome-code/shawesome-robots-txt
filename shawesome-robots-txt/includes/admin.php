<?php
/**
 * Admin: settings, editor, overview of all sites (network), handling of a
 * physical robots.txt and SEO plugin detection.
 *
 * On a single site the page is under Settings, in a network under
 * Network Admin → Settings. Forms are posted to admin-post.php in both cases.
 *
 * @package Shawesome_Robots_Txt
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability needed to manage the plugin.
 *
 * @return string
 */
function shawesome_robots_txt_capability() {
	return is_multisite() ? 'manage_network_options' : 'manage_options';
}

/**
 * SEO plugins that change the robots.txt output, keyed by plugin file.
 *
 * @return array<string, string> Plugin file => name.
 */
function shawesome_robots_txt_seo_plugins() {
	return array(
		'wordpress-seo/wp-seo.php'                        => 'Yoast SEO',
		'all-in-one-seo-pack/all_in_one_seo_pack.php'     => 'All in One SEO',
		'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' => 'All in One SEO Pro',
	);
}

/**
 * SEO plugins active on a site (network-wide or per site).
 *
 * @param int $site_id Site ID (ignored on a single site).
 * @return string[] Plugin names.
 */
function shawesome_robots_txt_active_seo_plugins( $site_id ) {
	if ( is_multisite() ) {
		$active = array_merge(
			array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ),
			(array) get_blog_option( $site_id, 'active_plugins', array() )
		);
	} else {
		$active = (array) get_option( 'active_plugins', array() );
	}

	$names = array();
	foreach ( shawesome_robots_txt_seo_plugins() as $file => $name ) {
		if ( in_array( $file, $active, true ) ) {
			$names[] = $name;
		}
	}

	return $names;
}

/**
 * URL of the settings page, optionally for one site's editor.
 *
 * @param array $args Query arguments.
 * @return string
 */
function shawesome_robots_txt_page_url( $args = array() ) {
	$url = is_multisite()
		? network_admin_url( 'admin.php?page=shawesome-robots-txt' )
		: admin_url( 'admin.php?page=shawesome-robots-txt' );

	return add_query_arg( $args, $url );
}

/**
 * The shawesome mark as a data URI for the menu. WordPress repaints it to
 * match the admin color scheme.
 *
 * @return string
 */
function shawesome_robots_txt_menu_icon() {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><g fill="#a7aaad"><rect x="5" y="5" width="17" height="17" rx="4.5"/><rect x="26" y="5" width="17" height="17" rx="4.5"/><rect x="5" y="26" width="17" height="17" rx="4.5"/><rect x="28.5" y="28.5" width="17" height="17" rx="4.5"/></g></svg>';

	return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
}

/**
 * Add the page to the "shawesome" menu: in the network admin in a network,
 * in the admin otherwise. The menu is created here unless another shawesome
 * plugin already added it (same slug, so all shawesome plugins share it).
 */
function shawesome_robots_txt_admin_menu() {
	global $admin_page_hooks;

	$own_menu = empty( $admin_page_hooks['shawesome'] );
	if ( $own_menu ) {
		add_menu_page( 'shawesome', 'shawesome', shawesome_robots_txt_capability(), 'shawesome', '__return_null', shawesome_robots_txt_menu_icon(), is_multisite() ? 30 : 81 );
	}

	$hook = add_submenu_page(
		'shawesome',
		__( 'robots.txt', 'shawesome-robots-txt' ),
		__( 'robots.txt', 'shawesome-robots-txt' ),
		shawesome_robots_txt_capability(),
		'shawesome-robots-txt',
		'shawesome_robots_txt_render_page'
	);

	// WordPress repeats a new menu as its first entry; the menu has no page of its own.
	if ( $own_menu ) {
		remove_submenu_page( 'shawesome', 'shawesome' );
	}

	add_action( 'load-' . $hook, 'shawesome_robots_txt_load_page' );
}
add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', 'shawesome_robots_txt_admin_menu' );

/**
 * Load the styles and the script of the plugin page (only there).
 */
function shawesome_robots_txt_load_page() {
	add_action(
		'admin_enqueue_scripts',
		function () {
			wp_enqueue_style( 'shawesome-robots-txt', plugins_url( 'admin.css', __FILE__ ), array(), SHAWESOME_ROBOTS_TXT_VERSION );
			wp_enqueue_script( 'shawesome-robots-txt', plugins_url( 'admin.js', __FILE__ ), array(), SHAWESOME_ROBOTS_TXT_VERSION, true );
			wp_localize_script(
				'shawesome-robots-txt',
				'shawesomeRobotsTxt',
				array(
					'url'      => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'shawesome_robots_txt_scan' ),
					'scanning' => __( 'Checking …', 'shawesome-robots-txt' ),
					'failed'   => __( 'The check failed.', 'shawesome-robots-txt' ),
					'labels'   => array(
						'live'       => __( 'Currently live', 'shawesome-robots-txt' ),
						'liveError'  => __( '# The live robots.txt could not be loaded.', 'shawesome-robots-txt' ),
						/* translators: %s: names of the active SEO plugins */
						'seo'        => __( 'SEO plugins (%s)', 'shawesome-robots-txt' ),
						'seoText'    => __( '# May add, remove or rebuild lines in "Base" mode.', 'shawesome-robots-txt' ),
						'rules'      => __( 'Your rules', 'shawesome-robots-txt' ),
						'file'       => __( 'Override file (delivered instead of your rules)', 'shawesome-robots-txt' ),
						'empty'      => __( '# Empty – the robots.txt generated by WordPress and SEO plugins is used.', 'shawesome-robots-txt' ),
						'options'    => __( 'Crawler options', 'shawesome-robots-txt' ),
						'paths'      => __( 'Sites with a path', 'shawesome-robots-txt' ),
						'exactNote'  => __( '"Exact" mode: crawlers get these lines as shown.', 'shawesome-robots-txt' ),
						'baseNote'   => __( '"Base" mode: the SEO plugins may still change the result. "Currently live" shows what crawlers get now.', 'shawesome-robots-txt' ),
						'hiddenNote' => __( 'This site is hidden from search engines, so WordPress delivers its default robots.txt instead of your rules and crawler options.', 'shawesome-robots-txt' ),
						'fileNote'   => __( 'A physical robots.txt in the web root is delivered instead of all of this.', 'shawesome-robots-txt' ),
						'liveNote'   => __( 'What crawlers get now. Saved changes appear here after reloading the page.', 'shawesome-robots-txt' ),
						'noLimit'    => __( 'No limit', 'shawesome-robots-txt' ),
						/* translators: %d: seconds */
						'delay'      => __( '%d s delay', 'shawesome-robots-txt' ),
						'allAllowed' => __( 'All allowed', 'shawesome-robots-txt' ),
						/* translators: %d: number of blocked file types */
						'blocked'    => __( '%d blocked', 'shawesome-robots-txt' ),
						'dirty'      => __( 'Unsaved changes', 'shawesome-robots-txt' ),
						'saved'      => __( 'Saved', 'shawesome-robots-txt' ),
						'clean'      => __( 'No changes', 'shawesome-robots-txt' ),
					),
				)
			);
		}
	);
}

/**
 * Tabs of the plugin page.
 *
 * @return array<string, string> Tab slug => label.
 */
function shawesome_robots_txt_tabs() {
	return array(
		'robots'   => __( 'robots.txt', 'shawesome-robots-txt' ),
		'settings' => __( 'Settings', 'shawesome-robots-txt' ),
		'faq'      => __( 'FAQ', 'shawesome-robots-txt' ),
	);
}

/**
 * Label of a mode.
 *
 * @param string $mode 'base' or 'exact'.
 * @return string
 */
function shawesome_robots_txt_mode_label( $mode ) {
	return 'exact' === $mode ? _x( 'Exact', 'robots.txt mode', 'shawesome-robots-txt' ) : _x( 'Base', 'robots.txt mode', 'shawesome-robots-txt' );
}

/**
 * Request the robots.txt of a site as crawlers get it.
 *
 * @param int $site_id Site ID.
 * @return array{body: string|null, error: string} Body, or null and an error (message or HTTP status code).
 */
function shawesome_robots_txt_fetch( $site_id ) {
	$response = wp_remote_get( get_home_url( $site_id, 'robots.txt' ), array( 'timeout' => 5 ) );

	if ( is_wp_error( $response ) ) {
		return array(
			'body'  => null,
			'error' => $response->get_error_message(),
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		return array(
			'body'  => null,
			/* translators: %d: HTTP status code */
			'error' => sprintf( __( 'HTTP status %d', 'shawesome-robots-txt' ), $code ),
		);
	}

	return array(
		'body'  => (string) wp_remote_retrieve_body( $response ),
		'error' => '',
	);
}

/**
 * Evaluate a robots.txt: are all crawlers blocked, how many sitemaps are
 * linked, how many AI training crawlers are blocked. A crawler follows the
 * group that names it, otherwise the group for "*"; "Disallow: /" blocks it
 * unless the same group allows "/".
 *
 * @param string $robots_txt robots.txt content.
 * @return array{all_blocked: bool, sitemaps: int, ai_blocked: int, ai_total: int}
 */
function shawesome_robots_txt_analyze( $robots_txt ) {
	$groups    = array();
	$in_agents = false;
	$sitemaps  = 0;

	foreach ( preg_split( '/\r\n|\r|\n/', $robots_txt ) as $line ) {
		if ( ! preg_match( '/^\s*([a-z-]+)\s*:\s*([^#]*)/i', $line, $match ) ) {
			continue;
		}
		$key   = strtolower( $match[1] );
		$value = trim( $match[2] );

		if ( 'sitemap' === $key ) {
			++$sitemaps;
		} elseif ( 'user-agent' === $key ) {
			if ( ! $in_agents ) {
				$groups[] = array(
					'agents' => array(),
					'rules'  => array(),
				);
			}
			$groups[ count( $groups ) - 1 ]['agents'][] = strtolower( $value );
			$in_agents                                  = true;
		} else {
			// Any other line ends the list of user agents of a group.
			$in_agents = false;
			if ( $groups && in_array( $key, array( 'allow', 'disallow' ), true ) ) {
				$groups[ count( $groups ) - 1 ]['rules'][] = $key . ':' . $value;
			}
		}
	}

	$blocked = function ( $agent ) use ( $groups ) {
		$own = array();
		foreach ( array( strtolower( $agent ), '*' ) as $name ) {
			foreach ( $groups as $group ) {
				if ( in_array( $name, $group['agents'], true ) ) {
					$own = array_merge( $own, $group['rules'] );
				}
			}
			if ( $own ) {
				break;
			}
		}

		return in_array( 'disallow:/', $own, true ) && ! in_array( 'allow:/', $own, true );
	};

	$ai_blocked = 0;
	foreach ( shawesome_robots_txt_ai_agents() as $agent ) {
		$ai_blocked += $blocked( $agent ) ? 1 : 0;
	}

	return array(
		'all_blocked' => $blocked( '*' ),
		'sitemaps'    => $sitemaps,
		'ai_blocked'  => $ai_blocked,
		'ai_total'    => count( shawesome_robots_txt_ai_agents() ),
	);
}

/**
 * Check the robots.txt of a site and store the result with a timestamp.
 *
 * @param int         $site_id Site ID.
 * @param array|null  $fetched Result of shawesome_robots_txt_fetch(), if already requested.
 * @return array Status: time, error and the result of shawesome_robots_txt_analyze().
 */
function shawesome_robots_txt_scan( $site_id, $fetched = null ) {
	$fetched = $fetched ? $fetched : shawesome_robots_txt_fetch( $site_id );
	$status  = array(
		'time'  => time(),
		'error' => $fetched['error'],
	);
	if ( null !== $fetched['body'] ) {
		$status += shawesome_robots_txt_analyze( $fetched['body'] );
	}

	shawesome_robots_txt_set_site_option( $site_id, SHAWESOME_ROBOTS_TXT_STATUS_OPTION, $status );

	return $status;
}

/**
 * Stored status of a site, or null if there is none.
 *
 * @param int $site_id Site ID.
 * @return array|null
 */
function shawesome_robots_txt_get_status( $site_id ) {
	$status = shawesome_robots_txt_get_site_option( $site_id, SHAWESOME_ROBOTS_TXT_STATUS_OPTION, null );

	return is_array( $status ) && isset( $status['time'] ) ? $status : null;
}

/**
 * Whether a stored status is older than the time set in the settings.
 *
 * @param array|null $status Status.
 * @return bool
 */
function shawesome_robots_txt_status_outdated( $status ) {
	$settings = shawesome_robots_txt_get_settings();

	return ! $status || $status['time'] + $settings['status_hours'] * HOUR_IN_SECONDS < time();
}

/**
 * Items of a status: delivery problems, blocked crawlers, sitemaps and AI
 * training crawlers, each with a type (good, warn, bad, neutral).
 *
 * @param int        $site_id Site ID.
 * @param array|null $status  Status.
 * @return array<int, array{0: string, 1: string}> Type and escaped text.
 */
function shawesome_robots_txt_status_items( $site_id, $status ) {
	$settings = shawesome_robots_txt_get_settings();
	$items    = array();

	if ( is_file( shawesome_robots_txt_physical_file() ) ) {
		$items[] = array( 'warn', esc_html__( 'Physical robots.txt in the web root is delivered', 'shawesome-robots-txt' ) );
	}
	if ( $settings['respect_public'] && ! shawesome_robots_txt_is_public( $site_id ) ) {
		$items[] = array( 'warn', esc_html__( 'Hidden from search engines', 'shawesome-robots-txt' ) );
	}

	if ( ! $status ) {
		$items[] = array( 'neutral', esc_html__( 'Not checked yet', 'shawesome-robots-txt' ) );
	} elseif ( '' !== $status['error'] ) {
		/* translators: %s: error message or HTTP status code */
		$items[] = array( 'bad', esc_html( sprintf( __( 'Not reachable (%s)', 'shawesome-robots-txt' ), $status['error'] ) ) );
	} else {
		if ( $status['all_blocked'] ) {
			$items[] = array( 'bad', esc_html__( 'All crawlers blocked', 'shawesome-robots-txt' ) );
		}

		$items[] = $status['sitemaps']
			/* translators: %d: number of Sitemap lines */
			? array( 'good', esc_html( sprintf( _n( '%d sitemap linked', '%d sitemaps linked', $status['sitemaps'], 'shawesome-robots-txt' ), $status['sitemaps'] ) ) )
			: array( 'warn', esc_html__( 'No sitemap linked', 'shawesome-robots-txt' ) );

		if ( $status['ai_blocked'] === $status['ai_total'] ) {
			$items[] = array( 'good', esc_html__( 'AI training blocked', 'shawesome-robots-txt' ) );
		} elseif ( $status['ai_blocked'] ) {
			/* translators: 1: number of blocked AI training crawlers, 2: number of known AI training crawlers */
			$items[] = array( 'warn', esc_html( sprintf( __( 'AI training partly blocked (%1$d of %2$d)', 'shawesome-robots-txt' ), $status['ai_blocked'], $status['ai_total'] ) ) );
		} else {
			$items[] = array( 'warn', esc_html__( 'AI training not blocked', 'shawesome-robots-txt' ) );
		}
	}

	return $items;
}

/**
 * HTML of a status, as lines (overview) or as badges (editor), followed by
 * the time of the check and a link to check again.
 *
 * @param int        $site_id Site ID.
 * @param array|null $status  Status.
 * @param string     $format  'lines' or 'pills'.
 * @return string
 */
function shawesome_robots_txt_status_html( $site_id, $status, $format = 'lines' ) {
	$html = '';
	foreach ( shawesome_robots_txt_status_items( $site_id, $status ) as $item ) {
		$html .= 'pills' === $format
			? '<span class="shrt-pill shrt-pill--' . esc_attr( $item[0] ) . '">' . $item[1] . '</span>'
			: '<span class="shrt-line shrt-line--' . esc_attr( $item[0] ) . '">' . $item[1] . '</span>';
	}

	$meta = '';
	if ( $status ) {
		/* translators: %s: time since the check, e.g. "2 hours" */
		$meta = esc_html( sprintf( __( 'Checked %s ago', 'shawesome-robots-txt' ), human_time_diff( $status['time'] ) ) ) . ' · ';
	}
	$meta .= '<a href="#" data-shrt-scan="one">' . esc_html__( 'Check again', 'shawesome-robots-txt' ) . '</a>';

	return $html . '<span class="shrt-meta">' . $meta . '</span>';
}

/**
 * AJAX: check the robots.txt of a site and return its status HTML.
 */
function shawesome_robots_txt_ajax_scan() {
	check_ajax_referer( 'shawesome_robots_txt_scan' );
	shawesome_robots_txt_check_cap();

	$site_id = shawesome_robots_txt_posted_site_id();
	$format  = isset( $_POST['format'] ) && 'pills' === $_POST['format'] ? 'pills' : 'lines';

	wp_send_json_success( array( 'html' => shawesome_robots_txt_status_html( $site_id, shawesome_robots_txt_scan( $site_id ), $format ) ) );
}
add_action( 'wp_ajax_shawesome_robots_txt_scan', 'shawesome_robots_txt_ajax_scan' );

/**
 * Example rules for a site: the WordPress default plus its sitemap. Allow
 * comes first: crawlers do not care about the order (the most specific rule
 * wins), but Yoast SEO removes the default block in its exact order.
 *
 * @param int $site_id Site ID.
 * @return string
 */
function shawesome_robots_txt_example_rules( $site_id ) {
	return "User-agent: *\nAllow: /wp-admin/admin-ajax.php\nDisallow: /wp-admin/\n\nSitemap: " . get_home_url( $site_id, 'wp-sitemap.xml' );
}

/**
 * Rules that block crawlers collecting content for AI training. AI search
 * crawlers (OAI-SearchBot, Claude-SearchBot, PerplexityBot) get no group of
 * their own on purpose: they follow the rules for all crawlers, a group of
 * their own would make them ignore those.
 *
 * @return string
 */
function shawesome_robots_txt_ai_rules() {
	return "# Block crawlers that collect content for AI training.\n"
		. "# AI search crawlers (OAI-SearchBot, Claude-SearchBot, PerplexityBot) follow the rules for all crawlers.\n"
		. 'User-agent: ' . implode( "\nUser-agent: ", shawesome_robots_txt_ai_agents() ) . "\nDisallow: /";
}

/**
 * Add a "Settings" link to the plugin row.
 *
 * @param string[] $links Action links.
 * @return string[]
 */
function shawesome_robots_txt_action_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( shawesome_robots_txt_page_url() ) . '">' . esc_html__( 'Settings', 'shawesome-robots-txt' ) . '</a>' );

	return $links;
}
add_filter(
	( is_multisite() ? 'network_admin_plugin_action_links_' : 'plugin_action_links_' ) . plugin_basename( dirname( __DIR__ ) . '/shawesome-robots-txt.php' ),
	'shawesome_robots_txt_action_links'
);

/**
 * Check the capability for an action, die otherwise.
 */
function shawesome_robots_txt_check_cap() {
	if ( ! current_user_can( shawesome_robots_txt_capability() ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to manage the robots.txt.', 'shawesome-robots-txt' ) );
	}
}

/**
 * Site posted to a per-site action, die if it does not exist. On a single
 * site this is always the current site.
 *
 * @return int Site ID.
 */
function shawesome_robots_txt_posted_site_id() {
	if ( ! is_multisite() ) {
		return get_current_blog_id();
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the caller.
	$site = isset( $_POST['site_id'] ) ? get_site( absint( $_POST['site_id'] ) ) : null;

	if ( ! $site ) {
		wp_die( esc_html__( 'Site not found.', 'shawesome-robots-txt' ) );
	}

	return (int) $site->blog_id;
}

/**
 * Hidden fields that send a form to admin-post.php.
 *
 * @param string $action Action name without prefix.
 * @param string $nonce  Nonce action.
 */
function shawesome_robots_txt_form_fields( $action, $nonce ) {
	echo '<input type="hidden" name="action" value="' . esc_attr( 'shawesome_robots_txt_' . $action ) . '">';
	wp_nonce_field( $nonce );
}

/**
 * Save the settings.
 */
function shawesome_robots_txt_save_settings() {
	check_admin_referer( 'shawesome_robots_txt_save' );
	shawesome_robots_txt_check_cap();

	$mode     = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'base';
	$settings = shawesome_robots_txt_get_settings();

	update_site_option(
		SHAWESOME_ROBOTS_TXT_OPTION,
		array(
			'mode'           => in_array( $mode, array( 'exact', 'base' ), true ) ? $mode : 'base',
			'respect_public' => ! empty( $_POST['respect_public'] ),
			// The option is only shown in networks with subdirectories; keep it otherwise.
			'path_sites'     => isset( $_POST['path_sites_shown'] ) ? ! empty( $_POST['path_sites'] ) : $settings['path_sites'],
			'status_hours'   => isset( $_POST['status_hours'] ) ? min( 720, max( 1, absint( $_POST['status_hours'] ) ) ) : $settings['status_hours'],
		)
	);

	wp_safe_redirect(
		shawesome_robots_txt_page_url(
			array(
				'tab'     => 'settings',
				'updated' => 'settings',
			)
		)
	);
	exit;
}
add_action( 'admin_post_shawesome_robots_txt_save', 'shawesome_robots_txt_save_settings' );

/**
 * Redirect back to the editor of a site after an action.
 *
 * @param int    $site_id Site ID.
 * @param string $updated Result of the action.
 */
function shawesome_robots_txt_redirect_to_site( $site_id, $updated ) {
	$args = array( 'updated' => $updated );
	if ( is_multisite() ) {
		$args['site'] = $site_id;
	}

	wp_safe_redirect( shawesome_robots_txt_page_url( $args ) );
	exit;
}

/**
 * Save a site's robots.txt content.
 */
function shawesome_robots_txt_save_site() {
	$site_id = shawesome_robots_txt_posted_site_id();
	check_admin_referer( 'shawesome_robots_txt_save_site_' . $site_id );
	shawesome_robots_txt_check_cap();

	// robots.txt is served as text/plain and may contain characters such as
	// "%" or "*" that sanitize_textarea_field() would alter, so only invalid
	// UTF-8 and NUL bytes are removed.
	$content = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$content = str_replace( "\0", '', wp_check_invalid_utf8( (string) $content, true ) );

	shawesome_robots_txt_set_db_content( $site_id, $content );

	$mode = isset( $_POST['site_mode'] ) ? sanitize_key( wp_unslash( $_POST['site_mode'] ) ) : '';
	shawesome_robots_txt_set_site_option( $site_id, SHAWESOME_ROBOTS_TXT_MODE_OPTION, in_array( $mode, array( 'base', 'exact' ), true ) ? $mode : '' );

	// Only values that differ from the defaults are stored.
	$rules = array_filter(
		array(
			'crawl_delay' => empty( $_POST['crawl_delay_on'] ) ? 0 : min( 60, max( 1, absint( $_POST['crawl_delay'] ?? 10 ) ) ),
			'css_js'      => empty( $_POST['css_js'] ) ? 0 : null,
			'images'      => empty( $_POST['images'] ) ? 0 : null,
			'pdfs'        => empty( $_POST['pdfs'] ) ? 0 : null,
		),
		function ( $value, $key ) {
			return 'crawl_delay' === $key ? $value > 0 : null !== $value;
		},
		ARRAY_FILTER_USE_BOTH
	);
	shawesome_robots_txt_set_site_option( $site_id, SHAWESOME_ROBOTS_TXT_RULES_OPTION, $rules );

	shawesome_robots_txt_redirect_to_site( $site_id, 'site' );
}
add_action( 'admin_post_shawesome_robots_txt_save_site', 'shawesome_robots_txt_save_site' );

/**
 * Copy a site's override file into the database.
 */
function shawesome_robots_txt_import_file() {
	$site_id = shawesome_robots_txt_posted_site_id();
	check_admin_referer( 'shawesome_robots_txt_import_' . $site_id );
	shawesome_robots_txt_check_cap();

	$file    = shawesome_robots_txt_find_file( shawesome_robots_txt_host( $site_id ) );
	$content = null === $file ? null : shawesome_robots_txt_read_file( $file );

	if ( null !== $content ) {
		shawesome_robots_txt_set_db_content( $site_id, $content );
	}

	shawesome_robots_txt_redirect_to_site( $site_id, null === $content ? 'import-failed' : 'imported' );
}
add_action( 'admin_post_shawesome_robots_txt_import', 'shawesome_robots_txt_import_file' );

/**
 * Path of a physical robots.txt in the web root, which the web server
 * delivers instead of WordPress. In a network all sites share it.
 *
 * @return string Absolute path (the file may not exist).
 */
function shawesome_robots_txt_physical_file() {
	if ( ! function_exists( 'get_home_path' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	return get_home_path() . 'robots.txt';
}

/**
 * Whether WordPress can rename files in the web root without asking for
 * FTP credentials.
 *
 * @return bool
 */
function shawesome_robots_txt_can_rename_physical() {
	if ( ! function_exists( 'get_filesystem_method' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	return 'direct' === get_filesystem_method( array(), dirname( shawesome_robots_txt_physical_file() ) );
}

/**
 * Rename the physical robots.txt to _old-robots.txt (with a date suffix if
 * that name is taken), optionally after copying its content into the
 * database of the main site or the single site.
 */
function shawesome_robots_txt_rename_physical() {
	check_admin_referer( 'shawesome_robots_txt_physical' );
	shawesome_robots_txt_check_cap();

	$file   = shawesome_robots_txt_physical_file();
	$copy   = ! empty( $_POST['copy'] );
	$result = 'rename-failed';
	$target = '';

	global $wp_filesystem;

	if ( is_file( $file ) && shawesome_robots_txt_can_rename_physical() && WP_Filesystem() ) {
		$target = dirname( $file ) . '/_old-robots.txt';
		if ( $wp_filesystem->exists( $target ) ) {
			$target = dirname( $file ) . '/_old-robots-' . gmdate( 'Y-m-d-His' ) . '.txt';
		}

		$content = $copy ? shawesome_robots_txt_read_file( $file ) : '';

		if ( null !== $content && ! $wp_filesystem->exists( $target ) && $wp_filesystem->move( $file, $target ) ) {
			if ( $copy ) {
				shawesome_robots_txt_set_db_content( is_multisite() ? get_main_site_id() : get_current_blog_id(), $content );
			}
			$result = $copy ? 'copied-renamed' : 'renamed';
		}
	}

	wp_safe_redirect(
		shawesome_robots_txt_page_url(
			array(
				'updated' => $result,
				'renamed' => 'rename-failed' === $result ? false : basename( $target ),
			)
		)
	);
	exit;
}
add_action( 'admin_post_shawesome_robots_txt_physical', 'shawesome_robots_txt_rename_physical' );

/**
 * Render the settings page: in a network the overview or, with ?site=ID,
 * the editor of one site; on a single site settings and editor.
 */
function shawesome_robots_txt_render_page() {
	if ( ! current_user_can( shawesome_robots_txt_capability() ) ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
	$site    = is_multisite() && isset( $_GET['site'] ) ? get_site( absint( $_GET['site'] ) ) : null;
	$updated = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : '';
	$renamed = isset( $_GET['renamed'] ) ? sanitize_file_name( wp_unslash( $_GET['renamed'] ) ) : '';
	$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
	// phpcs:enable

	$tabs = shawesome_robots_txt_tabs();
	if ( ! isset( $tabs[ $tab ] ) || $site ) {
		$tab = 'robots';
	}

	echo '<div class="wrap">';
	// Same text as the plugin name in the header, so it shares its translation.
	echo '<h1>' . esc_html__( 'shawesome robots.txt – Per-Site Rules for Single Sites and Multisite', 'shawesome-robots-txt' ) . '</h1>';
	/* translators: %s: version number of the plugin */
	echo '<p class="shrt-version">' . esc_html( sprintf( __( 'Version %s', 'shawesome-robots-txt' ), SHAWESOME_ROBOTS_TXT_VERSION ) ) . '</p>';

	echo '<nav class="nav-tab-wrapper">';
	foreach ( $tabs as $slug => $label ) {
		printf(
			'<a href="%s" class="%s"%s>%s</a>',
			esc_url( shawesome_robots_txt_page_url( 'robots' === $slug ? array() : array( 'tab' => $slug ) ) ),
			esc_attr( $slug === $tab ? 'nav-tab nav-tab-active' : 'nav-tab' ),
			$slug === $tab ? ' aria-current="page"' : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed string.
			esc_html( $label )
		);
	}
	echo '</nav>';

	shawesome_robots_txt_render_result( $updated, $renamed );

	if ( 'settings' === $tab ) {
		shawesome_robots_txt_render_settings();
	} elseif ( 'faq' === $tab ) {
		shawesome_robots_txt_render_faq();
	} elseif ( $site ) {
		shawesome_robots_txt_render_editor( (int) $site->blog_id, $updated );
	} else {
		shawesome_robots_txt_render_warnings();

		if ( is_multisite() ) {
			shawesome_robots_txt_render_sites();
		} else {
			shawesome_robots_txt_render_editor( get_current_blog_id(), $updated );
		}
	}

	echo '</div>';
}

/**
 * Render a notice.
 *
 * @param string $message Message, may contain <code>.
 * @param string $type    'success', 'info', 'warning' or 'error'.
 * @param bool   $inline  Whether the notice stays in place (not moved below the heading).
 */
function shawesome_robots_txt_notice( $message, $type = 'success', $inline = false ) {
	printf(
		'<div class="notice notice-%s%s"><p>%s</p></div>',
		esc_attr( $type ),
		$inline ? ' inline' : ' is-dismissible',
		wp_kses( $message, array( 'code' => array() ) )
	);
}

/**
 * Render the result of the last action.
 *
 * @param string $updated Result of the last action.
 * @param string $renamed New name of the physical robots.txt, if renamed.
 */
function shawesome_robots_txt_render_result( $updated, $renamed ) {
	/* translators: %s: new file name of the physical robots.txt */
	$renamed_text = sprintf( esc_html__( 'The physical robots.txt was renamed to %s.', 'shawesome-robots-txt' ), '<code>' . esc_html( $renamed ) . '</code>' );

	$messages = array(
		'settings'       => array( esc_html__( 'Settings saved.', 'shawesome-robots-txt' ), 'success' ),
		'site'           => array( esc_html__( 'robots.txt saved.', 'shawesome-robots-txt' ), 'success' ),
		'imported'       => array( esc_html__( 'File copied into the database. Delete the file to make the database content active.', 'shawesome-robots-txt' ), 'success' ),
		'import-failed'  => array( esc_html__( 'The file could not be read.', 'shawesome-robots-txt' ), 'error' ),
		'renamed'        => array( $renamed_text, 'success' ),
		'copied-renamed' => array( $renamed_text . ' ' . esc_html__( 'Its content is now stored in the database.', 'shawesome-robots-txt' ), 'success' ),
		'rename-failed'  => array( esc_html__( 'The physical robots.txt could not be renamed. Rename or delete it via FTP or your hosting panel.', 'shawesome-robots-txt' ), 'error' ),
	);

	if ( isset( $messages[ $updated ] ) ) {
		shawesome_robots_txt_notice( $messages[ $updated ][0], $messages[ $updated ][1] );
	}
}

/**
 * Render warnings about the installation: a physical robots.txt, a single
 * site with a path in its home URL, SEO plugins in "Base" mode.
 */
function shawesome_robots_txt_render_warnings() {
	if ( ! is_multisite() && ! shawesome_robots_txt_serves_own( get_current_blog_id() ) ) {
		shawesome_robots_txt_notice(
			sprintf(
				/* translators: %s: URL of the robots.txt at the root of the host */
				esc_html__( 'The address of this site contains a path. Crawlers only request the robots.txt at the root of the host (%s), which WordPress cannot deliver here, so the plugin does nothing.', 'shawesome-robots-txt' ),
				'<code>' . esc_html( set_url_scheme( 'http://' . shawesome_robots_txt_host( get_current_blog_id() ) . '/robots.txt' ) ) . '</code>'
			),
			'error',
			true
		);
	}

	shawesome_robots_txt_render_physical();

	$sites   = is_multisite() ? get_sites(
		array(
			'number'  => 0,
			'deleted' => 0,
			'fields'  => 'ids',
		)
	) : array( get_current_blog_id() );
	$any_seo = false;
	foreach ( $sites as $site_id ) {
		if ( 'base' === shawesome_robots_txt_mode( (int) $site_id ) && shawesome_robots_txt_active_seo_plugins( (int) $site_id ) ) {
			$any_seo = true;
			break;
		}
	}

	if ( $any_seo ) {
		shawesome_robots_txt_notice( esc_html__( 'An SEO plugin is active on a site in "Base" mode. In "Base" mode, Yoast SEO removes the WordPress default rules and appends its own rules and sitemap, and All in One SEO rebuilds the robots.txt: comments and Sitemap lines are dropped. Use "Exact" mode if the content should be delivered as is.', 'shawesome-robots-txt' ), 'warning', true );
	}
}

/**
 * Render the warning about a physical robots.txt with its content, a link
 * and the buttons to rename it.
 */
function shawesome_robots_txt_render_physical() {
	$file = shawesome_robots_txt_physical_file();
	if ( ! is_file( $file ) ) {
		return;
	}

	$content = shawesome_robots_txt_read_file( $file );
	?>
	<div class="notice notice-error inline">
		<p>
			<?php
			printf(
				/* translators: %s: path of the physical robots.txt file */
				esc_html__( 'A physical robots.txt exists at %s. The web server delivers it directly, so WordPress and this plugin are never asked.', 'shawesome-robots-txt' ),
				'<code>' . esc_html( $file ) . '</code>'
			);
			?>
			<a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View it', 'shawesome-robots-txt' ); ?></a>
		</p>
		<?php if ( null !== $content ) : ?>
			<textarea class="large-text code" rows="8" readonly><?php echo esc_textarea( $content ); ?></textarea>
		<?php endif; ?>
		<?php if ( shawesome_robots_txt_can_rename_physical() ) : ?>
			<p><?php esc_html_e( 'Rename it to use the robots.txt of this plugin. The renamed file stays in the web root and can be restored at any time.', 'shawesome-robots-txt' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php shawesome_robots_txt_form_fields( 'physical', 'shawesome_robots_txt_physical' ); ?>
				<p>
					<?php if ( null !== $content ) : ?>
						<button type="submit" name="copy" value="1" class="button button-primary"><?php echo esc_html( is_multisite() ? __( 'Copy into the editor of the main site and rename', 'shawesome-robots-txt' ) : __( 'Copy into the editor and rename', 'shawesome-robots-txt' ) ); ?></button>
					<?php endif; ?>
					<button type="submit" class="button"><?php esc_html_e( 'Rename to _old-robots.txt', 'shawesome-robots-txt' ); ?></button>
				</p>
			</form>
		<?php else : ?>
			<p><?php esc_html_e( 'WordPress cannot rename it on this server. Rename or delete it via FTP or your hosting panel.', 'shawesome-robots-txt' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Render the settings form.
 */
function shawesome_robots_txt_render_settings() {
	$settings = shawesome_robots_txt_get_settings();
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php shawesome_robots_txt_form_fields( 'save', 'shawesome_robots_txt_save' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Default mode', 'shawesome-robots-txt' ); ?></th>
				<td>
					<p class="description"><?php echo esc_html( is_multisite() ? __( 'Applies to all sites that do not choose their own mode in their editor.', 'shawesome-robots-txt' ) : __( 'Applies unless the editor chooses another mode.', 'shawesome-robots-txt' ) ); ?></p>
					<fieldset>
						<label>
							<input type="radio" name="mode" value="base" <?php checked( $settings['mode'], 'base' ); ?>>
							<?php
							printf(
								/* translators: %s: name of the mode "Base" */
								esc_html__( '%s – the content replaces the WordPress default. SEO plugins may add their rules and sitemap; duplicate Sitemap lines are removed.', 'shawesome-robots-txt' ),
								'<strong>' . esc_html_x( 'Base', 'robots.txt mode', 'shawesome-robots-txt' ) . '</strong>'
							);
							?>
						</label><br>
						<label>
							<input type="radio" name="mode" value="exact" <?php checked( $settings['mode'], 'exact' ); ?>>
							<?php
							printf(
								/* translators: %s: name of the mode "Exact" */
								esc_html__( '%s – a custom robots.txt is delivered as is. SEO plugins (Yoast SEO, All in One SEO …) cannot change it. Sites without a custom robots.txt keep the one from WordPress and the SEO plugins.', 'shawesome-robots-txt' ),
								'<strong>' . esc_html_x( 'Exact', 'robots.txt mode', 'shawesome-robots-txt' ) . '</strong>'
							);
							?>
						</label>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Search engine visibility', 'shawesome-robots-txt' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="respect_public" value="1" <?php checked( $settings['respect_public'] ); ?>>
						<?php esc_html_e( 'Ignore the custom robots.txt on sites set to "Discourage search engines from indexing this site" (WordPress delivers its default robots.txt).', 'shawesome-robots-txt' ); ?>
					</label>
				</td>
			</tr>
			<?php if ( is_multisite() && ! is_subdomain_install() ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sites with a path', 'shawesome-robots-txt' ); ?></th>
					<td>
						<input type="hidden" name="path_sites_shown" value="1">
						<label>
							<input type="checkbox" name="path_sites" value="1" <?php checked( $settings['path_sites'] ); ?>>
							<?php esc_html_e( 'Add rules for sites whose address contains a path (example.com/blog/) to the robots.txt of their host, also in "Exact" mode: Disallow for sites hidden from search engines, Sitemap for public sites.', 'shawesome-robots-txt' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'The Disallow lines are added as a group for all crawlers. Crawlers that have a group of their own in the robots.txt do not read it.', 'shawesome-robots-txt' ); ?></p>
					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><label for="shawesome-robots-txt-status-hours"><?php esc_html_e( 'Keep status for', 'shawesome-robots-txt' ); ?></label></th>
				<td>
					<input type="number" id="shawesome-robots-txt-status-hours" name="status_hours" min="1" max="720" step="1" class="small-text" value="<?php echo esc_attr( $settings['status_hours'] ); ?>">
					<?php esc_html_e( 'hours', 'shawesome-robots-txt' ); ?>
					<p class="description"><?php esc_html_e( 'The status of a robots.txt (blocked crawlers, sitemaps, AI training) is checked again when the overview is opened after this time. It can be checked at any time with "Check again".', 'shawesome-robots-txt' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Save settings', 'shawesome-robots-txt' ) ); ?>
	</form>
	<?php
}

/**
 * Render the editor of one site: a header with the status, cards for the
 * mode, the rules and the crawler options, the output (preview and live) and
 * a bar to save.
 *
 * @param int    $site_id Site ID.
 * @param string $updated Result of the last action.
 */
function shawesome_robots_txt_render_editor( $site_id, $updated = '' ) {
	if ( ! shawesome_robots_txt_serves_own( $site_id ) ) {
		if ( is_multisite() ) {
			shawesome_robots_txt_render_served_by( $site_id );
		}
		return;
	}

	$resolved = shawesome_robots_txt_resolve( $site_id );
	$settings = shawesome_robots_txt_get_settings();
	$mode     = shawesome_robots_txt_mode( $site_id );
	$seo      = shawesome_robots_txt_active_seo_plugins( $site_id );
	$hidden   = $settings['respect_public'] && ! shawesome_robots_txt_is_public( $site_id );
	$fetched  = shawesome_robots_txt_fetch( $site_id );
	$status   = shawesome_robots_txt_scan( $site_id, $fetched );

	if ( $hidden ) {
		shawesome_robots_txt_notice( esc_html__( 'This site is hidden from search engines, so the custom robots.txt is ignored and WordPress delivers its default robots.txt.', 'shawesome-robots-txt' ), 'warning', true );
	}

	// Yoast SEO removes this block wherever it is (same pattern as in its robots.txt integration).
	$yoast_block = '`User-agent: \*[\r\n]+Disallow: /wp-admin/[\r\n]+Allow: /wp-admin/admin-ajax\.php[\r\n]+`';
	if ( 'base' === $mode && in_array( 'Yoast SEO', $seo, true ) && preg_match( $yoast_block, (string) $resolved['content'] . "\n" ) ) {
		shawesome_robots_txt_notice( esc_html__( 'In "Base" mode, Yoast SEO removes the lines "User-agent: *", "Disallow: /wp-admin/" and "Allow: /wp-admin/admin-ajax.php" when they appear in this order. Put the Allow line first (crawlers do not care about the order) or use "Exact" mode.', 'shawesome-robots-txt' ), 'warning', true );
	}

	if ( 'file' === $resolved['source'] ) :
		shawesome_robots_txt_notice(
			sprintf(
				/* translators: %s: file path */
				esc_html__( 'This site is overridden by the file %s. The file is delivered; your rules below are only used once the file is removed.', 'shawesome-robots-txt' ),
				'<code>' . esc_html( $resolved['file'] ) . '</code>'
			),
			'info',
			true
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php shawesome_robots_txt_form_fields( 'import', 'shawesome_robots_txt_import_' . $site_id ); ?>
			<input type="hidden" name="site_id" value="<?php echo esc_attr( $site_id ); ?>">
			<?php submit_button( __( 'Copy file into your rules', 'shawesome-robots-txt' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	endif;

	$site_mode = shawesome_robots_txt_site_mode( $site_id );
	$rules     = shawesome_robots_txt_site_rules( $site_id );
	$modes     = array(
		''      => array(
			__( 'Default', 'shawesome-robots-txt' ),
			/* translators: %s: name of the default mode */
			sprintf( __( 'Uses the "%s" mode from the settings.', 'shawesome-robots-txt' ), shawesome_robots_txt_mode_label( $settings['mode'] ) ),
		),
		'base'  => array( shawesome_robots_txt_mode_label( 'base' ), __( 'Your rules replace the WordPress default; SEO plugins may still change them.', 'shawesome-robots-txt' ) ),
		'exact' => array( shawesome_robots_txt_mode_label( 'exact' ), __( 'Delivered exactly as written. SEO plugins cannot change it.', 'shawesome-robots-txt' ) ),
	);
	$resources = array(
		'css_js' => array( __( 'CSS and JavaScript', 'shawesome-robots-txt' ), __( 'Google needs these to render your pages and to check whether they work on mobile devices.', 'shawesome-robots-txt' ), true ),
		'images' => array( __( 'Images', 'shawesome-robots-txt' ), __( 'Lets crawlers fetch images for Google Images and other image searches.', 'shawesome-robots-txt' ), true ),
		'pdfs'   => array( __( 'PDF files', 'shawesome-robots-txt' ), __( 'Lets PDF documents appear in search results.', 'shawesome-robots-txt' ), false ),
	);
	$blocked   = 3 - count( array_filter( array( $rules['css_js'], $rules['images'], $rules['pdfs'] ) ) );

	// Lines the preview needs; built in PHP so that they match the robots.txt.
	$blocked_lines = array();
	foreach ( array( 'css_js', 'images', 'pdfs' ) as $key ) {
		$blocked_lines[ $key ] = shawesome_robots_txt_site_rule_lines_for( $key );
	}
	$data = array(
		'defaultMode' => $settings['mode'],
		'seo'         => wp_sprintf( '%l', $seo ),
		'file'        => 'file' === $resolved['source'] ? $resolved['content'] : null,
		'live'        => $fetched['body'],
		'physical'    => is_file( shawesome_robots_txt_physical_file() ),
		'hidden'      => $hidden,
		'paths'       => $settings['path_sites'] ? shawesome_robots_txt_path_rules( shawesome_robots_txt_host( $site_id ) ) : '',
		'blocked'     => $blocked_lines,
		'saved'       => 'site' === $updated,
	);
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="shrt-editor" data-shrt="<?php echo esc_attr( wp_json_encode( $data ) ); ?>">
		<?php shawesome_robots_txt_form_fields( 'save_site', 'shawesome_robots_txt_save_site_' . $site_id ); ?>
		<input type="hidden" name="site_id" value="<?php echo esc_attr( $site_id ); ?>">

		<div class="shrt-card shrt-head">
			<div class="shrt-head__site">
				<?php if ( is_multisite() ) : ?>
					<a href="<?php echo esc_url( shawesome_robots_txt_page_url() ); ?>">&larr; <?php esc_html_e( 'All sites', 'shawesome-robots-txt' ); ?></a>
				<?php endif; ?>
				<h2><?php echo esc_html( shawesome_robots_txt_host( $site_id ) ); ?></h2>
			</div>
			<div class="shrt-head__actions">
				<div class="shrt-head__status" data-shrt-status="<?php echo esc_attr( $site_id ); ?>" data-format="pills">
					<?php echo shawesome_robots_txt_status_html( $site_id, $status, 'pills' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in shawesome_robots_txt_status_html(). ?>
				</div>
				<a class="button" href="<?php echo esc_url( get_home_url( $site_id, 'robots.txt' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open live robots.txt', 'shawesome-robots-txt' ); ?> <span aria-hidden="true">&#8599;</span><span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'shawesome-robots-txt' ); ?></span></a>
			</div>
		</div>

		<div class="shrt-layout">
			<div class="shrt-main">
				<section class="shrt-card">
					<header class="shrt-card__head">
						<div class="shrt-card__title">
							<h3>1 &middot; <?php esc_html_e( 'Mode', 'shawesome-robots-txt' ); ?></h3>
							<span><?php esc_html_e( 'How your rules are combined with WordPress and SEO plugins.', 'shawesome-robots-txt' ); ?></span>
						</div>
					</header>
					<fieldset class="shrt-modes">
						<legend class="screen-reader-text"><?php esc_html_e( 'Mode', 'shawesome-robots-txt' ); ?></legend>
						<?php foreach ( $modes as $value => $texts ) : ?>
							<label class="shrt-mode">
								<span class="shrt-mode__title"><input type="radio" name="site_mode" value="<?php echo esc_attr( $value ); ?>" <?php checked( $site_mode, $value ); ?>> <?php echo esc_html( $texts[0] ); ?></span>
								<span class="shrt-mode__desc"><?php echo esc_html( $texts[1] ); ?></span>
							</label>
						<?php endforeach; ?>
					</fieldset>
				</section>

				<section class="shrt-card">
					<header class="shrt-card__head">
						<div class="shrt-card__title">
							<h3>2 &middot; <label for="shrt-content"><?php esc_html_e( 'Your rules', 'shawesome-robots-txt' ); ?></label></h3>
							<span><?php esc_html_e( 'Leave empty to keep the robots.txt generated by WordPress and SEO plugins.', 'shawesome-robots-txt' ); ?></span>
						</div>
						<div class="shrt-card__actions">
							<button type="button" class="button button-small" aria-controls="shrt-content" data-shrt-insert="replace" data-text="<?php echo esc_attr( shawesome_robots_txt_example_rules( $site_id ) ); ?>" data-confirm="<?php esc_attr_e( 'Replace the current content with the example rules?', 'shawesome-robots-txt' ); ?>">+ <?php esc_html_e( 'Example rules', 'shawesome-robots-txt' ); ?></button>
							<button type="button" class="button button-small" aria-controls="shrt-content" data-shrt-insert="append" data-text="<?php echo esc_attr( shawesome_robots_txt_ai_rules() ); ?>">+ <?php esc_html_e( 'Block AI training, allow AI search', 'shawesome-robots-txt' ); ?></button>
						</div>
					</header>
					<textarea id="shrt-content" name="content" class="shrt-rules" spellcheck="false" placeholder="<?php echo esc_attr( shawesome_robots_txt_example_rules( $site_id ) ); ?>"><?php echo esc_textarea( shawesome_robots_txt_get_db_content( $site_id ) ); ?></textarea>
				</section>

				<section class="shrt-card">
					<header class="shrt-card__head">
						<div class="shrt-card__title">
							<h3>3 &middot; <?php esc_html_e( 'Crawler options', 'shawesome-robots-txt' ); ?></h3>
							<span><?php echo wp_kses( __( 'Added at the end as a <code>User-agent: *</code> group, in both modes. Crawlers with a group of their own ignore it.', 'shawesome-robots-txt' ), array( 'code' => array() ) ); ?></span>
						</div>
					</header>

					<details class="shrt-group"<?php echo $rules['crawl_delay'] ? ' open' : ''; ?>>
						<summary>
							<span class="shrt-group__text"><strong><?php esc_html_e( 'Crawl rate', 'shawesome-robots-txt' ); ?></strong><span><?php esc_html_e( 'Ask crawlers to wait between requests.', 'shawesome-robots-txt' ); ?></span></span>
							<?php /* translators: %d: seconds */ ?>
							<span class="shrt-badge<?php echo $rules['crawl_delay'] ? ' is-active' : ''; ?>" data-shrt-summary="rate"><?php echo esc_html( $rules['crawl_delay'] ? sprintf( __( '%d s delay', 'shawesome-robots-txt' ), $rules['crawl_delay'] ) : __( 'No limit', 'shawesome-robots-txt' ) ); ?></span>
						</summary>
						<div class="shrt-option">
							<input type="checkbox" id="shrt-crawl-delay-on" name="crawl_delay_on" value="1" <?php checked( $rules['crawl_delay'] > 0 ); ?>>
							<div>
								<label for="shrt-crawl-delay-on" class="shrt-option__title"><?php esc_html_e( 'Crawl-delay', 'shawesome-robots-txt' ); ?></label>
								<input type="number" name="crawl_delay" min="1" max="60" step="1" class="small-text" value="<?php echo esc_attr( $rules['crawl_delay'] ? $rules['crawl_delay'] : 10 ); ?>" aria-label="<?php esc_attr_e( 'Crawl-delay in seconds', 'shawesome-robots-txt' ); ?>"<?php disabled( 0, $rules['crawl_delay'] ); ?>>
								<?php esc_html_e( 'seconds', 'shawesome-robots-txt' ); ?>
								<p><?php esc_html_e( 'Ignored by Google; followed by Bing and Yandex, for example.', 'shawesome-robots-txt' ); ?></p>
							</div>
						</div>
					</details>

					<details class="shrt-group" open>
						<summary>
							<span class="shrt-group__text"><strong><?php esc_html_e( 'Resources and assets', 'shawesome-robots-txt' ); ?></strong><span><?php esc_html_e( 'File types crawlers may fetch. Unchecked types are blocked.', 'shawesome-robots-txt' ); ?></span></span>
							<?php /* translators: %d: number of blocked file types */ ?>
							<span class="shrt-badge<?php echo $blocked ? ' is-active' : ''; ?>" data-shrt-summary="resources"><?php echo esc_html( $blocked ? sprintf( __( '%d blocked', 'shawesome-robots-txt' ), $blocked ) : __( 'All allowed', 'shawesome-robots-txt' ) ); ?></span>
						</summary>
						<?php foreach ( $resources as $key => $texts ) : ?>
							<div class="shrt-option">
								<input type="checkbox" id="shrt-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $rules[ $key ] ); ?>>
								<div>
									<label for="shrt-<?php echo esc_attr( $key ); ?>" class="shrt-option__title"><?php echo esc_html( $texts[0] ); ?></label>
									<?php if ( $texts[2] ) : ?>
										<span class="shrt-tag"><?php esc_html_e( 'recommended', 'shawesome-robots-txt' ); ?></span>
									<?php endif; ?>
									<p><?php echo esc_html( $texts[1] ); ?></p>
								</div>
							</div>
						<?php endforeach; ?>
					</details>
				</section>
			</div>

			<aside class="shrt-side">
				<section class="shrt-card">
					<header class="shrt-card__head shrt-card__head--row">
						<h3><?php esc_html_e( 'Output', 'shawesome-robots-txt' ); ?></h3>
						<div class="shrt-switch" role="group" aria-label="<?php esc_attr_e( 'Output', 'shawesome-robots-txt' ); ?>">
							<button type="button" data-shrt-view="preview" aria-pressed="true"><?php esc_html_e( 'Preview after save', 'shawesome-robots-txt' ); ?></button>
							<button type="button" data-shrt-view="live" aria-pressed="false"><?php esc_html_e( 'Currently live', 'shawesome-robots-txt' ); ?></button>
						</div>
					</header>
					<div class="shrt-output" aria-live="polite">
						<?php // Without JavaScript, the live robots.txt is shown. ?>
						<pre><?php echo esc_html( null === $fetched['body'] ? $fetched['error'] : $fetched['body'] ); ?></pre>
					</div>
					<p class="shrt-output__note" hidden></p>
				</section>
			</aside>
		</div>

		<div class="shrt-bar">
			<span class="shrt-bar__state"><?php echo esc_html( 'site' === $updated ? __( 'Saved', 'shawesome-robots-txt' ) : __( 'No changes', 'shawesome-robots-txt' ) ); ?></span>
			<button type="button" class="button-link shrt-bar__discard" hidden><?php esc_html_e( 'Discard', 'shawesome-robots-txt' ); ?></button>
			<?php submit_button( __( 'Save robots.txt', 'shawesome-robots-txt' ), 'primary', 'submit', false ); ?>
		</div>
	</form>
	<?php
}

/**
 * Render the hint for a network site with a path in its address, whose
 * robots.txt comes from its host.
 *
 * @param int $site_id Site ID.
 */
function shawesome_robots_txt_render_served_by( $site_id ) {
	$url = set_url_scheme( 'http://' . shawesome_robots_txt_host( $site_id ) . '/robots.txt' );

	shawesome_robots_txt_notice(
		sprintf(
			/* translators: %s: URL of the robots.txt at the root of the host */
			esc_html__( 'The address of this site contains a path. Crawlers only request the robots.txt at the root of the host, so the rules for this site belong in %s.', 'shawesome-robots-txt' ),
			'<code>' . esc_html( $url ) . '</code>'
		),
		'info',
		true
	);
}

/**
 * Render the overview of all sites of the network.
 */
function shawesome_robots_txt_render_sites() {
	$settings  = shawesome_robots_txt_get_settings();
	$check_all = isset( $_GET['check'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only triggers the check in the browser.
	$sites    = get_sites(
		array(
			'number'  => 0,
			'deleted' => 0,
		)
	);
	?>
	<h2><?php esc_html_e( 'Sites', 'shawesome-robots-txt' ); ?></h2>
	<p>
		<?php
		printf(
			/* translators: %s: directory path */
			esc_html__( 'Edit the robots.txt of a site here. Optionally, a file in %s overrides the database content, e.g. for deployments. The hyphenated file name takes precedence; the name with dots is an unambiguous alternative.', 'shawesome-robots-txt' ),
			'<code>' . esc_html( shawesome_robots_txt_dir() ) . '</code>'
		);
		?>
	</p>
	<p class="shrt-toolbar"><button type="button" class="button" data-shrt-scan="all"><?php esc_html_e( 'Check all sites again', 'shawesome-robots-txt' ); ?></button></p>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Site', 'shawesome-robots-txt' ); ?></th>
				<th><?php esc_html_e( 'Mode', 'shawesome-robots-txt' ); ?></th>
				<th><?php echo esc_html_x( 'Source', 'where the robots.txt comes from', 'shawesome-robots-txt' ); ?></th>
				<th><?php esc_html_e( 'Override file name', 'shawesome-robots-txt' ); ?></th>
				<th><?php esc_html_e( 'SEO plugins', 'shawesome-robots-txt' ); ?></th>
				<th><?php esc_html_e( 'Status', 'shawesome-robots-txt' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $sites as $site ) : ?>
				<?php
				$site_id  = (int) $site->blog_id;
				$host     = shawesome_robots_txt_host( $site_id );
				$own      = shawesome_robots_txt_serves_own( $site_id );
				$seo      = shawesome_robots_txt_active_seo_plugins( $site_id );
				$edit_url = shawesome_robots_txt_page_url( array( 'site' => $site_id ) );
				$label    = untrailingslashit( $host . wp_parse_url( get_home_url( $site_id ), PHP_URL_PATH ) );
				?>
				<tr>
					<td>
						<?php if ( $own ) : ?>
							<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $label ); ?></a></strong>
							<div class="row-actions visible">
								<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html_x( 'Edit', 'edit the robots.txt of a site', 'shawesome-robots-txt' ); ?></a> |
								<a href="<?php echo esc_url( get_home_url( $site_id, 'robots.txt' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open robots.txt', 'shawesome-robots-txt' ); ?></a>
							</div>
						<?php else : ?>
							<strong><?php echo esc_html( $label ); ?></strong>
						<?php endif; ?>
					</td>
					<td>
						<?php
						if ( ! $own ) {
							echo '–';
						} elseif ( '' === shawesome_robots_txt_site_mode( $site_id ) ) {
							/* translators: %s: name of the default mode */
							echo esc_html( sprintf( __( '%s (default)', 'shawesome-robots-txt' ), shawesome_robots_txt_mode_label( $settings['mode'] ) ) );
						} else {
							echo esc_html( shawesome_robots_txt_mode_label( shawesome_robots_txt_site_mode( $site_id ) ) );
						}
						?>
					</td>
					<td>
						<?php
						if ( ! $own ) {
							/* translators: %s: host name */
							echo esc_html( sprintf( __( 'Served by the robots.txt of %s', 'shawesome-robots-txt' ), $host ) );
							if ( $settings['path_sites'] ) {
								echo '<br>' . esc_html( '1' === (string) $site->public ? __( 'Sitemap line added', 'shawesome-robots-txt' ) : __( 'Disallow line added', 'shawesome-robots-txt' ) );
							}
						} else {
							$resolved = shawesome_robots_txt_resolve( $site_id );
							if ( null === $resolved['source'] && $seo ) {
								/* translators: %s: names of the active SEO plugins */
								echo esc_html( sprintf( __( 'Not set – generated by WordPress and %s', 'shawesome-robots-txt' ), wp_sprintf( '%l', $seo ) ) );
							} elseif ( null === $resolved['source'] ) {
								esc_html_e( 'Not set – generated by WordPress', 'shawesome-robots-txt' );
							} elseif ( $settings['respect_public'] && ! shawesome_robots_txt_is_public( $site_id ) ) {
								esc_html_e( 'Ignored – site hidden from search engines', 'shawesome-robots-txt' );
							} elseif ( 'file' === $resolved['source'] ) {
								/* translators: %s: file name */
								printf( '<span style="color:#008a20;">&#10003;</span> ' . esc_html__( 'File: %s', 'shawesome-robots-txt' ), '<code>' . esc_html( basename( $resolved['file'] ) ) . '</code>' );
							} else {
								echo '<span style="color:#008a20;">&#10003;</span> ' . esc_html_x( 'Database', 'where the robots.txt comes from', 'shawesome-robots-txt' );
							}
						}
						?>
					</td>
					<td>
						<?php
						if ( $own ) {
							echo implode( '<br>', array_map( 'shawesome_robots_txt_code', array_map( 'basename', shawesome_robots_txt_candidate_files( $host ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in shawesome_robots_txt_code().
						} else {
							echo '–';
						}
						?>
					</td>
					<td><?php echo $seo ? esc_html( implode( ', ', $seo ) ) : '–'; ?></td>
					<?php if ( $own ) : ?>
						<?php $status = shawesome_robots_txt_get_status( $site_id ); ?>
						<td data-shrt-status="<?php echo esc_attr( $site_id ); ?>"<?php echo $check_all || shawesome_robots_txt_status_outdated( $status ) ? ' data-outdated' : ''; ?>>
							<?php echo shawesome_robots_txt_status_html( $site_id, $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in shawesome_robots_txt_status_html(). ?>
						</td>
					<?php else : ?>
						<td>–</td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * Render the FAQ: questions grouped by topic as cards that expand, and a
 * sidebar with help. The questions match the FAQ in readme.txt.
 */
function shawesome_robots_txt_render_faq() {
	$check = shawesome_robots_txt_page_url( array( 'check' => 'all' ) );
	$run   = array(
		'url'  => $check,
		'text' => __( 'Run status check', 'shawesome-robots-txt' ),
	);

	$groups = array(
		array(
			'title' => __( 'Troubleshooting', 'shawesome-robots-txt' ),
			'desc'  => __( 'When the robots.txt does not look right', 'shawesome-robots-txt' ),
			'items' => array(
				array(
					'id'     => 'not-delivered',
					'q'      => __( 'My robots.txt is not delivered.', 'shawesome-robots-txt' ),
					'a'      => __( 'WordPress only delivers its robots.txt when no physical robots.txt file exists in the web root. If one exists, the web server delivers it directly and the plugin is never asked – the robots.txt tab shows the file and offers to rename it. Also check that the site is not set to "Discourage search engines" and that override files are readable by the web server.', 'shawesome-robots-txt' ),
					'action' => $run,
				),
				array(
					'id' => 'old-content',
					'q'  => __( 'I changed the content in the editor, but the old one is still delivered.', 'shawesome-robots-txt' ),
					'a'  => __( 'A file in the override directory takes precedence over your rules – delete it or copy it into your rules first. Otherwise it is almost always a cache: purge your page cache plugin, your CDN and the server cache, then reload /robots.txt in a private window.', 'shawesome-robots-txt' ),
				),
			),
		),
		array(
			'title' => __( 'Configuration', 'shawesome-robots-txt' ),
			'desc'  => __( 'Modes and checks', 'shawesome-robots-txt' ),
			'items' => array(
				array(
					'id'   => 'modes',
					'q'    => __( 'What is the difference between "Base" and "Exact"?', 'shawesome-robots-txt' ),
					'a'    => __( 'In "Base" mode, your rules replace the WordPress default and SEO plugins may still change them: Yoast SEO removes the WordPress default rules and appends its own rules and sitemap, All in One SEO rebuilds the robots.txt. In "Exact" mode, your rules are delivered as written, after all SEO plugins have run. The crawler options and the rules for sites with a path are added in both modes.', 'shawesome-robots-txt' ),
					'code' => __( "Base:  your rules, then SEO plugins may change them\nExact: your rules as written, SEO plugins cannot change them", 'shawesome-robots-txt' ),
				),
				array(
					'id'     => 'status',
					'q'      => __( 'What does the status check?', 'shawesome-robots-txt' ),
					'a'      => __( 'It requests the robots.txt of the site as crawlers get it and shows whether all crawlers are blocked, how many sitemaps are linked and whether crawlers that collect content for AI training are blocked. It also reports when the robots.txt cannot be loaded, when a physical file is delivered and when the site is hidden from search engines. The result is kept for the time set in the settings.', 'shawesome-robots-txt' ),
					'action' => $run,
				),
			),
		),
		array(
			'title' => __( 'Compatibility', 'shawesome-robots-txt' ),
			'desc'  => __( 'Setup and removal', 'shawesome-robots-txt' ),
			'items' => array(
				array(
					'id' => 'subdirectory',
					'q'  => __( 'Does it work if WordPress is installed in a subdirectory?', 'shawesome-robots-txt' ),
					'a'  => __( 'Crawlers only read the robots.txt at the root of a domain. If the address of the site contains a path (example.com/blog/), WordPress cannot deliver it there, so the plugin does nothing and says so. If only the WordPress files are in a subdirectory and the address is the root of the domain, it works.', 'shawesome-robots-txt' ),
				),
				array(
					'id' => 'uninstall',
					'q'  => __( 'What happens on uninstall?', 'shawesome-robots-txt' ),
					'a'  => __( 'The settings and everything stored per site – rules, mode, crawler options and status – are deleted from the database, on every site of a network. Files on the server are never touched: override files and a renamed _old-robots.txt are kept.', 'shawesome-robots-txt' ),
				),
			),
		),
	);
	?>
	<div class="shrt-faq">
		<div class="shrt-faq__groups">
			<?php foreach ( $groups as $g => $group ) : ?>
				<section class="shrt-faq__group">
					<header>
						<h2><?php echo esc_html( $group['title'] ); ?></h2>
						<span><?php echo esc_html( $group['desc'] ); ?></span>
					</header>
					<?php foreach ( $group['items'] as $i => $item ) : ?>
						<details class="shrt-faq__item" id="faq-<?php echo esc_attr( $item['id'] ); ?>"<?php echo 0 === $g && 0 === $i ? ' open' : ''; ?>>
							<summary><span class="shrt-faq__icon" aria-hidden="true"></span><span class="shrt-faq__q"><?php echo esc_html( $item['q'] ); ?></span></summary>
							<div class="shrt-faq__body">
								<p><?php echo esc_html( $item['a'] ); ?></p>
								<?php if ( ! empty( $item['code'] ) ) : ?>
									<code class="shrt-faq__code"><?php echo esc_html( $item['code'] ); ?></code>
								<?php endif; ?>
								<?php if ( ! empty( $item['action'] ) ) : ?>
									<a href="<?php echo esc_url( $item['action']['url'] ); ?>"><?php echo esc_html( $item['action']['text'] ); ?> &rarr;</a>
								<?php endif; ?>
							</div>
						</details>
					<?php endforeach; ?>
				</section>
			<?php endforeach; ?>
		</div>

		<aside class="shrt-faq__help">
			<h2><?php esc_html_e( 'Need more help?', 'shawesome-robots-txt' ); ?></h2>
			<p><?php esc_html_e( 'Run the status check first – it detects most delivery problems automatically.', 'shawesome-robots-txt' ); ?></p>
			<a class="button button-primary" href="<?php echo esc_url( $check ); ?>"><?php esc_html_e( 'Run status check', 'shawesome-robots-txt' ); ?></a>
			<?php
			/*
			 * Placeholders for the first update after the plugin is approved on
			 * WordPress.org – the support forum and a documentation page do not
			 * exist yet:
			 *
			 * <a href="https://wordpress.org/support/plugin/shawesome-robots-txt/">Support forum</a>
			 * <a href="…">Documentation</a>
			 */
			?>
		</aside>
	</div>
	<?php
}

/**
 * Escape a text and wrap it in <code>.
 *
 * @param string $text Text.
 * @return string
 */
function shawesome_robots_txt_code( $text ) {
	return '<code>' . esc_html( $text ) . '</code>';
}

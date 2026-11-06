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
					'dismiss'  => wp_create_nonce( 'shawesome_robots_txt_dismiss' ),
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
						'hiddenNote' => __( 'This site is hidden from search engines, so all crawlers are blocked from all paths ("Blocking"). Your rules and options are kept, but not delivered.', 'shawesome-robots-txt' ),
						'fileNote'   => __( 'A physical robots.txt in the web root is delivered instead of all of this.', 'shawesome-robots-txt' ),
						'liveNote'   => __( 'What crawlers get now. Saved changes appear here after reloading the page.', 'shawesome-robots-txt' ),
						'block'      => __( 'Blocking mode', 'shawesome-robots-txt' ),
						'blockNote'  => __( '"Blocking" mode: all crawlers are blocked from all paths. Your rules and crawler options are kept, but not delivered.', 'shawesome-robots-txt' ),
						'noLimit'    => __( 'No limit', 'shawesome-robots-txt' ),
						/* translators: %d: seconds */
						'delay'      => __( '%d s delay', 'shawesome-robots-txt' ),
						/* translators: %d: number of blocked crawlers, paths or file types */
						'blocked'     => __( '%d blocked', 'shawesome-robots-txt' ),
						'noneBlocked' => __( 'None blocked', 'shawesome-robots-txt' ),
						'added'       => __( 'Added', 'shawesome-robots-txt' ),
						'notAdded'    => __( 'Not added', 'shawesome-robots-txt' ),
						'confirmBlock' => is_multisite()
							? __( 'Make "Blocking" the default mode? All crawlers will be blocked on every site that does not set its own mode, and search engines will drop these sites.', 'shawesome-robots-txt' )
							: __( 'Make "Blocking" the default mode? All crawlers will be blocked on this site, and search engines will drop it.', 'shawesome-robots-txt' ),
						'dirty'      => __( 'Unsaved changes', 'shawesome-robots-txt' ),
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
 * @param string $mode 'base', 'exact' or 'block'.
 * @return string
 */
function shawesome_robots_txt_mode_label( $mode ) {
	if ( 'block' === $mode ) {
		return _x( 'Blocking', 'robots.txt mode', 'shawesome-robots-txt' );
	}

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

	$seo_blocked = 0;
	foreach ( shawesome_robots_txt_group_agents( 'seo' ) as $agent ) {
		$seo_blocked += $blocked( $agent ) ? 1 : 0;
	}

	return array(
		'all_blocked'    => $blocked( '*' ),
		'google_blocked' => $blocked( 'Googlebot' ),
		'sitemaps'       => $sitemaps,
		'ai_blocked'     => $ai_blocked,
		'ai_total'       => count( shawesome_robots_txt_ai_agents() ),
		'seo_blocked'    => $seo_blocked,
		'seo_total'      => count( shawesome_robots_txt_group_agents( 'seo' ) ),
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
		if ( $status['all_blocked'] && ( 'block' === shawesome_robots_txt_mode( $site_id ) || shawesome_robots_txt_hidden_blocked( $site_id ) ) ) {
			$items[] = array( 'warn', esc_html__( 'All crawlers blocked ("Blocking")', 'shawesome-robots-txt' ) );
		} elseif ( $status['all_blocked'] ) {
			$items[] = array( 'bad', esc_html__( 'All crawlers blocked', 'shawesome-robots-txt' ) );
		} elseif ( ! empty( $status['google_blocked'] ) ) {
			$items[] = array( 'bad', esc_html__( 'Googlebot blocked', 'shawesome-robots-txt' ) );
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

		if ( ! empty( $status['seo_blocked'] ) ) {
			/* translators: 1: number of blocked SEO tools, 2: number of known SEO tools */
			$items[] = array( 'neutral', esc_html( sprintf( __( 'SEO tools blocked (%1$d of %2$d)', 'shawesome-robots-txt' ), $status['seo_blocked'], $status['seo_total'] ) ) );
		}
	}

	return $items;
}

/**
 * Overall level of a status for filtering and sorting: 'error' if one item
 * is bad, 'warn' if one item is a warning, otherwise 'ok'.
 *
 * @param int        $site_id Site ID.
 * @param array|null $status  Status.
 * @return string
 */
function shawesome_robots_txt_status_level( $site_id, $status ) {
	$types = wp_list_pluck( shawesome_robots_txt_status_items( $site_id, $status ), 0 );

	if ( in_array( 'bad', $types, true ) ) {
		return 'error';
	}

	return in_array( 'warn', $types, true ) ? 'warn' : 'ok';
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

	$status  = shawesome_robots_txt_scan( $site_id );

	wp_send_json_success(
		array(
			'html'  => shawesome_robots_txt_status_html( $site_id, $status, $format ),
			'level' => shawesome_robots_txt_status_level( $site_id, $status ),
		)
	);
}
add_action( 'wp_ajax_shawesome_robots_txt_scan', 'shawesome_robots_txt_ajax_scan' );

/**
 * AJAX: hide the notice about SEO plugins in "Base" mode for the current user.
 */
function shawesome_robots_txt_ajax_dismiss() {
	check_ajax_referer( 'shawesome_robots_txt_dismiss' );
	shawesome_robots_txt_check_cap();

	update_user_meta( get_current_user_id(), 'shawesome_robots_txt_dismissed', 1 );
	wp_send_json_success();
}
add_action( 'wp_ajax_shawesome_robots_txt_dismiss', 'shawesome_robots_txt_ajax_dismiss' );

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
			'mode'           => in_array( $mode, array( 'exact', 'base', 'block' ), true ) ? $mode : 'base',
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
	shawesome_robots_txt_set_site_option( $site_id, SHAWESOME_ROBOTS_TXT_MODE_OPTION, in_array( $mode, array( 'base', 'exact', 'block' ), true ) ? $mode : '' );

	// Lists of keys; unknown keys are dropped by shawesome_robots_txt_site_rules().
	$keys = function ( $name ) {
		return isset( $_POST[ $name ] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST[ $name ] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
	};

	// Only values that differ from the defaults are stored.
	$rules = array_filter(
		array(
			'crawl_delay' => empty( $_POST['crawl_delay_on'] ) ? 0 : min( 60, max( 1, absint( $_POST['crawl_delay'] ?? 10 ) ) ),
			'css_js'      => empty( $_POST['css_js'] ) ? null : 1,
			'images'      => empty( $_POST['images'] ) ? null : 1,
			'pdfs'        => empty( $_POST['pdfs'] ) ? null : 1,
			'bots'        => array_values( array_intersect( $keys( 'bots' ), array_keys( shawesome_robots_txt_bot_index() ) ) ),
			'cleanup'     => array_values( array_intersect( $keys( 'cleanup' ), array_keys( shawesome_robots_txt_cleanup_lines() ) ) ),
			'woo'         => array_values( array_intersect( $keys( 'woo' ), array( 'cart', 'checkout', 'account', 'add_to_cart' ) ) ),
			'no_sitemap'  => empty( $_POST['no_sitemap'] ) ? null : 1,
		),
		function ( $value, $key ) {
			if ( 'crawl_delay' === $key ) {
				return $value > 0;
			}
			return null !== $value && array() !== $value;
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
		shawesome_robots_txt_render_settings( $updated );
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
	$count = 0;
	foreach ( $sites as $site_id ) {
		if ( 'base' === shawesome_robots_txt_mode( (int) $site_id ) && shawesome_robots_txt_active_seo_plugins( (int) $site_id ) ) {
			++$count;
		}
	}

	// Each user can hide this notice; it then stays hidden.
	if ( $count && ! get_user_meta( get_current_user_id(), 'shawesome_robots_txt_dismissed', true ) ) :
		?>
		<div class="shrt-notice">
			<div>
				<?php /* translators: %d: number of sites */ ?>
				<strong><?php echo esc_html( sprintf( _n( '%d site uses "Base" mode with an SEO plugin', '%d sites use "Base" mode with an SEO plugin', $count, 'shawesome-robots-txt' ), $count ) ); ?></strong>
				<span><?php esc_html_e( 'Yoast SEO removes the WordPress default rules and appends its own; All in One SEO rebuilds the robots.txt and drops comments and Sitemap lines. Switch to "Exact" to deliver your rules unchanged.', 'shawesome-robots-txt' ); ?></span>
			</div>
			<button type="button" class="shrt-notice__dismiss" data-shrt-dismiss><span aria-hidden="true">&times;</span><span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'shawesome-robots-txt' ); ?></span></button>
		</div>
		<?php
	endif;
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
 * Render the settings: default mode as cards, behaviour as switches, and a
 * bar to save.
 *
 * @param string $updated Result of the last action.
 */
function shawesome_robots_txt_render_settings( $updated = '' ) {
	$settings = shawesome_robots_txt_get_settings();
	$modes    = array(
		'base'  => array(
			'desc'   => __( 'Your rules replace the WordPress default. SEO plugins may add their rules and sitemap.', 'shawesome-robots-txt' ),
			'rec'    => true,
			'points' => array(
				array( true, __( 'Works with Yoast SEO, All in One SEO …', 'shawesome-robots-txt' ) ),
				array( true, __( 'Duplicate Sitemap lines are removed', 'shawesome-robots-txt' ) ),
			),
		),
		'exact' => array(
			'desc'   => __( 'Your robots.txt is delivered as is. SEO plugins cannot change it.', 'shawesome-robots-txt' ),
			'rec'    => false,
			'points' => array(
				array( true, __( 'Full control over the output', 'shawesome-robots-txt' ) ),
				array( false, __( 'Sites without own rules keep the robots.txt of WordPress and the SEO plugins', 'shawesome-robots-txt' ) ),
			),
		),
		'block' => array(
			'desc'   => __( 'All crawlers are blocked from all paths. Rules and options of the sites are kept, but not delivered.', 'shawesome-robots-txt' ),
			'rec'    => false,
			'points' => array(
				array( true, __( 'For staging and development sites', 'shawesome-robots-txt' ) ),
				array( false, __( 'Not for live sites: they disappear from search engines', 'shawesome-robots-txt' ) ),
			),
		),
	);
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="shrt-settings shrt-dirty">
		<?php shawesome_robots_txt_form_fields( 'save', 'shawesome_robots_txt_save' ); ?>

		<section class="shrt-card">
			<header class="shrt-card__head">
				<div class="shrt-card__title">
					<h2><?php esc_html_e( 'Default mode', 'shawesome-robots-txt' ); ?></h2>
					<span><?php echo esc_html( is_multisite() ? __( 'Used by every site that does not set its own mode in the editor.', 'shawesome-robots-txt' ) : __( 'Used unless the editor sets another mode.', 'shawesome-robots-txt' ) ); ?></span>
				</div>
			</header>
			<fieldset class="shrt-modes shrt-modes--wide">
				<legend class="screen-reader-text"><?php esc_html_e( 'Default mode', 'shawesome-robots-txt' ); ?></legend>
				<?php foreach ( $modes as $value => $mode ) : ?>
					<label class="shrt-mode<?php echo 'block' === $value ? ' shrt-mode--block' : ''; ?>">
						<span class="shrt-mode__title">
							<input type="radio" name="mode" value="<?php echo esc_attr( $value ); ?>" <?php checked( $settings['mode'], $value ); ?>>
							<?php echo esc_html( shawesome_robots_txt_mode_label( $value ) ); ?>
							<?php if ( $mode['rec'] ) : ?>
								<span class="shrt-tag"><?php esc_html_e( 'recommended', 'shawesome-robots-txt' ); ?></span>
							<?php endif; ?>
						</span>
						<span class="shrt-mode__desc"><?php echo esc_html( $mode['desc'] ); ?></span>
						<span class="shrt-points">
							<?php foreach ( $mode['points'] as $point ) : ?>
								<span class="<?php echo esc_attr( $point[0] ? 'is-yes' : 'is-no' ); ?>"><?php echo esc_html( $point[1] ); ?></span>
							<?php endforeach; ?>
						</span>
					</label>
				<?php endforeach; ?>
			</fieldset>
		</section>

		<section class="shrt-card">
			<header class="shrt-card__head">
				<div class="shrt-card__title"><h2><?php esc_html_e( 'Behaviour', 'shawesome-robots-txt' ); ?></h2></div>
			</header>
			<div class="shrt-row">
				<div>
					<label for="shrt-respect-public" class="shrt-row__title"><?php esc_html_e( 'Block sites hidden from search engines', 'shawesome-robots-txt' ); ?></label>
					<p><?php esc_html_e( 'Sites set to "Discourage search engines from indexing this site" get "Blocking": all crawlers are blocked from all paths, whatever their mode. WordPress itself no longer blocks these sites in its robots.txt, and SEO plugins may even allow everything.', 'shawesome-robots-txt' ); ?></p>
				</div>
				<input type="checkbox" role="switch" class="shrt-switch-toggle" id="shrt-respect-public" name="respect_public" value="1" <?php checked( $settings['respect_public'] ); ?>>
			</div>
			<?php if ( is_multisite() && ! is_subdomain_install() ) : ?>
				<div class="shrt-row">
					<div>
						<label for="shrt-path-sites" class="shrt-row__title"><?php esc_html_e( 'Rules for sites with a path', 'shawesome-robots-txt' ); ?></label>
						<p><?php esc_html_e( 'Adds rules for sites whose address contains a path (example.com/blog/) to the robots.txt of their host, also in "Exact" mode: Disallow for sites hidden from search engines, Sitemap for public sites. The Disallow lines form a group for all crawlers, which crawlers with a group of their own do not read.', 'shawesome-robots-txt' ); ?></p>
					</div>
					<input type="hidden" name="path_sites_shown" value="1">
					<input type="checkbox" role="switch" class="shrt-switch-toggle" id="shrt-path-sites" name="path_sites" value="1" <?php checked( $settings['path_sites'] ); ?>>
				</div>
			<?php endif; ?>
			<div class="shrt-row">
				<div>
					<label for="shrt-status-hours" class="shrt-row__title"><?php esc_html_e( 'Re-check status after', 'shawesome-robots-txt' ); ?></label>
					<p><?php esc_html_e( 'Sitemaps, blocked crawlers and AI training are checked again when the overview is opened after this time. "Check again" works at any time.', 'shawesome-robots-txt' ); ?></p>
				</div>
				<span class="shrt-row__control">
					<input type="number" id="shrt-status-hours" name="status_hours" min="1" max="720" step="1" class="small-text" value="<?php echo esc_attr( $settings['status_hours'] ); ?>">
					<?php esc_html_e( 'hours', 'shawesome-robots-txt' ); ?>
				</span>
			</div>
		</section>

		<div class="shrt-bar">
			<span class="shrt-bar__state"><?php echo esc_html( 'settings' === $updated ? __( 'Saved', 'shawesome-robots-txt' ) : __( 'No changes', 'shawesome-robots-txt' ) ); ?></span>
			<button type="button" class="button-link shrt-bar__discard" hidden><?php esc_html_e( 'Discard', 'shawesome-robots-txt' ); ?></button>
			<?php submit_button( __( 'Save settings', 'shawesome-robots-txt' ), 'primary', 'submit', false ); ?>
		</div>
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
	$hidden   = shawesome_robots_txt_hidden_blocked( $site_id );
	$fetched  = shawesome_robots_txt_fetch( $site_id );
	$status   = shawesome_robots_txt_scan( $site_id, $fetched );

	if ( $hidden ) {
		shawesome_robots_txt_notice( esc_html__( 'This site is set to "Discourage search engines", so all crawlers are blocked from all paths ("Blocking"), whatever the mode below. This can be changed in the settings.', 'shawesome-robots-txt' ), 'warning', true );
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
		'block' => array( shawesome_robots_txt_mode_label( 'block' ), __( 'Blocks all crawlers from all paths. Your rules and crawler options are kept, but not delivered. Not for live sites.', 'shawesome-robots-txt' ) ),
	);
	$woo_active = shawesome_robots_txt_plugin_active( $site_id, 'woocommerce/woocommerce.php' );

	// Lines the preview needs; built in PHP so that they match the robots.txt.
	$resource_lines = array();
	foreach ( array( 'css_js', 'images', 'pdfs' ) as $key ) {
		$resource_lines[ $key ] = shawesome_robots_txt_site_rule_lines_for( $key );
	}
	$data = array(
		'defaultMode' => $settings['mode'],
		'seo'         => wp_sprintf( '%l', $seo ),
		'file'        => 'file' === $resolved['source'] ? $resolved['content'] : null,
		'live'        => $fetched['body'],
		'physical'    => is_file( shawesome_robots_txt_physical_file() ),
		'hidden'      => $hidden,
		'paths'       => $settings['path_sites'] ? shawesome_robots_txt_path_rules( shawesome_robots_txt_host( $site_id ) ) : '',
		'bots'        => shawesome_robots_txt_bot_index(),
		'resources'   => $resource_lines,
		'cleanup'     => shawesome_robots_txt_cleanup_lines(),
		'woo'         => $woo_active || $rules['woo'] ? shawesome_robots_txt_woo_lines( $site_id ) : array(),
		'sitemapUrl'  => shawesome_robots_txt_sitemap_url( $site_id ),
		'saved'       => 'site' === $updated,
	);
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="shrt-editor shrt-dirty" data-shrt="<?php echo esc_attr( wp_json_encode( $data ) ); ?>">
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
							<label class="shrt-mode<?php echo 'block' === $value ? ' shrt-mode--block' : ''; ?>">
								<span class="shrt-mode__title"><input type="radio" name="site_mode" value="<?php echo esc_attr( $value ); ?>" <?php checked( $site_mode, $value ); ?>> <?php echo esc_html( $texts[0] ); ?></span>
								<span class="shrt-mode__desc"><?php echo esc_html( $texts[1] ); ?></span>
							</label>
						<?php endforeach; ?>
					</fieldset>
				</section>

				<p class="shrt-blocked-note"<?php echo 'block' === $mode || $hidden ? '' : ' hidden'; ?>><?php esc_html_e( '"Blocking" mode: all crawlers are blocked from all paths. The options below are kept, but not delivered.', 'shawesome-robots-txt' ); ?></p>
				<div class="shrt-blockable"<?php echo 'block' === $mode || $hidden ? ' inert' : ''; ?>>
				<section class="shrt-card">
					<header class="shrt-card__head">
						<div class="shrt-card__title">
							<h3>2 &middot; <label for="shrt-content"><?php esc_html_e( 'Your rules', 'shawesome-robots-txt' ); ?></label></h3>
							<span><?php esc_html_e( 'Leave empty to keep the robots.txt generated by WordPress and SEO plugins.', 'shawesome-robots-txt' ); ?></span>
						</div>
						<div class="shrt-card__actions">
							<button type="button" class="button button-small" aria-controls="shrt-content" data-shrt-insert="replace" data-text="<?php echo esc_attr( shawesome_robots_txt_example_rules( $site_id ) ); ?>" data-confirm="<?php esc_attr_e( 'Replace the current content with the example rules?', 'shawesome-robots-txt' ); ?>">+ <?php esc_html_e( 'Example rules', 'shawesome-robots-txt' ); ?></button>
						</div>
					</header>
					<textarea id="shrt-content" name="content" class="shrt-rules" spellcheck="false" placeholder="<?php echo esc_attr( shawesome_robots_txt_example_rules( $site_id ) ); ?>"><?php echo esc_textarea( shawesome_robots_txt_get_db_content( $site_id ) ); ?></textarea>
				</section>

				<section class="shrt-card">
					<header class="shrt-card__head">
						<div class="shrt-card__title">
							<h3>3 &middot; <?php esc_html_e( 'Crawlers', 'shawesome-robots-txt' ); ?></h3>
							<span><?php echo wp_kses( __( 'Checked crawlers are blocked. They get a group of their own at the end of the robots.txt, in both modes. The Crawl-delay goes into the <code>User-agent: *</code> group.', 'shawesome-robots-txt' ), array( 'code' => array() ) ); ?></span>
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

					<?php
					shawesome_robots_txt_render_bot_group(
						__( 'AI crawlers', 'shawesome-robots-txt' ),
						__( 'Crawlers of AI companies.', 'shawesome-robots-txt' ),
						array(
							'ai_training' => array( __( 'Training', 'shawesome-robots-txt' ), __( 'Collect content to train AI models. Blocking them does not affect search engines.', 'shawesome-robots-txt' ) ),
							'ai_search'   => array( __( 'AI search', 'shawesome-robots-txt' ), __( 'Find content for answers with sources in AI search. Blocked, the site does not appear there.', 'shawesome-robots-txt' ) ),
							'ai_user'     => array( __( 'Requests on behalf of users', 'shawesome-robots-txt' ), __( 'Fetch a page when a user asks for it. Some of them, such as Perplexity-User and meta-externalfetcher, may ignore robots.txt.', 'shawesome-robots-txt' ) ),
						),
						$rules
					);
					shawesome_robots_txt_render_bot_group(
						__( 'SEO tools', 'shawesome-robots-txt' ),
						__( 'Crawlers of SEO tools that analyze links and content. Your own audits with these tools stop working as well.', 'shawesome-robots-txt' ),
						array( 'seo' => array( '', '' ) ),
						$rules
					);
					shawesome_robots_txt_render_bot_group(
						__( 'Web archives', 'shawesome-robots-txt' ),
						__( 'Crawlers that save copies of pages. The Internet Archive does not promise to follow robots.txt in every case.', 'shawesome-robots-txt' ),
						array( 'archive' => array( '', '' ) ),
						$rules
					);
					shawesome_robots_txt_render_bot_group(
						__( 'Social media', 'shawesome-robots-txt' ),
						__( 'Crawlers that build link previews (title, image, description) when your pages are shared. Blocked, shared links look plain or broken.', 'shawesome-robots-txt' ),
						array( 'social' => array( '', '' ) ),
						$rules,
						true
					);
					shawesome_robots_txt_render_bot_group(
						__( 'Search engines', 'shawesome-robots-txt' ),
						__( 'Blocked, the site disappears from the results of that search engine. To keep a site out of all search engines, use "Discourage search engines from indexing this site" in the reading settings of WordPress instead.', 'shawesome-robots-txt' ),
						array( 'search' => array( '', '' ) ),
						$rules,
						true
					);
					?>
				</section>

				<section class="shrt-card">
					<header class="shrt-card__head">
						<div class="shrt-card__title">
							<h3>4 &middot; <?php esc_html_e( 'Paths and files', 'shawesome-robots-txt' ); ?></h3>
							<span><?php echo wp_kses( __( 'Checked paths and file types are blocked for all crawlers. Added at the end of the robots.txt as a <code>User-agent: *</code> group, in both modes; crawlers with a group of their own ignore it.', 'shawesome-robots-txt' ), array( 'code' => array() ) ); ?></span>
						</div>
					</header>

					<?php
					shawesome_robots_txt_render_path_group(
						'',
						__( 'Resources and assets', 'shawesome-robots-txt' ),
						__( 'File types crawlers may fetch. All are allowed unless checked.', 'shawesome-robots-txt' ),
						array(
							'css_js' => array( __( 'CSS and JavaScript', 'shawesome-robots-txt' ), __( 'Google needs these to render your pages and to check whether they work on mobile devices.', 'shawesome-robots-txt' ), 'not' ),
							'images' => array( __( 'Images', 'shawesome-robots-txt' ), __( 'Blocked, your images no longer appear in Google Images and other image searches.', 'shawesome-robots-txt' ), 'not' ),
							'pdfs'   => array( __( 'PDF files', 'shawesome-robots-txt' ), __( 'Blocked, PDF documents no longer appear in search results.', 'shawesome-robots-txt' ), '' ),
						),
						$resource_lines,
						array_keys( array_filter( array_intersect_key( $rules, $resource_lines ) ) )
					);
					shawesome_robots_txt_render_path_group(
						'cleanup',
						__( 'WordPress cleanup', 'shawesome-robots-txt' ),
						__( 'Pages without value in search results that waste crawl budget.', 'shawesome-robots-txt' ),
						array(
							'search'     => array( __( 'Internal search results', 'shawesome-robots-txt' ), __( 'Google recommends keeping search result pages out of the crawl.', 'shawesome-robots-txt' ), 'rec' ),
							'replytocom' => array( __( 'Reply links of comments', 'shawesome-robots-txt' ), __( 'Copies of a post for every comment reply (?replytocom=).', 'shawesome-robots-txt' ), 'rec' ),
							'feeds'      => array( __( 'Feeds of posts, archives and comments', 'shawesome-robots-txt' ), __( 'The main feed /feed/ stays open.', 'shawesome-robots-txt' ), '' ),
						),
						shawesome_robots_txt_cleanup_lines(),
						$rules['cleanup']
					);
					if ( $woo_active ) {
						shawesome_robots_txt_render_path_group(
							'woo',
							__( 'WooCommerce', 'shawesome-robots-txt' ),
							__( 'Shop pages that belong to a single visitor.', 'shawesome-robots-txt' ),
							array(
								'cart'        => array( __( 'Cart', 'shawesome-robots-txt' ), '', 'rec' ),
								'checkout'    => array( __( 'Checkout', 'shawesome-robots-txt' ), '', 'rec' ),
								'account'     => array( __( 'My account', 'shawesome-robots-txt' ), '', 'rec' ),
								'add_to_cart' => array( __( '"Add to cart" links', 'shawesome-robots-txt' ), __( 'Links with ?add-to-cart= create a new cart for every crawler request.', 'shawesome-robots-txt' ), 'rec' ),
							),
							shawesome_robots_txt_woo_lines( $site_id ),
							$rules['woo']
						);
					}
					?>

					<details class="shrt-group"<?php echo $rules['no_sitemap'] ? ' open' : ''; ?>>
						<summary>
							<span class="shrt-group__text"><strong><?php esc_html_e( 'Sitemap', 'shawesome-robots-txt' ); ?></strong><span><?php esc_html_e( 'The sitemap of this site is linked at the end of the robots.txt unless checked.', 'shawesome-robots-txt' ); ?></span></span>
							<span class="shrt-badge" data-shrt-summary="sitemap"></span>
						</summary>
						<div class="shrt-option">
							<input type="checkbox" id="shrt-no-sitemap" name="no_sitemap" value="1" <?php checked( $rules['no_sitemap'] ); ?>>
							<div>
								<label for="shrt-no-sitemap" class="shrt-option__title"><?php esc_html_e( 'Do not add the sitemap', 'shawesome-robots-txt' ); ?></label>
								<code><?php echo esc_html( shawesome_robots_txt_sitemap_url( $site_id ) ); ?></code>
								<p><?php esc_html_e( 'The sitemap of Yoast SEO, Rank Math or All in One SEO if active, otherwise the one of WordPress. It is not added twice if the robots.txt already links it.', 'shawesome-robots-txt' ); ?></p>
							</div>
						</div>
					</details>
				</section>
				</div>
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
 * Render a group of crawlers that can be blocked one by one.
 *
 * @param string $title           Title.
 * @param string $desc            Description.
 * @param array  $sections        Group key of shawesome_robots_txt_bots() => array( heading, note ); empty strings for none.
 * @param array  $rules           Options of the site (shawesome_robots_txt_site_rules()).
 * @param bool   $not_recommended Whether to mark the group as not recommended.
 */
function shawesome_robots_txt_render_bot_group( $title, $desc, $sections, $rules, $not_recommended = false ) {
	$bots = shawesome_robots_txt_bots();
	$open = false;
	foreach ( array_keys( $sections ) as $group ) {
		$open = $open || array_intersect( array_keys( $bots[ $group ] ), $rules['bots'] );
	}
	?>
	<details class="shrt-group"<?php echo $open ? ' open' : ''; ?>>
		<summary>
			<span class="shrt-group__text">
				<strong>
					<?php echo esc_html( $title ); ?>
					<?php if ( $not_recommended ) : ?>
						<span class="shrt-tag shrt-tag--warn"><?php esc_html_e( 'Not recommended', 'shawesome-robots-txt' ); ?></span>
					<?php endif; ?>
				</strong>
				<span><?php echo esc_html( $desc ); ?></span>
			</span>
			<span class="shrt-badge" data-shrt-summary="block"></span>
		</summary>
		<?php foreach ( $sections as $group => $texts ) : ?>
			<?php if ( '' !== $texts[0] ) : ?>
				<p class="shrt-subhead"><strong><?php echo esc_html( $texts[0] ); ?></strong> – <?php echo esc_html( $texts[1] ); ?></p>
			<?php endif; ?>
			<?php foreach ( $bots[ $group ] as $key => $bot ) : ?>
				<div class="shrt-option shrt-option--compact">
					<input type="checkbox" id="shrt-bot-<?php echo esc_attr( $key ); ?>" name="bots[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $rules['bots'], true ) ); ?>>
					<div>
						<?php if ( array( '*' ) === $bot[1] ) : ?>
							<label for="shrt-bot-<?php echo esc_attr( $key ); ?>" class="shrt-option__title"><?php esc_html_e( 'All crawlers', 'shawesome-robots-txt' ); ?></label>
							<code>*</code>
							<p class="shrt-option__warn"><?php esc_html_e( 'Blocks every crawler, including all search engines. Only for sites that must not be crawled at all.', 'shawesome-robots-txt' ); ?></p>
						<?php else : ?>
							<label for="shrt-bot-<?php echo esc_attr( $key ); ?>" class="shrt-option__title"><?php echo esc_html( $bot[1][0] ); ?></label>
							<span class="shrt-option__by"><?php echo esc_html( $bot[0] ); ?></span>
							<?php if ( count( $bot[1] ) > 1 ) : ?>
								<?php /* translators: %s: further user agent tokens of the same operator */ ?>
								<p><?php echo esc_html( sprintf( __( 'Also: %s', 'shawesome-robots-txt' ), implode( ', ', array_slice( $bot[1], 1 ) ) ) ); ?></p>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		<?php endforeach; ?>
	</details>
	<?php
}

/**
 * Render a group of paths that can be blocked one by one.
 *
 * @param string $name     Field name ('cleanup' or 'woo'), or '' for one field per item.
 * @param string $title    Title.
 * @param string $desc     Description.
 * @param array  $items    Key => array( label, description, tag ); tag 'rec', 'not' or ''.
 * @param array  $lines    Key => Disallow lines.
 * @param array  $selected Blocked keys.
 */
function shawesome_robots_txt_render_path_group( $name, $title, $desc, $items, $lines, $selected ) {
	?>
	<details class="shrt-group"<?php echo $selected ? ' open' : ''; ?>>
		<summary>
			<span class="shrt-group__text"><strong><?php echo esc_html( $title ); ?></strong><span><?php echo esc_html( $desc ); ?></span></span>
			<span class="shrt-badge" data-shrt-summary="block"></span>
		</summary>
		<?php foreach ( $items as $key => $texts ) : ?>
			<div class="shrt-option shrt-option--compact">
				<input type="checkbox" id="shrt-<?php echo esc_attr( $name . '-' . $key ); ?>" name="<?php echo esc_attr( '' === $name ? $key : $name . '[]' ); ?>" value="<?php echo esc_attr( '' === $name ? '1' : $key ); ?>" <?php checked( in_array( $key, $selected, true ) ); ?>>
				<div>
					<label for="shrt-<?php echo esc_attr( $name . '-' . $key ); ?>" class="shrt-option__title"><?php echo esc_html( $texts[0] ); ?></label>
					<?php if ( 'rec' === ( $texts[2] ?? '' ) ) : ?>
						<span class="shrt-tag"><?php esc_html_e( 'recommended', 'shawesome-robots-txt' ); ?></span>
					<?php elseif ( 'not' === ( $texts[2] ?? '' ) ) : ?>
						<span class="shrt-tag shrt-tag--warn"><?php esc_html_e( 'Not recommended', 'shawesome-robots-txt' ); ?></span>
					<?php endif; ?>
					<span class="shrt-option__by"><?php echo esc_html( implode( ' ', str_replace( 'Disallow: ', '', $lines[ $key ] ) ) ); ?></span>
					<?php if ( '' !== $texts[1] ) : ?>
						<p><?php echo esc_html( $texts[1] ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</details>
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
 * Render the overview of all sites of the network: filters by status, a
 * search, and a table sorted by status (errors first).
 */
function shawesome_robots_txt_render_sites() {
	$settings  = shawesome_robots_txt_get_settings();
	$check_all = isset( $_GET['check'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only triggers the check in the browser.
	$rows      = array();
	$counts    = array(
		'all'   => 0,
		'error' => 0,
		'warn'  => 0,
		'ok'    => 0,
	);

	foreach ( get_sites(
		array(
			'number'  => 0,
			'deleted' => 0,
		)
	) as $site ) {
		$site_id = (int) $site->blog_id;
		$own     = shawesome_robots_txt_serves_own( $site_id );
		$status  = $own ? shawesome_robots_txt_get_status( $site_id ) : null;
		$level   = $own ? shawesome_robots_txt_status_level( $site_id, $status ) : 'ok';
		$rows[]  = array(
			'id'     => $site_id,
			'own'    => $own,
			'status' => $status,
			'level'  => $level,
			'host'   => shawesome_robots_txt_host( $site_id ),
			'label'  => untrailingslashit( shawesome_robots_txt_host( $site_id ) . wp_parse_url( get_home_url( $site_id ), PHP_URL_PATH ) ),
			'public' => '1' === (string) $site->public,
		);
		++$counts['all'];
		++$counts[ $level ];
	}

	// Errors first, then warnings, then the rest; the order of the network otherwise.
	$order = array(
		'error' => 0,
		'warn'  => 1,
		'ok'    => 2,
	);
	uasort(
		$rows,
		function ( $a, $b ) use ( $order ) {
			return $order[ $a['level'] ] - $order[ $b['level'] ];
		}
	);

	$filters = array(
		'all'   => __( 'All', 'shawesome-robots-txt' ),
		'error' => __( 'Errors', 'shawesome-robots-txt' ),
		'warn'  => __( 'Warnings', 'shawesome-robots-txt' ),
		'ok'    => __( 'OK', 'shawesome-robots-txt' ),
	);
	?>
	<div class="shrt-toolbar">
		<h2><?php esc_html_e( 'Sites', 'shawesome-robots-txt' ); ?></h2>
		<div class="shrt-chips" role="group" aria-label="<?php esc_attr_e( 'Filter sites by status', 'shawesome-robots-txt' ); ?>">
			<?php foreach ( $filters as $key => $label ) : ?>
				<button type="button" class="shrt-chip" data-shrt-filter="<?php echo esc_attr( $key ); ?>" aria-pressed="<?php echo 'all' === $key ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?> <span data-shrt-count="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $counts[ $key ] ); ?></span></button>
			<?php endforeach; ?>
		</div>
		<span class="shrt-toolbar__space"></span>
		<label class="screen-reader-text" for="shrt-search"><?php esc_html_e( 'Search sites', 'shawesome-robots-txt' ); ?></label>
		<input type="search" id="shrt-search" class="shrt-search" placeholder="<?php esc_attr_e( 'Search sites …', 'shawesome-robots-txt' ); ?>">
		<button type="button" class="button" data-shrt-scan="all"><?php esc_html_e( 'Check all sites', 'shawesome-robots-txt' ); ?></button>
	</div>

	<div class="shrt-table-wrap">
		<table class="shrt-sites">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Site', 'shawesome-robots-txt' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Mode', 'shawesome-robots-txt' ); ?></th>
					<th scope="col"><?php echo esc_html_x( 'Source', 'where the robots.txt comes from', 'shawesome-robots-txt' ); ?></th>
					<th scope="col"><?php esc_html_e( 'SEO plugin', 'shawesome-robots-txt' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'shawesome-robots-txt' ); ?></th>
				</tr>
			</thead>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$site_id  = $row['id'];
				$seo      = shawesome_robots_txt_active_seo_plugins( $site_id );
				$edit_url = shawesome_robots_txt_page_url( array( 'site' => $site_id ) );
				$files    = array_map( 'basename', shawesome_robots_txt_candidate_files( $row['host'] ) );
				?>
				<tbody class="shrt-site is-<?php echo esc_attr( $row['level'] ); ?>" data-level="<?php echo esc_attr( $row['level'] ); ?>" data-search="<?php echo esc_attr( $row['label'] ); ?>">
					<tr>
						<td>
							<?php if ( $row['own'] ) : ?>
								<a class="shrt-site__name" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $row['label'] ); ?></a>
								<span class="shrt-site__actions">
									<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit rules', 'shawesome-robots-txt' ); ?></a>
									<a href="<?php echo esc_url( get_home_url( $site_id, 'robots.txt' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'shawesome-robots-txt' ); ?> <span aria-hidden="true">&#8599;</span><span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'shawesome-robots-txt' ); ?></span></a>
									<button type="button" class="button-link" aria-expanded="false" aria-controls="shrt-file-<?php echo esc_attr( $site_id ); ?>"><?php esc_html_e( 'File override', 'shawesome-robots-txt' ); ?></button>
								</span>
							<?php else : ?>
								<span class="shrt-site__name"><?php echo esc_html( $row['label'] ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php
							if ( ! $row['own'] ) {
								echo '–';
							} else {
								$mode = shawesome_robots_txt_site_mode( $site_id );
								/* translators: %s: name of the default mode */
								$text = '' === $mode ? sprintf( __( '%s (default)', 'shawesome-robots-txt' ), shawesome_robots_txt_mode_label( $settings['mode'] ) ) : shawesome_robots_txt_mode_label( $mode );
								if ( shawesome_robots_txt_hidden_blocked( $site_id ) ) {
									$text = __( 'Blocking (hidden site)', 'shawesome-robots-txt' );
								}
								echo '<span class="shrt-mode-badge' . ( 'block' === shawesome_robots_txt_mode( $site_id ) || shawesome_robots_txt_hidden_blocked( $site_id ) ? ' is-block' : '' ) . '">' . esc_html( $text ) . '</span>';
							}
							?>
						</td>
						<td>
							<?php
							if ( ! $row['own'] ) {
								esc_html_e( 'Served by its host', 'shawesome-robots-txt' );
								/* translators: %s: host name */
								echo '<span class="shrt-sub">' . esc_html( sprintf( __( 'robots.txt of %s', 'shawesome-robots-txt' ), $row['host'] ) ) . '</span>';
								if ( $settings['path_sites'] ) {
									echo '<span class="shrt-sub">' . esc_html( $row['public'] ? __( 'Sitemap line added', 'shawesome-robots-txt' ) : __( 'Disallow line added', 'shawesome-robots-txt' ) ) . '</span>';
								}
							} else {
								$resolved = shawesome_robots_txt_resolve( $site_id );
								if ( 'file' === $resolved['source'] ) {
									echo esc_html_x( 'File', 'where the robots.txt comes from', 'shawesome-robots-txt' );
									echo '<span class="shrt-sub">' . esc_html( basename( $resolved['file'] ) ) . '</span>';
								} elseif ( 'db' === $resolved['source'] ) {
									echo esc_html_x( 'Database', 'where the robots.txt comes from', 'shawesome-robots-txt' );
								} else {
									echo esc_html_x( 'Generated', 'where the robots.txt comes from', 'shawesome-robots-txt' );
									/* translators: %s: names of the active SEO plugins */
									echo '<span class="shrt-sub">' . esc_html( $seo ? sprintf( __( 'WordPress + %s', 'shawesome-robots-txt' ), wp_sprintf( '%l', $seo ) ) : __( 'WordPress', 'shawesome-robots-txt' ) ) . '</span>';
								}
								if ( shawesome_robots_txt_hidden_blocked( $site_id ) ) {
									echo '<span class="shrt-sub">' . esc_html__( 'Not delivered – site hidden from search engines', 'shawesome-robots-txt' ) . '</span>';
								}
							}
							?>
						</td>
						<td><?php echo $seo ? esc_html( implode( ', ', $seo ) ) : '–'; ?></td>
						<?php if ( $row['own'] ) : ?>
							<td class="shrt-status" data-shrt-status="<?php echo esc_attr( $site_id ); ?>"<?php echo $check_all || shawesome_robots_txt_status_outdated( $row['status'] ) ? ' data-outdated' : ''; ?>>
								<?php echo shawesome_robots_txt_status_html( $site_id, $row['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in shawesome_robots_txt_status_html(). ?>
							</td>
						<?php else : ?>
							<td>–</td>
						<?php endif; ?>
					</tr>
					<?php if ( $row['own'] ) : ?>
						<tr class="shrt-file" id="shrt-file-<?php echo esc_attr( $site_id ); ?>" hidden>
							<td colspan="5">
								<strong><?php esc_html_e( 'File override', 'shawesome-robots-txt' ); ?></strong>
								<span class="shrt-sub-inline"><?php esc_html_e( '– a file here replaces the database content, e.g. for deployments', 'shawesome-robots-txt' ); ?></span>
								<div class="shrt-file__paths">
									<code>&hellip;/<?php echo esc_html( basename( untrailingslashit( shawesome_robots_txt_dir() ) ) ); ?>/<strong><?php echo esc_html( $files[0] ); ?></strong></code>
									<?php if ( isset( $files[1] ) ) : ?>
										<span><?php esc_html_e( 'or', 'shawesome-robots-txt' ); ?></span>
										<code><?php echo esc_html( $files[1] ); ?></code>
									<?php endif; ?>
									<span>
										<?php
										$found = shawesome_robots_txt_find_file( $row['host'] );
										/* translators: %s: file name */
										echo esc_html( '– ' . __( 'hyphenated name wins', 'shawesome-robots-txt' ) . ' · ' . ( $found ? sprintf( __( 'file found: %s', 'shawesome-robots-txt' ), basename( $found ) ) : __( 'no file found', 'shawesome-robots-txt' ) ) );
										?>
									</span>
								</div>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			<?php endforeach; ?>
			<tbody class="shrt-empty" hidden><tr><td colspan="5"><?php esc_html_e( 'No sites match.', 'shawesome-robots-txt' ); ?></td></tr></tbody>
		</table>
	</div>
	<?php /* translators: %s: name of the directory for override files */ ?>
	<p class="shrt-footnote"><?php echo wp_kses( sprintf( __( 'Override files live in %s. Use "File override" in a row for the exact file name.', 'shawesome-robots-txt' ), '<code>' . esc_html( str_replace( ABSPATH, '', shawesome_robots_txt_dir() ) ) . '</code>' ), array( 'code' => array() ) ); ?></p>
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
					'q'    => __( 'What is the difference between "Base", "Exact" and "Blocking"?', 'shawesome-robots-txt' ),
					'a'    => __( 'In "Base" mode, your rules replace the WordPress default and SEO plugins may still change them: Yoast SEO removes the WordPress default rules and appends its own rules and sitemap, All in One SEO rebuilds the robots.txt. In "Exact" mode, your rules are delivered as written, after all SEO plugins have run. The crawler options and the rules for sites with a path are added in both modes. "Blocking" mode blocks all crawlers from all paths – for example for a staging site.', 'shawesome-robots-txt' ),
					'code' => __( "Base:     your rules, then SEO plugins may change them\nExact:    your rules as written, SEO plugins cannot change them\nBlocking: User-agent: * / Disallow: / for all crawlers", 'shawesome-robots-txt' ),
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

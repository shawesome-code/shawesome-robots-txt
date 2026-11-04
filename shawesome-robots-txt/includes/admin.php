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
 * Add the styles of the settings page.
 */
function shawesome_robots_txt_load_page() {
	add_action(
		'admin_enqueue_scripts',
		function () {
			wp_register_style( 'shawesome-robots-txt', false, array(), SHAWESOME_ROBOTS_TXT_VERSION );
			wp_enqueue_style( 'shawesome-robots-txt' );
			wp_add_inline_style(
				'shawesome-robots-txt',
				'.shawesome-robots-txt-version{margin-top:0;color:#646970}'
				. '.shawesome-robots-txt-columns{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:24px}'
				. '.shawesome-robots-txt-toolbar{text-align:right}'
				. '.shawesome-robots-txt-faq{max-width:960px;border-bottom:1px solid #dcdcde}'
				. '.shawesome-robots-txt-faq summary{padding:12px 0;font-size:14px;font-weight:600;cursor:pointer}'
				. '.shawesome-robots-txt-faq p{margin:0 0 12px}'
				. '@media (max-width:960px){.shawesome-robots-txt-columns{grid-template-columns:minmax(0,1fr)}}'
			);

			// Buttons that insert example rules into the editor (data-shawesome-robots-txt-insert="replace|append").
			wp_register_script( 'shawesome-robots-txt', false, array(), SHAWESOME_ROBOTS_TXT_VERSION, true );
			wp_enqueue_script( 'shawesome-robots-txt' );
			wp_add_inline_script(
				'shawesome-robots-txt',
				'document.addEventListener("click",function(e){'
				. 'var b=e.target.closest("[data-shawesome-robots-txt-insert]");if(!b){return;}'
				. 'var t=document.getElementById(b.getAttribute("aria-controls")),v=t.value.replace(/\s+$/,""),x=b.getAttribute("data-text");'
				. 'if("replace"===b.getAttribute("data-shawesome-robots-txt-insert")){if(v&&!window.confirm(b.getAttribute("data-confirm"))){return;}t.value=x;}'
				. 'else{t.value=v?v+"\n\n"+x:x;}'
				. 't.focus();});'
			);

			// Status of the sites: scan rows with an outdated status on load, more on request (three at a time).
			wp_add_inline_script(
				'shawesome-robots-txt',
				'var shawesomeRobotsTxt=' . wp_json_encode(
					array(
						'url'      => admin_url( 'admin-ajax.php' ),
						'nonce'    => wp_create_nonce( 'shawesome_robots_txt_scan' ),
						'scanning' => __( 'Checking …', 'shawesome-robots-txt' ),
						'failed'   => __( 'The check failed.', 'shawesome-robots-txt' ),
					)
				) . ';',
				'before'
			);
			wp_add_inline_script(
				'shawesome-robots-txt',
				'(function(c){'
				. 'function scan(td){td.textContent=c.scanning;var f=new FormData();f.append("action","shawesome_robots_txt_scan");f.append("_ajax_nonce",c.nonce);f.append("site_id",td.getAttribute("data-shawesome-robots-txt-status"));'
				. 'return fetch(c.url,{method:"POST",body:f,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){if(j&&j.success){td.innerHTML=j.data.html;}else{td.textContent=c.failed;}}).catch(function(){td.textContent=c.failed;});}'
				. 'function run(tds){var i=0;function next(){return i<tds.length?scan(tds[i++]).then(next):null;}return Promise.all([next(),next(),next()]);}'
				. 'document.addEventListener("click",function(e){var b=e.target.closest("[data-shawesome-robots-txt-scan]");if(!b){return;}e.preventDefault();'
				. 'run("all"===b.getAttribute("data-shawesome-robots-txt-scan")?[].slice.call(document.querySelectorAll("[data-shawesome-robots-txt-status]")):[b.closest("[data-shawesome-robots-txt-status]")]);});'
				. 'run([].slice.call(document.querySelectorAll("[data-shawesome-robots-txt-status][data-outdated]")));'
				. '})(shawesomeRobotsTxt);'
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
 * HTML of a status: delivery problems, blocked crawlers, sitemaps, AI
 * training crawlers, time of the check and a button to check again.
 *
 * @param int        $site_id Site ID.
 * @param array|null $status  Status.
 * @return string
 */
function shawesome_robots_txt_status_html( $site_id, $status ) {
	$settings = shawesome_robots_txt_get_settings();
	$lines    = array();
	$bad      = '<span style="color:#d63638;">&#10007;</span> ';
	$good     = '<span style="color:#008a20;">&#10003;</span> ';
	$warn     = '<span style="color:#dba617;">&#9888;</span> ';

	if ( is_file( shawesome_robots_txt_physical_file() ) ) {
		$lines[] = $warn . esc_html__( 'Physical robots.txt in the web root is delivered', 'shawesome-robots-txt' );
	}
	if ( $settings['respect_public'] && ! shawesome_robots_txt_is_public( $site_id ) ) {
		$lines[] = $warn . esc_html__( 'Hidden from search engines', 'shawesome-robots-txt' );
	}

	if ( ! $status ) {
		$lines[] = esc_html__( 'Not checked yet', 'shawesome-robots-txt' );
	} elseif ( '' !== $status['error'] ) {
		/* translators: %s: error message or HTTP status code */
		$lines[] = $bad . esc_html( sprintf( __( 'Not reachable (%s)', 'shawesome-robots-txt' ), $status['error'] ) );
	} else {
		if ( $status['all_blocked'] ) {
			$lines[] = $bad . '<strong>' . esc_html__( 'All crawlers blocked', 'shawesome-robots-txt' ) . '</strong>';
		}

		$lines[] = $status['sitemaps']
			/* translators: %d: number of Sitemap lines */
			? $good . esc_html( sprintf( _n( '%d sitemap linked', '%d sitemaps linked', $status['sitemaps'], 'shawesome-robots-txt' ), $status['sitemaps'] ) )
			: $warn . esc_html__( 'No sitemap linked', 'shawesome-robots-txt' );

		if ( $status['ai_blocked'] === $status['ai_total'] ) {
			$lines[] = $good . esc_html__( 'AI training blocked', 'shawesome-robots-txt' );
		} elseif ( $status['ai_blocked'] ) {
			/* translators: 1: number of blocked AI training crawlers, 2: number of known AI training crawlers */
			$lines[] = $warn . esc_html( sprintf( __( 'AI training partly blocked (%1$d of %2$d)', 'shawesome-robots-txt' ), $status['ai_blocked'], $status['ai_total'] ) );
		} else {
			$lines[] = esc_html__( 'AI training not blocked', 'shawesome-robots-txt' );
		}
	}

	$footer = '';
	if ( $status ) {
		/* translators: %s: time since the check, e.g. "2 hours" */
		$footer = esc_html( sprintf( __( 'Checked %s ago', 'shawesome-robots-txt' ), human_time_diff( $status['time'] ) ) ) . ' · ';
	}
	$footer .= '<a href="#" data-shawesome-robots-txt-scan="one">' . esc_html__( 'Check again', 'shawesome-robots-txt' ) . '</a>';

	return implode( '<br>', $lines ) . '<br><span class="description">' . $footer . '</span>';
}

/**
 * AJAX: check the robots.txt of a site and return its status HTML.
 */
function shawesome_robots_txt_ajax_scan() {
	check_ajax_referer( 'shawesome_robots_txt_scan' );
	shawesome_robots_txt_check_cap();

	$site_id = shawesome_robots_txt_posted_site_id();

	wp_send_json_success( array( 'html' => shawesome_robots_txt_status_html( $site_id, shawesome_robots_txt_scan( $site_id ) ) ) );
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
	echo '<p class="shawesome-robots-txt-version">' . esc_html( sprintf( __( 'Version %s', 'shawesome-robots-txt' ), SHAWESOME_ROBOTS_TXT_VERSION ) ) . '</p>';

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
		$site_id = (int) $site->blog_id;
		echo '<p><a href="' . esc_url( shawesome_robots_txt_page_url() ) . '">&larr; ' . esc_html__( 'All sites', 'shawesome-robots-txt' ) . '</a></p>';
		/* translators: %s: host name of the site */
		echo '<h2>' . esc_html( sprintf( __( '%s robots.txt', 'shawesome-robots-txt' ), shawesome_robots_txt_host( $site_id ) ) ) . '</h2>';
		shawesome_robots_txt_render_editor( $site_id );
	} else {
		shawesome_robots_txt_render_warnings();

		if ( is_multisite() ) {
			shawesome_robots_txt_render_sites();
		} else {
			/* translators: %s: host name of the site */
			echo '<h2>' . esc_html( sprintf( __( '%s robots.txt', 'shawesome-robots-txt' ), shawesome_robots_txt_host( get_current_blog_id() ) ) ) . '</h2>';
			shawesome_robots_txt_render_editor( get_current_blog_id() );
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
 * Render the editor of one site.
 *
 * @param int $site_id Site ID.
 */
function shawesome_robots_txt_render_editor( $site_id ) {
	if ( ! shawesome_robots_txt_serves_own( $site_id ) ) {
		if ( is_multisite() ) {
			shawesome_robots_txt_render_served_by( $site_id );
		}
		return;
	}

	$resolved   = shawesome_robots_txt_resolve( $site_id );
	$db_content = shawesome_robots_txt_get_db_content( $site_id );
	$settings   = shawesome_robots_txt_get_settings();

	if ( $settings['respect_public'] && ! shawesome_robots_txt_is_public( $site_id ) ) {
		shawesome_robots_txt_notice( esc_html__( 'This site is hidden from search engines, so the custom robots.txt is ignored and WordPress delivers its default robots.txt.', 'shawesome-robots-txt' ), 'warning', true );
	}

	// Yoast SEO removes this block wherever it is (same pattern as in its robots.txt integration).
	$yoast_block = '`User-agent: \*[\r\n]+Disallow: /wp-admin/[\r\n]+Allow: /wp-admin/admin-ajax\.php[\r\n]+`';
	if ( 'base' === shawesome_robots_txt_mode( $site_id ) && in_array( 'Yoast SEO', shawesome_robots_txt_active_seo_plugins( $site_id ), true )
		&& preg_match( $yoast_block, (string) $resolved['content'] . "\n" ) ) {
		shawesome_robots_txt_notice( esc_html__( 'In "Base" mode, Yoast SEO removes the lines "User-agent: *", "Disallow: /wp-admin/" and "Allow: /wp-admin/admin-ajax.php" when they appear in this order. Put the Allow line first (crawlers do not care about the order) or use "Exact" mode.', 'shawesome-robots-txt' ), 'warning', true );
	}

	if ( 'file' === $resolved['source'] ) :
		shawesome_robots_txt_notice(
			sprintf(
				/* translators: %s: file path */
				esc_html__( 'This site is overridden by the file %s. The file is delivered; the database content below is only used once the file is removed.', 'shawesome-robots-txt' ),
				'<code>' . esc_html( $resolved['file'] ) . '</code>'
			),
			'info',
			true
		);
		?>
		<h3><?php esc_html_e( 'File content (read-only)', 'shawesome-robots-txt' ); ?></h3>
		<textarea class="large-text code" rows="12" readonly><?php echo esc_textarea( $resolved['content'] ); ?></textarea>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php shawesome_robots_txt_form_fields( 'import', 'shawesome_robots_txt_import_' . $site_id ); ?>
			<input type="hidden" name="site_id" value="<?php echo esc_attr( $site_id ); ?>">
			<?php submit_button( __( 'Copy file into database', 'shawesome-robots-txt' ), 'secondary', 'submit', false ); ?>
		</form>
	<?php endif; ?>

	<?php
	$site_mode = shawesome_robots_txt_site_mode( $site_id );
	$rules     = shawesome_robots_txt_site_rules( $site_id );
	$choices   = array(
		/* translators: %s: name of the default mode */
		''      => sprintf( __( 'Default (%s)', 'shawesome-robots-txt' ), shawesome_robots_txt_mode_label( $settings['mode'] ) ),
		/* translators: %s: name of the mode "Base" */
		'base'  => sprintf( __( '%s – SEO plugins may add to the content', 'shawesome-robots-txt' ), shawesome_robots_txt_mode_label( 'base' ) ),
		/* translators: %s: name of the mode "Exact" */
		'exact' => sprintf( __( '%s – the content is delivered as is', 'shawesome-robots-txt' ), shawesome_robots_txt_mode_label( 'exact' ) ),
	);
	$resources = array(
		'css_js' => array( __( 'Allow CSS and JavaScript files (recommended)', 'shawesome-robots-txt' ), __( 'Important so that Google understands the layout of your pages and whether they work on mobile devices.', 'shawesome-robots-txt' ) ),
		'images' => array( __( 'Allow images (recommended)', 'shawesome-robots-txt' ), __( 'Lets crawlers fetch images for Google Images and other image searches.', 'shawesome-robots-txt' ) ),
		'pdfs'   => array( __( 'Allow PDF files', 'shawesome-robots-txt' ), __( 'Lets crawlers fetch PDF documents, so they can appear in search results.', 'shawesome-robots-txt' ) ),
	);
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php shawesome_robots_txt_form_fields( 'save_site', 'shawesome_robots_txt_save_site_' . $site_id ); ?>
		<input type="hidden" name="site_id" value="<?php echo esc_attr( $site_id ); ?>">

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Mode', 'shawesome-robots-txt' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Mode', 'shawesome-robots-txt' ); ?></legend>
						<?php foreach ( $choices as $value => $label ) : ?>
							<label><input type="radio" name="site_mode" value="<?php echo esc_attr( $value ); ?>" <?php checked( $site_mode, $value ); ?>> <?php echo esc_html( $label ); ?></label><br>
						<?php endforeach; ?>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Crawl-delay', 'shawesome-robots-txt' ); ?></th>
				<td>
					<label><input type="checkbox" name="crawl_delay_on" value="1" <?php checked( $rules['crawl_delay'] > 0 ); ?>> <?php esc_html_e( 'Ask crawlers to wait between requests:', 'shawesome-robots-txt' ); ?></label>
					<input type="number" name="crawl_delay" min="1" max="60" step="1" class="small-text" value="<?php echo esc_attr( $rules['crawl_delay'] ? $rules['crawl_delay'] : 10 ); ?>" aria-label="<?php esc_attr_e( 'Crawl-delay in seconds', 'shawesome-robots-txt' ); ?>">
					<?php esc_html_e( 'seconds', 'shawesome-robots-txt' ); ?>
					<p class="description"><?php esc_html_e( 'Google ignores this line; Bing and Yandex, for example, follow it.', 'shawesome-robots-txt' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Resources and assets', 'shawesome-robots-txt' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Resources and assets', 'shawesome-robots-txt' ); ?></legend>
						<?php foreach ( $resources as $key => $texts ) : ?>
							<label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $rules[ $key ] ); ?>> <?php echo esc_html( $texts[0] ); ?></label>
							<p class="description"><?php echo esc_html( $texts[1] ); ?></p>
						<?php endforeach; ?>
					</fieldset>
					<p class="description"><?php esc_html_e( 'Crawl-delay and blocked resources are added at the end of the robots.txt as a group for all crawlers, in both modes. Crawlers that have a group of their own in the robots.txt do not read it.', 'shawesome-robots-txt' ); ?></p>
				</td>
			</tr>
		</table>

		<div class="shawesome-robots-txt-columns">
			<div>
				<h3><?php esc_html_e( 'Database content', 'shawesome-robots-txt' ); ?></h3>
				<textarea id="shawesome-robots-txt-content" name="content" class="large-text code" rows="16" placeholder="<?php echo esc_attr( shawesome_robots_txt_example_rules( $site_id ) ); ?>"><?php echo esc_textarea( $db_content ); ?></textarea>
				<p>
					<button type="button" class="button" aria-controls="shawesome-robots-txt-content" data-shawesome-robots-txt-insert="replace" data-text="<?php echo esc_attr( shawesome_robots_txt_example_rules( $site_id ) ); ?>" data-confirm="<?php esc_attr_e( 'Replace the current content with the example rules?', 'shawesome-robots-txt' ); ?>"><?php esc_html_e( 'Insert example rules', 'shawesome-robots-txt' ); ?></button>
					<button type="button" class="button" aria-controls="shawesome-robots-txt-content" data-shawesome-robots-txt-insert="append" data-text="<?php echo esc_attr( shawesome_robots_txt_ai_rules() ); ?>"><?php esc_html_e( 'Add: block AI training, allow AI search', 'shawesome-robots-txt' ); ?></button>
				</p>
				<p class="description"><?php esc_html_e( 'The buttons only change the field; nothing is saved until you click "Save robots.txt". Leave empty to keep the robots.txt generated by WordPress and SEO plugins.', 'shawesome-robots-txt' ); ?></p>
				<?php submit_button( __( 'Save robots.txt', 'shawesome-robots-txt' ) ); ?>
			</div>
			<div>
				<?php shawesome_robots_txt_render_live( $site_id ); ?>
			</div>
		</div>
	</form>
	<?php
}

/**
 * Render the robots.txt as currently delivered to crawlers. It is requested
 * from the site itself, so it includes a physical file and the output of SEO
 * plugins.
 *
 * @param int $site_id Site ID.
 */
function shawesome_robots_txt_render_live( $site_id ) {
	$url     = get_home_url( $site_id, 'robots.txt' );
	$fetched = shawesome_robots_txt_fetch( $site_id );
	$status  = shawesome_robots_txt_scan( $site_id, $fetched );
	?>
	<h3><?php esc_html_e( 'Live robots.txt', 'shawesome-robots-txt' ); ?></h3>
	<?php if ( null === $fetched['body'] ) : ?>
		<?php
		shawesome_robots_txt_notice(
			sprintf(
				/* translators: %s: error message or HTTP status code */
				esc_html__( 'The live robots.txt could not be loaded (%s). The server may block requests to itself.', 'shawesome-robots-txt' ),
				esc_html( $fetched['error'] )
			),
			'warning',
			true
		);
		?>
	<?php else : ?>
		<textarea class="large-text code" rows="16" readonly><?php echo esc_textarea( $fetched['body'] ); ?></textarea>
	<?php endif; ?>
	<p class="description"><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open live robots.txt', 'shawesome-robots-txt' ); ?></a></p>
	<h3><?php esc_html_e( 'Status', 'shawesome-robots-txt' ); ?></h3>
	<p data-shawesome-robots-txt-status="<?php echo esc_attr( $site_id ); ?>"><?php echo shawesome_robots_txt_status_html( $site_id, $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in shawesome_robots_txt_status_html(). ?></p>
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
	$settings = shawesome_robots_txt_get_settings();
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
	<p class="shawesome-robots-txt-toolbar"><button type="button" class="button" data-shawesome-robots-txt-scan="all"><?php esc_html_e( 'Check all sites again', 'shawesome-robots-txt' ); ?></button></p>
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
						<td data-shawesome-robots-txt-status="<?php echo esc_attr( $site_id ); ?>"<?php echo shawesome_robots_txt_status_outdated( $status ) ? ' data-outdated' : ''; ?>>
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
 * Render the FAQ (the same questions as in readme.txt).
 */
function shawesome_robots_txt_render_faq() {
	$faq = array(
		__( 'My robots.txt is not delivered.', 'shawesome-robots-txt' )
			=> __( 'Check that there is no physical robots.txt in the web root and that the site is not set to "Discourage search engines". In a network, the overview shows the source and the status of every site. Override files must be readable by the web server.', 'shawesome-robots-txt' ),
		__( 'I changed the content in the editor, but the old one is still delivered.', 'shawesome-robots-txt' )
			=> __( 'A file in the override directory takes precedence over the database content. Delete the file – or copy it into the database first with the button in the editor. A page cache or CDN may also deliver an old copy.', 'shawesome-robots-txt' ),
		__( 'What is the difference between "Base" and "Exact"?', 'shawesome-robots-txt' )
			=> __( 'In "Base" mode, the content replaces the WordPress default and SEO plugins may still change it: Yoast SEO removes the WordPress default rules and appends its own rules and sitemap, All in One SEO rebuilds the robots.txt. In "Exact" mode, a custom robots.txt is delivered as is. The default mode is set in the settings; each site can choose its own in its editor.', 'shawesome-robots-txt' ),
		__( 'What does the status check?', 'shawesome-robots-txt' )
			=> __( 'It requests the robots.txt of the site as crawlers get it and shows whether all crawlers are blocked, how many sitemaps are linked and whether crawlers that collect content for AI training are blocked. The result is kept for the time set in the settings and can be checked again at any time.', 'shawesome-robots-txt' ),
		__( 'Does it work if WordPress is installed in a subdirectory?', 'shawesome-robots-txt' )
			=> __( 'If the address of the site contains a path (example.com/blog/), crawlers request the robots.txt from example.com/robots.txt, which WordPress does not deliver. The plugin then does nothing and says so. If only the WordPress files are in a subdirectory and the address is the root of the domain, it works.', 'shawesome-robots-txt' ),
		__( 'What happens on uninstall?', 'shawesome-robots-txt' )
			=> __( 'The settings and everything stored in the database are deleted. Files in the override directory and a renamed _old-robots.txt are kept.', 'shawesome-robots-txt' ),
	);

	foreach ( $faq as $question => $answer ) {
		echo '<details class="shawesome-robots-txt-faq"><summary>' . esc_html( $question ) . '</summary><p>' . esc_html( $answer ) . '</p></details>';
	}
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

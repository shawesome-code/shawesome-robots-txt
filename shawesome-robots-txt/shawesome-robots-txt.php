<?php
/**
 * Plugin Name:         shawesome robots.txt – Per-Site Rules for Single Sites and Multisite
 * Plugin URI:          https://github.com/shawesome-code/shawesome-robots-txt
 * Description:         Its own robots.txt for a single site or for every site of a multisite network – edited in the admin, or deployed as a file to /wp-content/shawesome-robots-txt/ (e.g. www.example.com → www-example-com.txt), which takes precedence.
 * Version:             1.0.0
 * Requires at least:   7.1
 * Requires PHP:        7.4
 * Author:              shawesome
 * Author URI:          https://shawesome.de/
 * License:             GPL-2.0-or-later
 * License URI:         https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:         shawesome-robots-txt
 * Domain Path:         /languages
 * Network:             true
 *
 * Copyright © shawesome.
 *
 * @package Shawesome_Robots_Txt
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SHAWESOME_ROBOTS_TXT_VERSION', '1.0.0' );
define( 'SHAWESOME_ROBOTS_TXT_OPTION', 'shawesome_robots_txt_settings' );
define( 'SHAWESOME_ROBOTS_TXT_CONTENT_OPTION', 'shawesome_robots_txt_content' );
define( 'SHAWESOME_ROBOTS_TXT_MODE_OPTION', 'shawesome_robots_txt_mode' );
define( 'SHAWESOME_ROBOTS_TXT_STATUS_OPTION', 'shawesome_robots_txt_status' );
define( 'SHAWESOME_ROBOTS_TXT_RULES_OPTION', 'shawesome_robots_txt_rules' );

/**
 * Option of a site (the current site on a single site).
 *
 * @param int    $site_id Site ID (ignored on a single site).
 * @param string $name    Option name.
 * @param mixed  $default Value if the option does not exist.
 * @return mixed
 */
function shawesome_robots_txt_get_site_option( $site_id, $name, $default = '' ) {
	return is_multisite() ? get_blog_option( $site_id, $name, $default ) : get_option( $name, $default );
}

/**
 * Store an option of a site, not autoloaded (only needed for robots.txt
 * requests and the admin). An empty value deletes the option.
 *
 * @param int    $site_id Site ID (ignored on a single site).
 * @param string $name    Option name.
 * @param mixed  $value   Value.
 */
function shawesome_robots_txt_set_site_option( $site_id, $name, $value ) {
	if ( is_multisite() ) {
		switch_to_blog( $site_id );
	}
	if ( '' === $value || null === $value || array() === $value ) {
		delete_option( $name );
	} else {
		update_option( $name, $value, false );
	}
	if ( is_multisite() ) {
		restore_current_blog();
	}
}

/**
 * Mode chosen for a site: 'base', 'exact' or '' (use the default mode).
 *
 * @param int $site_id Site ID (ignored on a single site).
 * @return string
 */
function shawesome_robots_txt_site_mode( $site_id ) {
	$mode = (string) shawesome_robots_txt_get_site_option( $site_id, SHAWESOME_ROBOTS_TXT_MODE_OPTION );

	return in_array( $mode, array( 'base', 'exact' ), true ) ? $mode : '';
}

/**
 * Mode in effect for a site: its own or the default mode.
 *
 * @param int $site_id Site ID (ignored on a single site).
 * @return string 'base' or 'exact'.
 */
function shawesome_robots_txt_mode( $site_id ) {
	$mode = shawesome_robots_txt_site_mode( $site_id );
	if ( '' === $mode ) {
		$settings = shawesome_robots_txt_get_settings();
		$mode     = $settings['mode'];
	}

	return $mode;
}

/**
 * Options of a site for added rules, merged with the defaults.
 *
 * - crawl_delay: seconds, 0 = no Crawl-delay line.
 * - css_js, images, pdfs: allowed (default) or blocked with Disallow lines.
 *
 * @param int $site_id Site ID (ignored on a single site).
 * @return array{crawl_delay: int, css_js: bool, images: bool, pdfs: bool}
 */
function shawesome_robots_txt_site_rules( $site_id ) {
	$rules = wp_parse_args(
		(array) shawesome_robots_txt_get_site_option( $site_id, SHAWESOME_ROBOTS_TXT_RULES_OPTION, array() ),
		array(
			'crawl_delay' => 0,
			'css_js'      => true,
			'images'      => true,
			'pdfs'        => true,
		)
	);

	return array(
		'crawl_delay' => min( 60, max( 0, (int) $rules['crawl_delay'] ) ),
		'css_js'      => (bool) $rules['css_js'],
		'images'      => (bool) $rules['images'],
		'pdfs'        => (bool) $rules['pdfs'],
	);
}

/**
 * Lines for the added rules of a site: Crawl-delay and Disallow lines for
 * blocked resources. Patterns use "*" and "$" as defined in RFC 9309; "?"
 * matches the query string of versioned CSS and JavaScript files.
 *
 * @param int $site_id Site ID (ignored on a single site).
 * @return string[]
 */
function shawesome_robots_txt_site_rule_lines( $site_id ) {
	$rules = shawesome_robots_txt_site_rules( $site_id );
	$lines = array();

	if ( $rules['crawl_delay'] ) {
		$lines[] = 'Crawl-delay: ' . $rules['crawl_delay'];
	}
	if ( ! $rules['css_js'] ) {
		foreach ( array( 'css', 'js' ) as $ext ) {
			$lines[] = 'Disallow: /*.' . $ext . '$';
			$lines[] = 'Disallow: /*.' . $ext . '?';
		}
	}
	if ( ! $rules['images'] ) {
		foreach ( array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg' ) as $ext ) {
			$lines[] = 'Disallow: /*.' . $ext . '$';
		}
	}
	if ( ! $rules['pdfs'] ) {
		$lines[] = 'Disallow: /*.pdf$';
	}

	return $lines;
}

/**
 * Crawlers that collect content for AI training, used for the example block
 * and the status. AI search crawlers (OAI-SearchBot, Claude-SearchBot,
 * PerplexityBot) are not listed on purpose.
 *
 * Sources: the crawler documentation of OpenAI, Anthropic, Google, Apple and
 * Common Crawl (checked 2026-11).
 *
 * @return string[] User agent tokens.
 */
function shawesome_robots_txt_ai_agents() {
	return array( 'GPTBot', 'ClaudeBot', 'Google-Extended', 'Applebot-Extended', 'CCBot' );
}

/**
 * Directory holding the per-site robots.txt files.
 *
 * Can be changed with the constant SHAWESOME_ROBOTS_TXT_DIR (e.g. in wp-config.php)
 * or the filter 'shawesome_robots_txt_dir'.
 *
 * @return string Absolute path with trailing slash.
 */
function shawesome_robots_txt_dir() {
	$dir = defined( 'SHAWESOME_ROBOTS_TXT_DIR' ) ? SHAWESOME_ROBOTS_TXT_DIR : WP_CONTENT_DIR . '/shawesome-robots-txt/';

	return trailingslashit( apply_filters( 'shawesome_robots_txt_dir', $dir ) );
}

/**
 * Host of a site's home URL, e.g. www.example.com. Also correct for sites
 * with a mapped domain in a network.
 *
 * @param int $site_id Site ID (ignored on a single site).
 * @return string Lowercase host without port.
 */
function shawesome_robots_txt_host( $site_id ) {
	return strtolower( (string) wp_parse_url( get_home_url( $site_id ), PHP_URL_HOST ) );
}

/**
 * Whether a site gets its own robots.txt. Crawlers only request robots.txt
 * from the root of a host, so this is the case for sites whose home URL has
 * no path: single sites at the root, every site of a network with
 * (sub)domains, and the main site and sites with a mapped domain in a
 * network with subdirectories.
 *
 * @param int $site_id Site ID (ignored on a single site).
 * @return bool
 */
function shawesome_robots_txt_serves_own( $site_id ) {
	return '' === trim( (string) wp_parse_url( get_home_url( $site_id ), PHP_URL_PATH ), '/' );
}

/**
 * Whether a site is visible to search engines (option blog_public).
 *
 * @param int $site_id Site ID (ignored on a single site).
 * @return bool
 */
function shawesome_robots_txt_is_public( $site_id ) {
	$public = is_multisite() ? get_blog_option( $site_id, 'blog_public' ) : get_option( 'blog_public' );

	return '0' !== (string) $public;
}

/**
 * Settings, merged with the defaults. In a network they apply to all sites.
 *
 * - mode: the default mode, each site can choose its own.
 *   'base' (default): the content replaces the WordPress default, SEO
 *   plugins may still add their rules and sitemaps.
 *   'exact': the content is delivered as is, after all SEO plugins
 *   (Yoast SEO, All in One SEO …) have run.
 * - respect_public: sites set to "Discourage search engines" keep the
 *   WordPress default instead of the file.
 * - path_sites: in a network, add rules for the sites with a path to the
 *   robots.txt of their host (see shawesome_robots_txt_path_rules()).
 * - status_hours: how long the status of a robots.txt is kept before it is
 *   checked again (1 to 720 hours).
 *
 * @return array{mode: string, respect_public: bool, path_sites: bool, status_hours: int}
 */
function shawesome_robots_txt_get_settings() {
	$defaults = array(
		'mode'           => 'base',
		'respect_public' => true,
		'path_sites'     => true,
		'status_hours'   => 24,
	);

	$settings = wp_parse_args( (array) get_site_option( SHAWESOME_ROBOTS_TXT_OPTION, array() ), $defaults );

	if ( ! in_array( $settings['mode'], array( 'exact', 'base' ), true ) ) {
		$settings['mode'] = $defaults['mode'];
	}
	$settings['respect_public'] = (bool) $settings['respect_public'];
	$settings['path_sites']     = (bool) $settings['path_sites'];
	$settings['status_hours']   = min( 720, max( 1, (int) $settings['status_hours'] ) );

	return $settings;
}

/**
 * Sites of the network whose address is a path on the given host
 * (e.g. example.com/blog/). Crawlers only read the robots.txt of the host,
 * so their rules belong there.
 *
 * @param string $host Host name.
 * @return WP_Site[]
 */
function shawesome_robots_txt_path_sites( $host ) {
	if ( ! is_multisite() ) {
		return array();
	}

	$sites = array();
	foreach ( get_sites(
		array(
			'number'   => 0,
			'deleted'  => 0,
			'archived' => 0,
			'spam'     => 0,
			'domain'   => $host,
		)
	) as $site ) {
		if ( '/' !== $site->path ) {
			$sites[] = $site;
		}
	}

	return $sites;
}

/**
 * Rules for the sites with a path on a host: Disallow for sites hidden from
 * search engines (WordPress only adds noindex to their pages), Sitemap for
 * public sites. The Disallow lines get their own group for all crawlers, so
 * they do not end up in the group of a single crawler. Crawlers with their
 * own group in the robots.txt do not read it.
 *
 * @param string $host Host name.
 * @return string Rules, empty if there are no such sites.
 */
function shawesome_robots_txt_path_rules( $host ) {
	$disallow = array();
	$sitemaps = array();

	foreach ( shawesome_robots_txt_path_sites( $host ) as $site ) {
		if ( '1' === (string) $site->public ) {
			$sitemaps[] = 'Sitemap: ' . set_url_scheme( 'http://' . $site->domain . $site->path . 'wp-sitemap.xml' );
		} else {
			$disallow[] = 'Disallow: ' . $site->path;
		}
	}

	if ( ! $disallow && ! $sitemaps ) {
		return '';
	}

	$rules = '# Sites with a path, added by shawesome robots.txt';
	if ( $disallow ) {
		$rules .= "\nUser-agent: *\n" . implode( "\n", $disallow );
	}
	if ( $sitemaps ) {
		$rules .= "\n\n" . implode( "\n", $sitemaps );
	}

	return $rules;
}

/**
 * Candidate file names for a domain, in order of precedence.
 *
 * The hyphenated name (www-example-com.txt) comes first for backwards
 * compatibility. The name with dots (www.example.com.txt) is unambiguous,
 * because a-b.example and a.b.example both map to a-b-example.txt.
 *
 * @param string $domain Site domain.
 * @return string[] Absolute file paths.
 */
function shawesome_robots_txt_candidate_files( $domain ) {
	$dir    = shawesome_robots_txt_dir();
	$domain = strtolower( $domain );

	$files = array(
		$dir . str_replace( '.', '-', $domain ) . '.txt',
		$dir . $domain . '.txt',
	);

	return (array) apply_filters( 'shawesome_robots_txt_candidate_files', $files, $domain );
}

/**
 * First readable robots.txt file for a domain.
 *
 * @param string $domain Site domain.
 * @return string|null Absolute file path or null.
 */
function shawesome_robots_txt_find_file( $domain ) {
	foreach ( shawesome_robots_txt_candidate_files( $domain ) as $file ) {
		if ( is_readable( $file ) ) {
			return $file;
		}
	}

	return null;
}

/**
 * Normalize robots.txt content: strip a UTF-8 BOM (it would break the first
 * directive for crawlers) and normalize line endings to "\n".
 *
 * @param string $content Raw content.
 * @return string
 */
function shawesome_robots_txt_normalize( $content ) {
	if ( 0 === strpos( $content, "\xEF\xBB\xBF" ) ) {
		$content = substr( $content, 3 );
	}

	return str_replace( array( "\r\n", "\r" ), "\n", $content );
}

/**
 * Read a robots.txt file.
 *
 * @param string $file Absolute file path.
 * @return string|null Normalized file contents or null on failure.
 */
function shawesome_robots_txt_read_file( $file ) {
	$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

	return false === $content ? null : shawesome_robots_txt_normalize( $content );
}

/**
 * robots.txt content stored in the database for a site.
 *
 * @param int $site_id Site ID (ignored on a single site).
 * @return string Content, empty if none.
 */
function shawesome_robots_txt_get_db_content( $site_id ) {
	return (string) shawesome_robots_txt_get_site_option( $site_id, SHAWESOME_ROBOTS_TXT_CONTENT_OPTION );
}

/**
 * Store robots.txt content in the database for a site. Empty content
 * deletes the option, so the site falls back to the WordPress default.
 *
 * @param int    $site_id Site ID (ignored on a single site).
 * @param string $content Content.
 */
function shawesome_robots_txt_set_db_content( $site_id, $content ) {
	$content = shawesome_robots_txt_normalize( (string) $content );

	shawesome_robots_txt_set_site_option( $site_id, SHAWESOME_ROBOTS_TXT_CONTENT_OPTION, '' === trim( $content ) ? '' : $content );
}

/**
 * Where the robots.txt of a site comes from. A file takes precedence over
 * the database, so that deployments keep control.
 *
 * @param int $site_id Site ID (ignored on a single site).
 * @return array{source: string|null, file: string|null, content: string|null}
 *               source is 'file', 'db' or null (WordPress default).
 */
function shawesome_robots_txt_resolve( $site_id ) {
	$file = shawesome_robots_txt_find_file( shawesome_robots_txt_host( $site_id ) );
	if ( null !== $file ) {
		$content = shawesome_robots_txt_read_file( $file );
		if ( null !== $content ) {
			return array(
				'source'  => 'file',
				'file'    => $file,
				'content' => $content,
			);
		}
	}

	$content = shawesome_robots_txt_get_db_content( $site_id );
	if ( '' !== trim( $content ) ) {
		return array(
			'source'  => 'db',
			'file'    => null,
			'content' => $content,
		);
	}

	return array(
		'source'  => null,
		'file'    => null,
		'content' => null,
	);
}

/**
 * Remove repeated Sitemap lines (case-insensitive), keeping the first one.
 * Used in 'base' mode, when the file and an SEO plugin both add the sitemap.
 *
 * @param string $output robots.txt output.
 * @return string
 */
function shawesome_robots_txt_dedupe_sitemaps( $output ) {
	$seen  = array();
	$lines = array();

	foreach ( preg_split( '/\r\n|\r|\n/', $output ) as $line ) {
		if ( preg_match( '/^\s*sitemap\s*:\s*(\S+)/i', $line, $match ) ) {
			$url = strtolower( untrailingslashit( $match[1] ) );
			if ( isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;
		}
		$lines[] = $line;
	}

	return implode( "\n", $lines );
}

/**
 * Filter callback for 'robots_txt': replace the output with the site's
 * file or database content.
 *
 * @param string $output robots.txt output so far.
 * @param bool   $public Whether the site is public (false = discourage search engines).
 * @return string
 */
function shawesome_robots_txt_filter_robots_txt( $output, $public ) {
	// WordPress has already resolved the current site from the requested host.
	$site_id = get_current_blog_id();

	if ( ! shawesome_robots_txt_serves_own( $site_id ) ) {
		return $output;
	}

	$settings = shawesome_robots_txt_get_settings();

	// Keep the WordPress default for sites hidden from search engines, so
	// that e.g. a staging site does not deliver the rules of production.
	if ( $settings['respect_public'] && ! $public ) {
		return $output;
	}

	$resolved = shawesome_robots_txt_resolve( $site_id );

	// No file and no database content -> keep the default behavior.
	return null === $resolved['content'] ? $output : $resolved['content'];
}

/**
 * Filter callback for 'robots_txt' in 'base' mode, after all SEO plugins.
 *
 * @param string $output robots.txt output.
 * @return string
 */
function shawesome_robots_txt_filter_dedupe( $output ) {
	return shawesome_robots_txt_dedupe_sitemaps( $output );
}

/**
 * Filter callback for 'robots_txt' after all SEO plugins, in both modes: add
 * the rules of the site (Crawl-delay, blocked resources) as a group for all
 * crawlers, and in a network the rules for the sites with a path on this host.
 *
 * @param string $output robots.txt output.
 * @param bool   $public Whether the site is public (false = discourage search engines).
 * @return string
 */
function shawesome_robots_txt_filter_added_rules( $output, $public ) {
	$site_id  = get_current_blog_id();
	$settings = shawesome_robots_txt_get_settings();

	if ( ! shawesome_robots_txt_serves_own( $site_id ) ) {
		return $output;
	}

	$blocks = array();

	// Like the custom robots.txt, the site rules are left out on hidden sites.
	$lines = shawesome_robots_txt_site_rule_lines( $site_id );
	if ( $lines && ! ( $settings['respect_public'] && ! $public ) ) {
		$blocks[] = "# Added by shawesome robots.txt\nUser-agent: *\n" . implode( "\n", $lines );
	}

	if ( $settings['path_sites'] ) {
		$blocks[] = shawesome_robots_txt_path_rules( shawesome_robots_txt_host( $site_id ) );
	}

	$blocks = array_filter( $blocks );

	return $blocks ? rtrim( $output ) . "\n\n" . implode( "\n\n", $blocks ) . "\n" : $output;
}

/**
 * Register the robots.txt filters according to the mode of the current site.
 * Runs on 'do_robotstxt', so only for robots.txt requests.
 *
 * 'exact' runs at PHP_INT_MAX, after Yoast SEO (99999) and All in One SEO
 * (10000), which would otherwise append to or rebuild the file. 'base' runs
 * at 10, before them. The priority can be changed with the filter
 * 'shawesome_robots_txt_priority'.
 */
function shawesome_robots_txt_register_filters() {
	$settings = shawesome_robots_txt_get_settings();
	$mode     = shawesome_robots_txt_mode( get_current_blog_id() );
	$priority = 'exact' === $mode ? PHP_INT_MAX : 10;
	$priority = (int) apply_filters( 'shawesome_robots_txt_priority', $priority, $mode );

	add_filter( 'robots_txt', 'shawesome_robots_txt_filter_robots_txt', $priority, 2 );

	// Added first, so that duplicate Sitemap lines are removed afterwards.
	add_filter( 'robots_txt', 'shawesome_robots_txt_filter_added_rules', PHP_INT_MAX, 2 );

	if ( 'base' === $mode ) {
		add_filter( 'robots_txt', 'shawesome_robots_txt_filter_dedupe', PHP_INT_MAX );
	}
}
add_action( 'do_robotstxt', 'shawesome_robots_txt_register_filters' );

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin.php';
}

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
 * - mode 'base' (default): the file replaces the WordPress default, SEO
 *   plugins may still add their rules and sitemaps.
 * - mode 'exact': the file is delivered as is, after all SEO plugins
 *   (Yoast SEO, All in One SEO …) have run.
 * - respect_public: sites set to "Discourage search engines" keep the
 *   WordPress default instead of the file.
 *
 * @return array{mode: string, respect_public: bool}
 */
function shawesome_robots_txt_get_settings() {
	$defaults = array(
		'mode'           => 'base',
		'respect_public' => true,
	);

	$settings = wp_parse_args( (array) get_site_option( SHAWESOME_ROBOTS_TXT_OPTION, array() ), $defaults );

	if ( ! in_array( $settings['mode'], array( 'exact', 'base' ), true ) ) {
		$settings['mode'] = $defaults['mode'];
	}
	$settings['respect_public'] = (bool) $settings['respect_public'];

	return $settings;
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
	if ( is_multisite() ) {
		return (string) get_blog_option( $site_id, SHAWESOME_ROBOTS_TXT_CONTENT_OPTION, '' );
	}

	return (string) get_option( SHAWESOME_ROBOTS_TXT_CONTENT_OPTION, '' );
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

	if ( is_multisite() ) {
		switch_to_blog( $site_id );
	}
	if ( '' === trim( $content ) ) {
		delete_option( SHAWESOME_ROBOTS_TXT_CONTENT_OPTION );
	} else {
		// Not autoloaded: only needed when robots.txt is requested.
		update_option( SHAWESOME_ROBOTS_TXT_CONTENT_OPTION, $content, false );
	}
	if ( is_multisite() ) {
		restore_current_blog();
	}
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
 * Register the robots.txt filters according to the selected mode.
 *
 * 'exact' runs at PHP_INT_MAX, after Yoast SEO (99999) and All in One SEO
 * (10000), which would otherwise append to or rebuild the file. 'base' runs
 * at 10, before them. The priority can be changed with the filter
 * 'shawesome_robots_txt_priority'.
 */
function shawesome_robots_txt_register_filters() {
	$settings = shawesome_robots_txt_get_settings();
	$priority = 'exact' === $settings['mode'] ? PHP_INT_MAX : 10;
	$priority = (int) apply_filters( 'shawesome_robots_txt_priority', $priority, $settings['mode'] );

	add_filter( 'robots_txt', 'shawesome_robots_txt_filter_robots_txt', $priority, 2 );

	if ( 'base' === $settings['mode'] ) {
		add_filter( 'robots_txt', 'shawesome_robots_txt_filter_dedupe', PHP_INT_MAX );
	}
}
add_action( 'plugins_loaded', 'shawesome_robots_txt_register_filters' );

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin.php';
}

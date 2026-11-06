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
 * Mode chosen for a site: 'base', 'exact', 'block' or '' (use the default
 * mode).
 *
 * @param int $site_id Site ID (ignored on a single site).
 * @return string
 */
function shawesome_robots_txt_site_mode( $site_id ) {
	$mode = (string) shawesome_robots_txt_get_site_option( $site_id, SHAWESOME_ROBOTS_TXT_MODE_OPTION );

	return in_array( $mode, array( 'base', 'exact', 'block' ), true ) ? $mode : '';
}

/**
 * Mode in effect for a site: its own or the default mode.
 *
 * @param int $site_id Site ID (ignored on a single site).
 * @return string 'base', 'exact' or 'block'.
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
 * - css_js, images, pdfs: blocked with Disallow lines (default: allowed).
 * - bots: keys of blocked crawlers (see shawesome_robots_txt_bots()).
 * - cleanup, woo: keys of blocked WordPress and WooCommerce paths.
 * - no_sitemap: do not add the Sitemap line (default: added).
 *
 * @param int $site_id Site ID (ignored on a single site).
 * @return array{crawl_delay: int, css_js: bool, images: bool, pdfs: bool, bots: string[], cleanup: string[], woo: string[], no_sitemap: bool}
 */
function shawesome_robots_txt_site_rules( $site_id ) {
	$rules = wp_parse_args(
		(array) shawesome_robots_txt_get_site_option( $site_id, SHAWESOME_ROBOTS_TXT_RULES_OPTION, array() ),
		array(
			'crawl_delay' => 0,
			'css_js'      => false,
			'images'      => false,
			'pdfs'        => false,
			'bots'        => array(),
			'cleanup'     => array(),
			'woo'         => array(),
			'no_sitemap'  => false,
		)
	);

	return array(
		'crawl_delay' => min( 60, max( 0, (int) $rules['crawl_delay'] ) ),
		'css_js'      => (bool) $rules['css_js'],
		'images'      => (bool) $rules['images'],
		'pdfs'        => (bool) $rules['pdfs'],
		'bots'        => array_values( array_intersect( (array) $rules['bots'], array_keys( shawesome_robots_txt_bot_index() ) ) ),
		'cleanup'     => array_values( array_intersect( (array) $rules['cleanup'], array_keys( shawesome_robots_txt_cleanup_lines() ) ) ),
		'woo'         => array_values( array_intersect( (array) $rules['woo'], array( 'cart', 'checkout', 'account', 'add_to_cart' ) ) ),
		'no_sitemap'  => (bool) $rules['no_sitemap'],
	);
}

/**
 * Crawlers that can be blocked, by group: key => array( operator, user agent
 * tokens ). Sources: the crawler documentation of the operators where
 * available (OpenAI, Anthropic, Google, Apple, Meta, Perplexity, Ahrefs,
 * Semrush), otherwise tokens in common use (checked 2026-11). The token "*"
 * stands for all crawlers.
 *
 * @return array<string, array<string, array{0: string, 1: string[]}>>
 */
function shawesome_robots_txt_bots() {
	return array(
		'ai_training' => array(
			'gptbot'      => array( 'OpenAI', array( 'GPTBot' ) ),
			'claudebot'   => array( 'Anthropic', array( 'ClaudeBot' ) ),
			'google_ext'  => array( 'Google (Gemini)', array( 'Google-Extended' ) ),
			'apple_ext'   => array( 'Apple', array( 'Applebot-Extended' ) ),
			'meta_agent'  => array( 'Meta', array( 'meta-externalagent' ) ),
			'ccbot'       => array( 'Common Crawl', array( 'CCBot' ) ),
		),
		'ai_search'   => array(
			'oai_search'    => array( 'OpenAI', array( 'OAI-SearchBot' ) ),
			'claude_search' => array( 'Anthropic', array( 'Claude-SearchBot' ) ),
			'perplexity'    => array( 'Perplexity', array( 'PerplexityBot' ) ),
			'meta_index'    => array( 'Meta', array( 'meta-webindexer' ) ),
		),
		'ai_user'     => array(
			'chatgpt_user'    => array( 'OpenAI', array( 'ChatGPT-User' ) ),
			'claude_user'     => array( 'Anthropic', array( 'Claude-User' ) ),
			'perplexity_user' => array( 'Perplexity', array( 'Perplexity-User' ) ),
			'meta_fetcher'    => array( 'Meta', array( 'meta-externalfetcher' ) ),
		),
		'seo'         => array(
			'ahrefs'     => array( 'Ahrefs', array( 'AhrefsBot', 'AhrefsSiteAudit' ) ),
			'semrush'    => array( 'Semrush', array( 'SemrushBot', 'SiteAuditBot', 'SemrushBot-BA', 'SemrushBot-SI', 'SemrushBot-SWA', 'SemrushBot-OCOB', 'SemrushBot-FT', 'SemrushBot-ESI', 'SplitSignalBot' ) ),
			'moz'        => array( 'Moz', array( 'dotbot', 'rogerbot' ) ),
			'majestic'   => array( 'Majestic', array( 'MJ12bot' ) ),
			'blexbot'    => array( 'WebMeUp', array( 'BLEXBot' ) ),
			'serpstat'   => array( 'Serpstat', array( 'serpstatbot' ) ),
			'dataforseo' => array( 'DataForSEO', array( 'DataForSeoBot' ) ),
		),
		'archive'     => array(
			'internet_archive' => array( 'Internet Archive', array( 'archive.org_bot', 'ia_archiver' ) ),
		),
		'social'      => array(
			'facebook'  => array( 'Meta (Facebook, Instagram, Messenger)', array( 'facebookexternalhit' ) ),
			'x'         => array( 'X', array( 'Twitterbot' ) ),
			'linkedin'  => array( 'LinkedIn', array( 'LinkedInBot' ) ),
			'pinterest' => array( 'Pinterest', array( 'Pinterestbot' ) ),
			'slack'     => array( 'Slack', array( 'Slackbot' ) ),
			'whatsapp'  => array( 'WhatsApp', array( 'WhatsApp' ) ),
			'telegram'  => array( 'Telegram', array( 'TelegramBot' ) ),
			'discord'   => array( 'Discord', array( 'Discordbot' ) ),
		),
		'search'      => array(
			'google'     => array( 'Google', array( 'Googlebot' ) ),
			'bing'       => array( 'Microsoft Bing', array( 'bingbot' ) ),
			'apple'      => array( 'Apple', array( 'Applebot' ) ),
			'duckduckgo' => array( 'DuckDuckGo', array( 'DuckDuckBot' ) ),
			'yandex'     => array( 'Yandex', array( 'YandexBot' ) ),
			'baidu'      => array( 'Baidu', array( 'Baiduspider' ) ),
			'all'        => array( '', array( '*' ) ),
		),
	);
}

/**
 * All crawlers that can be blocked, without groups.
 *
 * @return array<string, string[]> Key => user agent tokens.
 */
function shawesome_robots_txt_bot_index() {
	$index = array();
	foreach ( shawesome_robots_txt_bots() as $bots ) {
		foreach ( $bots as $key => $bot ) {
			$index[ $key ] = $bot[1];
		}
	}

	return $index;
}

/**
 * Disallow lines for WordPress paths that waste crawl budget.
 *
 * @return array<string, string[]> Key => lines.
 */
function shawesome_robots_txt_cleanup_lines() {
	return array(
		'search'     => array( 'Disallow: /?s=', 'Disallow: /search/' ),
		'replytocom' => array( 'Disallow: /*?replytocom=' ),
		'feeds'      => array( 'Disallow: /comments/feed/', 'Disallow: /*/feed/' ),
	);
}

/**
 * Disallow lines for the WooCommerce pages of a site, from the pages set in
 * WooCommerce (the usual slugs if none is set).
 *
 * @param int $site_id Site ID (ignored on a single site).
 * @return array<string, string[]> Key => lines.
 */
function shawesome_robots_txt_woo_lines( $site_id ) {
	if ( is_multisite() ) {
		switch_to_blog( $site_id );
	}

	$lines = array();
	foreach ( array(
		'cart'     => array( 'woocommerce_cart_page_id', 'cart' ),
		'checkout' => array( 'woocommerce_checkout_page_id', 'checkout' ),
		'account'  => array( 'woocommerce_myaccount_page_id', 'my-account' ),
	) as $key => $page ) {
		$page_id       = (int) get_option( $page[0] );
		$uri           = $page_id ? get_page_uri( $page_id ) : '';
		$lines[ $key ] = array( 'Disallow: /' . ( $uri ? $uri : $page[1] ) . '/' );
	}
	$lines['add_to_cart'] = array( 'Disallow: /*?add-to-cart=' );

	if ( is_multisite() ) {
		restore_current_blog();
	}

	return $lines;
}

/**
 * Whether a plugin is active on a site (network-wide or on the site).
 *
 * @param int    $site_id Site ID (ignored on a single site).
 * @param string $file    Plugin file, e.g. 'wordpress-seo/wp-seo.php'.
 * @return bool
 */
function shawesome_robots_txt_plugin_active( $site_id, $file ) {
	$active = (array) shawesome_robots_txt_get_site_option( $site_id, 'active_plugins', array() );
	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}

	return in_array( $file, $active, true );
}

/**
 * URL of the sitemap of a site: the one of Yoast SEO, Rank Math or All in
 * One SEO if active, otherwise the sitemap of WordPress.
 *
 * @param int $site_id Site ID (ignored on a single site).
 * @return string
 */
function shawesome_robots_txt_sitemap_url( $site_id ) {
	if ( shawesome_robots_txt_plugin_active( $site_id, 'wordpress-seo/wp-seo.php' ) || shawesome_robots_txt_plugin_active( $site_id, 'seo-by-rank-math/rank-math.php' ) ) {
		return get_home_url( $site_id, 'sitemap_index.xml' );
	}
	if ( shawesome_robots_txt_plugin_active( $site_id, 'all-in-one-seo-pack/all_in_one_seo_pack.php' ) || shawesome_robots_txt_plugin_active( $site_id, 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' ) ) {
		return get_home_url( $site_id, 'sitemap.xml' );
	}

	return get_home_url( $site_id, 'wp-sitemap.xml' );
}

/**
 * The block the plugin adds for a site: a group that blocks the selected
 * crawlers, a group for all crawlers (Crawl-delay, blocked resources and
 * paths) and the Sitemap line.
 *
 * @param int    $site_id Site ID (ignored on a single site).
 * @param string $output  robots.txt so far; a sitemap already in it is not added again.
 * @return string Block, empty if there is nothing to add.
 */
function shawesome_robots_txt_added_block( $site_id, $output = '' ) {
	$rules  = shawesome_robots_txt_site_rules( $site_id );
	$index  = shawesome_robots_txt_bot_index();
	$tokens = array();
	$star   = array();
	$parts  = array();

	if ( $rules['crawl_delay'] ) {
		$star[] = 'Crawl-delay: ' . $rules['crawl_delay'];
	}
	foreach ( $rules['bots'] as $key ) {
		if ( array( '*' ) === $index[ $key ] ) {
			$star[] = 'Disallow: /';
		} else {
			$tokens = array_merge( $tokens, $index[ $key ] );
		}
	}
	foreach ( array( 'css_js', 'images', 'pdfs' ) as $key ) {
		if ( $rules[ $key ] ) {
			$star = array_merge( $star, shawesome_robots_txt_site_rule_lines_for( $key ) );
		}
	}
	$cleanup = shawesome_robots_txt_cleanup_lines();
	foreach ( $rules['cleanup'] as $key ) {
		$star = array_merge( $star, $cleanup[ $key ] );
	}
	if ( $rules['woo'] ) {
		$woo = shawesome_robots_txt_woo_lines( $site_id );
		foreach ( $rules['woo'] as $key ) {
			$star = array_merge( $star, $woo[ $key ] );
		}
	}

	if ( $tokens ) {
		$parts[] = 'User-agent: ' . implode( "\nUser-agent: ", $tokens ) . "\nDisallow: /";
	}
	if ( $star ) {
		$parts[] = "User-agent: *\n" . implode( "\n", $star );
	}
	if ( ! $rules['no_sitemap'] ) {
		$url = shawesome_robots_txt_sitemap_url( $site_id );
		if ( false === stripos( $output, $url ) ) {
			$parts[] = 'Sitemap: ' . $url;
		}
	}

	return $parts ? "# Added by shawesome robots.txt\n" . implode( "\n\n", $parts ) : '';
}

/**
 * Disallow lines that block a type of resource. Patterns use "*" and "$" as
 * defined in RFC 9309; "?" matches the query string of versioned CSS and
 * JavaScript files.
 *
 * @param string $key 'css_js', 'images' or 'pdfs'.
 * @return string[]
 */
function shawesome_robots_txt_site_rule_lines_for( $key ) {
	$lines = array();

	if ( 'css_js' === $key ) {
		foreach ( array( 'css', 'js' ) as $ext ) {
			$lines[] = 'Disallow: /*.' . $ext . '$';
			$lines[] = 'Disallow: /*.' . $ext . '?';
		}
	} elseif ( 'images' === $key ) {
		foreach ( array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg' ) as $ext ) {
			$lines[] = 'Disallow: /*.' . $ext . '$';
		}
	} elseif ( 'pdfs' === $key ) {
		$lines[] = 'Disallow: /*.pdf$';
	}

	return $lines;
}

/**
 * User agent tokens of a group of crawlers, used for the status.
 *
 * @param string $group Group key of shawesome_robots_txt_bots().
 * @return string[] One token per crawler (the first one).
 */
function shawesome_robots_txt_group_agents( $group ) {
	$bots = shawesome_robots_txt_bots();

	return array_map(
		function ( $bot ) {
			return $bot[1][0];
		},
		isset( $bots[ $group ] ) ? array_values( $bots[ $group ] ) : array()
	);
}

/**
 * Crawlers that collect content for AI training.
 *
 * @return string[] User agent tokens.
 */
function shawesome_robots_txt_ai_agents() {
	return shawesome_robots_txt_group_agents( 'ai_training' );
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
 * - respect_public: sites set to "Discourage search engines" are blocked for
 *   all crawlers ("Blocking"), whatever their mode. WordPress itself no
 *   longer blocks them in its robots.txt.
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

	if ( ! in_array( $settings['mode'], array( 'exact', 'base', 'block' ), true ) ) {
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
		$rules .= ( $disallow ? "\n\n" : "\n" ) . implode( "\n", $sitemaps );
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
 * @return string
 */
function shawesome_robots_txt_filter_robots_txt( $output ) {
	// WordPress has already resolved the current site from the requested host.
	$site_id = get_current_blog_id();

	if ( ! shawesome_robots_txt_serves_own( $site_id ) ) {
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
 * @return string
 */
function shawesome_robots_txt_filter_added_rules( $output ) {
	$site_id  = get_current_blog_id();
	$settings = shawesome_robots_txt_get_settings();

	if ( ! shawesome_robots_txt_serves_own( $site_id ) ) {
		return $output;
	}

	$blocks = array( shawesome_robots_txt_added_block( $site_id, $output ) );

	if ( $settings['path_sites'] ) {
		$blocks[] = shawesome_robots_txt_path_rules( shawesome_robots_txt_host( $site_id ) );
	}

	$blocks = array_filter( $blocks );

	return $blocks ? rtrim( $output ) . "\n\n" . implode( "\n\n", $blocks ) . "\n" : $output;
}

/**
 * Whether a site is blocked because it is hidden from search engines
 * (setting "respect_public").
 *
 * @param int $site_id Site ID (ignored on a single site).
 * @return bool
 */
function shawesome_robots_txt_hidden_blocked( $site_id ) {
	$settings = shawesome_robots_txt_get_settings();

	return $settings['respect_public'] && ! shawesome_robots_txt_is_public( $site_id );
}

/**
 * Filter callback for 'robots_txt' in 'block' mode, after all SEO plugins:
 * block all crawlers from all paths. The rules and options of the site stay
 * stored, but are not delivered.
 *
 * @return string
 */
function shawesome_robots_txt_filter_block() {
	return "User-agent: *\nDisallow: /\n";
}

/**
 * Register the robots.txt filters according to the mode of the current site.
 * Runs on 'do_robotstxt', so only for robots.txt requests.
 *
 * 'exact' runs at PHP_INT_MAX, after Yoast SEO (99999) and All in One SEO
 * (10000), which would otherwise append to or rebuild the file. 'base' runs
 * at 10, before them. The priority can be changed with the filter
 * 'shawesome_robots_txt_priority'. 'block' replaces everything at PHP_INT_MAX, also
 * on sites hidden from search engines.
 */
function shawesome_robots_txt_register_filters() {
	$mode = shawesome_robots_txt_mode( get_current_blog_id() );

	if ( 'block' === $mode || shawesome_robots_txt_hidden_blocked( get_current_blog_id() ) ) {
		add_filter( 'robots_txt', 'shawesome_robots_txt_filter_block', PHP_INT_MAX );
		return;
	}

	$priority = 'exact' === $mode ? PHP_INT_MAX : 10;
	$priority = (int) apply_filters( 'shawesome_robots_txt_priority', $priority, $mode );

	add_filter( 'robots_txt', 'shawesome_robots_txt_filter_robots_txt', $priority );

	// Added first, so that duplicate Sitemap lines are removed afterwards.
	add_filter( 'robots_txt', 'shawesome_robots_txt_filter_added_rules', PHP_INT_MAX );

	if ( 'base' === $mode ) {
		add_filter( 'robots_txt', 'shawesome_robots_txt_filter_dedupe', PHP_INT_MAX );
	}
}
add_action( 'do_robotstxt', 'shawesome_robots_txt_register_filters' );

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin.php';
}

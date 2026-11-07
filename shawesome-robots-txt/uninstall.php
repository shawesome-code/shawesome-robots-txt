<?php
/**
 * Remove the plugin settings and the robots.txt content stored in the
 * database on uninstall. Files in /wp-content/shawesome-robots-txt/ are kept
 * on purpose, as they are usually managed by deployments.
 *
 * @package Shawesome_Robots_Txt
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_site_option( 'shawesome_robots_txt_settings' );

// The site functions only exist in a network.
if ( ! is_multisite() ) {
	delete_option( 'shawesome_robots_txt_content' );
	return;
}

foreach ( get_sites(
	array(
		'number' => 0,
		'fields' => 'ids',
	)
) as $site_id ) {
	delete_blog_option( $site_id, 'shawesome_robots_txt_content' );
}

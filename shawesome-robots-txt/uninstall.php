<?php
/**
 * Remove the plugin settings and everything stored per site (content, mode,
 * status, rules) on uninstall. Files in /wp-content/shawesome-robots-txt/ are kept
 * on purpose, as they are usually managed by deployments.
 *
 * @package Shawesome_Robots_Txt
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$shawesome_robots_txt_options = array( 'shawesome_robots_txt_content', 'shawesome_robots_txt_mode', 'shawesome_robots_txt_status', 'shawesome_robots_txt_rules', 'shawesome_robots_txt_ignore_file' );

delete_site_option( 'shawesome_robots_txt_settings' );

// Dismissed notice, stored per user.
delete_metadata( 'user', 0, 'shawesome_robots_txt_dismissed', '', true );

// The site functions only exist in a network.
if ( ! is_multisite() ) {
	foreach ( $shawesome_robots_txt_options as $shawesome_robots_txt_option ) {
		delete_option( $shawesome_robots_txt_option );
	}
	return;
}

foreach ( get_sites(
	array(
		'number' => 0,
		'fields' => 'ids',
	)
) as $shawesome_robots_txt_site_id ) {
	foreach ( $shawesome_robots_txt_options as $shawesome_robots_txt_option ) {
		delete_blog_option( $shawesome_robots_txt_site_id, $shawesome_robots_txt_option );
	}
}

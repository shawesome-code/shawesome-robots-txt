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
				'.shawesome-robots-txt-version{margin-left:8px;font-size:13px;font-weight:400;color:#646970}'
				. '.shawesome-robots-txt-columns{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:24px}'
				. '@media (max-width:960px){.shawesome-robots-txt-columns{grid-template-columns:minmax(0,1fr)}}'
			);
		}
	);
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

	$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'base';

	update_site_option(
		SHAWESOME_ROBOTS_TXT_OPTION,
		array(
			'mode'           => in_array( $mode, array( 'exact', 'base' ), true ) ? $mode : 'base',
			'respect_public' => ! empty( $_POST['respect_public'] ),
		)
	);

	wp_safe_redirect( shawesome_robots_txt_page_url( array( 'updated' => 'settings' ) ) );
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
	// phpcs:enable

	echo '<div class="wrap">';
	echo '<h1>shawesome robots.txt <span class="shawesome-robots-txt-version">';
	/* translators: %s: version number of the plugin */
	echo esc_html( sprintf( __( 'Version %s', 'shawesome-robots-txt' ), SHAWESOME_ROBOTS_TXT_VERSION ) );
	echo '</span></h1>';

	if ( $site ) {
		$site_id = (int) $site->blog_id;
		echo '<p><a href="' . esc_url( shawesome_robots_txt_page_url() ) . '">&larr; ' . esc_html__( 'All sites', 'shawesome-robots-txt' ) . '</a></p>';
		shawesome_robots_txt_render_result( $updated, $renamed );
		/* translators: %s: host name of the site */
		echo '<h2>' . esc_html( sprintf( __( 'robots.txt for %s', 'shawesome-robots-txt' ), shawesome_robots_txt_host( $site_id ) ) ) . '</h2>';
		shawesome_robots_txt_render_editor( $site_id );
	} else {
		shawesome_robots_txt_render_result( $updated, $renamed );
		shawesome_robots_txt_render_warnings();
		shawesome_robots_txt_render_settings();

		if ( is_multisite() ) {
			shawesome_robots_txt_render_sites();
		} else {
			/* translators: %s: host name of the site */
			echo '<h2>' . esc_html( sprintf( __( 'robots.txt for %s', 'shawesome-robots-txt' ), shawesome_robots_txt_host( get_current_blog_id() ) ) ) . '</h2>';
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
	$settings = shawesome_robots_txt_get_settings();

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
		if ( shawesome_robots_txt_active_seo_plugins( (int) $site_id ) ) {
			$any_seo = true;
			break;
		}
	}

	if ( $any_seo && 'base' === $settings['mode'] ) {
		shawesome_robots_txt_notice( esc_html__( 'An SEO plugin is active. In "Base" mode, Yoast SEO appends its rules and sitemap to the robots.txt, and All in One SEO rebuilds it: comments and Sitemap lines are dropped. Use "Exact" mode if the content should be delivered as is.', 'shawesome-robots-txt' ), 'warning', true );
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
				<th scope="row"><?php esc_html_e( 'Mode', 'shawesome-robots-txt' ); ?></th>
				<td>
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
								esc_html__( '%s – the content is delivered as is. SEO plugins (Yoast SEO, All in One SEO …) cannot change it.', 'shawesome-robots-txt' ),
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

	<div class="shawesome-robots-txt-columns">
		<div>
			<h3><?php esc_html_e( 'Database content', 'shawesome-robots-txt' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php shawesome_robots_txt_form_fields( 'save_site', 'shawesome_robots_txt_save_site_' . $site_id ); ?>
				<input type="hidden" name="site_id" value="<?php echo esc_attr( $site_id ); ?>">
				<textarea name="content" class="large-text code" rows="16" placeholder="User-agent: *&#10;Disallow: /wp-admin/&#10;Allow: /wp-admin/admin-ajax.php&#10;&#10;Sitemap: <?php echo esc_attr( get_home_url( $site_id, 'wp-sitemap.xml' ) ); ?>"><?php echo esc_textarea( $db_content ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Leave empty to use the WordPress default (including output from SEO plugins).', 'shawesome-robots-txt' ); ?></p>
				<?php submit_button( __( 'Save robots.txt', 'shawesome-robots-txt' ) ); ?>
			</form>
		</div>
		<div>
			<?php shawesome_robots_txt_render_live( $site_id ); ?>
		</div>
	</div>
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
	$url      = get_home_url( $site_id, 'robots.txt' );
	$response = wp_remote_get( $url, array( 'timeout' => 5 ) );
	$code     = wp_remote_retrieve_response_code( $response );
	?>
	<h3><?php esc_html_e( 'Live robots.txt', 'shawesome-robots-txt' ); ?></h3>
	<?php if ( is_wp_error( $response ) || 200 !== $code ) : ?>
		<?php
		shawesome_robots_txt_notice(
			sprintf(
				/* translators: %s: error message or HTTP status code */
				esc_html__( 'The live robots.txt could not be loaded (%s). The server may block requests to itself.', 'shawesome-robots-txt' ),
				esc_html( is_wp_error( $response ) ? $response->get_error_message() : (string) $code )
			),
			'warning',
			true
		);
		?>
	<?php else : ?>
		<textarea class="large-text code" rows="16" readonly><?php echo esc_textarea( wp_remote_retrieve_body( $response ) ); ?></textarea>
	<?php endif; ?>
	<p class="description"><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open live robots.txt', 'shawesome-robots-txt' ); ?></a></p>
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
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Site', 'shawesome-robots-txt' ); ?></th>
				<th><?php echo esc_html_x( 'Source', 'where the robots.txt comes from', 'shawesome-robots-txt' ); ?></th>
				<th><?php esc_html_e( 'Override file name', 'shawesome-robots-txt' ); ?></th>
				<th><?php esc_html_e( 'SEO plugins', 'shawesome-robots-txt' ); ?></th>
				<th><?php esc_html_e( 'robots.txt', 'shawesome-robots-txt' ); ?></th>
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
							<div class="row-actions visible"><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html_x( 'Edit', 'edit the robots.txt of a site', 'shawesome-robots-txt' ); ?></a></div>
						<?php else : ?>
							<strong><?php echo esc_html( $label ); ?></strong>
						<?php endif; ?>
					</td>
					<td>
						<?php
						if ( ! $own ) {
							/* translators: %s: host name */
							echo esc_html( sprintf( __( 'Served by the robots.txt of %s', 'shawesome-robots-txt' ), $host ) );
						} else {
							$resolved = shawesome_robots_txt_resolve( $site_id );
							if ( null === $resolved['source'] ) {
								esc_html_e( 'WordPress default', 'shawesome-robots-txt' );
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
					<td><a href="<?php echo esc_url( set_url_scheme( 'http://' . $host . '/robots.txt' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html_x( 'Open', 'open the live robots.txt', 'shawesome-robots-txt' ); ?></a></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
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

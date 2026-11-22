<?php
/**
 * Plugin Name: WP Plugin Rollback
 * Plugin URI:  https://github.com/Azanumer/wp-plugin-rollback
 * Description: Roll back any WordPress.org plugin to a previous version in one click — with an automatic backup of the current version first.
 * Version:     1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author:      Azan Umer
 * Author URI:  https://azanumer.cv
 * License:     MIT
 * Text Domain: wp-plugin-rollback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPR_VERSION', '1.0.0' );
define( 'WPR_API', 'https://api.wordpress.org/plugins/info/1.2/' );
define( 'WPR_BACKUP_DIR', WP_CONTENT_DIR . '/wpr-backups' );

/* ------------------------------------------------------------------ */
/* WordPress.org API                                                   */
/* ------------------------------------------------------------------ */

/**
 * Fetch the version list for a plugin slug from WordPress.org.
 *
 * @param string $slug Plugin slug (usually the plugin folder name).
 * @return array<string, string>|WP_Error Map of version => download URL, or WP_Error.
 */
function wpr_get_versions( $slug ) {
	$transient = 'wpr_versions_' . sanitize_key( $slug );
	$cached    = get_transient( $transient );
	if ( false !== $cached ) {
		return $cached;
	}

	$response = wp_remote_get( add_query_arg( array(
		'action'  => 'plugin_information',
		'request' => array(
			'slug'   => $slug,
			'fields' => array( 'versions' => true ),
		),
	), WPR_API ), array( 'timeout' => 15 ) );

	if ( is_wp_error( $response ) ) {
		return $response;
	}
	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return new WP_Error( 'wpr_api', __( 'WordPress.org did not return the plugin info.', 'wp-plugin-rollback' ) );
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $data['versions'] ) || ! is_array( $data['versions'] ) ) {
		return new WP_Error( 'wpr_api', __( 'No versions found — this plugin may not be hosted on WordPress.org.', 'wp-plugin-rollback' ) );
	}

	$versions = array();
	foreach ( $data['versions'] as $version => $url ) {
		if ( 'trunk' === $version || '' === $url ) {
			continue;
		}
		$versions[ (string) $version ] = esc_url_raw( $url );
	}

	set_transient( $transient, $versions, 12 * HOUR_IN_SECONDS );
	return $versions;
}

/* ------------------------------------------------------------------ */
/* Filesystem helpers                                                  */
/* ------------------------------------------------------------------ */

/**
 * Recursively copy a directory. Returns false on the first failure.
 */
function wpr_copy_dir( $from, $to ) {
	if ( ! wp_mkdir_p( $to ) ) {
		return false;
	}
	foreach ( scandir( $from ) as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$src = $from . '/' . $item;
		$dst = $to . '/' . $item;
		if ( is_dir( $src ) ) {
			if ( ! wpr_copy_dir( $src, $dst ) ) {
				return false;
			}
		} elseif ( ! copy( $src, $dst ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			return false;
		}
	}
	return true;
}

/**
 * Recursively delete a directory, refusing anything outside WP_PLUGIN_DIR.
 */
function wpr_delete_dir( $dir ) {
	$real_plugin_dir = realpath( WP_PLUGIN_DIR );
	$real_target     = realpath( $dir );
	if ( false === $real_plugin_dir || false === $real_target ) {
		return false;
	}
	if ( 0 !== strpos( $real_target, $real_plugin_dir . DIRECTORY_SEPARATOR ) ) {
		return false; // Safety: never delete outside the plugins folder.
	}
	if ( $real_target === $real_plugin_dir ) {
		return false;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;
	return $wp_filesystem->delete( $real_target, true );
}

/* ------------------------------------------------------------------ */
/* Rollback                                                            */
/* ------------------------------------------------------------------ */

/**
 * Roll a plugin back to a given version.
 *
 * @param string $plugin_file Plugin path relative to WP_PLUGIN_DIR, e.g. 'akismet/akismet.php'.
 * @param string $target_version Version to install.
 * @return array{ok: bool, message: string}
 */
function wpr_rollback( $plugin_file, $target_version ) {
	if ( ! current_user_can( 'install_plugins' ) ) {
		return array( 'ok' => false, 'message' => __( 'You do not have permission to install plugins.', 'wp-plugin-rollback' ) );
	}

	$all_plugins = get_plugins();
	if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
		return array( 'ok' => false, 'message' => __( 'Plugin not found.', 'wp-plugin-rollback' ) );
	}

	$slug       = dirname( $plugin_file );
	$main_file  = basename( $plugin_file );
	$current    = $all_plugins[ $plugin_file ]['Version'];
	$plugin_dir = WP_PLUGIN_DIR . '/' . $slug;

	if ( version_compare( $target_version, $current, '=' ) ) {
		return array( 'ok' => false, 'message' => __( 'That version is already installed.', 'wp-plugin-rollback' ) );
	}

	$versions = wpr_get_versions( $slug );
	if ( is_wp_error( $versions ) ) {
		return array( 'ok' => false, 'message' => $versions->get_error_message() );
	}
	if ( ! isset( $versions[ $target_version ] ) ) {
		return array( 'ok' => false, 'message' => __( 'That version is not available on WordPress.org.', 'wp-plugin-rollback' ) );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	// 1. Download the target version.
	$zip = download_url( $versions[ $target_version ], 300 );
	if ( is_wp_error( $zip ) ) {
		return array( 'ok' => false, 'message' => $zip->get_error_message() );
	}

	// 2. Unzip into a staging folder inside the plugins dir (same filesystem,
	//    so the later rename is atomic) and verify the main file is present.
	$staging = WP_PLUGIN_DIR . '/.wpr-staging-' . sanitize_file_name( $slug );
	WP_Filesystem();
	global $wp_filesystem;
	$wp_filesystem->delete( $staging, true );
	$unzipped = unzip_file( $zip, $staging );
	@unlink( $zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	if ( is_wp_error( $unzipped ) ) {
		$wp_filesystem->delete( $staging, true );
		return array( 'ok' => false, 'message' => $unzipped->get_error_message() );
	}
	$staged_plugin = $staging . '/' . $slug;
	if ( ! file_exists( $staged_plugin . '/' . $main_file ) ) {
		$wp_filesystem->delete( $staging, true );
		return array( 'ok' => false, 'message' => __( 'The downloaded package does not contain the expected plugin file — nothing was changed.', 'wp-plugin-rollback' ) );
	}

	// 3. Back up the current version before touching it.
	$backup = WPR_BACKUP_DIR . '/' . sanitize_file_name( $slug ) . '-' . sanitize_file_name( $current ) . '-' . gmdate( 'Ymd-His' );
	if ( ! wpr_copy_dir( $plugin_dir, $backup ) ) {
		$wp_filesystem->delete( $staging, true );
		return array( 'ok' => false, 'message' => __( 'Could not back up the current version — nothing was changed.', 'wp-plugin-rollback' ) );
	}

	// 4. Swap: deactivate, delete the old version, move the staged one in.
	$was_active = is_plugin_active( $plugin_file );
	if ( $was_active ) {
		deactivate_plugins( $plugin_file, true );
	}
	if ( ! wpr_delete_dir( $plugin_dir ) ) {
		$wp_filesystem->delete( $staging, true );
		return array( 'ok' => false, 'message' => __( 'Could not remove the current version. Your backup is safe at:', 'wp-plugin-rollback' ) . ' ' . $backup );
	}
	if ( ! rename( $staged_plugin, $plugin_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		$wp_filesystem->delete( $staging, true );
		return array( 'ok' => false, 'message' => __( 'Could not move the new version into place. Restore from the backup at:', 'wp-plugin-rollback' ) . ' ' . $backup );
	}
	$wp_filesystem->delete( $staging, true );

	// 5. Reactivate if it was active before.
	if ( $was_active ) {
		$activated = activate_plugin( $plugin_file, '', false, true );
		if ( is_wp_error( $activated ) ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: 1: version rolled back to, 2: activation error. */
					__( 'Rolled back to %1$s, but re-activation failed: %2$s', 'wp-plugin-rollback' ),
					$target_version,
					$activated->get_error_message()
				),
			);
		}
	}

	// The version list may now be stale; refresh it.
	delete_transient( 'wpr_versions_' . sanitize_key( $slug ) );

	return array(
		'ok'      => true,
		'message' => sprintf(
			/* translators: 1: plugin name, 2: old version, 3: new version. */
			__( '%1$s rolled back from %2$s to %3$s. Backup kept at wp-content/wpr-backups/.', 'wp-plugin-rollback' ),
			$all_plugins[ $plugin_file ]['Name'],
			$current,
			$target_version
		),
	);
}

/* ------------------------------------------------------------------ */
/* Tools page                                                          */
/* ------------------------------------------------------------------ */

function wpr_register_tools_page() {
	add_management_page(
		__( 'Plugin Rollback', 'wp-plugin-rollback' ),
		__( 'Plugin Rollback', 'wp-plugin-rollback' ),
		'install_plugins',
		'wp-plugin-rollback',
		'wpr_render_tools_page'
	);
}
add_action( 'admin_menu', 'wpr_register_tools_page' );

function wpr_render_tools_page() {
	if ( ! current_user_can( 'install_plugins' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-plugin-rollback' ) );
	}

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$notice = '';
	$ok     = false;

	if ( isset( $_POST['wpr_rollback'] ) && check_admin_referer( 'wpr_rollback' ) ) {
		$plugin_file    = isset( $_POST['wpr_plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['wpr_plugin'] ) ) : '';
		$target_version = isset( $_POST['wpr_version'] ) ? sanitize_text_field( wp_unslash( $_POST['wpr_version'] ) ) : '';
		$result         = wpr_rollback( $plugin_file, $target_version );
		$notice         = $result['message'];
		$ok             = $result['ok'];
	}

	$plugins = get_plugins();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Plugin Rollback', 'wp-plugin-rollback' ); ?></h1>
		<p><?php esc_html_e( 'Roll a plugin back to an earlier WordPress.org release — for example when a new version breaks your site. The current version is backed up to wp-content/wpr-backups/ first, and the plugin is re-activated automatically if it was active.', 'wp-plugin-rollback' ); ?></p>
		<p><?php esc_html_e( 'Only plugins hosted on WordPress.org can be rolled back. Single-file plugins and premium plugins are not listed.', 'wp-plugin-rollback' ); ?></p>

		<?php if ( $notice ) : ?>
			<div class="notice <?php echo $ok ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Plugin', 'wp-plugin-rollback' ); ?></th>
					<th><?php esc_html_e( 'Installed', 'wp-plugin-rollback' ); ?></th>
					<th><?php esc_html_e( 'Roll back to', 'wp-plugin-rollback' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $plugins as $plugin_file => $data ) : ?>
				<?php
				$slug = dirname( $plugin_file );
				if ( '.' === $slug ) {
					continue; // Single-file plugin — no folder to replace.
				}
				$versions = wpr_get_versions( $slug );
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( $data['Name'] ); ?></strong>
						<?php if ( is_plugin_active( $plugin_file ) ) : ?>
							<span class="dashicons dashicons-yes-alt" style="color:green;" title="<?php esc_attr_e( 'Active', 'wp-plugin-rollback' ); ?>"></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $data['Version'] ); ?></td>
					<td>
						<?php if ( is_wp_error( $versions ) ) : ?>
							<em><?php esc_html_e( 'Not on WordPress.org', 'wp-plugin-rollback' ); ?></em>
						<?php else : ?>
							<?php
							$older = array_filter( array_keys( $versions ), function ( $v ) use ( $data ) {
								return version_compare( $v, $data['Version'], '<' );
							} );
							usort( $older, 'version_compare' );
							$older = array_reverse( $older );
							?>
							<?php if ( empty( $older ) ) : ?>
								<em><?php esc_html_e( 'No older version available', 'wp-plugin-rollback' ); ?></em>
							<?php else : ?>
								<form method="post" style="display:inline;">
									<?php wp_nonce_field( 'wpr_rollback' ); ?>
									<input type="hidden" name="wpr_plugin" value="<?php echo esc_attr( $plugin_file ); ?>">
									<select name="wpr_version">
										<?php foreach ( $older as $v ) : ?>
											<option value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $v ); ?></option>
										<?php endforeach; ?>
									</select>
									<input type="submit" name="wpr_rollback" class="button" value="<?php esc_attr_e( 'Roll back', 'wp-plugin-rollback' ); ?>"
										onclick="return confirm('<?php esc_attr_e( 'Roll this plugin back? The current version will be backed up first.', 'wp-plugin-rollback' ); ?>');">
								</form>
							<?php endif; ?>
						<?php endif; ?>
					</td>
					<td></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

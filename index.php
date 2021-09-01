<?php
/**
 * Plugin Name: Extension for Distributor - Multisite Cloner
 * Plugin URI: https://wordpress.org/plugins/mc_wp_mu-dt-clone
 * Description: Fixes integration between cloned sites and the Distributor plugin.
 * Version: 1.3.0
 * Author: milkycode GmbH
 * Author URI: https://www.milkycode.com
 * License: License: GPL2+
 */

defined( 'ABSPATH' ) || exit();

// Since an original site would not know about any Distributor connections in the duplicate of a duplicate,
// this plugin connects the original with the latest created site.

// Add settings menu to run fix manually.
add_action( 'network_admin_menu', 'mc_mudtcl_create_page' );

// Add hook for plugin MultiSite Clone Duplicator.
add_action( 'wpmu_new_blog', 'mc_mudtcl_fix_dt_connections', 2, 1 );

// Add hook for plugin WP Ultimo.
add_action( 'mucd_after_copy_data', 'mc_mudtcl_wp_ultimo', 10, 2 );

// Add hook for plugin NS Cloner - Site Copier.
add_action( 'ns_cloner_process_finish', function () {
	$target_id = ns_cloner_request()->get( 'target_id' );

	return mc_mudtcl_fix_dt_connections( $target_id );
} );

/**
 * Add admin page for running fix manually.
 */
function mc_mudtcl_create_page() {
	add_submenu_page(
		'settings.php',
		'Distributor Multisite cloning Fixer',
		'Distributor Multisite cloning Fixer',
		'manage_options',
		'mc_wp_mu-dt-clone/index.php',
		'mudtcl_admin_page'
	);
}

/**
 * Wrapper for mc_mudtcl_fix_dt_connections.
 *
 * @param int $template_id
 * @param int $target_id
 *
 * @return bool
 */
function mc_mudtcl_wp_ultimo( $template_id, $target_id ) {
	return mc_mudtcl_fix_dt_connections( $target_id );
}

/**
 * Fix all missing distributor connections.
 *
 * @param int $target_id
 *
 * @return bool
 */
function mc_mudtcl_fix_dt_connections( $target_id ) {
	switch_to_blog( $target_id );
	// Search through site for distributed posts

	$args              = array(
		'meta_key'       => 'dt_original_blog_id',
		'post_type'      => 'any',
		'posts_per_page' => - 1,
	);
	$posts_query       = new WP_Query( $args );
	$distributed_posts = $posts_query->posts;

	// Getting the original blog ID and post ID from every distributed post.
	$og_blog_and_post_ids = array();
	foreach ( $distributed_posts as $post ) {
		$original_blog_id = get_post_meta( $post->ID, 'dt_original_blog_id' )[0];
		$original_post_id = get_post_meta( $post->ID, 'dt_original_post_id' )[0];
		if ( ! isset( $og_blog_and_post_ids[ $original_blog_id ] ) ) {
			$og_blog_and_post_ids[ $original_blog_id ] = array();
		}
		array_push( $og_blog_and_post_ids[ $original_blog_id ],
			array(
				'og_post_id' => $original_post_id,
				'post_id'    => $post->ID,
			) );
	}

	foreach ( $og_blog_and_post_ids as $blog_id => $og_post_id ) {
		// Switching to original blog to create and add new site connection.
		switch_to_blog( $blog_id );
		foreach ( $og_post_id as $post ) {
			$dt_connection = get_post_meta( $post['og_post_id'], 'dt_connection_map' );
			if ( isset( $dt_connection ) ) {
				$dt_connection[0]['internal'][ $target_id ] = array(
					'post_id' => $post['post_id'],
					'time'    => time(),
				);
				update_post_meta( $post['og_post_id'], 'dt_connection_map', $dt_connection[0] );
			}
		}
	}
	restore_current_blog();

	return true;
}

/**
 * Create admin page for running fix manually.
 */
function mudtcl_admin_page() {
	$network_sites = get_sites();
	$plugin_url    = admin_url(
		'network/settings.php?page=mc_wp_mu-dt-clone%2Findex.php' );
	?>
    <div class="wrap">
        <h2>Distributor Multisite cloning Fixer</h2>
        <p>Since an original site would not know about any Distributor connections in the duplicate of a site
            containing connections, <br> this fix connects the original with the latest created clone.</p>
        <p>This manual fix is only needed, when installing this plugin after sites where already cloned.</p>
        <form action="<?php echo $plugin_url ?>" method="POST">
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="latest">Site to fix:</label></th>
                    <td>
                        <select name="latest" id="latest">
							<?php foreach ( $network_sites as $site ) {
								echo '<option value="'
								     . $site->blog_id
								     . '" name="'
								     . $site->domain
								     . '"' . '>'
								     . $site->domain
								     . '</option>';
							}
							?>
                        </select>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" class="button button-primary" value="Fix it">
            </p>
        </form>
    </div>
	<?php

	if ( isset( $_POST['latest'] ) && $_SERVER['REQUEST_METHOD'] == "POST" ) {
		if ( mc_mudtcl_fix_dt_connections( $_POST['latest'] ) ) {
			echo "Fix succesfully executed.";
		} else {
			echo "There was an error executing the fix.";
		}
	}
}
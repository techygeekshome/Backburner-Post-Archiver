<?php
/**
 * Plugin Name: BackBurner Post Archiver
 * Description: Automatically moves old content out of active circulation (homepage, category/tag archives, search, RSS) after a configurable age — without deleting anything or breaking any URL. Archived posts keep working for anyone with a direct link or existing search ranking; they just stop appearing in "latest" listings.
 * Version: 1.0.2
 * Author: TechyGeeksHome
 * Author URI: https://techygeekshome.info
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: backburner-post-archiver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BACKBURNER_OPTION', 'backburner_settings' );
define( 'BACKBURNER_LOG_OPTION', 'backburner_last_run_log' );
define( 'BACKBURNER_CRON_HOOK', 'backburner_daily_cron' );
define( 'BACKBURNER_STATUS', 'backburner_archived' );

/* -----------------------------------------------------------------------
 * Activation / Deactivation
 * -------------------------------------------------------------------- */

register_activation_hook( __FILE__, 'backburner_activate' );
function backburner_activate() {
	if ( ! wp_next_scheduled( BACKBURNER_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'daily', BACKBURNER_CRON_HOOK );
	}
	if ( false === get_option( BACKBURNER_OPTION ) ) {
		add_option( BACKBURNER_OPTION, backburner_default_settings() );
	}
}

register_deactivation_hook( __FILE__, 'backburner_deactivate' );
function backburner_deactivate() {
	$timestamp = wp_next_scheduled( BACKBURNER_CRON_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, BACKBURNER_CRON_HOOK );
	}
}

function backburner_default_settings() {
	return array(
		'enabled'      => false, // Master switch — off by default, nothing runs until this is checked.
		'dry_run'      => true,  // Log candidates only, don't act — on by default.
		'age_value'    => 12,
		'age_unit'     => 'months', // days | months | years
		'post_types'   => array( 'post' ),
		'category_ids' => array(), // empty = all categories
		'tag_ids'      => array(), // empty = all tags
	);
}

/* -----------------------------------------------------------------------
 * Custom "Archived" post status
 * -------------------------------------------------------------------- */

add_action( 'init', 'backburner_register_status' );
function backburner_register_status() {
	register_post_status(
		BACKBURNER_STATUS,
		array(
			'label'                     => _x( 'Archived', 'post status', 'backburner-post-archiver' ),
			'public'                     => true,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of archived posts */
			'label_count'               => _n_noop( 'Archived <span class="count">(%s)</span>', 'Archived <span class="count">(%s)</span>', 'backburner-post-archiver' ),
		)
	);
}

// Keep archived posts out of the homepage, category/tag archives, search and RSS
// while still rendering fine on their own single-post URL — that is the whole
// point: no dead links, no redirects needed, just removed from active circulation.
add_action( 'pre_get_posts', 'backburner_exclude_from_public_queries' );
function backburner_exclude_from_public_queries( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( $query->is_singular() ) {
		return; // never interfere with a direct visit to the post itself
	}
	$statuses = $query->get( 'post_status' );
	if ( empty( $statuses ) ) {
		$query->set( 'post_status', array( 'publish' ) );
	}
}

/* -----------------------------------------------------------------------
 * Per-post "never archive" exclusion flag
 * -------------------------------------------------------------------- */

add_action( 'add_meta_boxes', 'backburner_add_metabox' );
function backburner_add_metabox() {
	foreach ( array( 'post', 'page', 'product' ) as $pt ) {
		add_meta_box( 'backburner_exclude', 'Auto Archive', 'backburner_render_metabox', $pt, 'side', 'low' );
	}
}
function backburner_render_metabox( $post ) {
	wp_nonce_field( 'backburner_save_meta', 'backburner_nonce' );
	$excluded = get_post_meta( $post->ID, '_backburner_exclude', true );
	$status   = get_post_status( $post );
	echo '<label><input type="checkbox" name="backburner_exclude" value="1" ' . checked( $excluded, '1', false ) . ' /> Never auto-archive this post</label>';
	if ( BACKBURNER_STATUS === $status ) {
		echo '<p style="margin-top:8px;"><strong>This post is currently archived</strong> — hidden from homepage/category/search listings, but still live at its own URL.</p>';
		echo '<p><em>Change the Status field above (in the Publish box) back to Published to restore it to normal circulation.</em></p>';
	}
}
add_action( 'save_post', 'backburner_save_meta' );
function backburner_save_meta( $post_id ) {
	if ( ! isset( $_POST['backburner_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['backburner_nonce'] ) ), 'backburner_save_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( isset( $_POST['backburner_exclude'] ) ) {
		update_post_meta( $post_id, '_backburner_exclude', '1' );
	} else {
		delete_post_meta( $post_id, '_backburner_exclude' );
	}
}

/* -----------------------------------------------------------------------
 * The actual archiving job
 * -------------------------------------------------------------------- */

add_action( BACKBURNER_CRON_HOOK, 'backburner_run' );

function backburner_run( $manual = false ) {
	$settings = wp_parse_args( get_option( BACKBURNER_OPTION, array() ), backburner_default_settings() );

	if ( ! $manual && empty( $settings['enabled'] ) ) {
		return;
	}

	$candidates = backburner_find_candidates( $settings );

	$log = array(
		'run_at'     => current_time( 'mysql' ),
		'dry_run'    => ! empty( $settings['dry_run'] ),
		'candidates' => array(),
	);

	foreach ( $candidates as $post ) {
		$log['candidates'][] = array(
			'ID'    => $post->ID,
			'title' => get_the_title( $post ),
			'date'  => $post->post_date,
			'link'  => get_permalink( $post ),
		);

		if ( empty( $settings['dry_run'] ) ) {
			wp_update_post(
				array(
					'ID'          => $post->ID,
					'post_status' => BACKBURNER_STATUS,
				)
			);
		}
	}

	update_option( BACKBURNER_LOG_OPTION, $log );
}

function backburner_find_candidates( $settings ) {
	$value = max( 1, (int) $settings['age_value'] );
	$unit  = in_array( $settings['age_unit'], array( 'days', 'months', 'years' ), true ) ? $settings['age_unit'] : 'months';

	$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$value} {$unit}", current_time( 'timestamp', true ) ) );

	$args = array(
		'post_type'      => ! empty( $settings['post_types'] ) ? $settings['post_types'] : array( 'post' ),
		'post_status'    => 'publish',
		'posts_per_page' => 200, // safety cap per run; the daily cron catches up over subsequent days
		'date_query'     => array(
			array(
				'before'    => $cutoff,
				'inclusive' => true,
			),
		),
		'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				'key'     => '_backburner_exclude',
				'compare' => 'NOT EXISTS',
			),
		),
		'orderby'        => 'date',
		'order'          => 'ASC',
	);

	// Category and/or tag targeting — matches EITHER (a post only needs to be
	// in the chosen category(ies) OR carry the chosen tag(s), not both), so
	// e.g. "General Tech category, no tags" or "any category, 'legacy' tag"
	// both work as expected. Leaving both empty applies to everything.
	$tax_query = array();
	if ( ! empty( $settings['category_ids'] ) ) {
		$tax_query[] = array(
			'taxonomy' => 'category',
			'field'    => 'term_id',
			'terms'    => array_map( 'intval', $settings['category_ids'] ),
		);
	}
	if ( ! empty( $settings['tag_ids'] ) ) {
		$tax_query[] = array(
			'taxonomy' => 'post_tag',
			'field'    => 'term_id',
			'terms'    => array_map( 'intval', $settings['tag_ids'] ),
		);
	}
	if ( count( $tax_query ) > 1 ) {
		$tax_query['relation'] = 'OR';
	}
	if ( ! empty( $tax_query ) ) {
		$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
	}

	$query = new WP_Query( $args );
	return $query->posts;
}

/* -----------------------------------------------------------------------
 * Settings page
 * -------------------------------------------------------------------- */

add_action( 'admin_menu', 'backburner_admin_menu' );
function backburner_admin_menu() {
	if ( ! defined( 'TGHHUB_MENU_REGISTERED' ) ) {
		define( 'TGHHUB_MENU_REGISTERED', true );
		add_menu_page(
			'TGH',
			'TGH',
			'manage_options',
			'tghhub',
			'tghhub_render_landing_page',
			'data:image/svg+xml;base64,' . base64_encode( tghhub_menu_icon_svg() ),
			null
		);
	}
	add_submenu_page( 'tghhub', 'BackBurner Post Archiver', 'BackBurner', 'manage_options', 'backburner-post-archiver', 'backburner_settings_page' );
}

if ( ! function_exists( 'tghhub_menu_icon_svg' ) ) {
	function tghhub_menu_icon_svg() {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><polygon points="10,7 13.46,9 13.46,13 10,15 6.54,13 6.54,9" fill="none" stroke="black" stroke-width="1.6"/><line x1="10" y1="7" x2="10" y2="3" stroke="black" stroke-width="1.4"/><line x1="13.46" y1="13" x2="16.93" y2="15" stroke="black" stroke-width="1.4"/><line x1="6.54" y1="13" x2="3.07" y2="15" stroke="black" stroke-width="1.4"/><circle cx="10" cy="3" r="1.4" fill="black"/><circle cx="16.93" cy="15" r="1.4" fill="black"/><circle cx="3.07" cy="15" r="1.4" fill="black"/></svg>';
	}
}

if ( ! function_exists( 'tghhub_render_landing_page' ) ) {
	function tghhub_render_landing_page() {
		$plugins = apply_filters( 'tghhub_plugins', array(
			// BackBurner Post Archiver is intentionally omitted here until it is
			// actually live/approved on WordPress.org -- the hub only links to
			// plugins and themes with a real public listing. Add it back in
			// (linking to https://wordpress.org/plugins/backburner-post-archiver/)
			// once approved, and update every other published plugin/theme's
			// copy of this array at the same time.
			array(
				'name'        => 'Controlled Draft Publisher',
				'description' => 'Hold posts as controlled drafts and publish them on your own schedule.',
				'url'         => 'https://wordpress.org/plugins/controlled-draft-publisher/',
			),
			array(
				'name'        => 'LinkGather',
				'description' => 'Collects and manages links across your site.',
				'url'         => 'https://wordpress.org/plugins/linkgather/',
			),
		) );

		$themes = array(
			array(
				'name'        => 'NeoDark Free',
				'description' => 'A fast, dark-mode WordPress theme for tech blogs, tutorials, and reviews.',
				'url'         => 'https://techygeekshome.info/neodark-free/',
				'cta'         => 'View Theme',
			),
		);

		$software = array(
			array(
				'name'        => 'DiskGeek',
				'description' => 'Free disk space analyser for Windows: scan, find duplicates, and reclaim space.',
				'url'         => 'https://techygeekshome.info/introducing-diskgeek-a-free-disk-space-analyser-for-windows/',
				'cta'         => 'View / Download',
			),
		);
		?>
		<div class="wrap tghhub-dashboard">
			<h1>TechyGeeksHome</h1>
			<p>A shared home for everything we have built &mdash; our WordPress plugins, our theme, and our standalone software.</p>

			<h2>Our Plugins</h2>
			<div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:12px;">
				<?php foreach ( $plugins as $p ) : ?>
					<div style="border:1px solid #dcdcde;border-radius:6px;padding:16px;width:280px;background:#fff;">
						<h3 style="margin-top:0;"><?php echo esc_html( $p['name'] ); ?></h3>
						<p><?php echo esc_html( $p['description'] ); ?></p>
						<a href="<?php echo esc_url( $p['url'] ); ?>" class="button button-primary" target="_blank" rel="noopener">View Plugin</a>
					</div>
				<?php endforeach; ?>
			</div>

			<h2 style="margin-top:32px;">Our Themes</h2>
			<div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:12px;">
				<?php foreach ( $themes as $t ) : ?>
					<div style="border:1px solid #dcdcde;border-radius:6px;padding:16px;width:280px;background:#fff;">
						<h3 style="margin-top:0;"><?php echo esc_html( $t['name'] ); ?></h3>
						<p><?php echo esc_html( $t['description'] ); ?></p>
						<a href="<?php echo esc_url( $t['url'] ); ?>" class="button" target="_blank" rel="noopener"><?php echo esc_html( $t['cta'] ); ?></a>
					</div>
				<?php endforeach; ?>
			</div>

			<h2 style="margin-top:32px;">Our Software</h2>
			<div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:12px;">
				<?php foreach ( $software as $s ) : ?>
					<div style="border:1px solid #dcdcde;border-radius:6px;padding:16px;width:280px;background:#fff;">
						<h3 style="margin-top:0;"><?php echo esc_html( $s['name'] ); ?></h3>
						<p><?php echo esc_html( $s['description'] ); ?></p>
						<a href="<?php echo esc_url( $s['url'] ); ?>" class="button" target="_blank" rel="noopener"><?php echo esc_html( $s['cta'] ); ?></a>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}

add_action( 'admin_init', 'backburner_handle_actions' );
function backburner_handle_actions() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['backburner_save'] ) && check_admin_referer( 'backburner_save_settings' ) ) {
		$settings = array(
			'enabled'      => isset( $_POST['enabled'] ),
			'dry_run'      => isset( $_POST['dry_run'] ),
		'age_value'    => max( 1, isset( $_POST['age_value'] ) ? absint( wp_unslash( $_POST['age_value'] ) ) : 12 ),
		'age_unit'     => ( isset( $_POST['age_unit'] ) && in_array( sanitize_text_field( wp_unslash( $_POST['age_unit'] ) ), array( 'days', 'months', 'years' ), true ) ) ? sanitize_text_field( wp_unslash( $_POST['age_unit'] ) ) : 'months',
			'post_types'   => isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['post_types'] ) : array(),
			'category_ids' => isset( $_POST['category_ids'] ) ? array_map( 'intval', (array) $_POST['category_ids'] ) : array(),
			'tag_ids'      => isset( $_POST['tag_ids'] ) ? array_map( 'intval', (array) $_POST['tag_ids'] ) : array(),
		);
		update_option( BACKBURNER_OPTION, $settings );
		add_settings_error( 'tgh_aa', 'saved', 'Settings saved.', 'success' );
	}

	if ( isset( $_POST['backburner_run_now'] ) && check_admin_referer( 'backburner_run_now' ) ) {
		backburner_run( true );
		add_settings_error( 'tgh_aa', 'ran', 'Run complete — see the log below.', 'success' );
	}
}

function backburner_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings   = wp_parse_args( get_option( BACKBURNER_OPTION, array() ), backburner_default_settings() );
	$log        = get_option( BACKBURNER_LOG_OPTION, array() );
	$categories = get_categories( array( 'hide_empty' => false ) );
	$tags       = get_tags( array( 'hide_empty' => false ) );
	$post_types = get_post_types( array( 'public' => true ), 'objects' );

	settings_errors( 'tgh_aa' );
	?>
	<div class="wrap">
		<h1>Auto Archive</h1>
		<p>Moves old content out of active circulation (homepage, category/tag archives, search, RSS) after a configurable age. <strong>Nothing is deleted and no URL breaks</strong> — archived posts still load normally for anyone with a direct link or existing search ranking; they simply stop being promoted as &quot;latest&quot; content. This is different from Trash, which is a step toward permanent deletion.</p>

		<form method="post">
			<?php wp_nonce_field( 'backburner_save_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Enabled</th>
					<td>
						<label><input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?> /> Turn on the daily auto-archive job</label>
						<p class="description">Off by default. Nothing runs until you check this.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Dry run</th>
					<td>
						<label><input type="checkbox" name="dry_run" value="1" <?php checked( $settings['dry_run'] ); ?> /> Log candidates only — don't actually change anything yet</label>
						<p class="description">Strongly recommended to leave this on for the first few runs so you can review the log below before anything is archived for real.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Archive content older than</th>
					<td>
						<input type="number" min="1" name="age_value" value="<?php echo esc_attr( $settings['age_value'] ); ?>" style="width:80px;" />
						<select name="age_unit">
							<option value="days" <?php selected( $settings['age_unit'], 'days' ); ?>>Days</option>
							<option value="months" <?php selected( $settings['age_unit'], 'months' ); ?>>Months</option>
							<option value="years" <?php selected( $settings['age_unit'], 'years' ); ?>>Years</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">Post types</th>
					<td>
						<?php foreach ( $post_types as $pt ) : ?>
							<label style="display:block;">
								<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $settings['post_types'], true ) ); ?> />
								<?php echo esc_html( $pt->labels->name ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Categories</th>
					<td>
						<select name="category_ids[]" multiple size="8" style="min-width:300px;">
							<?php foreach ( $categories as $cat ) : ?>
								<option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( in_array( $cat->term_id, $settings['category_ids'], true ) ); ?>>
									<?php echo esc_html( $cat->name ); ?> (<?php echo (int) $cat->count; ?>)
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">Leave nothing selected to apply to all categories. Ctrl/Cmd-click to select more than one.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Tags</th>
					<td>
						<select name="tag_ids[]" multiple size="8" style="min-width:300px;">
							<?php foreach ( $tags as $tag ) : ?>
								<option value="<?php echo esc_attr( $tag->term_id ); ?>" <?php selected( in_array( $tag->term_id, $settings['tag_ids'], true ) ); ?>>
									<?php echo esc_html( $tag->name ); ?> (<?php echo (int) $tag->count; ?>)
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">A post matches if it's in <strong>any selected category OR carries any selected tag</strong> — you don't need both. E.g. select just the "General Tech" category to target that category regardless of tags, or just a "legacy" tag to sweep matching posts across every category. Leave both Categories and Tags empty to apply to everything.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Save Settings', 'primary', 'backburner_save' ); ?>
		</form>

		<hr />

		<h2>Run now</h2>
		<p>Runs the job immediately using the settings above (still respects the Dry Run toggle) instead of waiting for the daily schedule.</p>
		<form method="post">
			<?php wp_nonce_field( 'backburner_run_now' ); ?>
			<?php submit_button( 'Run Now', 'secondary', 'backburner_run_now' ); ?>
		</form>

		<hr />

		<h2>Last run</h2>
		<?php if ( empty( $log ) ) : ?>
			<p>Never run yet.</p>
		<?php else : ?>
			<p>
				<strong><?php echo esc_html( $log['run_at'] ); ?></strong>
				— <?php echo $log['dry_run'] ? 'Dry run (nothing changed)' : 'Live run (posts archived)'; ?>
				— <?php echo count( $log['candidates'] ); ?> post(s) matched
			</p>
			<?php if ( ! empty( $log['candidates'] ) ) : ?>
				<table class="widefat striped">
					<thead><tr><th>Title</th><th>Published</th><th>Link</th></tr></thead>
					<tbody>
					<?php foreach ( $log['candidates'] as $c ) : ?>
						<tr>
							<td><?php echo esc_html( $c['title'] ); ?></td>
							<td><?php echo esc_html( $c['date'] ); ?></td>
							<td><a href="<?php echo esc_url( $c['link'] ); ?>" target="_blank" rel="noopener">View</a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>

		<hr />
		<h2>How to un-archive a post</h2>
		<p>Open the post in the editor, change its Status (in the Publish box) from "Archived" back to "Published", and update. Or check "Never auto-archive this post" on any post beforehand to keep it permanently exempt — that checkbox is in the "Auto Archive" box in the sidebar of every post editor.</p>
	</div>
	<?php
}

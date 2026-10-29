<?php
/**
 * Creates the visual-diff fixture content on the tests site. Run with:
 *   wp eval-file tests/e2e/fixtures/content.php <theme>
 *
 * Idempotent: deletes earlier fixture content first.
 *
 * @package Stillpress
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- WP-CLI eval-file script.

$stillpress_theme = $args[0] ?? 'twentytwentyfive';

foreach ( get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $stillpress_id ) {
	wp_delete_post( $stillpress_id, true );
}
foreach ( get_posts( array( 'post_type' => array( 'attachment', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_global_styles', 'nav_menu_item' ), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $stillpress_id ) {
	wp_delete_post( $stillpress_id, true );
}
foreach ( array( 'category', 'post_tag', 'nav_menu' ) as $stillpress_tax ) {
	foreach ( get_terms( array( 'taxonomy' => $stillpress_tax, 'hide_empty' => false, 'fields' => 'ids' ) ) as $stillpress_term ) {
		if ( 1 !== (int) $stillpress_term ) {
			wp_delete_term( $stillpress_term, $stillpress_tax );
		}
	}
}

update_option( 'blogname', 'Stillpress Visual Test' );
update_option( 'blogdescription', 'Static output must look like this' );
update_option( 'show_on_front', 'posts' );
update_option( 'posts_per_page', 4 );
update_option( 'timezone_string', 'UTC' );
// Through WP_Rewrite, not update_option(): the flush below builds rules from $wp_rewrite, which read the old
// structure when this request started. A fresh site (date-based permalinks) would keep rules that 404 every post.
$GLOBALS['wp_rewrite']->set_permalink_structure( '/%postname%/' );
flush_rewrite_rules();

/**
 * Generates a JPEG with GD and attaches it.
 *
 * @param string $name  File name.
 * @param int[]  $color RGB.
 * @return int Attachment ID.
 */
function stillpress_fixture_image( string $name, array $color ): int {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$img = imagecreatetruecolor( 1600, 900 );
	imagefill( $img, 0, 0, imagecolorallocate( $img, $color[0], $color[1], $color[2] ) );
	$white = imagecolorallocate( $img, 255, 255, 255 );
	for ( $i = 0; $i < 12; $i++ ) {
		imagefilledellipse( $img, 120 + $i * 120, 450 + (int) ( 200 * sin( $i ) ), 90, 90, $white );
	}
	ob_start();
	imagejpeg( $img, null, 85 );
	$bytes  = (string) ob_get_clean();
	$upload = wp_upload_bits( $name . '.jpg', null, $bytes );
	$id     = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => ucfirst( $name ),
			'post_status'    => 'inherit',
		),
		$upload['file']
	);
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
	return (int) $id;
}

$stillpress_images = array(
	stillpress_fixture_image( 'harbor', array( 30, 90, 140 ) ),
	stillpress_fixture_image( 'meadow', array( 60, 130, 60 ) ),
	stillpress_fixture_image( 'sunset', array( 200, 90, 40 ) ),
);

$stillpress_cats = array();
foreach ( array( 'News', 'Guides' ) as $stillpress_name ) {
	$stillpress_cats[] = (int) wp_insert_term( $stillpress_name, 'category' )['term_id'];
}

$stillpress_body = '<!-- wp:paragraph --><p>Static sites are fast, cheap to host and hard to break. This post checks that headings, lists, quotes, images and links survive the trip.</p><!-- /wp:paragraph -->'
	. '<!-- wp:heading --><h2 class="wp-block-heading">A section</h2><!-- /wp:heading -->'
	. '<!-- wp:list --><ul class="wp-block-list"><li>First point</li><li>Second point with <a href="' . home_url( '/about/' ) . '">a link</a></li></ul><!-- /wp:list -->'
	. '<!-- wp:quote --><blockquote class="wp-block-quote"><p>Simplicity is prerequisite for reliability.</p></blockquote><!-- /wp:quote -->'
	. '<!-- wp:image {"id":%1$d,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="%2$s" alt="" class="wp-image-%1$d"/></figure><!-- /wp:image -->';

$stillpress_posts = array();
for ( $stillpress_n = 1; $stillpress_n <= 6; $stillpress_n++ ) {
	$stillpress_img = $stillpress_images[ $stillpress_n % 3 ];
	$stillpress_id  = wp_insert_post(
		array(
			'post_title'    => "Fixture story {$stillpress_n}",
			'post_name'     => "fixture-story-{$stillpress_n}",
			'post_content'  => sprintf( $stillpress_body, $stillpress_img, wp_get_attachment_image_url( $stillpress_img, 'large' ) ),
			'post_excerpt'  => "Excerpt of story {$stillpress_n}.",
			'post_status'   => 'publish',
			'post_author'   => 1,
			'post_date'     => sprintf( '2026-0%d-1%d 10:00:00', ( $stillpress_n % 9 ) + 1, $stillpress_n ),
			'post_category' => array( $stillpress_cats[ $stillpress_n % 2 ] ),
			'tags_input'    => array( 'static', 0 === $stillpress_n % 2 ? 'even' : 'odd' ),
		)
	);
	set_post_thumbnail( $stillpress_id, $stillpress_img );
	$stillpress_posts[] = $stillpress_id;
}

$stillpress_about = wp_insert_post(
	array(
		'post_title'   => 'About',
		'post_name'    => 'about',
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_content' => '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>We publish WordPress as static files.</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . home_url( '/contact/' ) . '">Get in touch</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:column --></div><!-- /wp:columns -->',
	)
);
$stillpress_contact = wp_insert_post(
	array(
		'post_title'   => 'Contact',
		'post_name'    => 'contact',
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_content' => '<!-- wp:paragraph --><p>Write to us at hello@example.org.</p><!-- /wp:paragraph -->',
	)
);

// Classic menu for classic themes.
$stillpress_menu = wp_create_nav_menu( 'Main' );
foreach ( array( $stillpress_about, $stillpress_contact ) as $stillpress_page ) {
	wp_update_nav_menu_item(
		$stillpress_menu,
		0,
		array(
			'menu-item-object-id' => $stillpress_page,
			'menu-item-object'    => 'page',
			'menu-item-type'      => 'post_type',
			'menu-item-status'    => 'publish',
		)
	);
}
$stillpress_locations = array();
foreach ( array_keys( get_registered_nav_menus() ) as $stillpress_location ) {
	$stillpress_locations[ $stillpress_location ] = $stillpress_menu;
}
set_theme_mod( 'nav_menu_locations', $stillpress_locations );

// A page built with Elementor, when Elementor is active.
if ( defined( 'ELEMENTOR_VERSION' ) ) {
	$stillpress_data = array(
		array(
			'id'       => 'a1b2c3d',
			'elType'   => 'container',
			'settings' => array(),
			'elements' => array(
				array(
					'id'         => 'e1',
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => array(
						'title'       => 'Built with a page builder',
						'header_size' => 'h1',
					),
				),
				array(
					'id'         => 'e2',
					'elType'     => 'widget',
					'widgetType' => 'text-editor',
					'settings'   => array( 'editor' => '<p>Page builders write their own CSS files; the static copy must include them.</p>' ),
				),
				array(
					'id'         => 'e3',
					'elType'     => 'widget',
					'widgetType' => 'image',
					'settings'   => array(
						'image' => array(
							'id'  => $stillpress_images[0],
							'url' => wp_get_attachment_url( $stillpress_images[0] ),
						),
					),
				),
				array(
					'id'         => 'e4',
					'elType'     => 'widget',
					'widgetType' => 'button',
					'settings'   => array(
						'text' => 'Read the stories',
						'link' => array( 'url' => home_url( '/' ) ),
					),
				),
			),
		),
	);
	$stillpress_builder = wp_insert_post(
		array(
			'post_title'  => 'Builder page',
			'post_name'   => 'builder-page',
			'post_type'   => 'page',
			'post_status' => 'publish',
		)
	);
	update_post_meta( $stillpress_builder, '_elementor_edit_mode', 'builder' );
	update_post_meta( $stillpress_builder, '_elementor_template_type', 'wp-page' );
	update_post_meta( $stillpress_builder, '_elementor_version', ELEMENTOR_VERSION );
	update_post_meta( $stillpress_builder, '_elementor_data', wp_slash( wp_json_encode( $stillpress_data ) ) );
	update_post_meta( $stillpress_builder, '_wp_page_template', 'elementor_canvas' === $stillpress_theme ? 'elementor_canvas' : 'default' );
	if ( class_exists( '\Elementor\Plugin' ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}
}

WP_CLI::success( sprintf( 'Fixture content created for %s: %d posts.', $stillpress_theme, count( $stillpress_posts ) ) );

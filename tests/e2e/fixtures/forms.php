<?php
/**
 * E2E fixture for forms: a page with a Contact Form 7 form that skips sending mail (the test site has no mail
 * server), so a valid submission ends as "sent". Run with `wp eval-file` after content.php.
 *
 * @package Stillpress
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- WP-CLI script scope.

foreach ( get_posts(
	array(
		'post_type'   => 'wpcf7_contact_form',
		'numberposts' => -1,
		'post_status' => 'any',
	)
) as $old ) {
	wp_delete_post( $old->ID, true );
}
$form = WPCF7_ContactForm::get_template( array( 'title' => 'E2E contact' ) );
$form->set_properties( array( 'additional_settings' => 'skip_mail: on' ) );
$form->save();

$existing = get_page_by_path( 'contact-form' );
if ( $existing ) {
	wp_delete_post( $existing->ID, true );
}
wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Contact form',
		'post_name'    => 'contact-form',
		'post_content' => '<!-- wp:shortcode -->[contact-form-7 id="' . $form->id() . '"]<!-- /wp:shortcode -->',
	)
);

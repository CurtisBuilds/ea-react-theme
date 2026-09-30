<?php
/**
 * Template Name: EA Test Page
 * Template Post Type: page
 *
 * Sandbox page template with editable Customizer content.
 */
get_header();
?>

<main id="ea-react-root" class="ea-react-root" data-page="testPage">
    <noscript>
        <p><?php esc_html_e( 'This site requires JavaScript to display. Please enable JavaScript in your browser.', 'ea-react-theme' ); ?></p>
    </noscript>
</main>

<?php get_footer(); ?>

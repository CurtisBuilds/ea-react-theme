<?php
/**
 * Template Name: EA City Programs
 * Template Post Type: page
 *
 * Reusable city page. Set the WordPress page slug to the matching city or
 * programs.json pageIdentifyer, and React filters the public programs feed.
 */
$ea_slug = get_post_field( 'post_name', get_queried_object_id() );
get_header(); ?>

<main
    id="ea-react-root"
    class="ea-react-root"
    data-page="cityPrograms"
    data-city-slug="<?php echo esc_attr( $ea_slug ); ?>"
    data-wp-slug="<?php echo esc_attr( $ea_slug ); ?>"
>
    <?php echo function_exists( 'ea_ssr_city_markup' ) ? ea_ssr_city_markup( $ea_slug ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>
    <noscript>
        <p><?php esc_html_e( 'This site requires JavaScript to display. Please enable JavaScript in your browser.', 'ea-react-theme' ); ?></p>
    </noscript>
</main>

<?php get_footer(); ?>

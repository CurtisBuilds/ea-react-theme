<?php
/**
 * Template Name: EA Free Trial Landing
 * Template Post Type: page
 *
 * Ad landing page (/free-trial/). The page title is the headline; the trial form
 * comes first. Everything typed in the page editor (first session, FAQ …) shows
 * below the form. Header is logo-only; footer stays.
 */
get_header(); ?>

<?php
while ( have_posts() ) :
    the_post();
    ?>
<main
    id="ea-react-root"
    class="ea-react-root"
    data-page="freeTrialLanding"
    data-title="<?php echo esc_attr( get_the_title() ); ?>"
>
    <template id="ea-free-trial-content">
        <?php the_content(); ?>
    </template>
    <noscript>
        <p><?php esc_html_e( 'This site requires JavaScript to display. Please enable JavaScript in your browser.', 'ea-react-theme' ); ?></p>
    </noscript>
</main>
    <?php
endwhile;
?>

<?php get_footer(); ?>

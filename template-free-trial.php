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
<?php
    // Optional offer settings (page custom fields, e.g. /bring-a-friend/): limit the form to
    // one venue and a date window, tag its bookings, show "Who invited you?", and switch to an
    // "offer ended" message after the end date. Plain /free-trial/ sets none of these.
    foreach ( array( 'venue', 'start', 'end', 'source', 'invite', 'max_sessions', 'ended_text', 'ended_url' ) as $ea_key ) {
        $ea_val = trim( (string) get_post_meta( get_the_ID(), 'ea_offer_' . $ea_key, true ) );
        if ( '' !== $ea_val ) {
            printf( "    data-offer-%s=\"%s\"\n", esc_attr( str_replace( '_', '-', $ea_key ) ), esc_attr( $ea_val ) );
        }
    }
?>
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

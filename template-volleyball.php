<?php
/**
 * Template Name: EA Volleyball Page
 * Template Post Type: page
 *
 * Standalone volleyball landing/programs page. React owns the layout while
 * WordPress provides the asset shell and Customizer data.
 */
get_header(); ?>

<?php
// Town pages are child pages of /volleyball/ (e.g. /volleyball/innisfil/): pass the town slug.
// The hub gets the list of its town pages so city headings can link to them.
$ea_vb_id     = get_queried_object_id();
$ea_vb_parent = (int) wp_get_post_parent_id( $ea_vb_id );
$ea_vb_town   = $ea_vb_parent ? get_post_field( 'post_name', $ea_vb_id ) : '';
$ea_vb_towns  = array();
foreach ( get_pages( array( 'parent' => $ea_vb_parent ? $ea_vb_parent : $ea_vb_id, 'post_status' => 'publish' ) ) as $ea_vb_child ) {
    $ea_vb_towns[ $ea_vb_child->post_name ] = get_permalink( $ea_vb_child );
}
$ea_vb_hub = $ea_vb_parent ? get_permalink( $ea_vb_parent ) : get_permalink( $ea_vb_id );
?>
<main id="ea-react-root" class="ea-react-root" data-page="volleyball"
    data-town="<?php echo esc_attr( $ea_vb_town ); ?>"
    data-hub-url="<?php echo esc_url( $ea_vb_hub ); ?>"
    data-towns="<?php echo esc_attr( wp_json_encode( $ea_vb_towns ) ); ?>">
    <noscript>
        <p><?php esc_html_e( 'This site requires JavaScript to display. Please enable JavaScript in your browser.', 'ea-react-theme' ); ?></p>
    </noscript>
</main>

<?php get_footer(); ?>

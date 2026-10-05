<?php
/**
 * Header.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="skip-link visually-hidden-focusable" href="#main"><?php esc_html_e( 'Skip to main content', 'support-desk' ); ?></a>

<?php get_template_part( 'template-parts/header/site-header' ); ?>

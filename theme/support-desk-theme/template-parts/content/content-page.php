<?php
/**
 * Plain page layout: title + editor content (used when a page has no
 * Support Desk blocks, e.g. a privacy policy written with normal blocks).
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="page-plain">
	<div class="container">
		<div class="page-plain__inner">
			<h1 class="page-plain__title display-heading"><?php the_title(); ?></h1>
			<div class="content-block">
				<?php the_content(); ?>
			</div>
		</div>
	</div>
</section>

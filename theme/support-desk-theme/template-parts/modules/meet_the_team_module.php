<?php
/**
 * Meet the Team — portrait grid of team members.
 *
 * Layout: meet_the_team_module in the page builder (row values arrive as $args).
 * Data: 'team' posts (inc/cpt-team.php). Headshot = featured image;
 * team_role, team_bio, team_linkedin post meta (Team → edit person).
 *
 * Source: "selected" uses the order people were picked in; "all" lists
 * every team member by menu order.
 *
 * Fixes from the original theme: unique heading ID per module; bio line breaks
 * no longer print as literal "<br />"; LinkedIn link names the person.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

$bsup_tag     = bsup_arg( $args, 'section_tag' );
$bsup_heading = bsup_arg( $args, 'heading' );
$bsup_intro   = bsup_arg( $args, 'intro' );
$bsup_source  = bsup_arg( $args, 'source' );
$bsup_members = bsup_arg( $args, 'team_members' );
$bsup_bg      = bsup_bg_class( bsup_arg( $args, 'background_style' ) );

if ( 'selected' !== $bsup_source || ! $bsup_members ) {
	$bsup_members = get_posts(
		array(
			'post_type'      => 'team',
			'posts_per_page' => 48,
			'no_found_rows'  => true,
			'orderby'        => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
		)
	);
}

$bsup_heading_id = bsup_uid( 'mtt-heading' );
?>
<section class="meet-the-team-module<?php echo esc_attr( $bsup_bg ); ?>"<?php echo $bsup_heading ? ' aria-labelledby="' . esc_attr( $bsup_heading_id ) . '"' : ''; ?>>
	<div class="container">

		<?php if ( $bsup_tag || $bsup_heading || $bsup_intro ) : ?>
			<div class="section-header reveal-on-scroll">
				<?php if ( $bsup_tag ) : ?>
					<span class="section-tag"><?php echo esc_html( $bsup_tag ); ?></span>
				<?php endif; ?>
				<?php if ( $bsup_heading ) : ?>
					<h2 class="display-heading<?php echo esc_attr( bsup_stop_class( $bsup_heading ) ); ?>" id="<?php echo esc_attr( $bsup_heading_id ); ?>"><?php echo esc_html( $bsup_heading ); ?></h2>
				<?php endif; ?>
				<?php if ( $bsup_intro ) : ?>
					<div class="meet-the-team-module__intro"><?php echo wp_kses_post( $bsup_intro ); ?></div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( $bsup_members ) : ?>
			<ul class="meet-the-team-module__grid" data-reveal-cards="4">
				<?php foreach ( $bsup_members as $bsup_member ) : ?>
					<?php
					$bsup_member = get_post( $bsup_member );
					if ( ! $bsup_member || 'publish' !== $bsup_member->post_status ) {
						continue;
					}
					$bsup_id       = $bsup_member->ID;
					$bsup_name     = get_the_title( $bsup_id );
					$bsup_role     = get_post_meta( $bsup_id, 'team_role', true );
					$bsup_bio      = get_post_meta( $bsup_id, 'team_bio', true );
					$bsup_linkedin = get_post_meta( $bsup_id, 'team_linkedin', true );
					$bsup_has_img  = has_post_thumbnail( $bsup_id );
					?>
					<li class="mtt-card<?php echo $bsup_has_img ? '' : ' mtt-card--no-image'; ?>">
						<div class="mtt-card__image-wrap">
							<?php if ( $bsup_has_img ) : ?>
								<?php
								echo get_the_post_thumbnail(
									$bsup_id,
									'bsup-portrait',
									array(
										'class'   => 'mtt-card__image',
										'alt'     => '', // Decorative: the name is right below it.
										'loading' => 'lazy',
										'sizes'   => '(max-width: 480px) 100vw, (max-width: 900px) 50vw, 25vw',
									)
								);
								?>
							<?php else : ?>
								<div class="mtt-card__placeholder" aria-hidden="true">
									<span><?php echo esc_html( mb_substr( $bsup_name, 0, 1 ) ); ?></span>
								</div>
							<?php endif; ?>
						</div>

						<div class="mtt-card__info">
							<h3 class="mtt-card__name"><?php echo esc_html( $bsup_name ); ?></h3>
							<?php if ( $bsup_role ) : ?>
								<span class="mtt-card__role"><?php echo esc_html( $bsup_role ); ?></span>
							<?php endif; ?>
							<?php if ( $bsup_bio ) : ?>
								<p class="mtt-card__bio"><?php echo nl2br( esc_html( $bsup_bio ) ); ?></p>
							<?php endif; ?>
							<?php if ( $bsup_linkedin ) : ?>
								<a class="mtt-card__linkedin-link" href="<?php echo esc_url( $bsup_linkedin ); ?>" target="_blank" rel="noopener noreferrer">
									LinkedIn
									<span class="visually-hidden">
										<?php
										/* translators: %s: person's name. */
										echo esc_html( sprintf( __( 'profile for %s (opens in a new tab)', 'support-desk' ), $bsup_name ) );
										?>
									</span>
									<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M7 17L17 7M17 7H7M17 7v10"/></svg>
								</a>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php elseif ( current_user_can( 'edit_posts' ) ) : ?>
			<p class="bsup-editor-notice"><?php esc_html_e( 'No team members yet. Add them under Team in the admin menu.', 'support-desk' ); ?></p>
		<?php endif; ?>

	</div>
</section>

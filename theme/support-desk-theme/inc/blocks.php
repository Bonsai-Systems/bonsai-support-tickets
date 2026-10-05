<?php
/**
 * Support Desk blocks: one server-rendered block per page module.
 *
 * Block = definition below + template-parts/modules/{layout}.php (receives
 * the attributes as $args) + optional assets/css/modules/{layout}.css.
 * Child themes can override the template or the CSS.
 *
 * Attribute names match the old ACF sub field names, so content converted
 * from the ACF page builder maps one to one.
 *
 * Editing: one script (assets/js/blocks.js) registers every block from
 * these definitions and builds the sidebar from the "fields" list, with a
 * live server-rendered preview. No build step.
 *
 * Module CSS is enqueued in <head> (by reading which blocks the page uses
 * before rendering), so there's no flash of unstyled modules and no CLS.
 *
 * @package Support_Desk_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Block namespace.
 */
const BSUP_BLOCK_NS = 'support-desk';

/**
 * Background choices shared by most blocks.
 *
 * @return array Field definition.
 */
function bsup_background_field() {
	return array(
		'name'    => 'background_style',
		'type'    => 'select',
		'label'   => __( 'Background', 'support-desk' ),
		'default' => 'default',
		'options' => array(
			'default' => __( 'White', 'support-desk' ),
			'warm'    => __( 'Page background', 'support-desk' ),
			'blue'    => __( 'Tint', 'support-desk' ),
			'black'   => __( 'Dark', 'support-desk' ),
		),
	);
}

/**
 * Section tag / heading / description fields (most blocks start with them).
 *
 * @param string $tag_name Tag attribute name.
 * @return array[]
 */
function bsup_header_fields( $tag_name = 'section_tag' ) {
	return array(
		array(
			'name'  => $tag_name,
			'type'  => 'text',
			'label' => __( 'Section tag', 'support-desk' ),
			'help'  => __( 'Small label above the heading. Optional.', 'support-desk' ),
		),
		array(
			'name'  => 'heading',
			'type'  => 'text',
			'label' => __( 'Heading', 'support-desk' ),
		),
	);
}

/**
 * Description field.
 *
 * @return array
 */
function bsup_description_field() {
	return array(
		'name'  => 'description',
		'type'  => 'text',
		'label' => __( 'Description', 'support-desk' ),
	);
}

/**
 * Block definitions, keyed by block slug (support-desk/{slug}).
 *
 * Field types: text, textarea, html (basic formatting), select, toggle,
 * number, url, link (url/title/target), posts (source: articles|team),
 * terms (source: topics), repeater (fields).
 *
 * @return array<string,array>
 */
function bsup_block_definitions() {
	$defs = array(
		'help-search-hero' => array(
			'layout'      => 'help_search_hero_module',
			'title'       => __( 'Help search hero', 'support-desk' ),
			'description' => __( 'Page heading with the help centre search and popular articles. For the home page.', 'support-desk' ),
			'icon'        => 'search',
			'fields'      => array(
				array(
					'name'  => 'hero_badge',
					'type'  => 'text',
					'label' => __( 'Badge', 'support-desk' ),
					'help'  => __( 'Small label above the heading.', 'support-desk' ),
				),
				array(
					'name'        => 'hero_title',
					'type'        => 'text',
					'label'       => __( 'Heading', 'support-desk' ),
					'placeholder' => __( 'How can we help', 'support-desk' ),
				),
				array(
					'name'  => 'hero_lead',
					'type'  => 'textarea',
					'label' => __( 'Lead text', 'support-desk' ),
				),
				array(
					'name'        => 'search_placeholder',
					'type'        => 'text',
					'label'       => __( 'Search placeholder', 'support-desk' ),
					'placeholder' => __( 'e.g. update opening hours', 'support-desk' ),
				),
				array(
					'name'   => 'popular_articles',
					'type'   => 'posts',
					'source' => 'articles',
					'max'    => 6,
					'label'  => __( 'Popular articles', 'support-desk' ),
					'help'   => __( 'Optional quick links under the search box. Three or four works best.', 'support-desk' ),
				),
				bsup_background_field(),
			),
		),
		'support-links'    => array(
			'layout'      => 'support_links_module',
			'title'       => __( 'Support links', 'support-desk' ),
			'description' => __( 'Up to four link cards: Submit a request, My requests, Help centre or your own.', 'support-desk' ),
			'icon'        => 'screenoptions',
			'fields'      => array_merge(
				bsup_header_fields(),
				array(
					bsup_description_field(),
					array(
						'name'      => 'links',
						'type'      => 'repeater',
						'label'     => __( 'Cards', 'support-desk' ),
						'add_label' => __( 'Add card', 'support-desk' ),
						'max'       => 4,
						'title_key' => 'title',
						'help'      => __( 'Submit a request, My requests and Help centre link to the right page automatically.', 'support-desk' ),
						'fields'    => array(
							array(
								'name'    => 'link_type',
								'type'    => 'select',
								'label'   => __( 'Goes to', 'support-desk' ),
								'default' => 'submit',
								'options' => array(
									'submit' => __( 'Submit a request', 'support-desk' ),
									'portal' => __( 'My requests', 'support-desk' ),
									'help'   => __( 'Help centre', 'support-desk' ),
									'custom' => __( 'Custom link', 'support-desk' ),
								),
							),
							array(
								'name'    => 'icon',
								'type'    => 'select',
								'label'   => __( 'Icon', 'support-desk' ),
								'default' => 'ticket',
								'options' => array(
									'ticket'   => __( 'Ticket', 'support-desk' ),
									'list'     => __( 'List', 'support-desk' ),
									'book'     => __( 'Book', 'support-desk' ),
									'mail'     => __( 'Email', 'support-desk' ),
									'phone'    => __( 'Phone', 'support-desk' ),
									'pulse'    => __( 'Status / pulse', 'support-desk' ),
									'calendar' => __( 'Calendar', 'support-desk' ),
									'search'   => __( 'Search', 'support-desk' ),
									'arrow'    => __( 'Arrow', 'support-desk' ),
								),
							),
							array(
								'name'  => 'title',
								'type'  => 'text',
								'label' => __( 'Title', 'support-desk' ),
							),
							array(
								'name'  => 'description',
								'type'  => 'text',
								'label' => __( 'Description', 'support-desk' ),
							),
							array(
								'name'      => 'link',
								'type'      => 'link',
								'label'     => __( 'Custom link', 'support-desk' ),
								'show_when' => array( 'link_type', 'custom' ),
							),
						),
					),
					bsup_background_field(),
				)
			),
		),
		'help-topics'      => array(
			'layout'      => 'help_topics_module',
			'title'       => __( 'Help topics', 'support-desk' ),
			'description' => __( 'Help centre topics with their top articles.', 'support-desk' ),
			'icon'        => 'category',
			'fields'      => array_merge(
				bsup_header_fields(),
				array(
					bsup_description_field(),
					array(
						'name'    => 'topics_source',
						'type'    => 'select',
						'label'   => __( 'Topics', 'support-desk' ),
						'default' => 'all',
						'options' => array(
							'all'      => __( 'All topics that have articles', 'support-desk' ),
							'selected' => __( 'Selected topics', 'support-desk' ),
						),
					),
					array(
						'name'      => 'topics',
						'type'      => 'terms',
						'source'    => 'topics',
						'label'     => __( 'Selected topics', 'support-desk' ),
						'show_when' => array( 'topics_source', 'selected' ),
					),
					array(
						'name'    => 'articles_per_topic',
						'type'    => 'number',
						'label'   => __( 'Articles per topic', 'support-desk' ),
						'default' => 5,
						'min'     => 1,
						'max'     => 12,
					),
					array(
						'name'    => 'columns',
						'type'    => 'select',
						'label'   => __( 'Columns', 'support-desk' ),
						'default' => '3',
						'options' => array(
							'3' => '3',
							'2' => '2',
						),
					),
					bsup_background_field(),
				)
			),
		),
		'ticket-portal'    => array(
			'layout'      => 'ticket_portal_module',
			'title'       => __( 'My requests', 'support-desk' ),
			'description' => __( 'The logged-in client\'s requests, or a log-in form. Choose this page as "My requests page" in Support → Settings.', 'support-desk' ),
			'icon'        => 'list-view',
			'fields'      => array(
				array(
					'name'  => 'intro',
					'type'  => 'textarea',
					'label' => __( 'Intro', 'support-desk' ),
					'help'  => __( 'Shown above the request list (not on a single request).', 'support-desk' ),
				),
				bsup_background_field(),
			),
		),
		'submit-request'   => array(
			'layout'      => 'submit_request_module',
			'title'       => __( 'Submit a request', 'support-desk' ),
			'description' => __( 'Guidance beside the request form. Choose this page as "Submit a request page" in Support → Settings.', 'support-desk' ),
			'icon'        => 'feedback',
			'fields'      => array_merge(
				bsup_header_fields(),
				array(
					array(
						'name'  => 'intro',
						'type'  => 'html',
						'label' => __( 'Intro', 'support-desk' ),
					),
					array(
						'name'      => 'tips',
						'type'      => 'repeater',
						'label'     => __( 'Tips', 'support-desk' ),
						'add_label' => __( 'Add tip', 'support-desk' ),
						'max'       => 8,
						'title_key' => 'tip',
						'help'      => __( 'Shown under "To help us fix it faster".', 'support-desk' ),
						'fields'    => array(
							array(
								'name'  => 'tip',
								'type'  => 'text',
								'label' => __( 'Tip', 'support-desk' ),
							),
						),
					),
					array(
						'name'    => 'show_help_link',
						'type'    => 'toggle',
						'label'   => __( 'Link to the help centre', 'support-desk' ),
						'default' => true,
					),
					bsup_background_field(),
				)
			),
		),
		'subpage-hero'     => array(
			'layout'      => 'subpage_hero_module',
			'title'       => __( 'Page header', 'support-desk' ),
			'description' => __( 'Page title (H1) with an optional badge and lead text.', 'support-desk' ),
			'icon'        => 'heading',
			'fields'      => array(
				array(
					'name'  => 'hero_badge',
					'type'  => 'text',
					'label' => __( 'Badge', 'support-desk' ),
				),
				array(
					'name'  => 'hero_title',
					'type'  => 'text',
					'label' => __( 'Title', 'support-desk' ),
					'help'  => __( 'Blank uses the page title.', 'support-desk' ),
				),
				array(
					'name'  => 'hero_lead',
					'type'  => 'textarea',
					'label' => __( 'Lead text', 'support-desk' ),
				),
				array(
					'name'    => 'hero_style',
					'type'    => 'select',
					'label'   => __( 'Alignment', 'support-desk' ),
					'default' => 'default',
					'options' => array(
						'default'  => __( 'Left', 'support-desk' ),
						'centered' => __( 'Centred', 'support-desk' ),
					),
				),
				bsup_background_field(),
			),
		),
		'content-block'    => array(
			'layout'      => 'content_block_module',
			'title'       => __( 'Content', 'support-desk' ),
			'description' => __( 'A section of normal content: text, lists, images, tables. Good for policies and guides.', 'support-desk' ),
			'icon'        => 'text-page',
			'inner'       => true,
			'fields'      => array(
				array(
					'name'  => 'section_tag',
					'type'  => 'text',
					'label' => __( 'Section tag', 'support-desk' ),
					'help'  => __( 'Optional, e.g. "Last updated: January 2026".', 'support-desk' ),
				),
				array(
					'name'  => 'heading',
					'type'  => 'text',
					'label' => __( 'Heading', 'support-desk' ),
				),
				array(
					'name'    => 'heading_level',
					'type'    => 'select',
					'label'   => __( 'Heading level', 'support-desk' ),
					'default' => 'h2',
					'options' => array(
						'h2' => __( 'H2 (section heading)', 'support-desk' ),
						'h1' => __( 'H1 (page title, only with no page header above)', 'support-desk' ),
					),
				),
				array(
					'name'    => 'content_width',
					'type'    => 'select',
					'label'   => __( 'Width', 'support-desk' ),
					'default' => 'narrow',
					'options' => array(
						'narrow'   => __( 'Reading width', 'support-desk' ),
						'standard' => __( 'Full container', 'support-desk' ),
					),
				),
				bsup_background_field(),
			),
		),
		'faq'              => array(
			'layout'      => 'faq_module',
			'title'       => __( 'FAQ', 'support-desk' ),
			'description' => __( 'Questions and answers in an accordion, with FAQ schema for search engines.', 'support-desk' ),
			'icon'        => 'editor-help',
			'fields'      => array_merge(
				bsup_header_fields(),
				array(
					bsup_description_field(),
					array(
						'name'      => 'faq_items',
						'type'      => 'repeater',
						'label'     => __( 'Questions', 'support-desk' ),
						'add_label' => __( 'Add question', 'support-desk' ),
						'title_key' => 'question',
						'fields'    => array(
							array(
								'name'  => 'question',
								'type'  => 'text',
								'label' => __( 'Question', 'support-desk' ),
							),
							array(
								'name'  => 'answer',
								'type'  => 'html',
								'label' => __( 'Answer', 'support-desk' ),
							),
						),
					),
					bsup_background_field(),
				)
			),
		),
		'feature-cards'    => array(
			'layout'      => 'feature_cards_module',
			'title'       => __( 'Feature cards', 'support-desk' ),
			'description' => __( 'A grid of cards, e.g. "What we cover" or your service levels.', 'support-desk' ),
			'icon'        => 'grid-view',
			'fields'      => array_merge(
				bsup_header_fields( 'eyebrow' ),
				array(
					array(
						'name'    => 'columns',
						'type'    => 'select',
						'label'   => __( 'Columns', 'support-desk' ),
						'default' => '3',
						'options' => array(
							'3' => '3',
							'2' => '2',
						),
					),
					array(
						'name'      => 'feature_cards',
						'type'      => 'repeater',
						'label'     => __( 'Cards', 'support-desk' ),
						'add_label' => __( 'Add card', 'support-desk' ),
						'title_key' => 'card_title',
						'fields'    => array(
							array(
								'name'  => 'card_label',
								'type'  => 'text',
								'label' => __( 'Label', 'support-desk' ),
							),
							array(
								'name'  => 'card_title',
								'type'  => 'text',
								'label' => __( 'Title', 'support-desk' ),
							),
							array(
								'name'  => 'card_description',
								'type'  => 'textarea',
								'label' => __( 'Description', 'support-desk' ),
							),
						),
					),
					bsup_background_field(),
				)
			),
		),
		'process'          => array(
			'layout'      => 'process_module',
			'title'       => __( 'Process steps', 'support-desk' ),
			'description' => __( 'Numbered steps, e.g. how support works.', 'support-desk' ),
			'icon'        => 'editor-ol',
			'fields'      => array_merge(
				bsup_header_fields(),
				array(
					bsup_description_field(),
					array(
						'name'      => 'steps',
						'type'      => 'repeater',
						'label'     => __( 'Steps', 'support-desk' ),
						'add_label' => __( 'Add step', 'support-desk' ),
						'title_key' => 'step_title',
						'fields'    => array(
							array(
								'name'        => 'step_number',
								'type'        => 'text',
								'label'       => __( 'Number', 'support-desk' ),
								'placeholder' => '01',
								'help'        => __( 'Blank numbers them automatically.', 'support-desk' ),
							),
							array(
								'name'  => 'step_title',
								'type'  => 'text',
								'label' => __( 'Title', 'support-desk' ),
							),
							array(
								'name'  => 'step_description',
								'type'  => 'text',
								'label' => __( 'Description', 'support-desk' ),
							),
						),
					),
					bsup_background_field(),
				)
			),
		),
		'cta-compact'      => array(
			'layout'      => 'cta_compact_module',
			'title'       => __( 'Call to action', 'support-desk' ),
			'description' => __( 'A dark band with a heading and one or two buttons.', 'support-desk' ),
			'icon'        => 'megaphone',
			'fields'      => array(
				array(
					'name'  => 'heading',
					'type'  => 'text',
					'label' => __( 'Heading', 'support-desk' ),
				),
				array(
					'name'  => 'description',
					'type'  => 'textarea',
					'label' => __( 'Description', 'support-desk' ),
				),
				array(
					'name'  => 'primary_button',
					'type'  => 'link',
					'label' => __( 'Main button', 'support-desk' ),
				),
				array(
					'name'  => 'secondary_button',
					'type'  => 'link',
					'label' => __( 'Second button (outline)', 'support-desk' ),
				),
			),
		),
		'meet-the-team'    => array(
			'layout'      => 'meet_the_team_module',
			'title'       => __( 'Meet the team', 'support-desk' ),
			'description' => __( 'Team members from the Team menu, with headshots.', 'support-desk' ),
			'icon'        => 'groups',
			'fields'      => array_merge(
				bsup_header_fields(),
				array(
					array(
						'name'  => 'intro',
						'type'  => 'html',
						'label' => __( 'Intro', 'support-desk' ),
					),
					array(
						'name'    => 'source',
						'type'    => 'select',
						'label'   => __( 'Show', 'support-desk' ),
						'default' => 'all',
						'options' => array(
							'all'      => __( 'Everyone (in Team menu order)', 'support-desk' ),
							'selected' => __( 'Selected people', 'support-desk' ),
						),
					),
					array(
						'name'      => 'team_members',
						'type'      => 'posts',
						'source'    => 'team',
						'label'     => __( 'People', 'support-desk' ),
						'show_when' => array( 'source', 'selected' ),
					),
					bsup_background_field(),
				)
			),
		),
	);

	/**
	 * Filters the Support Desk block definitions (add, remove or change blocks).
	 *
	 * @param array $defs Definitions keyed by block slug.
	 */
	return apply_filters( 'bsup_block_definitions', $defs );
}

/**
 * Block attribute type for a field type.
 *
 * @param string $type Field type.
 * @return string
 */
function bsup_attribute_type( $type ) {
	switch ( $type ) {
		case 'number':
			return 'number';
		case 'toggle':
			return 'boolean';
		case 'link':
			return 'object';
		case 'posts':
		case 'terms':
		case 'repeater':
			return 'array';
		default:
			return 'string';
	}
}

/**
 * Block attributes from a definition's fields.
 *
 * @param array $def Definition.
 * @return array
 */
function bsup_block_attributes( array $def ) {
	$attributes = array();
	foreach ( $def['fields'] as $field ) {
		$type = bsup_attribute_type( $field['type'] );
		$attr = array( 'type' => $type );
		if ( isset( $field['default'] ) ) {
			$attr['default'] = $field['default'];
		} elseif ( 'array' === $type ) {
			$attr['default'] = array();
		} elseif ( 'object' === $type ) {
			$attr['default'] = (object) array();
		} elseif ( 'string' === $type ) {
			$attr['default'] = '';
		}
		$attributes[ $field['name'] ] = $attr;
	}
	return $attributes;
}

/**
 * Register the blocks and the editor script.
 */
function bsup_register_blocks() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}

	wp_register_script(
		'bsup-blocks',
		BSUP_URI . '/assets/js/blocks.js',
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
		bsup_asset_version( 'assets/js/blocks.js' ),
		true
	);

	foreach ( bsup_block_definitions() as $slug => $def ) {
		register_block_type(
			BSUP_BLOCK_NS . '/' . $slug,
			array(
				'api_version'     => 3,
				'title'           => $def['title'],
				'description'     => $def['description'],
				'category'        => 'support-desk',
				'icon'            => $def['icon'],
				'attributes'      => bsup_block_attributes( $def ),
				'supports'        => array(
					'html'  => false,
					'align' => false,
				),
				'editor_script'   => 'bsup-blocks',
				'render_callback' => 'bsup_render_block',
			)
		);
	}
}
add_action( 'init', 'bsup_register_blocks' );

/**
 * "Support Desk" block category, first in the inserter.
 *
 * @param array $categories Categories.
 * @return array
 */
function bsup_block_category( $categories ) {
	array_unshift(
		$categories,
		array(
			'slug'  => 'support-desk',
			'title' => __( 'Support Desk', 'support-desk' ),
			'icon'  => null,
		)
	);
	return $categories;
}
add_filter( 'block_categories_all', 'bsup_block_category' );

/**
 * Render a block through its module template.
 *
 * @param array    $attributes Attributes.
 * @param string   $content    Inner blocks HTML (Content block only).
 * @param WP_Block $block      Block.
 * @return string
 */
function bsup_render_block( $attributes, $content = '', $block = null ) {
	$name = $block instanceof WP_Block ? $block->name : '';
	$slug = str_replace( BSUP_BLOCK_NS . '/', '', $name );
	$defs = bsup_block_definitions();

	if ( ! isset( $defs[ $slug ] ) ) {
		return '';
	}

	$template = locate_template( 'template-parts/modules/' . $defs[ $slug ]['layout'] . '.php' );
	if ( ! $template ) {
		error_log( 'Support Desk theme: no template for block "' . $name . '"' );
		return '';
	}

	$args               = is_array( $attributes ) ? $attributes : array();
	$args['inner_html'] = (string) $content;

	ob_start();
	try {
		load_template( $template, false, $args );
	} catch ( Throwable $e ) {
		error_log( 'Support Desk theme: block "' . $name . '" failed: ' . $e->getMessage() );
	}
	return (string) ob_get_clean();
}

/**
 * Support Desk block names used in some content, including nested ones.
 *
 * @param string $content Post content.
 * @return string[] Block slugs (without namespace).
 */
function bsup_blocks_in( $content ) {
	$found = array();
	$walk  = function ( $blocks ) use ( &$walk, &$found ) {
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) && 0 === strpos( $block['blockName'], BSUP_BLOCK_NS . '/' ) ) {
				$found[] = substr( $block['blockName'], strlen( BSUP_BLOCK_NS ) + 1 );
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$walk( $block['innerBlocks'] );
			}
			// Synced patterns: look inside the referenced block.
			if ( 'core/block' === ( $block['blockName'] ?? '' ) && ! empty( $block['attrs']['ref'] ) ) {
				$ref = get_post( (int) $block['attrs']['ref'] );
				if ( $ref && 'wp_block' === $ref->post_type ) {
					$walk( parse_blocks( $ref->post_content ) );
				}
			}
		}
	};
	$walk( parse_blocks( (string) $content ) );
	return array_values( array_unique( $found ) );
}

/**
 * Whether a post is laid out with Support Desk blocks (page.php then
 * prints them full width instead of the plain title + content layout).
 *
 * @param int|WP_Post|null $post Post.
 * @return bool
 */
function bsup_has_modules( $post = null ) {
	$post = get_post( $post );
	return $post && has_blocks( $post->post_content ) && array() !== bsup_blocks_in( $post->post_content );
}

/**
 * Enqueue module CSS in <head> for the blocks on this page.
 */
function bsup_enqueue_module_styles() {
	if ( ! is_singular() ) {
		return;
	}

	$post = get_queried_object();
	if ( ! $post instanceof WP_Post ) {
		return;
	}

	$slugs = bsup_blocks_in( $post->post_content );
	$defs  = bsup_block_definitions();

	foreach ( $slugs as $slug ) {
		if ( isset( $defs[ $slug ] ) ) {
			bsup_enqueue_module_style( $defs[ $slug ]['layout'] );
		}
	}

	// Help blocks reuse the help centre partials (search form, topics grid).
	if ( array_intersect( $slugs, array( 'help-search-hero', 'help-topics' ) ) ) {
		wp_enqueue_style( 'bsup-help' );
	}

	// Ticket blocks output the plugin's templates — load its stylesheet in <head> too.
	if ( array_intersect( $slugs, array( 'ticket-portal', 'submit-request' ) ) && wp_style_is( 'bst-frontend', 'registered' ) ) {
		wp_enqueue_style( 'bst-frontend' );
	}
}
add_action( 'wp_enqueue_scripts', 'bsup_enqueue_module_styles', 20 );

/**
 * Enqueue one module's stylesheet if it has one.
 *
 * @param string $layout Layout (template) name.
 */
function bsup_enqueue_module_style( $layout ) {
	$rel  = 'assets/css/modules/' . $layout . '.css';
	$path = get_theme_file_path( $rel );
	if ( file_exists( $path ) ) {
		wp_enqueue_style( 'bsup-' . $layout, get_theme_file_uri( $rel ), array( 'bsup-base' ), (string) filemtime( $path ) );
	}
}

/**
 * Editor data for blocks.js: definitions and the lists the pickers need.
 */
function bsup_block_editor_data() {
	$defs = array();
	foreach ( bsup_block_definitions() as $slug => $def ) {
		$defs[] = array(
			'name'        => BSUP_BLOCK_NS . '/' . $slug,
			'title'       => $def['title'],
			'description' => $def['description'],
			'icon'        => $def['icon'],
			'inner'       => ! empty( $def['inner'] ),
			'fields'      => $def['fields'],
			'attributes'  => bsup_block_attributes( $def ),
		);
	}

	$options = array(
		'articles' => array(),
		'team'     => array(),
		'topics'   => array(),
	);

	if ( post_type_exists( 'bst_article' ) ) {
		foreach ( get_posts( array( 'post_type' => 'bst_article', 'numberposts' => 300, 'orderby' => 'title', 'order' => 'ASC' ) ) as $post ) {
			$options['articles'][] = array( 'id' => $post->ID, 'label' => html_entity_decode( get_the_title( $post ), ENT_QUOTES ) );
		}
	}
	foreach ( get_posts( array( 'post_type' => 'team', 'numberposts' => 100, 'orderby' => array( 'menu_order' => 'ASC', 'title' => 'ASC' ) ) ) as $post ) {
		$options['team'][] = array( 'id' => $post->ID, 'label' => html_entity_decode( get_the_title( $post ), ENT_QUOTES ) );
	}
	if ( taxonomy_exists( 'bst_article_topic' ) ) {
		$terms = get_terms( array( 'taxonomy' => 'bst_article_topic', 'hide_empty' => false ) );
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$options['topics'][] = array( 'id' => $term->term_id, 'label' => html_entity_decode( $term->name, ENT_QUOTES ) );
		}
	}

	wp_add_inline_script(
		'bsup-blocks',
		'window.bsupBlocks = ' . wp_json_encode(
			array(
				'blocks'  => $defs,
				'options' => $options,
				'i18n'    => array(
					'none'       => __( 'Nothing to choose yet.', 'support-desk' ),
					'remove'     => __( 'Remove', 'support-desk' ),
					'moveUp'     => __( 'Move up', 'support-desk' ),
					'moveDown'   => __( 'Move down', 'support-desk' ),
					'item'       => __( 'Item', 'support-desk' ),
					'url'        => __( 'URL', 'support-desk' ),
					'text'       => __( 'Text', 'support-desk' ),
					'newTab'     => __( 'Open in a new tab', 'support-desk' ),
					'settings'   => __( 'Settings', 'support-desk' ),
					'htmlHelp'   => __( 'Basic HTML allowed: <p>, <strong>, <em>, <a>, <ul>, <ol>, <li>.', 'support-desk' ),
					'contentTip' => __( 'Add your content here.', 'support-desk' ),
				),
			)
		) . ';',
		'before'
	);
}
add_action( 'enqueue_block_editor_assets', 'bsup_block_editor_data' );

/**
 * Sidebar styles for the block settings (outside the canvas).
 */
function bsup_block_editor_ui_styles() {
	wp_register_style( 'bsup-blocks-ui', false, array(), BSUP_VERSION );
	wp_enqueue_style( 'bsup-blocks-ui' );
	wp_add_inline_style( 'bsup-blocks-ui', '.bsup-picker{max-height:240px;overflow:auto;padding:4px 0}.bsup-picker .components-checkbox-control{margin-bottom:6px}.bsup-repeater-item{border:1px solid #ddd;margin:0 0 8px}' );
}
add_action( 'enqueue_block_editor_assets', 'bsup_block_editor_ui_styles' );

/**
 * New pages start with a page header and a content block, so editors
 * don't face an empty page.
 *
 * @param array  $args      Post type args.
 * @param string $post_type Post type.
 * @return array
 */
function bsup_page_template( $args, $post_type ) {
	if ( 'page' === $post_type ) {
		$args['template'] = apply_filters(
			'bsup_default_page_blocks',
			array(
				array( BSUP_BLOCK_NS . '/subpage-hero' ),
				array( BSUP_BLOCK_NS . '/content-block', array(), array( array( 'core/paragraph' ) ) ),
			)
		);
	}
	return $args;
}
add_filter( 'register_post_type_args', 'bsup_page_template', 10, 2 );

/**
 * Serialised block markup for a Support Desk block (starter pages, converters).
 *
 * @param string $slug        Block slug.
 * @param array  $attrs       Attributes.
 * @param string $inner_html  Inner content HTML (Content block): wrapped in a Classic
 *                            block, which editors can convert to blocks. Paragraphs
 *                            are added with wpautop(), as the old editor did on output.
 * @return string
 */
function bsup_block_markup( $slug, array $attrs = array(), $inner_html = '' ) {
	$inner = array();
	if ( '' !== trim( $inner_html ) ) {
		$inner[] = array(
			'blockName'    => 'core/freeform',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => wpautop( $inner_html ),
			'innerContent' => array( wpautop( $inner_html ) ),
		);
	}

	return serialize_block(
		array(
			'blockName'    => BSUP_BLOCK_NS . '/' . $slug,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => '',
			'innerContent' => $inner ? array( null ) : array(),
		)
	);
}

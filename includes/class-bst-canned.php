<?php
/**
 * Canned responses: saved replies agents insert into the reply box.
 *
 * Stored as a private post type (BST_Post_Types::CANNED) with tags
 * (BST_Post_Types::CANNED_TAG). The reply text is plain text in
 * post_content, like the messages it becomes. One shared library: every
 * agent can add, edit and delete them.
 *
 * Placeholders reuse the auto-reply set (BST_Mailer::placeholders(), so
 * the bst_email_placeholders filter applies here too) plus {{agent.name}}.
 * They're filled for the ticket being viewed when the screen loads; a
 * placeholder with no value yet (e.g. on a brand-new ticket) is left as
 * typed so the agent sees it and fills it in.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Canned responses data.
 */
class BST_Canned {

	const SEEDED_OPTION = 'bst_canned_seeded';

	/**
	 * Placeholder => what it becomes, for the edit screen.
	 *
	 * @return array<string,string>
	 */
	public static function placeholder_help() {
		$help = array(
			'{{client.name}}'  => __( 'The contact\'s name', 'bonsai-support-tickets' ),
			'{{ticket.id}}'    => __( 'The ticket reference, e.g. SUP-1042', 'bonsai-support-tickets' ),
			'{{ticket.title}}' => __( 'The ticket subject', 'bonsai-support-tickets' ),
			'{{agent.name}}'   => __( 'Your name', 'bonsai-support-tickets' ),
			'{{site.name}}'    => __( 'Your support name', 'bonsai-support-tickets' ),
		);

		// Placeholders added through bst_email_placeholders work here too.
		foreach ( array_keys( BST_Mailer::placeholders( 0 ) ) as $key ) {
			if ( ! isset( $help[ $key ] ) ) {
				$help[ $key ] = '';
			}
		}

		return $help;
	}

	/**
	 * Fill placeholders for a ticket and agent. Plain text in, plain text out
	 * (the reply box is a textarea), so values aren't HTML-escaped here.
	 *
	 * @param string $text      Reply text.
	 * @param int    $ticket_id Ticket ID (0 or an unsaved ticket is fine).
	 * @param int    $user_id   Agent inserting it.
	 * @return string
	 */
	public static function fill( $text, $ticket_id, $user_id ) {
		$values = BST_Mailer::placeholders( $ticket_id && BST_Tickets::ref( $ticket_id ) ? $ticket_id : 0 );

		$agent                    = get_userdata( (int) $user_id );
		$values['{{agent.name}}'] = $agent ? $agent->display_name : '';

		// WordPress stores display names HTML-encoded ("Smith &amp; Sons");
		// the reply box is plain text, so decode.
		$values = array_map(
			function ( $value ) {
				return wp_specialchars_decode( (string) $value, ENT_QUOTES );
			},
			$values
		);

		// Leave unknown values as typed rather than leaving "Hi ," behind.
		$values = array_filter(
			$values,
			function ( $value ) {
				return '' !== $value;
			}
		);

		return strtr( (string) $text, $values );
	}

	/**
	 * Published replies for the reply-box picker, grouped by their first tag
	 * (A–Z), untagged last. Each item: id, title, text (filled).
	 *
	 * @param int $ticket_id Ticket being replied to.
	 * @param int $user_id   Agent.
	 * @return array<string,array[]> Group label => items. '' = untagged.
	 */
	public static function grouped( $ticket_id, $user_id ) {
		$posts = get_posts(
			array(
				'post_type'        => BST_Post_Types::CANNED,
				'post_status'      => 'publish',
				'numberposts'      => 500,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);

		$groups = array();
		foreach ( $posts as $post ) {
			$terms = get_the_terms( $post, BST_Post_Types::CANNED_TAG );
			$group = '';
			if ( $terms && ! is_wp_error( $terms ) ) {
				$names = wp_list_pluck( $terms, 'name' );
				natcasesort( $names );
				$group = (string) reset( $names );
			}

			$groups[ $group ][] = array(
				'id'    => (int) $post->ID,
				'title' => get_the_title( $post ),
				'text'  => self::fill( $post->post_content, $ticket_id, $user_id ),
			);
		}

		uksort(
			$groups,
			function ( $a, $b ) {
				if ( '' === $a || '' === $b ) {
					return '' === $a ? 1 : -1; // Untagged last.
				}
				return strnatcasecmp( $a, $b );
			}
		);

		return $groups;
	}

	/**
	 * Starter replies. Neutral wording, UK spelling; editable and deletable
	 * once created.
	 *
	 * @return array[] Each: title, tag, text.
	 */
	public static function defaults() {
		$sign_off = "\n\n" . __( 'Thanks,', 'bonsai-support-tickets' ) . "\n{{agent.name}}";

		$replies = array(
			array(
				'title' => __( 'Please send a screenshot', 'bonsai-support-tickets' ),
				'tag'   => __( 'Information needed', 'bonsai-support-tickets' ),
				'text'  => __( 'Hi {{client.name}},', 'bonsai-support-tickets' ) . "\n\n"
					. __( 'Thanks for getting in touch. Could you send us a screenshot of what you\'re seeing, and let us know which page it\'s on and which browser and device you\'re using? That will help us get to the bottom of it quickly.', 'bonsai-support-tickets' )
					. $sign_off,
			),
			array(
				'title' => __( 'Login details needed', 'bonsai-support-tickets' ),
				'tag'   => __( 'Information needed', 'bonsai-support-tickets' ),
				'text'  => __( 'Hi {{client.name}},', 'bonsai-support-tickets' ) . "\n\n"
					. __( 'To look into this we\'ll need access to the account involved. Please don\'t send passwords by email: if you can add us as a user instead, or share access through a password manager, that\'s the safest way.', 'bonsai-support-tickets' )
					. $sign_off,
			),
			array(
				'title' => __( 'DNS changes can take up to 48 hours', 'bonsai-support-tickets' ),
				'tag'   => __( 'Hosting and domains', 'bonsai-support-tickets' ),
				'text'  => __( 'Hi {{client.name}},', 'bonsai-support-tickets' ) . "\n\n"
					. __( 'We\'ve made the DNS change. These can take anywhere from a few minutes to 48 hours to reach everyone, depending on your internet provider, so you may still see the old version for a little while. If it isn\'t showing after 48 hours, just reply to this email and we\'ll check it.', 'bonsai-support-tickets' )
					. $sign_off,
			),
			array(
				'title' => __( 'Updates done', 'bonsai-support-tickets' ),
				'tag'   => __( 'Maintenance', 'bonsai-support-tickets' ),
				'text'  => __( 'Hi {{client.name}},', 'bonsai-support-tickets' ) . "\n\n"
					. __( 'We\'ve updated WordPress, your theme and plugins, and checked that the main pages and forms are working as expected. If you notice anything that doesn\'t look right, just reply to this email.', 'bonsai-support-tickets' )
					. $sign_off,
			),
			array(
				'title' => __( 'Still working on it', 'bonsai-support-tickets' ),
				'tag'   => __( 'Updates', 'bonsai-support-tickets' ),
				'text'  => __( 'Hi {{client.name}},', 'bonsai-support-tickets' ) . "\n\n"
					. __( 'Just a quick update to let you know we\'re still working on {{ticket.id}}. We\'ll be in touch as soon as we have more news.', 'bonsai-support-tickets' )
					. $sign_off,
			),
			array(
				'title' => __( 'Closing as solved', 'bonsai-support-tickets' ),
				'tag'   => __( 'Closing', 'bonsai-support-tickets' ),
				'text'  => __( 'Hi {{client.name}},', 'bonsai-support-tickets' ) . "\n\n"
					. __( 'As we haven\'t heard back, we\'ll mark this request as solved. If you still need help, just reply to this email and it will reopen.', 'bonsai-support-tickets' )
					. $sign_off,
			),
		);

		/**
		 * Filters the starter canned responses created on first install.
		 *
		 * @param array[] $replies Each: title, tag, text.
		 */
		return apply_filters( 'bst_default_canned_responses', $replies );
	}

	/**
	 * Create the starter replies once per site. Deleting them later doesn't
	 * bring them back.
	 */
	public static function create_defaults() {
		if ( get_option( self::SEEDED_OPTION ) ) {
			return;
		}
		update_option( self::SEEDED_OPTION, 1, false );

		try {
			foreach ( self::defaults() as $reply ) {
				$post_id = wp_insert_post(
					array(
						'post_type'    => BST_Post_Types::CANNED,
						'post_status'  => 'publish',
						'post_title'   => wp_slash( sanitize_text_field( $reply['title'] ?? '' ) ),
						'post_content' => wp_slash( sanitize_textarea_field( $reply['text'] ?? '' ) ),
					),
					true
				);

				if ( is_wp_error( $post_id ) ) {
					error_log( BST_PRODUCT_NAME . ': could not create starter canned response: ' . $post_id->get_error_message() );
					continue;
				}

				if ( ! empty( $reply['tag'] ) ) {
					wp_set_object_terms( $post_id, array( sanitize_text_field( $reply['tag'] ) ), BST_Post_Types::CANNED_TAG );
				}
			}
		} catch ( Throwable $e ) {
			error_log( BST_PRODUCT_NAME . ': starter canned responses failed: ' . $e->getMessage() );
		}
	}
}

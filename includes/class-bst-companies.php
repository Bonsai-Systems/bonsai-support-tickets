<?php
/**
 * Client companies: the business a client account belongs to ("The Ley
 * Arms"), shown as "Clients" in the UI. Post type BST_Post_Types::COMPANY.
 *
 * - Each person (Support Client user) belongs to at most one company, via
 *   the bst_company_id user meta.
 * - Each ticket records the company it was raised for (_bst_company_id),
 *   stamped from the client when the ticket is created or relinked, so
 *   reports still add up if a person later moves company.
 * - Permissions don't change: clients still only see their own tickets.
 *   BST_Tickets::user_can_view() never looks at the company.
 *
 * Before 0.2 a company was a free-text bst_client_name on each user.
 * migrate_legacy_names() turns those into company records on upgrade, and
 * the text is kept as "the name they typed at sign-up".
 *
 * @package Bonsai_Support_Tickets
 */

defined( 'ABSPATH' ) || exit;

/**
 * Client companies.
 */
class BST_Companies {

	const META_USER     = 'bst_company_id';      // User meta.
	const META_TICKET   = '_bst_company_id';     // Ticket post meta.
	const META_RETAINER = '_bst_retainer_hours'; // Monthly hours, float as string.
	const META_WEBSITES = '_bst_websites';       // string[] of URLs.
	const META_NOTES    = '_bst_notes';

	/*
	|----------------------------------------------------------------------
	| Companies
	|----------------------------------------------------------------------
	*/

	/**
	 * Whether an ID is a live company.
	 *
	 * @param int $company_id Company ID.
	 * @return bool
	 */
	public static function exists( $company_id ) {
		$company_id = (int) $company_id;
		return $company_id > 0
			&& BST_Post_Types::COMPANY === get_post_type( $company_id )
			&& 'trash' !== get_post_status( $company_id );
	}

	/**
	 * Company name.
	 *
	 * @param int $company_id Company ID.
	 * @return string '' when not a company.
	 */
	public static function name( $company_id ) {
		return self::exists( $company_id ) ? (string) get_post_field( 'post_title', (int) $company_id, 'raw' ) : '';
	}

	/**
	 * All companies, A–Z.
	 *
	 * @return WP_Post[]
	 */
	public static function all() {
		return get_posts(
			array(
				'post_type'      => BST_Post_Types::COMPANY,
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Create a company.
	 *
	 * @param string $name Company name.
	 * @return int|WP_Error Company ID.
	 */
	public static function create( $name ) {
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			return new WP_Error( 'bst_company_name', __( 'A client needs a name.', 'bonsai-support-tickets' ) );
		}

		$id = wp_insert_post(
			array(
				'post_type'   => BST_Post_Types::COMPANY,
				'post_status' => 'publish',
				'post_title'  => wp_slash( $name ),
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			error_log( 'Bonsai Support Tickets: could not create client ' . $name . ': ' . $id->get_error_message() );
		}

		return $id;
	}

	/**
	 * Normalise a name for matching: lower case, single spaces, trimmed.
	 * "  The  Ley Arms " and "the ley arms" match.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function normalize_name( $name ) {
		$name = wp_specialchars_decode( (string) $name, ENT_QUOTES );
		return trim( preg_replace( '/\s+/u', ' ', mb_strtolower( $name ) ) );
	}

	/**
	 * Company whose name matches exactly (after normalising).
	 *
	 * @param string $name Name.
	 * @return int Company ID or 0.
	 */
	public static function find_by_name( $name ) {
		$needle = self::normalize_name( $name );
		if ( '' === $needle ) {
			return 0;
		}
		foreach ( self::all() as $company ) {
			if ( self::normalize_name( $company->post_title ) === $needle ) {
				return (int) $company->ID;
			}
		}
		return 0;
	}

	/**
	 * Companies whose name looks like the typed one, best first. Used to
	 * suggest a match when approving a sign-up ("Ley Arms" → "The Ley Arms").
	 *
	 * @param string $name  Typed name.
	 * @param int    $limit Max results.
	 * @return WP_Post[]
	 */
	public static function suggest( $name, $limit = 3 ) {
		$needle = self::normalize_name( $name );
		if ( '' === $needle ) {
			return array();
		}

		$scored = array();
		foreach ( self::all() as $company ) {
			$hay = self::normalize_name( $company->post_title );
			if ( $hay === $needle ) {
				$score = 100;
			} elseif ( str_contains( $hay, $needle ) || str_contains( $needle, $hay ) ) {
				$score = 90;
			} else {
				similar_text( $needle, $hay, $score );
			}
			if ( $score >= 70 ) {
				$scored[] = array( $score, $company );
			}
		}

		usort(
			$scored,
			function ( $a, $b ) {
				return $b[0] <=> $a[0];
			}
		);

		return array_slice( wp_list_pluck( $scored, 1 ), 0, $limit );
	}

	/**
	 * Monthly retainer hours (0 = none).
	 *
	 * @param int $company_id Company ID.
	 * @return float
	 */
	public static function retainer_hours( $company_id ) {
		return (float) get_post_meta( (int) $company_id, self::META_RETAINER, true );
	}

	/**
	 * Websites the company owns.
	 *
	 * @param int $company_id Company ID.
	 * @return string[]
	 */
	public static function websites( $company_id ) {
		$sites = get_post_meta( (int) $company_id, self::META_WEBSITES, true );
		return is_array( $sites ) ? $sites : array();
	}

	/**
	 * Parse a textarea of websites (one per line, commas also accepted) into
	 * clean, unique https URLs. Bare domains get https:// added.
	 *
	 * @param string $text Raw input.
	 * @return string[]
	 */
	public static function parse_websites( $text ) {
		$out = array();
		foreach ( preg_split( '/[\r\n,]+/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( ! preg_match( '#^https?://#i', $line ) ) {
				$line = 'https://' . $line;
			}
			$url  = esc_url_raw( $line, array( 'http', 'https' ) );
			$host = $url ? wp_parse_url( $url, PHP_URL_HOST ) : '';
			// Must look like a real domain (example.co.uk), not "javascript" or "localhost".
			if ( ! $host || ! preg_match( '/^([a-z0-9-]+\.)+[a-z]{2,}$/i', $host ) ) {
				continue;
			}
			$key = untrailingslashit( strtolower( $url ) );
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = untrailingslashit( $url );
			}
		}
		return array_values( $out );
	}

	/**
	 * The company's single term in a list (partner or plan).
	 *
	 * @param int    $company_id Company ID.
	 * @param string $taxonomy   BST_Post_Types::PARTNER or ::PLAN.
	 * @return WP_Term|null
	 */
	public static function term( $company_id, $taxonomy ) {
		$terms = get_the_terms( (int) $company_id, $taxonomy );
		return $terms && ! is_wp_error( $terms ) ? $terms[0] : null;
	}

	/*
	|----------------------------------------------------------------------
	| People
	|----------------------------------------------------------------------
	*/

	/**
	 * A person's company.
	 *
	 * @param int $user_id User ID.
	 * @return int Company ID or 0.
	 */
	public static function for_user( $user_id ) {
		$company_id = $user_id ? (int) get_user_meta( (int) $user_id, self::META_USER, true ) : 0;
		return self::exists( $company_id ) ? $company_id : 0;
	}

	/**
	 * Link a person to a company (0 unlinks). One company per person.
	 *
	 * @param int $user_id    User ID.
	 * @param int $company_id Company ID or 0.
	 * @return bool False when the company doesn't exist.
	 */
	public static function set_user_company( $user_id, $company_id ) {
		$company_id = (int) $company_id;
		if ( ! $company_id ) {
			delete_user_meta( (int) $user_id, self::META_USER );
			return true;
		}
		if ( ! self::exists( $company_id ) ) {
			return false;
		}
		update_user_meta( (int) $user_id, self::META_USER, $company_id );
		return true;
	}

	/**
	 * People linked to a company.
	 *
	 * @param int $company_id Company ID.
	 * @return WP_User[]
	 */
	public static function people( $company_id ) {
		return get_users(
			array(
				'meta_key'   => self::META_USER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => (int) $company_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'    => 'display_name',
			)
		);
	}

	/*
	|----------------------------------------------------------------------
	| Tickets
	|----------------------------------------------------------------------
	*/

	/**
	 * The company a ticket was raised for.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return int Company ID or 0.
	 */
	public static function for_ticket( $ticket_id ) {
		$company_id = (int) get_post_meta( (int) $ticket_id, self::META_TICKET, true );
		return self::exists( $company_id ) ? $company_id : 0;
	}

	/**
	 * Store a ticket's company without logging. 0 clears it.
	 * Use BST_Tickets::set_company() for agent changes (logged).
	 *
	 * @param int $ticket_id  Ticket ID.
	 * @param int $company_id Company ID or 0.
	 */
	public static function stamp_ticket( $ticket_id, $company_id ) {
		$company_id = (int) $company_id;
		if ( $company_id && self::exists( $company_id ) ) {
			update_post_meta( (int) $ticket_id, self::META_TICKET, $company_id );
		} else {
			delete_post_meta( (int) $ticket_id, self::META_TICKET );
		}
	}

	/**
	 * Number of a company's tickets, optionally only active ones.
	 *
	 * @param int  $company_id  Company ID.
	 * @param bool $active_only Only active statuses.
	 * @return int
	 */
	public static function ticket_count( $company_id, $active_only = false ) {
		$meta_query = array(
			array(
				'key'   => self::META_TICKET,
				'value' => (int) $company_id,
				'type'  => 'NUMERIC',
			),
		);
		if ( $active_only ) {
			$meta_query[] = array(
				'key'     => BST_Tickets::META_STATUS,
				'value'   => BST_Tickets::active_statuses(),
				'compare' => 'IN',
			);
		}

		$query = new WP_Query(
			array(
				'post_type'              => BST_Post_Types::TICKET,
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		return (int) $query->found_posts;
	}

	/*
	|----------------------------------------------------------------------
	| Migration from free-text client names
	|----------------------------------------------------------------------
	*/

	/**
	 * Turn every distinct bst_client_name into a company, link the people,
	 * and stamp their existing tickets. Safe to run more than once: people
	 * already linked and tickets already stamped are skipped, and names
	 * match existing companies before new ones are created.
	 *
	 * Pending sign-ups are left alone; their company is chosen on approval.
	 *
	 * @return array{created:int,linked:int,tickets:int}
	 */
	public static function migrate_legacy_names() {
		$result = array(
			'created' => 0,
			'linked'  => 0,
			'tickets' => 0,
		);

		$users = get_users(
			array(
				'meta_key'     => BST_Clients::META_CLIENT_NAME, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare' => '!=',
			)
		);

		$by_name = array(); // normalised name => company ID, so a group shares one record.
		foreach ( $users as $user ) {
			if ( self::for_user( $user->ID ) || BST_Clients::is_pending( $user->ID ) ) {
				continue;
			}

			$typed = (string) get_user_meta( $user->ID, BST_Clients::META_CLIENT_NAME, true );
			$key   = self::normalize_name( $typed );
			if ( '' === $key ) {
				continue;
			}

			if ( ! isset( $by_name[ $key ] ) ) {
				$existing = self::find_by_name( $typed );
				if ( $existing ) {
					$by_name[ $key ] = $existing;
				} else {
					$created = self::create( $typed );
					if ( is_wp_error( $created ) ) {
						continue;
					}
					$by_name[ $key ] = (int) $created;
					++$result['created'];
				}
			}

			self::set_user_company( $user->ID, $by_name[ $key ] );
			++$result['linked'];
		}

		$result['tickets'] = self::backfill_tickets();

		return $result;
	}

	/**
	 * Stamp tickets that have no company with their client's company.
	 *
	 * @return int Tickets stamped.
	 */
	public static function backfill_tickets() {
		$ids = get_posts(
			array(
				'post_type'      => BST_Post_Types::TICKET,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META_TICKET,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$stamped = 0;
		foreach ( $ids as $ticket_id ) {
			$company_id = self::for_user( BST_Tickets::client_id( $ticket_id ) );
			if ( $company_id ) {
				self::stamp_ticket( $ticket_id, $company_id );
				++$stamped;
			}
		}
		return $stamped;
	}
}

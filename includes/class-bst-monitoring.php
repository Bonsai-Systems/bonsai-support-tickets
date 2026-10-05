<?php
/**
 * Uptime monitoring: turns site-down alerts into tickets.
 *
 * Two independent sources, each switched on under Support → Settings →
 * Uptime monitoring, each with its own REST endpoint:
 *
 * - Status monitor  POST /wp-json/bst/v1/monitor/status
 *   Our own uptime monitor app. JSON body, signed:
 *     X-Monitor-Timestamp: unix time
 *     X-Monitor-Signature: sha256=HMAC-SHA256( timestamp . "." . body, secret )
 *   Rejected if the clock is more than 5 minutes out or the signature was
 *   already used (replay).
 *   Body: event (down|up|ssl_expiry|domain_expiry|test), site {name, url},
 *   cause, duration_seconds, days_left, expires_at, incident_id.
 *
 * - UptimeRobot     POST /wp-json/bst/v1/monitor/uptimerobot?key=…
 *   UptimeRobot can't sign webhooks, so the secret is a key in the URL.
 *   Fields are UptimeRobot's own variables (monitorURL, alertType, …).
 *
 * A switched-off source answers 404, as if the route didn't exist.
 *
 * What happens:
 * - down          → one Urgent ticket per site, matched to a Client by its
 *                   Websites. Another down while that ticket is open adds a
 *                   note instead of a second ticket.
 * - up            → internal note on the open ticket with the downtime. The
 *                   status is left alone: someone should check why.
 * - ssl/domain    → one Normal ticket per site and kind while open.
 *
 * Monitor tickets have no client account, so no email ever goes to a
 * client; agents get the usual new-ticket email and Slack post.
 *
 * @package Support_Desk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Uptime alerts → tickets.
 */
class BST_Monitoring {

	const REST_NAMESPACE = 'bst/v1';
	const META_KEY       = '_bst_monitor_key'; // Ticket meta: source:kind:host, for dedupe.
	const LOG_OPTION     = 'bst_monitor_log';  // Last alert received per source, for the settings screen.
	const SOURCE         = 'monitor';          // Message/ticket source.
	const MAX_SKEW       = 300;                // Seconds a signed request may be early or late.

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Sources: settings keys, wp-config constant and label.
	 *
	 * @return array<string,array>
	 */
	public static function sources() {
		return array(
			'status'      => array(
				'enabled'  => 'monitor_enabled',
				'secret'   => 'monitor_secret',
				'constant' => 'BST_MONITOR_SECRET',
				'label'    => __( 'Status monitor', 'bonsai-support-tickets' ),
			),
			'uptimerobot' => array(
				'enabled'  => 'uptimerobot_enabled',
				'secret'   => 'uptimerobot_key',
				'constant' => 'BST_UPTIMEROBOT_KEY',
				'label'    => __( 'UptimeRobot', 'bonsai-support-tickets' ),
			),
		);
	}

	/**
	 * Register the endpoints.
	 */
	public static function routes() {
		foreach ( array_keys( self::sources() ) as $source ) {
			register_rest_route(
				self::REST_NAMESPACE,
				'/monitor/' . $source,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_request' ),
					'permission_callback' => array( __CLASS__, 'authorise' ),
					'args'                => array(),
				)
			);
		}
	}

	/*
	|----------------------------------------------------------------------
	| Settings helpers
	|----------------------------------------------------------------------
	*/

	/**
	 * Whether a source is switched on.
	 *
	 * @param string $source status|uptimerobot.
	 * @return bool
	 */
	public static function enabled( $source ) {
		$sources = self::sources();
		return isset( $sources[ $source ] ) && (bool) BST_Settings::get( $sources[ $source ]['enabled'] );
	}

	/**
	 * Secret for a source: wp-config constant if defined, else the generated one.
	 *
	 * @param string $source status|uptimerobot.
	 * @return string
	 */
	public static function secret( $source ) {
		$sources = self::sources();
		if ( ! isset( $sources[ $source ] ) ) {
			return '';
		}
		if ( self::secret_in_config( $source ) ) {
			return (string) constant( $sources[ $source ]['constant'] );
		}
		return (string) BST_Settings::get( $sources[ $source ]['secret'] );
	}

	/**
	 * Whether the secret is set in wp-config.php.
	 *
	 * @param string $source status|uptimerobot.
	 * @return bool
	 */
	public static function secret_in_config( $source ) {
		$sources = self::sources();
		return isset( $sources[ $source ] ) && defined( $sources[ $source ]['constant'] ) && '' !== (string) constant( $sources[ $source ]['constant'] );
	}

	/**
	 * Generate and save a new secret. Old one stops working at once.
	 *
	 * @param string $source status|uptimerobot.
	 * @return string The new secret.
	 */
	public static function regenerate_secret( $source ) {
		$sources = self::sources();
		$secret  = wp_generate_password( 40, false, false );
		BST_Settings::save( array( $sources[ $source ]['secret'] => $secret ) );
		return $secret;
	}

	/**
	 * Endpoint URL to paste into the monitor. UptimeRobot's includes the key.
	 *
	 * @param string $source status|uptimerobot.
	 * @return string
	 */
	public static function endpoint_url( $source ) {
		$url = rest_url( self::REST_NAMESPACE . '/monitor/' . $source );
		if ( 'uptimerobot' === $source && '' !== self::secret( $source ) ) {
			$url = add_query_arg( 'key', rawurlencode( self::secret( $source ) ), $url );
		}
		return $url;
	}

	/**
	 * Where buyers can get uptime monitoring from us. '' hides the link.
	 *
	 * @return string
	 */
	public static function promo_url() {
		$url = defined( 'BST_MONITOR_PROMO_URL' ) ? (string) BST_MONITOR_PROMO_URL : '';

		/**
		 * Filters the "get uptime monitoring" link on the settings screen.
		 *
		 * @param string $url URL, or '' to hide the link.
		 */
		return esc_url_raw( (string) apply_filters( 'bst_monitor_promo_url', $url ) );
	}

	/**
	 * Last alert received from a source.
	 *
	 * @param string $source status|uptimerobot.
	 * @return array{time:int,event:string,result:string}|null
	 */
	public static function last_received( $source ) {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) && isset( $log[ $source ] ) && is_array( $log[ $source ] ) ? $log[ $source ] : null;
	}

	/*
	|----------------------------------------------------------------------
	| REST
	|----------------------------------------------------------------------
	*/

	/**
	 * Permission callback: source switched on and the request authentic.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function authorise( WP_REST_Request $request ) {
		$source = self::source_from_route( $request->get_route() );

		// Switched off: behave as if the route doesn't exist.
		if ( ! $source || ! self::enabled( $source ) ) {
			return new WP_Error( 'rest_no_route', __( 'No route was found matching the URL and request method.', 'bonsai-support-tickets' ), array( 'status' => 404 ) );
		}

		$secret = self::secret( $source );
		if ( '' === $secret ) {
			return new WP_Error( 'bst_monitor_no_secret', __( 'No secret has been generated yet.', 'bonsai-support-tickets' ), array( 'status' => 401 ) );
		}

		$ok = 'status' === $source
			? self::verify_signature( $request, $secret )
			: hash_equals( $secret, (string) $request->get_param( 'key' ) );

		if ( ! $ok ) {
			error_log( BST_PRODUCT_NAME . ': rejected ' . $source . ' monitor alert (bad secret or signature).' );
			return new WP_Error( 'bst_monitor_unauthorised', __( 'Invalid signature.', 'bonsai-support-tickets' ), array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * Check the status monitor's HMAC signature, clock and replay.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $secret  Shared secret.
	 * @return bool
	 */
	public static function verify_signature( WP_REST_Request $request, $secret ) {
		$timestamp = (string) $request->get_header( 'x_monitor_timestamp' );
		$signature = (string) $request->get_header( 'x_monitor_signature' );

		if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > self::MAX_SKEW ) {
			return false;
		}

		$expected = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $request->get_body(), $secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			return false;
		}

		// Each signature works once. Kept a little longer than the clock window.
		$seen = 'bst_mon_' . substr( hash( 'sha256', $signature ), 0, 32 );
		if ( get_transient( $seen ) ) {
			return false;
		}
		set_transient( $seen, 1, 2 * self::MAX_SKEW );

		return true;
	}

	/**
	 * Handle an authenticated alert.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_request( WP_REST_Request $request ) {
		$source = self::source_from_route( $request->get_route() );
		$params = $request->get_params(); // JSON body, form body and query, merged.

		$alert  = 'status' === $source ? self::normalise_status( $params ) : self::normalise_uptimerobot( $params );
		$result = self::process( $alert );

		$log            = get_option( self::LOG_OPTION, array() );
		$log            = is_array( $log ) ? $log : array();
		$log[ $source ] = array(
			'time'   => time(),
			'event'  => $alert['event'],
			'result' => is_wp_error( $result ) ? 'error' : $result['result'],
		);
		update_option( self::LOG_OPTION, $log, false );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'ok'    => false,
					'error' => $result->get_error_message(),
				),
				500
			);
		}

		return new WP_REST_Response( array( 'ok' => true ) + $result, 200 );
	}

	/**
	 * status|uptimerobot from a route like /bst/v1/monitor/status.
	 *
	 * @param string $route Route.
	 * @return string '' if unknown.
	 */
	private static function source_from_route( $route ) {
		$source = basename( (string) $route );
		return array_key_exists( $source, self::sources() ) ? $source : '';
	}

	/*
	|----------------------------------------------------------------------
	| Normalising
	|----------------------------------------------------------------------
	*/

	/**
	 * Empty alert.
	 *
	 * @param string $source Source.
	 * @return array
	 */
	private static function blank_alert( $source ) {
		return array(
			'source'     => $source,
			'event'      => '',
			'name'       => '',
			'url'        => '',
			'cause'      => '',
			'duration'   => 0,
			'days_left'  => null,
			'expires_at' => '',
		);
	}

	/**
	 * Status monitor payload → alert.
	 *
	 * @param array $p Request params.
	 * @return array
	 */
	public static function normalise_status( array $p ) {
		$alert = self::blank_alert( 'status' );
		$site  = isset( $p['site'] ) && is_array( $p['site'] ) ? $p['site'] : array();

		$event          = sanitize_key( $p['event'] ?? '' );
		$alert['event'] = in_array( $event, array( 'down', 'up', 'ssl_expiry', 'domain_expiry', 'test' ), true ) ? $event : '';
		$alert['name']  = sanitize_text_field( $site['name'] ?? '' );
		$alert['url']   = esc_url_raw( $site['url'] ?? '' );
		$alert['cause'] = sanitize_text_field( $p['cause'] ?? '' );

		$alert['duration']   = absint( $p['duration_seconds'] ?? 0 );
		$alert['days_left']  = isset( $p['days_left'] ) && is_numeric( $p['days_left'] ) ? (int) $p['days_left'] : null;
		$alert['expires_at'] = sanitize_text_field( $p['expires_at'] ?? '' );

		return $alert;
	}

	/**
	 * UptimeRobot webhook variables → alert.
	 *
	 * alertType: 1 down, 2 up, 3 SSL expiry. Unfilled variables arrive as
	 * the literal "*name*", so those count as empty.
	 *
	 * @param array $p Request params.
	 * @return array
	 */
	public static function normalise_uptimerobot( array $p ) {
		$alert = self::blank_alert( 'uptimerobot' );
		$get   = function ( $key ) use ( $p ) {
			$value = isset( $p[ $key ] ) && is_scalar( $p[ $key ] ) ? trim( (string) $p[ $key ] ) : '';
			return preg_match( '/^\*[A-Za-z]+\*$/', $value ) ? '' : $value;
		};

		$types          = array(
			'1' => 'down',
			'2' => 'up',
			'3' => 'ssl_expiry',
		);
		$alert['event'] = $types[ $get( 'alertType' ) ] ?? '';
		$alert['name']  = sanitize_text_field( $get( 'monitorFriendlyName' ) );
		$alert['url']   = esc_url_raw( $get( 'monitorURL' ) );
		$alert['cause'] = sanitize_text_field( $get( 'alertDetails' ) );

		$alert['duration']   = absint( $get( 'alertDuration' ) );
		$alert['days_left']  = is_numeric( $get( 'sslExpiryDaysLeft' ) ) ? (int) $get( 'sslExpiryDaysLeft' ) : null;
		$alert['expires_at'] = sanitize_text_field( $get( 'sslExpiryDate' ) );

		return $alert;
	}

	/*
	|----------------------------------------------------------------------
	| Tickets
	|----------------------------------------------------------------------
	*/

	/**
	 * Turn an alert into a ticket or note.
	 *
	 * @param array $alert Normalised alert.
	 * @return array|WP_Error array( 'result' => created|noted|ignored|test, 'ticket_id' => int ).
	 */
	public static function process( array $alert ) {
		if ( 'test' === $alert['event'] ) {
			return array(
				'result'    => 'test',
				'ticket_id' => 0,
			);
		}

		$host = self::host( $alert['url'] );
		if ( '' === $alert['event'] || '' === $host ) {
			return array(
				'result'    => 'ignored',
				'ticket_id' => 0,
			);
		}

		if ( '' === $alert['name'] ) {
			$alert['name'] = $host;
		}

		// "up" closes the loop on the down ticket, so it shares its key.
		$kind     = 'up' === $alert['event'] ? 'down' : $alert['event'];
		$key      = $alert['source'] . ':' . $kind . ':' . $host;
		$existing = self::find_open( $key );

		if ( 'up' === $alert['event'] ) {
			if ( ! $existing ) {
				return array(
					'result'    => 'ignored',
					'ticket_id' => 0,
				);
			}
			return self::note( $existing, $alert );
		}

		if ( $existing ) {
			return self::note( $existing, $alert );
		}

		return self::create_ticket( $alert, $key );
	}

	/**
	 * Open a ticket for an alert.
	 *
	 * @param array  $alert Alert.
	 * @param string $key   Dedupe key.
	 * @return array|WP_Error
	 */
	private static function create_ticket( array $alert, $key ) {
		$sources = self::sources();

		$created = BST_Tickets::create(
			array(
				'client_id'    => 0,
				'contact_name' => $sources[ $alert['source'] ]['label'],
				'subject'      => self::subject( $alert ),
				'body'         => self::body( $alert ),
				'priority'     => 'down' === $alert['event'] ? 'urgent' : 'normal',
				'site_url'     => $alert['url'],
				'source'       => self::SOURCE,
				'company_id'   => BST_Companies::find_by_website( $alert['url'] ),
				'actor_id'     => 0,
			)
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		update_post_meta( $created['ticket_id'], self::META_KEY, $key );

		return array(
			'result'    => 'created',
			'ticket_id' => $created['ticket_id'],
		);
	}

	/**
	 * Add an internal note to an open monitor ticket.
	 *
	 * @param int   $ticket_id Ticket.
	 * @param array $alert     Alert.
	 * @return array|WP_Error
	 */
	private static function note( $ticket_id, array $alert ) {
		$sources = self::sources();

		$message_id = BST_Tickets::reply(
			$ticket_id,
			array(
				'user_id'     => 0,
				'author_name' => $sources[ $alert['source'] ]['label'],
				'visibility'  => BST_Messages::INTERNAL,
				'body'        => self::body( $alert ),
				'source'      => self::SOURCE,
				'system'      => true,
			)
		);

		if ( is_wp_error( $message_id ) ) {
			return $message_id;
		}

		return array(
			'result'    => 'noted',
			'ticket_id' => (int) $ticket_id,
		);
	}

	/**
	 * Open ticket with this dedupe key.
	 *
	 * @param string $key Key.
	 * @return int Ticket ID or 0.
	 */
	public static function find_open( $key ) {
		$ids = get_posts(
			array(
				'post_type'        => BST_Post_Types::TICKET,
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'numberposts'      => 1,
				'orderby'          => 'ID',
				'order'            => 'DESC',
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_KEY,
						'value' => $key,
					),
					array(
						'key'     => BST_Tickets::META_STATUS,
						'value'   => BST_Tickets::active_statuses(),
						'compare' => 'IN',
					),
				),
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Ticket subject.
	 *
	 * @param array $alert Alert.
	 * @return string
	 */
	private static function subject( array $alert ) {
		switch ( $alert['event'] ) {
			case 'ssl_expiry':
				/* translators: %s: site name. */
				return sprintf( __( 'SSL certificate expiring: %s', 'bonsai-support-tickets' ), $alert['name'] );
			case 'domain_expiry':
				/* translators: %s: site name. */
				return sprintf( __( 'Domain expiring: %s', 'bonsai-support-tickets' ), $alert['name'] );
			default:
				/* translators: %s: site name. */
				return sprintf( __( 'Site down: %s', 'bonsai-support-tickets' ), $alert['name'] );
		}
	}

	/**
	 * Message HTML for a ticket or note.
	 *
	 * @param array $alert Alert.
	 * @return string
	 */
	private static function body( array $alert ) {
		$sources = self::sources();
		$from    = $sources[ $alert['source'] ]['label'];
		$site    = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $alert['url'] ), esc_html( $alert['name'] ) );
		$lines   = array();

		switch ( $alert['event'] ) {
			case 'up':
				$lines[] = $alert['duration']
					/* translators: 1: site link, 2: duration, e.g. 12m 30s. */
					? sprintf( __( '%1$s is back up after %2$s.', 'bonsai-support-tickets' ), $site, esc_html( self::format_duration( $alert['duration'] ) ) )
					/* translators: %s: site link. */
					: sprintf( __( '%s is back up.', 'bonsai-support-tickets' ), $site );
				$lines[] = esc_html__( 'This ticket stays open so someone can check why it went down.', 'bonsai-support-tickets' );
				break;

			case 'ssl_expiry':
			case 'domain_expiry':
				$what    = 'ssl_expiry' === $alert['event'] ? __( 'SSL certificate', 'bonsai-support-tickets' ) : __( 'domain', 'bonsai-support-tickets' );
				$lines[] = null !== $alert['days_left']
					/* translators: 1: site link, 2: "SSL certificate" or "domain", 3: number of days. */
					? sprintf( _n( 'The %2$s for %1$s expires in %3$d day.', 'The %2$s for %1$s expires in %3$d days.', max( 1, (int) $alert['days_left'] ), 'bonsai-support-tickets' ), $site, esc_html( $what ), (int) $alert['days_left'] )
					/* translators: 1: site link, 2: "SSL certificate" or "domain". */
					: sprintf( __( 'The %2$s for %1$s expires soon.', 'bonsai-support-tickets' ), $site, esc_html( $what ) );
				if ( '' !== $alert['expires_at'] ) {
					/* translators: %s: expiry date as sent by the monitor. */
					$lines[] = esc_html( sprintf( __( 'Expiry date: %s', 'bonsai-support-tickets' ), $alert['expires_at'] ) );
				}
				break;

			default:
				/* translators: %s: site link. */
				$lines[] = sprintf( __( '%s is down.', 'bonsai-support-tickets' ), $site );
				if ( '' !== $alert['cause'] ) {
					/* translators: %s: reason from the monitor, e.g. HTTP 503. */
					$lines[] = esc_html( sprintf( __( 'Cause: %s', 'bonsai-support-tickets' ), $alert['cause'] ) );
				}
		}

		/* translators: 1: monitor name, 2: date and time. */
		$lines[] = esc_html( sprintf( __( 'Reported by %1$s at %2$s.', 'bonsai-support-tickets' ), $from, bst_format_datetime( current_time( 'mysql', true ) ) ) );

		return '<p>' . implode( '</p><p>', $lines ) . '</p>';
	}

	/**
	 * Lower-case host without www., or ''.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function host( $url ) {
		$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		return preg_replace( '/^www\./', '', $host );
	}

	/**
	 * 3725 → "1h 2m 5s".
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function format_duration( $seconds ) {
		$seconds = max( 0, (int) $seconds );
		$parts   = array();
		$units   = array(
			'd' => DAY_IN_SECONDS,
			'h' => HOUR_IN_SECONDS,
			'm' => MINUTE_IN_SECONDS,
		);
		foreach ( $units as $suffix => $size ) {
			if ( $seconds >= $size ) {
				$parts[]  = intdiv( $seconds, $size ) . $suffix;
				$seconds %= $size;
			}
		}
		if ( $seconds || ! $parts ) {
			$parts[] = $seconds . 's';
		}
		return implode( ' ', $parts );
	}
}

<?php
/**
 * Support → Settings screen, plus the inbound "Test connection" and
 * "Check mailbox now" actions.
 *
 * Left-hand tab nav, one tab per page load (&tab=…). Each tab with fields
 * has its own form and posts only its own fields; BST_Settings::save()
 * keeps everything else as it was.
 *
 * @package Bonsai_Support_Tickets
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page.
 */
class BST_Admin_Settings {

	const SLUG = 'bst-settings';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_bst_save_settings', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_bst_test_imap', array( __CLASS__, 'handle_test_imap' ) );
		add_action( 'admin_post_bst_check_mail', array( __CLASS__, 'handle_check_mail' ) );
		add_action( 'admin_post_bst_test_slack', array( __CLASS__, 'handle_test_slack' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BST_FILE ), array( __CLASS__, 'plugin_link' ) );
	}

	/**
	 * Tabs, in nav order.
	 *
	 * Each tab: label, render (callable, receives settings; printed inside
	 * the tab's form when form is true), form (bool), after (optional
	 * callable printed below the form, for cards with their own forms).
	 *
	 * @return array<string,array> Keyed by tab slug.
	 */
	public static function tabs() {
		return array(
			'general'    => array(
				'label'  => __( 'General', 'bonsai-support-tickets' ),
				'render' => array( __CLASS__, 'render_general' ),
				'form'   => true,
			),
			'appearance' => array(
				'label'  => __( 'Appearance', 'bonsai-support-tickets' ),
				'render' => array( __CLASS__, 'render_appearance' ),
				'form'   => true,
			),
			'outgoing'   => array(
				'label'  => __( 'Outgoing email', 'bonsai-support-tickets' ),
				'render' => array( __CLASS__, 'render_outgoing' ),
				'form'   => true,
			),
			'autoreply'  => array(
				'label'  => __( 'Auto-reply', 'bonsai-support-tickets' ),
				'render' => array( __CLASS__, 'render_autoreply' ),
				'form'   => true,
			),
			'inbound'    => array(
				'label'  => __( 'Incoming email', 'bonsai-support-tickets' ),
				'render' => array( __CLASS__, 'render_inbound' ),
				'form'   => true,
				'after'  => array( __CLASS__, 'render_inbound_status' ),
			),
			'slack'      => array(
				'label'  => __( 'Slack', 'bonsai-support-tickets' ),
				'render' => array( __CLASS__, 'render_slack' ),
				'form'   => true,
				'after'  => array( __CLASS__, 'render_slack_status' ),
			),
			'frontend'   => array(
				'label'  => __( 'Front end', 'bonsai-support-tickets' ),
				'render' => array( __CLASS__, 'render_frontend' ),
				'form'   => false,
			),
		);
	}

	/**
	 * Requested tab, falling back to the first one.
	 *
	 * @return string
	 */
	public static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		return array_key_exists( $tab, self::tabs() ) ? $tab : 'general';
	}

	/**
	 * Settings URL.
	 *
	 * @param string $tab Tab slug ('' = first tab).
	 * @return string
	 */
	public static function url( $tab = '' ) {
		$url = admin_url( 'edit.php?post_type=' . BST_Post_Types::TICKET . '&page=' . self::SLUG );
		return $tab && array_key_exists( $tab, self::tabs() ) ? add_query_arg( 'tab', $tab, $url ) : $url;
	}

	/**
	 * Submenu under Support.
	 */
	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=' . BST_Post_Types::TICKET,
			__( 'Support settings', 'bonsai-support-tickets' ),
			__( 'Settings', 'bonsai-support-tickets' ),
			'bst_manage_settings',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function plugin_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'bonsai-support-tickets' ) . '</a>' );
		return $links;
	}

	/**
	 * Common checks for the admin-post handlers.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'bst_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to change support settings.', 'bonsai-support-tickets' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Save one tab's fields, then go back to that tab.
	 */
	public static function handle_save() {
		self::guard( 'bst_save_settings' );

		$input = isset( $_POST['bst'] ) && is_array( $_POST['bst'] ) ? wp_unslash( $_POST['bst'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised field by field in BST_Settings::save().
		BST_Settings::save( $input );

		$tab = isset( $_POST['bst_tab'] ) ? sanitize_key( wp_unslash( $_POST['bst_tab'] ) ) : '';

		BST_Admin_UI::flash( __( 'Settings saved.', 'bonsai-support-tickets' ) );
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	/**
	 * Test the IMAP login.
	 */
	public static function handle_test_imap() {
		self::guard( 'bst_test_imap' );

		$result = BST_Inbound::test_connection();
		if ( is_wp_error( $result ) ) {
			/* translators: %s: error message. */
			BST_Admin_UI::flash( sprintf( __( 'Connection failed: %s', 'bonsai-support-tickets' ), $result->get_error_message() ), 'error' );
		} else {
			BST_Admin_UI::flash( __( 'Connected and logged in to the mailbox.', 'bonsai-support-tickets' ) );
		}

		wp_safe_redirect( self::url( 'inbound' ) );
		exit;
	}

	/**
	 * Poll the mailbox now.
	 */
	public static function handle_check_mail() {
		self::guard( 'bst_check_mail' );

		$status = BST_Inbound::run();
		if ( $status['error'] ) {
			BST_Admin_UI::flash( $status['error'], 'error' );
		} else {
			$counts = array_count_values( $status['results'] );
			BST_Admin_UI::flash(
				sprintf(
					/* translators: 1: emails processed, 2: new tickets, 3: replies. */
					__( 'Checked the mailbox: %1$d new emails, %2$d new tickets, %3$d replies.', 'bonsai-support-tickets' ),
					$status['processed'],
					$counts['created'] ?? 0,
					$counts['replied'] ?? 0
				)
			);
		}

		wp_safe_redirect( self::url( 'inbound' ) );
		exit;
	}

	/**
	 * Post a test message to Slack.
	 */
	public static function handle_test_slack() {
		self::guard( 'bst_test_slack' );

		$result = BST_Slack::send_test();
		if ( is_wp_error( $result ) ) {
			/* translators: %s: error message. */
			BST_Admin_UI::flash( sprintf( __( 'Slack test failed: %s', 'bonsai-support-tickets' ), $result->get_error_message() ), 'error' );
		} else {
			BST_Admin_UI::flash( __( 'Test message sent. Check the Slack channel.', 'bonsai-support-tickets' ) );
		}

		wp_safe_redirect( self::url( 'slack' ) );
		exit;
	}

	/**
	 * Page: header, tab nav, the current tab.
	 */
	public static function render() {
		if ( ! current_user_can( 'bst_manage_settings' ) ) {
			return;
		}

		$s       = BST_Settings::all();
		$tabs    = self::tabs();
		$current = self::current_tab();
		$tab     = $tabs[ $current ];
		?>
		<div class="wrap bonsai-ui">
			<?php
			BST_Admin_UI::header(
				__( 'Support settings', 'bonsai-support-tickets' ),
				__( 'Ticket references, the client portal pages, brand colours, outgoing email and the support mailbox that replies come back to.', 'bonsai-support-tickets' ),
				array(
					array(
						'label' => __( 'Tickets', 'bonsai-support-tickets' ),
						'url'   => admin_url( 'edit.php?post_type=' . BST_Post_Types::TICKET ),
					),
				)
			);
			?>

			<div class="bst-settings">
				<nav class="bst-settings__nav" aria-label="<?php esc_attr_e( 'Support settings', 'bonsai-support-tickets' ); ?>">
					<?php foreach ( $tabs as $slug => $item ) : ?>
						<a href="<?php echo esc_url( self::url( $slug ) ); ?>"<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $item['label'] ); ?></a>
					<?php endforeach; ?>
				</nav>

				<div class="bst-settings__main">
					<?php if ( $tab['form'] ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="bst_save_settings">
							<input type="hidden" name="bst_tab" value="<?php echo esc_attr( $current ); ?>">
							<?php wp_nonce_field( 'bst_save_settings' ); ?>

							<?php call_user_func( $tab['render'], $s ); ?>

							<?php submit_button( __( 'Save settings', 'bonsai-support-tickets' ) ); ?>
						</form>
					<?php else : ?>
						<?php call_user_func( $tab['render'], $s ); ?>
					<?php endif; ?>

					<?php
					if ( ! empty( $tab['after'] ) ) {
						call_user_func( $tab['after'], $s );
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * General tab.
	 *
	 * @param array $s Settings.
	 */
	public static function render_general( array $s ) {
		$pages = get_pages( array( 'post_status' => 'publish,private' ) );
		?>
		<section class="bonsai-ui-card">
			<h2 class="bonsai-ui-card__title"><?php esc_html_e( 'General', 'bonsai-support-tickets' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bst-ref-prefix"><?php esc_html_e( 'Reference prefix', 'bonsai-support-tickets' ); ?></label></th>
					<td>
						<input type="text" id="bst-ref-prefix" name="bst[ref_prefix]" value="<?php echo esc_attr( $s['ref_prefix'] ); ?>" class="small-text" maxlength="8">
						<p class="description"><?php esc_html_e( 'Letters and numbers. Tickets are numbered like BDC-1042. Changing it only affects new tickets.', 'bonsai-support-tickets' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bst-portal-page"><?php esc_html_e( 'My requests page', 'bonsai-support-tickets' ); ?></label></th>
					<td>
						<?php self::page_select( 'portal_page_id', 'bst-portal-page', $pages, (int) $s['portal_page_id'] ); ?>
						<p class="description"><?php echo wp_kses( __( 'The page with <code>[bst_my_tickets]</code> (or your theme template). Email links point here.', 'bonsai-support-tickets' ), array( 'code' => array() ) ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bst-submit-page"><?php esc_html_e( 'Submit a request page', 'bonsai-support-tickets' ); ?></label></th>
					<td>
						<?php self::page_select( 'submit_page_id', 'bst-submit-page', $pages, (int) $s['submit_page_id'] ); ?>
						<p class="description"><?php echo wp_kses( __( 'The page with <code>[bst_submit_form]</code>.', 'bonsai-support-tickets' ), array( 'code' => array() ) ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Client registration', 'bonsai-support-tickets' ); ?></th>
					<td>
						<input type="hidden" name="bst[registration_enabled]" value="0">
						<label for="bst-registration"><input type="checkbox" class="bonsai-ui-toggle" id="bst-registration" name="bst[registration_enabled]" value="1" <?php checked( $s['registration_enabled'] ); ?>> <?php esc_html_e( 'Show a Register link under the log-in form', 'bonsai-support-tickets' ); ?></label>
						<p class="description">
							<?php
							printf(
								/* translators: %s: link to the sign-ups screen. */
								esc_html__( 'New accounts wait for approval under %s before they can log in. Use [bst_register] to put the form on its own page.', 'bonsai-support-tickets' ),
								'<a href="' . esc_url( BST_Admin_Signups::url() ) . '">' . esc_html__( 'Support → Sign-ups', 'bonsai-support-tickets' ) . '</a>'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bst-auto-close"><?php esc_html_e( 'Auto-close solved tickets after', 'bonsai-support-tickets' ); ?></label></th>
					<td>
						<input type="number" id="bst-auto-close" name="bst[auto_close_days]" value="<?php echo esc_attr( $s['auto_close_days'] ); ?>" min="0" max="90" class="small-text"> <?php esc_html_e( 'days', 'bonsai-support-tickets' ); ?>
						<p class="description"><?php esc_html_e( '0 = never. A client can reopen a solved ticket by replying; a closed one stays closed in the list but replying still reopens it.', 'bonsai-support-tickets' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Attachments', 'bonsai-support-tickets' ); ?></th>
					<td>
						<label for="bst-max-files"><?php esc_html_e( 'Up to', 'bonsai-support-tickets' ); ?></label>
						<input type="number" id="bst-max-files" name="bst[max_upload_files]" value="<?php echo esc_attr( $s['max_upload_files'] ); ?>" min="1" max="20" class="small-text">
						<label for="bst-max-mb"><?php esc_html_e( 'files of', 'bonsai-support-tickets' ); ?></label>
						<input type="number" id="bst-max-mb" name="bst[max_upload_mb]" value="<?php echo esc_attr( $s['max_upload_mb'] ); ?>" min="1" max="64" class="small-text">
						<?php esc_html_e( 'MB each', 'bonsai-support-tickets' ); ?>
						<p class="description">
							<?php
							/* translators: %s: server upload limit. */
							echo esc_html( sprintf( __( 'The server allows %s per upload, so keep under that.', 'bonsai-support-tickets' ), size_format( wp_max_upload_size() ) ) );
							?>
						</p>
					</td>
				</tr>
			</table>
		</section>
		<?php
	}

	/**
	 * Appearance tab: brand colours for the front end and emails.
	 *
	 * @param array $s Settings.
	 */
	public static function render_appearance( array $s ) {
		$warnings = BST_Appearance::contrast_warnings();
		?>
		<section class="bonsai-ui-card">
			<h2 class="bonsai-ui-card__title"><?php esc_html_e( 'Brand colours', 'bonsai-support-tickets' ); ?></h2>
			<p class="bonsai-ui-card__intro"><?php esc_html_e( 'Used by the client portal, forms, help centre and every email. Leave a colour blank (Clear) to use the default. On the front end, blank also lets the theme\'s own Bonsai colours through. Success, warning and error colours are fixed so they stay readable.', 'bonsai-support-tickets' ); ?></p>

			<?php if ( $warnings ) : ?>
				<div class="notice notice-warning inline">
					<p><strong><?php esc_html_e( 'Some colour pairs are hard to read:', 'bonsai-support-tickets' ); ?></strong></p>
					<ul>
						<?php foreach ( $warnings as $warning ) : ?>
							<li><?php echo esc_html( $warning ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<?php foreach ( BST_Appearance::fields() as $key => $field ) : ?>
					<?php $id = 'bst-' . str_replace( '_', '-', $key ); ?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
						<td>
							<input type="text" id="<?php echo esc_attr( $id ); ?>" name="bst[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $s[ $key ] ?? '' ); ?>" class="bst-color-field" maxlength="7" placeholder="<?php echo esc_attr( $field['default'] ); ?>" data-default-color="<?php echo esc_attr( $field['default'] ); ?>">
							<p class="description">
								<?php
								echo esc_html( $field['description'] ) . ' ';
								/* translators: %s: default hex colour. */
								echo esc_html( sprintf( __( 'Default: %s.', 'bonsai-support-tickets' ), $field['default'] ) );
								?>
							</p>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
		</section>
		<?php
	}

	/**
	 * Outgoing email tab.
	 *
	 * @param array $s Settings.
	 */
	public static function render_outgoing( array $s ) {
		?>
		<section class="bonsai-ui-card">
			<h2 class="bonsai-ui-card__title"><?php esc_html_e( 'Outgoing email', 'bonsai-support-tickets' ); ?></h2>
			<p class="bonsai-ui-card__intro"><?php esc_html_e( 'Sent with wp_mail(), so an SMTP plugin will be used automatically once you add one. Until then, make sure the From domain\'s SPF record allows this server, or emails may land in spam.', 'bonsai-support-tickets' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bst-from-name"><?php esc_html_e( 'From name', 'bonsai-support-tickets' ); ?></label></th>
					<td><input type="text" id="bst-from-name" name="bst[from_name]" value="<?php echo esc_attr( $s['from_name'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="bst-from-email"><?php esc_html_e( 'From address', 'bonsai-support-tickets' ); ?></label></th>
					<td>
						<input type="email" id="bst-from-email" name="bst[from_email]" value="<?php echo esc_attr( $s['from_email'] ); ?>" class="regular-text" placeholder="support@bonsaidigitalcollective.co.uk">
						<p class="description"><?php esc_html_e( 'Use an address on your own domain, not the Gmail address — mail claiming to be from @gmail.com but sent by this server fails Gmail\'s checks. Blank uses the WordPress default.', 'bonsai-support-tickets' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bst-logo"><?php esc_html_e( 'Email logo URL', 'bonsai-support-tickets' ); ?></label></th>
					<td>
						<input type="url" id="bst-logo" name="bst[email_logo_url]" value="<?php echo esc_attr( $s['email_logo_url'] ); ?>" class="regular-text">
						<p class="description"><?php esc_html_e( 'Blank uses the Bonsai logo. Around 412 × 108px, on a light background.', 'bonsai-support-tickets' ); ?></p>
					</td>
				</tr>
			</table>
		</section>
		<?php
	}

	/**
	 * Auto-reply tab: the email a client gets when they raise a new ticket.
	 *
	 * @param array $s Settings.
	 */
	public static function render_autoreply( array $s ) {
		$descriptions = array(
			'{{ticket.title}}' => __( 'Ticket subject', 'bonsai-support-tickets' ),
			'{{ticket.id}}'    => __( 'Ticket reference, e.g. BDC-1042', 'bonsai-support-tickets' ),
			'{{client.name}}'  => __( 'Name of the person who raised it', 'bonsai-support-tickets' ),
		);
		?>
		<section class="bonsai-ui-card">
			<h2 class="bonsai-ui-card__title"><?php esc_html_e( 'Auto-reply', 'bonsai-support-tickets' ); ?></h2>
			<p class="bonsai-ui-card__intro"><?php esc_html_e( 'Sent once, when a client raises a new request through the form or by email. Replies never trigger it, and unknown senders never get it. The ticket reference is always added to the start of the subject, and the client\'s message and a View your request button follow your text.', 'bonsai-support-tickets' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Send auto-reply', 'bonsai-support-tickets' ); ?></th>
					<td>
						<input type="hidden" name="bst[autoreply_enabled]" value="0">
						<label for="bst-autoreply-enabled"><input type="checkbox" class="bonsai-ui-toggle" id="bst-autoreply-enabled" name="bst[autoreply_enabled]" value="1" <?php checked( $s['autoreply_enabled'] ); ?>> <?php esc_html_e( 'Email the client when a new ticket is created', 'bonsai-support-tickets' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bst-autoreply-subject"><?php esc_html_e( 'Subject', 'bonsai-support-tickets' ); ?></label></th>
					<td><input type="text" id="bst-autoreply-subject" name="bst[autoreply_subject]" value="<?php echo esc_attr( $s['autoreply_subject'] ); ?>" class="large-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="bst_autoreply_body"><?php esc_html_e( 'Message', 'bonsai-support-tickets' ); ?></label></th>
					<td>
						<?php
						wp_editor(
							$s['autoreply_body'],
							'bst_autoreply_body',
							array(
								'textarea_name' => 'bst[autoreply_body]',
								'textarea_rows' => 16,
								'media_buttons' => false,
								'teeny'         => true,
								// Keep <p> tags in the saved HTML; email has no wpautop().
								'wpautop'       => false,
							)
						);
						?>
						<p class="description"><?php esc_html_e( 'Clear the subject or message and save to go back to the default.', 'bonsai-support-tickets' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Placeholders', 'bonsai-support-tickets' ); ?></th>
					<td>
						<dl class="bonsai-ui-status">
							<?php foreach ( array_keys( BST_Mailer::placeholders() ) as $placeholder ) : ?>
								<dt><code><?php echo esc_html( $placeholder ); ?></code></dt>
								<dd><?php echo esc_html( $descriptions[ $placeholder ] ?? '' ); ?></dd>
							<?php endforeach; ?>
						</dl>
					</td>
				</tr>
			</table>
		</section>
		<?php
	}

	/**
	 * Incoming email tab: the fields (inside the tab's form).
	 *
	 * @param array $s Settings.
	 */
	public static function render_inbound( array $s ) {
		?>
		<section class="bonsai-ui-card">
			<h2 class="bonsai-ui-card__title"><?php esc_html_e( 'Incoming email', 'bonsai-support-tickets' ); ?></h2>
			<p class="bonsai-ui-card__intro"><?php esc_html_e( 'Emails to the support mailbox become tickets, and replies to our emails are added to the right ticket. The mailbox is checked every two minutes.', 'bonsai-support-tickets' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bst-inbound"><?php esc_html_e( 'Support mailbox address', 'bonsai-support-tickets' ); ?></label></th>
					<td>
						<input type="email" id="bst-inbound" name="bst[inbound_address]" value="<?php echo esc_attr( $s['inbound_address'] ); ?>" class="regular-text">
						<p class="description"><?php esc_html_e( 'Used as the Reply-To on every email we send. Blank turns off reply-by-email.', 'bonsai-support-tickets' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Reply tracking', 'bonsai-support-tickets' ); ?></th>
					<td>
						<input type="hidden" name="bst[plus_addressing]" value="0">
						<label for="bst-plus"><input type="checkbox" class="bonsai-ui-toggle" id="bst-plus" name="bst[plus_addressing]" value="1" <?php checked( $s['plus_addressing'] ); ?>> <?php esc_html_e( 'Add the ticket to the reply address (mailbox+t123-…@gmail.com)', 'bonsai-support-tickets' ); ?></label>
						<p class="description"><?php esc_html_e( 'Most reliable way to match replies. Gmail and Microsoft 365 support it. Turn off only if your mailbox rejects + addresses.', 'bonsai-support-tickets' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Check the mailbox', 'bonsai-support-tickets' ); ?></th>
					<td>
						<input type="hidden" name="bst[imap_enabled]" value="0">
						<label for="bst-imap-enabled"><input type="checkbox" class="bonsai-ui-toggle" id="bst-imap-enabled" name="bst[imap_enabled]" value="1" <?php checked( $s['imap_enabled'] ); ?>> <?php esc_html_e( 'Turn emails into tickets', 'bonsai-support-tickets' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bst-imap-host"><?php esc_html_e( 'IMAP server', 'bonsai-support-tickets' ); ?></label></th>
					<td>
						<input type="text" id="bst-imap-host" name="bst[imap_host]" value="<?php echo esc_attr( $s['imap_host'] ); ?>" class="regular-text">
						<label for="bst-imap-port" class="screen-reader-text"><?php esc_html_e( 'Port', 'bonsai-support-tickets' ); ?></label>
						<input type="number" id="bst-imap-port" name="bst[imap_port]" value="<?php echo esc_attr( $s['imap_port'] ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( 'Gmail: imap.gmail.com, port 993 (TLS).', 'bonsai-support-tickets' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bst-imap-mailbox"><?php esc_html_e( 'Folder', 'bonsai-support-tickets' ); ?></label></th>
					<td><input type="text" id="bst-imap-mailbox" name="bst[imap_mailbox]" value="<?php echo esc_attr( $s['imap_mailbox'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="bst-imap-label"><?php esc_html_e( 'Gmail label for processed emails', 'bonsai-support-tickets' ); ?></label></th>
					<td>
						<input type="text" id="bst-imap-label" name="bst[imap_processed_tag]" value="<?php echo esc_attr( $s['imap_processed_tag'] ); ?>" class="regular-text">
						<p class="description"><?php esc_html_e( 'Processed emails are marked read and given this label. Emails that fail get "Bonsai Support/Failed".', 'bonsai-support-tickets' ); ?></p>
					</td>
				</tr>
			</table>
		</section>
		<?php
	}

	/**
	 * Incoming email tab: mailbox status and setup steps. Printed outside the
	 * settings form because Test connection / Check now are forms of their own.
	 *
	 * @param array $s Settings.
	 */
	public static function render_inbound_status( array $s ) {
		$status = get_option( BST_Inbound::STATUS_OPTION, array() );
		?>
		<section class="bonsai-ui-card">
			<div class="bonsai-ui-card__head">
				<h2 class="bonsai-ui-card__title"><?php esc_html_e( 'Mailbox status', 'bonsai-support-tickets' ); ?></h2>
				<?php
				if ( ! BST_Settings::has_imap_credentials() ) {
					echo BST_Admin_UI::badge( __( 'No credentials', 'bonsai-support-tickets' ), 'error' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
				} elseif ( ! $s['imap_enabled'] ) {
					echo BST_Admin_UI::badge( __( 'Off', 'bonsai-support-tickets' ), 'neutral' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} elseif ( ! empty( $status['error'] ) ) {
					echo BST_Admin_UI::badge( __( 'Error', 'bonsai-support-tickets' ), 'error' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					echo BST_Admin_UI::badge( __( 'Working', 'bonsai-support-tickets' ), 'success' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</div>

			<dl class="bonsai-ui-status">
				<dt><?php esc_html_e( 'Credentials', 'bonsai-support-tickets' ); ?></dt>
				<dd><?php echo BST_Settings::has_imap_credentials() ? esc_html( BST_IMAP_USER ) : esc_html__( 'Not set — see below', 'bonsai-support-tickets' ); ?></dd>
				<dt><?php esc_html_e( 'Last check', 'bonsai-support-tickets' ); ?></dt>
				<dd><?php echo ! empty( $status['time'] ) ? esc_html( wp_date( 'j M Y H:i:s', (int) $status['time'] ) ) : esc_html__( 'Never', 'bonsai-support-tickets' ); ?></dd>
				<dt><?php esc_html_e( 'Last successful check', 'bonsai-support-tickets' ); ?></dt>
				<dd><?php echo ! empty( $status['last_success'] ) ? esc_html( wp_date( 'j M Y H:i:s', (int) $status['last_success'] ) ) : esc_html__( 'Never', 'bonsai-support-tickets' ); ?></dd>
				<?php if ( ! empty( $status['error'] ) ) : ?>
					<dt><?php esc_html_e( 'Last error', 'bonsai-support-tickets' ); ?></dt>
					<dd><?php echo esc_html( $status['error'] ); ?></dd>
				<?php endif; ?>
				<dt><?php esc_html_e( 'Next scheduled check', 'bonsai-support-tickets' ); ?></dt>
				<dd>
					<?php
					$next = wp_next_scheduled( BST_Cron::POLL_HOOK );
					echo $next ? esc_html( wp_date( 'H:i:s', $next ) ) : esc_html__( 'Not scheduled', 'bonsai-support-tickets' );
					if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
						echo ' — ' . esc_html__( 'WP-Cron is disabled, so a server cron must call wp-cron.php.', 'bonsai-support-tickets' );
					}
					?>
				</dd>
			</dl>

			<div class="bonsai-ui-card__footer bonsai-ui-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="bst_test_imap">
					<?php wp_nonce_field( 'bst_test_imap' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Test connection', 'bonsai-support-tickets' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="bst_check_mail">
					<?php wp_nonce_field( 'bst_check_mail' ); ?>
					<button type="submit" class="button" <?php disabled( ! $s['imap_enabled'] ); ?>><?php esc_html_e( 'Check mailbox now', 'bonsai-support-tickets' ); ?></button>
				</form>
			</div>
		</section>

		<section class="bonsai-ui-card">
			<h2 class="bonsai-ui-card__title"><?php esc_html_e( 'Setting up the Gmail mailbox', 'bonsai-support-tickets' ); ?></h2>
			<ol class="bst-steps">
				<li><?php esc_html_e( 'In the Google account, turn on 2-Step Verification (Security → 2-Step Verification).', 'bonsai-support-tickets' ); ?></li>
				<li><?php esc_html_e( 'Create an app password (Security → App passwords). Copy the 16-character password.', 'bonsai-support-tickets' ); ?></li>
				<li><?php esc_html_e( 'In Gmail → Settings → Forwarding and POP/IMAP, make sure IMAP is enabled.', 'bonsai-support-tickets' ); ?></li>
				<li><?php esc_html_e( 'Add these lines to wp-config.php, above "That\'s all, stop editing!":', 'bonsai-support-tickets' ); ?>
					<pre class="bst-code"><code>define( 'BST_IMAP_USER', 'bonsaisupport@gmail.com' );
define( 'BST_IMAP_PASSWORD', 'abcd efgh ijkl mnop' ); // App password, not the account password.</code></pre>
				</li>
				<li><?php esc_html_e( 'Turn on "Turn emails into tickets" above, save, then use Test connection.', 'bonsai-support-tickets' ); ?></li>
				<li><?php esc_html_e( 'For prompt checks on a quiet site, add a server cron job that runs every 2 minutes:', 'bonsai-support-tickets' ); ?>
					<pre class="bst-code"><code>*/2 * * * * curl -s <?php echo esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?> &gt; /dev/null</code></pre>
				</li>
			</ol>
		</section>
		<?php
	}

	/**
	 * Slack tab: the on/off switch (inside the tab's form).
	 *
	 * @param array $s Settings.
	 */
	public static function render_slack( array $s ) {
		?>
		<section class="bonsai-ui-card">
			<h2 class="bonsai-ui-card__title"><?php esc_html_e( 'Slack', 'bonsai-support-tickets' ); ?></h2>
			<p class="bonsai-ui-card__intro"><?php esc_html_e( 'Posts every new ticket to one Slack channel: reference, subject, client, priority, type, source and site, with a link to the ticket. The client\'s message is not sent to Slack.', 'bonsai-support-tickets' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'New tickets', 'bonsai-support-tickets' ); ?></th>
					<td>
						<input type="hidden" name="bst[slack_enabled]" value="0">
						<label for="bst-slack-enabled"><input type="checkbox" class="bonsai-ui-toggle" id="bst-slack-enabled" name="bst[slack_enabled]" value="1" <?php checked( $s['slack_enabled'] ); ?>> <?php esc_html_e( 'Post new tickets to Slack', 'bonsai-support-tickets' ); ?></label>
						<?php if ( ! BST_Slack::has_webhook() ) : ?>
							<p class="description"><?php esc_html_e( 'Nothing is sent until the webhook URL is added to wp-config.php (see below).', 'bonsai-support-tickets' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</section>
		<?php
	}

	/**
	 * Slack tab: status, test button and setup steps. Outside the settings
	 * form because Send test message is a form of its own.
	 *
	 * @param array $s Settings.
	 */
	public static function render_slack_status( array $s ) {
		$has_webhook = BST_Slack::has_webhook();
		?>
		<section class="bonsai-ui-card">
			<div class="bonsai-ui-card__head">
				<h2 class="bonsai-ui-card__title"><?php esc_html_e( 'Slack status', 'bonsai-support-tickets' ); ?></h2>
				<?php
				if ( ! $has_webhook ) {
					echo BST_Admin_UI::badge( __( 'No webhook', 'bonsai-support-tickets' ), 'error' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
				} elseif ( ! $s['slack_enabled'] ) {
					echo BST_Admin_UI::badge( __( 'Off', 'bonsai-support-tickets' ), 'neutral' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					echo BST_Admin_UI::badge( __( 'On', 'bonsai-support-tickets' ), 'success' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</div>

			<dl class="bonsai-ui-status">
				<dt><?php esc_html_e( 'Webhook', 'bonsai-support-tickets' ); ?></dt>
				<dd><?php echo $has_webhook ? esc_html__( 'Set in wp-config.php', 'bonsai-support-tickets' ) : esc_html__( 'Not set — see below', 'bonsai-support-tickets' ); ?></dd>
			</dl>

			<div class="bonsai-ui-card__footer bonsai-ui-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="bst_test_slack">
					<?php wp_nonce_field( 'bst_test_slack' ); ?>
					<button type="submit" class="button" <?php disabled( ! $has_webhook ); ?>><?php esc_html_e( 'Send test message', 'bonsai-support-tickets' ); ?></button>
				</form>
			</div>
		</section>

		<section class="bonsai-ui-card">
			<h2 class="bonsai-ui-card__title"><?php esc_html_e( 'Setting up the Slack webhook', 'bonsai-support-tickets' ); ?></h2>
			<ol class="bst-steps">
				<li><?php esc_html_e( 'Go to api.slack.com/apps → Create New App → From scratch, and pick your workspace.', 'bonsai-support-tickets' ); ?></li>
				<li><?php esc_html_e( 'Under Incoming Webhooks, turn them on, then Add New Webhook to Workspace and choose the channel.', 'bonsai-support-tickets' ); ?></li>
				<li><?php esc_html_e( 'Copy the webhook URL and add this line to wp-config.php, above "That\'s all, stop editing!":', 'bonsai-support-tickets' ); ?>
					<pre class="bst-code"><code>define( 'BST_SLACK_WEBHOOK_URL', 'https://hooks.slack.com/services/…' );</code></pre>
				</li>
				<li><?php esc_html_e( 'Use Send test message above. To change channel, create a new webhook and swap the URL.', 'bonsai-support-tickets' ); ?></li>
			</ol>
		</section>
		<?php
	}

	/**
	 * Front end tab: shortcode and template reference (no settings).
	 *
	 * @param array $s Settings.
	 */
	public static function render_frontend( array $s ) {
		unset( $s ); // Same signature as the other tabs; nothing to configure here.
		?>
		<section class="bonsai-ui-card">
			<h2 class="bonsai-ui-card__title"><?php esc_html_e( 'Front end', 'bonsai-support-tickets' ); ?></h2>
			<dl class="bonsai-ui-status">
				<dt><code>[bst_my_tickets]</code></dt>
				<dd><?php esc_html_e( 'The client\'s requests, and a single request when the URL has ?ticket=ID.', 'bonsai-support-tickets' ); ?></dd>
				<dt><code>[bst_submit_form]</code></dt>
				<dd><?php esc_html_e( 'Submit a request form.', 'bonsai-support-tickets' ); ?></dd>
				<dt><code>[bst_register]</code></dt>
				<dd><?php esc_html_e( 'Client registration form, for a page of its own.', 'bonsai-support-tickets' ); ?></dd>
				<dt><code>[bst_help_centre]</code></dt>
				<dd><?php esc_html_e( 'Help centre search and topics. Articles also have their own archive at /help/.', 'bonsai-support-tickets' ); ?></dd>
				<dt><?php esc_html_e( 'Theme overrides', 'bonsai-support-tickets' ); ?></dt>
				<dd><?php echo wp_kses( __( 'Copy any file from the plugin\'s <code>templates/</code> folder to <code>your-theme/bonsai-support/</code>.', 'bonsai-support-tickets' ), array( 'code' => array() ) ); ?></dd>
			</dl>
		</section>
		<?php
	}

	/**
	 * Page dropdown.
	 *
	 * @param string    $key      Setting key.
	 * @param string    $id       Element ID.
	 * @param WP_Post[] $pages    Pages.
	 * @param int       $selected Selected page ID.
	 */
	private static function page_select( $key, $id, $pages, $selected ) {
		?>
		<select id="<?php echo esc_attr( $id ); ?>" name="bst[<?php echo esc_attr( $key ); ?>]">
			<option value="0"><?php esc_html_e( 'Choose a page', 'bonsai-support-tickets' ); ?></option>
			<?php foreach ( $pages as $page ) : ?>
				<option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected( $selected, $page->ID ); ?>><?php echo esc_html( $page->post_title ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}
}

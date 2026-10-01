# Bonsai Support Tickets

Support ticketing for The Bonsai Digital Collective, built to replace Zendesk on
`support.bonsaidigitalcollective.co.uk`.

- **Clients** log in to see and reply to their own requests, raise new ones and mark them solved.
- **Agents** work tickets in wp-admin: assign, prioritise, set status, reply to the client or add **internal notes** the client never sees.
- **Email in and out**: every update is emailed, clients can reply by email, and emails to the support mailbox become tickets.
- **Help centre**: articles grouped by topic, searchable, at `/help/`.

## Requirements

- WordPress 6.2+, PHP 8.1+
- No ACF dependency. Works with any theme. The front end is meant to be themed by a bespoke Bonsai theme.

## Setup

1. Upload and activate. This creates the tables, the **Support Client** and **Support Agent** roles, and the default ticket types.
2. Create three pages:

   | Page | Content |
   |---|---|
   | My requests | `[bst_my_tickets]` |
   | Submit a request | `[bst_submit_form]` |
   | Help | `[bst_help_centre]` |

3. **Support → Settings**: choose those pages, and set the From address. Use an address on your own domain, not the Gmail one.
4. Clients can **register** themselves (see below), or you add them under **Users → Add New** with the role **Support Client** and fill in **Client name** on their profile.
5. Add team members as **Support Agent**. Administrators are agents automatically.

### Client registration

The log-in box on the portal and submit pages has a **Register** link (switch it off in **Support → Settings**). The form asks for client name (the business, e.g. *The Ley Arms*), first and last name, email, website and phone.

1. The account is created as a **Support Client**, *awaiting approval*. It can't log in or reset its password yet, and email from that address is treated as unknown (Unverified ticket).
2. The applicant gets a "we've received your registration" email, and everyone who can approve gets a "new sign-up" email.
3. **Support → Sign-ups** (agents and admins): **Approve** emails the client a link to set their password. **Reject** deletes the account (only pending, ticket-less client accounts can be deleted this way).

Each person has their own account and sees only their own requests. Client name is a label shown in the ticket list, ticket header, Client dropdown and Users list. Spam protection is a honeypot, a minimum fill time and 5 sign-ups per IP per hour, so there's no CAPTCHA. `[bst_register]` puts the form on a page of its own.

### Incoming email (Gmail)

1. In the Google account, turn on 2-Step Verification and create an **app password**.
2. Make sure IMAP is enabled in Gmail settings.
3. Add the credentials to `wp-config.php`. They are never stored in the database.

   ```php
   define( 'BST_IMAP_USER', 'bonsaisupport@gmail.com' );
   define( 'BST_IMAP_PASSWORD', 'abcd efgh ijkl mnop' );
   ```

4. **Support → Settings → Incoming email**: turn on "Turn emails into tickets", save, then click **Test connection**.
5. Add a server cron job so the mailbox is checked every 2 minutes even when nobody is visiting the site:

   ```
   */2 * * * * curl -s https://support.bonsaidigitalcollective.co.uk/wp-cron.php?doing_wp_cron > /dev/null
   ```

   Then add `define( 'DISABLE_WP_CRON', true );` to `wp-config.php`.

### How replies are matched

- Every email we send has `Reply-To: bonsaisupport+t{ID}-{token}@gmail.com`. Gmail delivers it to the normal inbox, and the secret token identifies the ticket.
- Without a token, a `[BDC-1042]` in the subject is accepted **only** from the ticket's client or an agent. Anyone else starts a new ticket.
- Email from an unknown address creates an **Unverified** ticket. It gets no automatic emails until an agent links it to a client.
- Auto-replies, bounces, mailing lists and duplicate emails are ignored.
- Agents can reply from their inbox. Starting the reply with `#note` makes it an internal note.

## Theming

Copy any file from `templates/` to `your-theme/bonsai-support/` and edit it there. Keep the form field names, nonces and `action` inputs.

To turn off the plugin's front-end CSS:

```php
add_filter( 'bst_load_frontend_css', '__return_false' );
```

The default CSS uses the Bonsai theme's custom properties (`--bonsai-accent`, `--bonsai-sans`, …) and falls back to the brand values when they aren't defined.

Template functions, all permission-checked:

| Function | Returns |
|---|---|
| `bst_get_client_tickets( $user_id, 'active'\|'resolved'\|'all' )` | Client's tickets |
| `bst_get_ticket_thread( $ticket_id )` | Client-visible messages, never internal notes |
| `bst_user_can_view_ticket( $ticket_id )` | bool |
| `bst_get_ticket_ref()`, `bst_get_ticket_status()`, `bst_get_status_label()` | Ticket details |
| `bst_get_ticket_url()`, `bst_get_portal_url()`, `bst_get_submit_url()`, `bst_get_register_url()` | URLs (register is `''` when switched off) |
| `bst_get_client_name( $user_id )` | Client (business) name |
| `bst_get_message_attachments()`, `bst_get_attachment_url()` | Files |
| `bst_get_template( $name, $args )` | Renders a template (with theme override) |

## Filters and actions

| Hook | Use |
|---|---|
| `bst_statuses`, `bst_priorities`, `bst_active_statuses` | Change status/priority lists |
| `bst_default_ticket_types` | Ticket types created on first activation |
| `bst_allowed_attachment_types` | Extension → MIME map for uploads |
| `bst_agent_notification_recipients` | Which agents get emailed |
| `bst_email` | Change any outgoing email before it's sent |
| `bst_settings_defaults` | Change setting defaults |
| `bst_ticket_created`, `bst_message_added`, `bst_status_changed`, `bst_ticket_assigned` | Actions for integrations (e.g. Slack later) |
| `bst_registration_notify_recipients` | Who is emailed about new sign-ups |
| `bst_client_registered`, `bst_client_approved`, `bst_client_rejected` | Registration actions |

## Security notes

- Attachments are stored in `wp-content/uploads/bst-private/` with random names and a `.bin` extension, and are only served through a permission check. The folder's `.htaccess` blocks direct access on Apache/LiteSpeed. **On nginx**, add:

  ```nginx
  location ^~ /wp-content/uploads/bst-private/ { deny all; }
  ```

- Clients are kept out of wp-admin and don't see the admin bar.
- Self-registered accounts can't log in, reset their password or submit until approved. The emailed set-password link is what proves they own the address. Login names are generated (`client-…`) so email addresses never appear in author slugs.
- Uninstalling removes settings and roles. Tickets, messages and files are only deleted if `BST_REMOVE_ALL_DATA` is `true` in `wp-config.php`.

## Development

```bash
composer install          # includes PHPUnit
composer test             # unit tests: email parsing, no WordPress needed
WP_TESTS_DIR=/path/to/wordpress-tests-lib vendor/bin/phpunit --testsuite integration
composer install --no-dev # before committing — vendor/ is committed
```

Manual pre-launch checklist: [tests/manual/support-flow.md](tests/manual/support-flow.md).

## Releasing

1. Bump `Version:` and `BST_VERSION` in `bonsai-support-tickets.php`, and add a `CHANGELOG.md` entry.
2. Merge `develop` into `main` and push.
3. Publish a GitHub Release tagged with the version, with a zip whose top folder is `bonsai-support-tickets/`. Release-assets mode is on.

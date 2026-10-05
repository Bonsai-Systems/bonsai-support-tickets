# Support Desk

Support ticketing for WordPress, sold as a white-label product: everything your
clients see (portal, emails, login) carries **your** name, logo and colours.
*Support Desk* is a working product name — see [Product identity](#product-identity).

- **Clients** log in to see and reply to their own requests, raise new ones and mark them solved.
- **Agents** work tickets in wp-admin: assign, prioritise, set status, reply to the client or add **internal notes** the client never sees.
- **Email in and out**: every update is emailed, clients can reply by email, and emails to the support mailbox become tickets.
- **Help centre**: articles grouped by topic, searchable, at `/help/`.

## Requirements

- WordPress 6.2+, PHP 8.1+
- No ACF dependency. Works with any theme; ships with a companion theme.

## Setup

1. Upload and activate. This creates the tables, the **Support Client** and **Support Agent** roles, and the default ticket types.
2. **Support → Settings → General** shows a **Get set up** checklist. Work through it:
   - **Appearance**: your support name (shown to clients; blank uses the site title), logo and brand colours.
   - **Create the pages for me** adds *My requests* (`[bst_my_tickets]`) and *Submit a request* (`[bst_submit_form]`) and selects them. The help centre lives at `/help/`, or put `[bst_help_centre]` on a page.
   - **Outgoing email**: the From address. Use an address on your own domain, not a Gmail one.
   - Optional: a support mailbox (below) and brand colours.

   Until the required steps are done, admins see a "Finish setting up" notice on the dashboard and support screens.
4. Add your clients (the businesses) under **Support → Clients**. People can **register** themselves (see below), or you add them under **Users → Add New** with the role **Support Client** and choose their **Client** on their profile.
5. Add team members as **Support Agent**. Administrators are agents automatically.

### Support overview

**Support → Overview** is the team's home screen: headline numbers (my open tickets, unassigned, new, overdue when SLAs are on, awaiting client, on hold, unverified), **Due next** (SLAs on), **My tickets**, **Unassigned, oldest first**, open uptime alerts, pending sign-ups, clients at 80%+ of their retainer (time tracking on, administrators) and the latest activity. Every number and panel links to the matching filtered ticket list.

By default it **replaces the WordPress dashboard** for anyone who can reply to tickets: they land on it after logging in (unless they were heading to a specific page), the Dashboard redirects to it, and so does the admin bar's Dashboard link. Agents no longer see the Dashboard menu; administrators keep it so **Dashboard → Updates** stays reachable. Editors, other users and clients are not affected. Switch it off under **Settings → General → Dashboard**.

### Client registration

The log-in box on the portal and submit pages has a **Register** link (switch it off in **Support → Settings**). The form asks for client name (the business, e.g. *The Ley Arms*), first and last name, email, website and phone.

1. The account is created as a **Support Client**, *awaiting approval*. It can't log in or reset its password yet, and email from that address is treated as unknown (Unverified ticket).
2. The applicant gets a "we've received your registration" email, and everyone who can approve gets a "new sign-up" email.
3. **Support → Sign-ups** (agents and admins): choose which client the person belongs to (a close match to the name they typed is suggested, or **New client**), then **Approve**. That emails them a link to set their password. **Reject** deletes the account (only pending, ticket-less client accounts can be deleted this way).

Each person has their own account and sees only their own requests, even if colleagues share a client. Spam protection is a honeypot, a minimum fill time and 5 sign-ups per IP per hour, so there's no CAPTCHA. `[bst_register]` puts the form on a page of its own.

### Clients

**Support → Clients** holds one record per business, e.g. *The Ley Arms*. Each record has:

- **Partner** and **Plan**, picked from lists that admins edit. The links are above the Clients list.
- **Retainer** hours a month. Time tracking against it comes later.
- **Websites**, one per line.
- **Notes**, visible to the team only.
- The **People** linked to it, and its ticket counts.

Each person belongs to one client, set on their user profile or when you approve their sign-up. Tickets record the client they were raised for. That's stamped from the person when the ticket is created and updated if you change the ticket's **Contact**. You can also override it with the ticket's **Client** field. A ticket keeps its client if the person later moves, so reports stay right. You can filter the ticket list by client.

Clients don't change permissions: people only ever see their own tickets.

**Upgrading from 0.1:** on the first admin page load after updating, every distinct *Client name* typed on a user becomes a client record. Names are matched ignoring case and spacing, so "the ley arms " joins "The Ley Arms". People are linked and their existing tickets are stamped. Pending sign-ups are left for approval. A notice reports the counts. Check **Support → Clients** for near-duplicates such as "Ley Arms" and "The Ley Arms", and move people between them on their profile.

### Incoming email (Gmail)

1. In the Google account, turn on 2-Step Verification and create an **app password**.
2. Make sure IMAP is enabled in Gmail settings.
3. Add the credentials to `wp-config.php`. They are never stored in the database.

   ```php
   define( 'BST_IMAP_USER', 'support@example.com' );
   define( 'BST_IMAP_PASSWORD', 'abcd efgh ijkl mnop' );
   ```

4. **Support → Settings → Incoming email**: turn on "Turn emails into tickets", save, then click **Test connection**.
5. Add a server cron job so the mailbox is checked every 2 minutes even when nobody is visiting the site:

   ```
   */2 * * * * curl -s https://support.example.com/wp-cron.php?doing_wp_cron > /dev/null
   ```

   Then add `define( 'DISABLE_WP_CRON', true );` to `wp-config.php`.

### How replies are matched

- Every email we send has `Reply-To: support+t{ID}-{token}@example.com` (your mailbox, plus-addressed). Gmail delivers it to the normal inbox, and the secret token identifies the ticket.
- Without a token, a `[SUP-1042]` in the subject is accepted **only** from the ticket's client or an agent. Anyone else starts a new ticket.
- Email from an unknown address creates an **Unverified** ticket. It gets no automatic emails until an agent links it to a client.
- Auto-replies, bounces, mailing lists and duplicate emails are ignored.
- Agents can reply from their inbox. Starting the reply with `#note` makes it an internal note.

### Slack

Every new ticket (web form, email or added by the team) is posted to one Slack channel with its reference, subject, client, priority, type, source and site, and a link to the ticket. The client's message isn't sent. Tickets from unknown senders are flagged.

1. At api.slack.com/apps, create an app for your workspace, turn on **Incoming Webhooks** and add a webhook for the channel.
2. Add it to `wp-config.php`. Like the IMAP credentials, it's never stored in the database:

   ```php
   define( 'BST_SLACK_WEBHOOK_URL', 'https://hooks.slack.com/services/…' );
   ```

3. Under **Support → Settings → Slack**, use **Send test message**. The same tab switches it on or off.

Posts are fire-and-forget, so a slow or down Slack never delays ticket creation. Failures go to the PHP error log. To change the message, use the `bst_slack_ticket_payload` filter. To supply the URL some other way, use `bst_slack_webhook_url`.

### Uptime monitoring

Site-down alerts can open tickets automatically. **Support → Settings → Uptime monitoring** has two sources, each with its own on/off switch (both off by default):

- **Status monitor**: the hosted uptime monitor. It sends signed alerts (HMAC-SHA256 with a shared secret, a timestamp checked to ±5 minutes, and each signature accepted once).
- **UptimeRobot**: a webhook alert contact. UptimeRobot can't sign requests, so the secret is a `key` in the webhook URL. The tab shows the URL and the JSON body to paste in.

What happens:

| Alert | Result |
|---|---|
| Site down | One **Urgent** ticket per site and source, filed under the client whose **Websites** include the site (matched on host, `www.` ignored; no match or two matches = no client). Another down while it's open adds an internal note. |
| Back up | Internal note on the open ticket with the downtime. The ticket stays open so someone checks why it went down. |
| SSL / domain expiring | One **Normal** ticket per site and kind while open. |

Monitor tickets have no client account, so clients are never emailed. Agents get the usual new-ticket email, and the Slack post if Slack is on. Generate each secret on the tab, or set `BST_MONITOR_SECRET` / `BST_UPTIMEROBOT_KEY` in `wp-config.php` to override. A switched-off source answers 404. The tab shows the last alert received from each source.

Endpoints: `POST /wp-json/bst/v1/monitor/status` and `POST /wp-json/bst/v1/monitor/uptimerobot?key=…`. A "Don't have uptime monitoring yet?" link appears on the tab once `BST_MONITOR_PROMO_URL` is set (or via the `bst_monitor_promo_url` filter).

### Time tracking and retainers

Off by default. Turn it on under **Support → Settings → Time tracking**. Turning it off hides every time screen and field; logged time is kept.

- **Log time** sits in the ticket reply box. Type `30m`, `1h 15m`, `1:30` or `1.5` (a bare number means hours, so `30` is rejected as 30 hours), pick the date and tick **Billable** or not. Add an optional team-only note. It's saved with your reply, or on its own if the message is empty.
- Each ticket has a **Time** box listing its entries and the client's retainer this month. Agents edit and delete their own entries; administrators can edit and delete anyone's.
- **Retainer hours a month** is set on each client (Support → Clients). Only **billable** time counts against it, and it resets on the 1st (site timezone). The client screen and the Clients list show used and remaining time, with a bar that turns amber at 80% and red at 100%.
- Entries are stamped with the ticket's client when logged, so a ticket moving client later doesn't rewrite past months. Time logged while a ticket had no client follows it to its first client.
- **Alerts:** administrators are emailed (and Slack gets a post, if it's on) the first time a client reaches 80% and 100% in the current month. Back-dated time doesn't alert. Clients aren't told. Change recipients with `bst_time_alert_recipients`.
- **Support → Time** (administrators): each month's clients with retainer, billable, non-billable and usage. Click a client for their entries. **Export summary (CSV)** and **Export all entries / Export CSV** produce invoicing spreadsheets (decimal hours, UTF-8, formula-safe). Agents see their own month's time there.
- **Client portal** (optional, its own switch): clients on a retainer see "Support hours · October 2026 — 9h 45m of 10h used, 15m left" on My requests. Totals only, never entries or notes.

### SLAs and response reminders

Both off by default, with separate switches under **Support → Settings → SLAs & reminders**.

**Business hours** (shared by both): working days and opening times in the site's timezone, UK bank holidays from gov.uk for the chosen region (refreshed weekly, or **Update bank holidays now**) and any other closed days. A ticket raised at 5pm on Friday isn't late at 9am on Monday.

**SLAs**
- Each priority has a first-response and a resolution target, in hours, counted on business hours or 24/7. A working day is the length of your opening hours (8.5 hours for 09:00–17:30).
- **First response** is met by the first reply to the client; internal notes don't count. **Resolution** is met by Solved. The resolution clock pauses while a ticket is Awaiting client or On hold (an agent reply moves New/Open tickets to Awaiting client) and restarts when it comes back.
- **Plans** can have their own targets (Support → Clients → Plans), so Gold clients can get faster responses than Bronze. The ticket's client decides the plan.
- The ticket list gets a **Due** column (the deadline, amber when close, red when overdue; sortable). Each ticket has an **SLA** box with its targets and status.
- When a target gets close (default: 25% of the time left) and again if it's missed, the assignee is emailed, or every agent if it's unassigned. Slack gets a post too, if it's on. Each is sent once per ticket.
- **Support → SLA report**: per month, the percentage of tickets that met each target, by priority, plan and client. **Export tickets (CSV)** gives one row per ticket.
- Only tickets created after SLAs are switched on are measured. Uptime-monitor tickets never are.

**Response reminders**
- While a ticket is **New** or **Open**, the assignee (or every agent) is emailed after the set time, then repeatedly at the interval you choose. Leave "Then every" blank for a single reminder, and "First reminder" blank to switch a priority off.
- Waiting time is counted on the priority's clock and restarts whenever the client replies. Awaiting client, On hold, Solved and Closed tickets never get reminders.

The checks run every 5 minutes via WP-Cron. On low-traffic sites, use a real cron job to call `wp-cron.php` so emails go out on time.

### Canned responses

Saved replies the team inserts into the ticket reply box. Manage them under **Support → Canned responses**; group them with **Tags** (linked above the list).

- It's one shared library: every agent can add, edit and delete any reply. Clients never see the library.
- On a ticket, **Insert a canned response** drops the chosen reply in at the cursor, grouped by tag. You can edit it before sending, and it works for client replies and internal notes. A filter box appears once there are more than 10 replies.
- Placeholders are filled for the ticket you're on: `{{client.name}}`, `{{ticket.id}}`, `{{ticket.title}}`, `{{agent.name}}` (you) and `{{site.name}}`, plus anything added with `bst_email_placeholders`. One with no value yet (e.g. on a brand-new ticket) is left as typed, so you can spot and fill it.
- The reply text is plain text, like ticket messages.
- Six neutral starter replies are created once on install (or on upgrading to DB version 5): please send a screenshot, login details needed, DNS can take 48 hours, updates done, still working on it, closing as solved. Edit or delete them freely; they don't come back. Change the set for new installs with `bst_default_canned_responses`.

### Auto-reply

When a client raises a new request, through the form or by emailing the support mailbox, they get an auto-reply. Edit the subject and message under **Support → Settings → Auto-reply**, or switch it off there.

- It's sent once, when the ticket is created. Replies don't trigger it, and unknown senders never get it.
- Placeholders: `{{site.name}}` (your support name), `{{ticket.title}}`, `{{ticket.id}}` (the reference, e.g. `SUP-1042`) and `{{client.name}}`. Add more with the `bst_email_placeholders` filter.
- The `[SUP-1042]` reference is always added to the start of the subject, because reply matching depends on it. The client's message and a **View your request** button come after your text.
- Clear the subject or message and save to go back to the default.

## Theming

Copy any file from `templates/` to `your-theme/support-desk/` and edit it there. Keep the form field names, nonces and `action` inputs.

To turn off the plugin's front-end CSS:

```php
add_filter( 'bst_load_frontend_css', '__return_false' );
```

Every colour is a token in `.bst {}`. The font follows `--bst-font-family` if your theme sets it, otherwise the system font.

### Branding

Everything clients see comes from **Support → Settings**:

- **Appearance**: support name, logo (media picker; blank shows the name as text in emails) and seven brand colours (accent, button hover, accent text, buttons and headings, body text, page background, cards). Colours are printed as `.bst { --bst-… }` after the stylesheet and used in every email. Blank uses the neutral default. A pair below WCAG AA (4.5:1) shows a warning. Success, warning and error colours are fixed so they stay readable.
- **Outgoing email**: From name (blank = support name) and address.
- **Auto-reply**: wording, with `{{site.name}}`.
- **General**: reference prefix (default `SUP`).

Template functions, all permission-checked:

| Function | Returns |
|---|---|
| `bst_get_client_tickets( $user_id, 'active'\|'resolved'\|'all' )` | Client's tickets |
| `bst_get_ticket_thread( $ticket_id )` | Client-visible messages, never internal notes |
| `bst_user_can_view_ticket( $ticket_id )` | bool |
| `bst_get_ticket_ref()`, `bst_get_ticket_status()`, `bst_get_status_label()` | Ticket details |
| `bst_get_ticket_url()`, `bst_get_portal_url()`, `bst_get_submit_url()`, `bst_get_register_url()` | URLs (register is `''` when switched off) |
| `bst_get_client_name( $user_id )` | Client (business) name: their client record, or the name typed at sign-up |
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
| `bst_email_placeholders` | Add `{{placeholders}}` for the auto-reply and canned responses |
| `bst_default_canned_responses` | Starter canned responses created on first install |
| `bst_time_alert_recipients` | Email addresses for retainer alerts (default: users with `bst_manage_time`) |
| `bst_time_logged` | Action after time is logged (entry ID, entry row) |
| `bst_ticket_company_changed` | Action when a ticket moves client (ticket ID, old, new) |
| `bst_priority_changed` | Action when a ticket's priority changes (ticket ID, old, new); SLA due times follow it |
| `bst_sla_now` | "Now" (Unix time) for SLA and reminder sums. For tests |
| `bst_settings_defaults` | Change setting defaults |
| `bst_overview_stats` | Add, remove or reorder the numbers at the top of Support → Overview (each: label, count, url, tone) |
| `bst_ticket_created`, `bst_message_added`, `bst_status_changed`, `bst_ticket_assigned` | Actions for integrations (the Slack post listens to `bst_ticket_created`) |
| `bst_monitor_promo_url` | "Get uptime monitoring" link on the Uptime monitoring tab (`''` hides it) |
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

## Product identity

- The product name shown in wp-admin and logs is `BST_PRODUCT_NAME` in `bonsai-support-tickets.php` (with `BST_PRODUCT_URL` for a help link). Rename it there and in the plugin header before launch.
- Code prefixes (`bst_`, `BST_`), the plugin folder, the text domain and the `bonsai-ui` admin CSS classes are internal and not yet renamed; the folder/text domain rename happens with the final name, in the packaging step.
- `includes/legacy/` is **development builds only** and must be excluded from the product zip. It keeps the original Bonsai support site's branding when it upgrades from the old Bonsai defaults (DB version 3 → 4), including copying its logo into the Media Library.

## Releasing

1. Bump `Version:` and `BST_VERSION` in `bonsai-support-tickets.php`, and add a `CHANGELOG.md` entry.
   (Updates currently come from GitHub releases; licensed updates replace this.)
2. Merge `develop` into `main` and push.
3. Publish a GitHub Release on `main` tagged `vX.Y.Z`. `.github/workflows/release.yml` checks the tag matches both version numbers, builds `bonsai-support-tickets.zip` (production `vendor/`, no tests or dev files) and attaches it. Sites update from that zip (release-assets mode).

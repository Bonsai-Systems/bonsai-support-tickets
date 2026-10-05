# Changelog

All notable changes to this plugin are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- Editable auto-reply for new tickets (web form and email in), under **Support → Settings → Auto-reply**: on/off, subject and rich-text message, with `{{site.name}}`, `{{ticket.title}}`, `{{ticket.id}}` and `{{client.name}}` placeholders. Ships with neutral wording as the default. Clearing a field restores the default.
- `bst_email_placeholders` filter.
- Email layout: `$body_html` slot for editable content. The heading is now optional.
- **Support → Settings → Appearance**: brand colours (accent, button hover, accent text, buttons and headings, body text, page background, cards) with the core colour picker, so the plugin can be rebranded per install. They drive the front-end CSS (printed inline after `bst-frontend.css`) and the HTML emails. Blank or default values fall back to the default. Status colours stay fixed.
- Contrast warnings on the Appearance tab when a chosen pair falls below WCAG AA (4.5:1).
- `BST_Appearance` class: colour fields, `color()`, `inline_css()`, `email_colors()` and the contrast maths (unit tested).
- Email layout: `$colors` variable.
- Slack: every new ticket is posted to one channel through an incoming webhook (`BST_SLACK_WEBHOOK_URL` in `wp-config.php`). The post has the reference, subject, client, priority, type, source, site and a link to the ticket, and flags unknown senders. The message body is never sent. Sends are non-blocking, and failures are logged.
- **Support → Settings → Slack**: on/off switch, status, **Send test message** and setup steps.
- `bst_slack_ticket_payload` and `bst_slack_webhook_url` filters.
- Integration tests for Slack (HTTP faked) and manual checklist steps.
- **Support → Clients**: one record per business (post type `bst_company`), with partner, plan, monthly retainer hours, websites, notes, linked people and ticket counts. Partners and plans are admin-managed lists (`bst_partner`, `bst_plan`), linked from above the Clients list.
- Tickets record their client (`_bst_company_id`). It's set when the ticket is created, follows the contact when relinked, and can be overridden on the ticket screen (logged). The ticket list has a client filter, and the Client column links to it.
- Sign-up approval asks which client the person belongs to. It suggests close matches to the typed name, with a **New client** option.
- `BST_Companies` (data) and `BST_Admin_Companies` (screens). `BST_Tickets::create()` accepts `company_id`. New `BST_Tickets::set_company()` and `BST_Clients::typed_name()`.
- Integration tests for clients, the migration and permissions.
- **White-label product groundwork.** Nothing a buyer's clients see names a company:
  - **Appearance → Brand**: support name (blank = site title) and logo with a media picker. With no logo, emails show the support name as text.
  - **Get set up** checklist on Settings → General, with **Create the pages for me** (My requests and Submit a request) and a dismissible "Finish setting up" notice until the essentials are done.
  - `{{site.name}}` placeholder. `BST_Settings::brand_name()` and `BST_Settings::from_name()`.
  - `BST_PRODUCT_NAME` / `BST_PRODUCT_URL` for the product name in wp-admin and logs.
  - `bst_upgraded` action (after an upgrade, with the previous DB version).
- **Uptime monitoring → tickets** (`BST_Monitoring`), under **Support → Settings → Uptime monitoring**, with separate on/off switches for the status monitor and UptimeRobot (both off by default):
  - REST endpoints `bst/v1/monitor/status` (HMAC-signed, timestamp window, replay protection) and `bst/v1/monitor/uptimerobot` (key in the URL). A switched-off source answers 404.
  - Down → one Urgent ticket per site, matched to a client by its Websites. Repeat downs while open add a note. Up → internal note with the downtime, status unchanged. SSL/domain expiry → Normal ticket.
  - Monitor tickets are never emailed to clients and aren't marked Unverified.
  - Secret generation on the tab, `BST_MONITOR_SECRET` / `BST_UPTIMEROBOT_KEY` overrides, last-alert status, setup steps.
  - Hidden "get uptime monitoring" link: `BST_MONITOR_PROMO_URL` / `bst_monitor_promo_url`.
- `BST_Companies::find_by_website()`. `BST_Tickets::reply()` accepts `system` for notes written by the plugin itself. Message/ticket source `monitor`.
- Integration tests for uptime monitoring.

### Changed
- The auto-reply replaces the fixed "We have received your request" email. It's still only sent on ticket creation and never to unverified senders.
- Plain-text email alternatives now show list items as `- ` bullets.
- **Support → Settings** now has a left-hand tab nav (General, Outgoing email, Auto-reply, Incoming email, Front end), one tab per page load (`&tab=…`). Each tab saves only its own fields, and you return to the same tab after saving, Test connection or Check now.
- `BST_Settings::save()` accepts partial input. Fields that aren't posted keep their saved values.
- `BST_Admin_Settings::url()` takes an optional tab slug.
- Front-end CSS: every colour is now a token in `.bst {}`. The input border moved to `--bst-input-border`.
- The free-text **Client name** on user profiles is replaced by a **Client** dropdown. `BST_Clients::client_name()` and `bst_get_client_name()` return the client record's name, falling back to the name typed at sign-up.
- Ticket screen: the person field is now **Contact**, grouped by client, with a separate **Client** field. Slack posts show "Client — Contact".
- Neutral defaults: reference prefix `SUP`, blank From name (uses the support name) and support mailbox, `Support/Processed` Gmail label, neutral auto-reply wording, and an indigo/grey colour palette (all pairs pass WCAG AA).
- The plugin header, admin header and banner show the product name and a neutral mark. The Bonsai logo, links and wording are gone from shipped code.
- The failed-email Gmail label is now a sibling of the processed label (`Support/Failed`).
- Theme template overrides now live in `your-theme/support-desk/` (was `bonsai-support/`).
- Front-end CSS no longer reads the theme's `--bonsai-*` properties. The font follows `--bst-font-family`.
- Admin screens use the product palette (overriding the shared admin design system's tokens, not the file).
- DB version 4. Development builds keep the original Bonsai site's branding through the upgrade (`includes/legacy/`, excluded from the product zip): prefix, names, mailbox, Gmail label, auto-reply wording, colours and logo (copied into the Media Library) are saved explicitly where they weren't already.
- DB version 3. On upgrade, existing client names become client records (matched ignoring case and spacing). People are linked, tickets are backfilled, pending sign-ups are skipped, and a notice reports the counts. The old `bst_client_name` meta is kept.

### Notes
- A theme override of `emails/layout.php` must add the `$body_html` block, or the auto-reply text won't appear. It also needs to use `$colors` if the Appearance colours should reach emails.

## [0.1.0] - 2026-10-01

### Added
- Tickets with references (`BDC-1001` onwards), statuses (New, Open, Awaiting client, On hold, Solved, Closed), priorities, assignee, ticket type and site URL.
- Support Client and Support Agent roles. Clients see only their own tickets and are kept out of wp-admin.
- Admin ticket screen: conversation, reply box with **Reply to client** / **Internal note** modes, details sidebar and activity log.
- Ticket list: Active / Mine / Unassigned / Unverified / Solved views, filters, reference search, bulk assign/solve/close, and a "waiting on us" menu count.
- Private attachments (uploads and email) served only after a permission check.
- Outbound HTML email via `wp_mail()` for new tickets, replies, internal notes (to the assignee), assignment and solved, threaded per ticket.
- Inbound email from a Gmail/IMAP mailbox every 2 minutes. Reply matching by secret plus-address token, then by subject reference (sender-checked). Unknown senders create unverified tickets. Auto-replies and duplicates are skipped. `#note` support for agents.
- Built-in IMAP client and MIME parser, with no extension or Composer dependencies.
- Front end: `[bst_my_tickets]`, `[bst_submit_form]`, `[bst_help_centre]` shortcodes, theme-overridable templates and template functions.
- Help centre: Help articles post type (`/help/`) with topics.
- Settings screen in the Bonsai admin design system, with mailbox status, Test connection and Check now.
- Auto-close of solved tickets after a configurable number of days.
- Unit tests (email parsing), WordPress integration tests (permissions, inbound) and a manual checklist.
- Client registration: Register link under the log-in form and `[bst_register]`. The form takes client name, first/last name, email, website and phone. Accounts are Support Clients awaiting approval, with honeypot, minimum fill time and per-IP limit.
- **Support → Sign-ups** screen to approve (emails a set-password link) or reject sign-ups, with a menu count. New `bst_approve_clients` capability for agents and admins.
- Client name and phone fields on client accounts, shown in the ticket list, ticket header, Client dropdown (sorted by client) and a Users column.
- Account emails (registration received, new sign-up, account ready) using the ticket email design without a reference.
- Settings: Client registration on/off.
- `bst_get_register_url()`, `bst_get_client_name()`.

### Fixed
- Help topic pages (`/help/topic/{topic}/`) returned 404: the topic taxonomy is now registered before the Help articles post type, so its rewrite rules are not swallowed by the article attachment rule. Re-save Settings → Permalinks (or reactivate) on any site that already had the plugin active.

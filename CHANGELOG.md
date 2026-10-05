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
- **Canned responses** (`bst_canned` post type, `bst_canned_tag` tags) under **Support → Canned responses**:
  - One shared library; every agent can add, edit and delete them (own `bst_canned_response` capabilities, so agents and admins only).
  - **Insert a canned response** picker in the ticket reply box, grouped by tag, inserting at the cursor. A filter box appears above 10 replies.
  - Placeholders filled for the current ticket: the auto-reply set plus `{{agent.name}}`. Empty values are left as typed.
  - Plain-text reply editor with a placeholder reference.
  - Six neutral starter replies, created once (`bst_default_canned_responses` filter).
- `BST_Canned` (data) and `BST_Admin_Canned` (screens and picker). Integration tests.
- **Time tracking and retainers** (off by default; **Support → Settings → Time tracking**):
  - `bst_time_entries` table: ticket, client (stamped at logging), agent, minutes, billable, note, work date.
  - **Log time** in the reply box (`30m`, `1h 15m`, `1:30`, `1.5`), saved with or without a reply. **Time** box on tickets with edit/delete (own entries for agents, any for admins).
  - Monthly retainer use (billable only, resets on the 1st) on the client screen, the Clients list and the ticket's Time box.
  - Team alerts at 80% and 100% (email + Slack if on), once per threshold per month.
  - **Support → Time**: monthly summary per client, client drill-down, entry editing, summary and entries CSV export. Agents see their own time.
  - Optional client portal panel with this month's used and remaining hours (totals only).
  - `BST_Time`, `BST_Duration` (unit tested), `BST_Admin_Time`. Capabilities `bst_log_time` (agents) and `bst_manage_time` (admins).
  - `bst_time_alert_recipients` filter, `bst_time_logged` and `bst_ticket_company_changed` actions.
  - Unit and integration tests.
- **SLAs** (off by default; **Support → Settings → SLAs & reminders**):
  - First-response and resolution targets per priority, each on business hours or a 24/7 clock. Defaults: Urgent 1h / 8h (24/7), High 4h / 2 working days, Normal 8h / 5 working days, Low 2 / 10 working days.
  - Business hours: working days, opening times (site timezone), UK bank holidays from the gov.uk feed (England and Wales, Scotland, Northern Ireland or none; refreshed weekly, with a built-in list as fallback) and custom closed days.
  - Per-plan targets on Support → Clients → Plans (e.g. Bronze/Silver/Gold); blank fields use the defaults.
  - First response = the first reply to the client (internal notes don't count). The resolution clock pauses while a ticket is Awaiting client or On hold, and a solved ticket is judged when it was solved.
  - **Due** column on the ticket list (sortable; amber when close, red when overdue), an **SLA** box on tickets, and **Support → SLA report**: percentage met per month by priority, plan and client, with CSV export.
  - One warning (at a set % of time left, default 25%) and one breach email per ticket and target, to the assignee or all agents if unassigned, plus Slack if it's on.
  - Monitor tickets aren't measured. Applies to tickets created after SLAs are switched on.
- **Response reminders** (own switch; works with or without SLAs): emails the assignee, or all agents if unassigned, while a ticket is New or Open. Per priority: first reminder after X hours, then every Y hours (blank = once). Counted on the priority's clock; restarts when the client replies; never sent for Awaiting client, On hold, Solved or Closed.
- `BST_Business_Hours` (pure PHP, unit tested incl. bank holidays and clock changes), `BST_SLA`, `BST_Admin_SLA`. A 5-minute cron (`bst_sla_check`), scheduled only while SLAs or reminders are on.
- `bst_priority_changed` action and `bst_sla_now` filter (tests).
- Unit and integration tests, manual checklist.
- **Support → Overview** (`BST_Admin_Overview`): the team's home screen, with headline numbers, Due next (SLAs on), My tickets, Unassigned (oldest first), uptime alerts, pending sign-ups, retainers at 80%+ and latest activity, each linking to the filtered ticket list. It's the first item under Support.
- **Replace the WordPress dashboard** (Settings → General, on by default): agents and administrators land on the overview after login; the Dashboard and the admin bar's Dashboard link open it. Agents lose the Dashboard menu; administrators keep it for Updates. Other roles are unchanged.
- `bst_overview_stats` filter. Hidden `bst_view=monitor` ticket list view (open uptime alerts).

### Changed
- `BST_Admin_Tickets::view_meta_query()` is public. Full-page screen detection uses `BST_Admin_UI::FULL_PAGES`.
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
- DB version 6: time entries table and time capabilities.
- DB version 5: canned response capabilities for agents and admins, plus the starter replies on upgrade.
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

# Changelog

All notable changes to this plugin are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- Editable auto-reply for new tickets (web form and email in), under **Support → Settings → Auto-reply**: on/off, subject and rich-text message, with `{{ticket.title}}`, `{{ticket.id}}` and `{{client.name}}` placeholders. Ships with the Bonsai wording as the default. Clearing a field restores the default.
- `bst_email_placeholders` filter.
- Email layout: `$body_html` slot for editable content. The heading is now optional.

### Changed
- The auto-reply replaces the fixed "We have received your request" email. It's still only sent on ticket creation and never to unverified senders.
- Plain-text email alternatives now show list items as `- ` bullets.
- **Support → Settings** now has a left-hand tab nav (General, Outgoing email, Auto-reply, Incoming email, Front end), one tab per page load (`&tab=…`). Each tab saves only its own fields, and you return to the same tab after saving, Test connection or Check now.
- `BST_Settings::save()` accepts partial input. Fields that aren't posted keep their saved values.
- `BST_Admin_Settings::url()` takes an optional tab slug.

### Notes
- A theme override of `emails/layout.php` must add the `$body_html` block, or the auto-reply text won't appear.

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

# Changelog

All notable changes to this plugin are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased] - 2026-10-01

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

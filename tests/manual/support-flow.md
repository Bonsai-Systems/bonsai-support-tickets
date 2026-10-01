# Support flow — pre-launch sign-off

Run on staging (`support.` subdomain) with a real Gmail mailbox before go-live.
Required by Bonsai testing rules: this plugin stores client data, sends email, and has custom tables and cron jobs.

- Site:
- Tested by:
- Date:
- Version:

## Setup
- [ ] Plugin activates with no errors. Support menu, Help articles and Settings appear.
- [ ] My requests / Submit a request / Help pages created and chosen in Settings.
- [ ] From address set to a domain address. SPF allows the server (or SMTP plugin active).
- [ ] `BST_IMAP_USER` / `BST_IMAP_PASSWORD` in wp-config. **Test connection** succeeds.
- [ ] Server cron hitting wp-cron.php every 2 minutes. "Next scheduled check" updates.
- [ ] `wp-content/uploads/bst-private/` returns 403 when opened directly in a browser (check nginx rule if not Apache).

## Client (Support Client login)
- [ ] Logged-out visit to My requests shows the login form. Logging in returns to the page.
- [ ] Visiting /wp-admin/ redirects to My requests. No admin bar.
- [ ] Submit with an empty form → field errors, values kept.
- [ ] Submit with subject, description and a PNG + PDF → lands on the ticket with "request has been sent".
- [ ] Upload a `.php` or `.exe` renamed to `.jpg` → rejected.
- [ ] "We have received your request" email arrives at the client inbox with the BDC reference.
- [ ] Reply from the portal → appears in the thread. The agent gets "Client reply".
- [ ] **Mark as solved** → status Solved, no "solved" email to the client.

## Agent (wp-admin)
- [ ] New ticket email arrives for agents.
- [ ] Reply to client → client receives the email. Status becomes Awaiting client.
- [ ] Internal note → yellow in admin, **not** in the client portal, **not** emailed to the client.
- [ ] Assign to another agent → they get "Assigned to you".
- [ ] Set status Solved without a reply → client gets the "Solved" email.
- [ ] Views (Mine / Unassigned / Unverified / Solved) and reference search work.
- [ ] New ticket from wp-admin for a client (phone call) → client sees it in the portal.

## Email in
- [ ] Client replies to a notification from Gmail/Outlook/iPhone → added to the same ticket, quoted history stripped.
- [ ] Client emails support@ fresh from their account email → new ticket owned by them.
- [ ] Unknown address emails in → Unverified ticket, no auto email to them. Link to client under Details → verified.
- [ ] Out-of-office reply → ignored (no ticket).
- [ ] Email with a PDF attachment → attachment on the ticket and downloadable.
- [ ] Agent replies to a notification starting with `#note` → internal note.
- [ ] Processed emails are marked read and labelled "Bonsai Support/Processed" in Gmail.

## Security
- [ ] Second client account cannot open the first client's ticket URL ("could not find") or attachment URL (403).
- [ ] Logged-out attachment URL → login screen.

## Sign-off
- [ ] All above pass. Signed:

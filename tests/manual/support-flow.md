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
- [ ] Auto-reply arrives at the client inbox: subject `[BDC-…] Thank you for contacting The Bonsai Digital Collective Support – [subject]`, placeholders filled, bullet list intact, client's message and View your request button below.
- [ ] Email the support mailbox from the client's address → new ticket gets the same auto-reply. Reply to it → no second auto-reply.
- [ ] **Support → Settings → Auto-reply**: edit the subject and message, then raise a ticket → edited version arrives. Switch it off → no auto-reply. Clear both fields and save → defaults return.
- [ ] Reply from the portal → appears in the thread. The agent gets "Client reply".
- [ ] **Mark as solved** → status Solved, no "solved" email to the client.

## Registration
- [ ] Logged-out My requests page shows **Register** under the log-in form. Clicking it shows the register form on the same page.
- [ ] Submit empty → errors on Client name, First name, Last name and Email; typed values kept.
- [ ] Register with an email that already has an account → "already an account" error.
- [ ] Register properly (client name "The Ley Arms", website without https://) → "Thanks for registering". Applicant gets "We've received your registration"; agents get "New support sign-up" with the details.
- [ ] Trying to log in before approval (after a password reset attempt) → "waiting for approval"; Forgotten password does not send a link.
- [ ] Emailing support@ from that address before approval → Unverified ticket, no auto email.
- [ ] Support → Sign-ups shows the count bubble and the person. **Approve** → they get "Your support account is ready" with Set your password; the link sets a password and lands on My requests after login.
- [ ] Ticket Client dropdown, ticket list and ticket header show "The Ley Arms".
- [ ] Sign-up typed "Ley Arms" when "The Ley Arms" exists → the client dropdown suggests The Ley Arms. Approve with it → the person appears under that client's People.
- [ ] Sign-up with a brand-new name → **New client: …** is pre-selected. Approve → a new client record exists with that name.
- [ ] **Reject** asks to confirm, then deletes the sign-up.
- [ ] Settings → untick Client registration → Register link disappears.

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

## Clients
- [ ] Upgrade a 0.1 site with typed client names → notice reports clients created and people/tickets linked. "the ley arms" and "The Ley Arms" became one client.
- [ ] Support → Clients: add a partner and a plan from the links above the list, set them on a client with retainer hours and websites, save → values stick. The list shows partner, plan, retainer, people and active tickets.
- [ ] Two people at the same client: each sees only their own tickets in the portal.
- [ ] New ticket from a linked person → ticket list shows the client name above the person. Filter by client works.
- [ ] Ticket screen: change **Contact** to someone at another client → **Client** follows. Change **Client** directly → it sticks, and Activity says "moved the ticket to …".
- [ ] Move a person to a different client on their profile → their old tickets keep the old client.

## Slack
- [ ] `BST_SLACK_WEBHOOK_URL` in wp-config.php → Settings → Slack shows **On**; Send test message arrives in the channel.
- [ ] Submit a ticket from the web form → one Slack post with reference, subject, client, priority and a working link to the ticket in wp-admin. No message body.
- [ ] Unknown address emails in → Slack post says "unknown sender" and "Could be spam".
- [ ] Client replies to a ticket → **no** Slack post.
- [ ] Toggle off and save → new ticket doesn't post. Toggle back on.
- [ ] Wrong webhook URL → Send test message shows an error; ticket submission still works and is not slowed down.

## Security
- [ ] Second client account cannot open the first client's ticket URL ("could not find") or attachment URL (403).
- [ ] Logged-out attachment URL → login screen.

## Sign-off
- [ ] All above pass. Signed:

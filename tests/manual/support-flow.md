# Support flow — pre-launch sign-off

Run on staging (`support.` subdomain) with a real Gmail mailbox before go-live.
Required because this plugin stores client data, sends email, and has custom tables and cron jobs.

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

## Support overview and dashboard
- [ ] Log in as an agent → lands on Support → Overview ("Hello, <name>"). No Dashboard menu. Admin bar on the front end: site menu → **Support overview**.
- [ ] Log in as an administrator → Overview. Dashboard menu still there; clicking it opens Overview; **Dashboard → Updates** works.
- [ ] Log in as an editor → normal WordPress dashboard.
- [ ] Follow a ticket email link while logged out, log in → goes to that ticket, not the overview.
- [ ] Each number at the top opens the ticket list with the same count.
- [ ] With SLAs on: Overdue count and **Due next** panel; overdue tickets show red badges. With SLAs off: neither appears.
- [ ] Time tracking on, a client at 80%+ → **Retainers** panel for administrators only.
- [ ] An open uptime alert → **Uptime alerts** panel; "View all" lists only monitor tickets.
- [ ] Settings → General → untick **Dashboard** → agents get the normal dashboard again after logging in.

## Client (Support Client login)
- [ ] Logged-out visit to My requests shows the login form. Logging in returns to the page.
- [ ] Visiting /wp-admin/ redirects to My requests. No admin bar.
- [ ] Submit with an empty form → field errors, values kept.
- [ ] Submit with subject, description and a PNG + PDF → lands on the ticket with "request has been sent".
- [ ] Upload a `.php` or `.exe` renamed to `.jpg` → rejected.
- [ ] Auto-reply arrives at the client inbox: subject `[SUP-…] We've received your request – [subject]` (or your edited wording), placeholders filled including the support name, bullet list intact, client's message and View your request button below.
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
- [ ] Processed emails are marked read and labelled "Support/Processed" (or your label) in Gmail; failures get the matching "/Failed" label.

## Clients
- [ ] Upgrade a 0.1 site with typed client names → notice reports clients created and people/tickets linked. "the ley arms" and "The Ley Arms" became one client.
- [ ] Support → Clients: add a partner and a plan from the links above the list, set them on a client with retainer hours and websites, save → values stick. The list shows partner, plan, retainer, people and active tickets.
- [ ] Two people at the same client: each sees only their own tickets in the portal.
- [ ] New ticket from a linked person → ticket list shows the client name above the person. Filter by client works.
- [ ] Ticket screen: change **Contact** to someone at another client → **Client** follows. Change **Client** directly → it sticks, and Activity says "moved the ticket to …".
- [ ] Move a person to a different client on their profile → their old tickets keep the old client.

## White-label (fresh install)
- [ ] Fresh site: Settings → General shows **Get set up**; dashboard shows "Finish setting up" until name, logo, pages and From address are done. Dismiss hides it.
- [ ] **Create the pages for me** → My requests and Submit a request exist and are selected.
- [ ] Appearance: set support name, choose a logo with **Choose image**, set colours → portal, register page and emails use them. No logo → emails show the name as text.
- [ ] Nowhere visible to a client (portal, register, login-required box, every email, email headers) mentions Bonsai or BDC.
- [ ] wp-admin shows the product name and mark, not Bonsai.

## Theme switch-over (Bonsai site, dev build)
Do this on staging first, with a fresh backup.
- [ ] Before: note the front page, Submit a request, My requests and Meet the team, and screenshot the header and footer.
- [ ] Pages, Support → Overview or Appearance → Themes shows **Move to the Support Desk theme**. Click **Switch and convert** → "Moved to the Support Desk theme: N pages converted…, 0 problems".
- [ ] Each page shows the same sections and wording as before. Open one in the editor: Support Desk blocks, each with its settings in the sidebar; no "This block contains unexpected content" warnings.
- [ ] Open a page that had a Content block → its text is in a Classic block inside the Content block. **Convert to blocks** works.
- [ ] Header: same logo, label and button. Footer: tagline, support hours, contact details, Main website link, © line and "Website by…" credit. If there's no footer logo, the footer now shows the support name instead of the "Bonsai." wordmark: add a footer logo under Customise → Support Desk theme → Footer if that matters.
- [ ] Tint sections are still light blue; buttons and accents still pink.
- [ ] Menus are in the same places.
- [ ] Rollback check (staging only): Appearance → Themes → activate Bonsai Support → pages look as before. Switch back to Support Desk.
- [ ] ACF Pro can be deactivated once nothing else on the site uses it; pages still render.

## White-label (Bonsai site upgrade, dev build)
- [ ] After updating support.bonsaidigitalcollective.co.uk: refs still `BDC-`, pink colours, Bonsai auto-reply wording, From "Bonsai Support", logo in emails (now from the Media Library), Gmail label unchanged.

## Slack
- [ ] `BST_SLACK_WEBHOOK_URL` in wp-config.php → Settings → Slack shows **On**; Send test message arrives in the channel.
- [ ] Submit a ticket from the web form → one Slack post with reference, subject, client, priority and a working link to the ticket in wp-admin. No message body.
- [ ] Unknown address emails in → Slack post says "unknown sender" and "Could be spam".
- [ ] Client replies to a ticket → **no** Slack post.
- [ ] Toggle off and save → new ticket doesn't post. Toggle back on.
- [ ] Wrong webhook URL → Send test message shows an error; ticket submission still works and is not slowed down.

## Time tracking
- [ ] Fresh install: no Log time field, no Time box, no Support → Time until Settings → Time tracking is switched on.
- [ ] Client "Ley Arms" with 10 retainer hours. On one of its tickets, reply with Log time `1h 30m` → reply sent, "1h 30m logged", Time box shows it and "1h 30m of 10h".
- [ ] Log time with the message empty → time saved, nothing sent to the client.
- [ ] Type `30` → error explaining it would be 30 hours; nothing logged. `1:30` and `1.5` both log 1h 30m.
- [ ] Untick Billable → entry shows Non-billable and doesn't move the retainer bar.
- [ ] Reach 80% → one email to admins (and Slack if on) naming the client. Log more → no repeat. Reach 100% → one more. The client gets nothing.
- [ ] Log time dated last month → no alert; it appears under last month in Support → Time.
- [ ] As an agent: can edit/delete own entries, not another agent's. Support → Time shows only their own time, no export.
- [ ] Support → Time → Export summary and Export CSV open in Excel with £/accents intact and decimal hours that add up.
- [ ] Turn on "Show clients their hours" → client sees "Support hours" on My requests with used/left; no entries or notes. Client with no retainer sees nothing.
- [ ] Move a ticket with logged time to another client → last month's report for the old client is unchanged.
- [ ] Switch time tracking off → all time UI disappears; switch on → entries are still there.

## SLAs and reminders
- [ ] Fresh install: no Due column, no SLA box, no Support → SLA report, and no reminder emails until the switches on Settings → SLAs & reminders are on.
- [ ] Turn SLAs on. The tab shows "A working day is 8.5 hours", and Closed days lists the next bank holidays. **Update bank holidays now** → "Bank holidays updated from gov.uk."
- [ ] Raise an Urgent ticket → Due shows "Reply" an hour from now; the SLA box shows targets 1h and 8h.
- [ ] Raise a Normal ticket at 5pm on Friday → Due is on Monday afternoon (not over the weekend).
- [ ] Reply to the client → the SLA box shows First response **Met**; the ticket goes to Awaiting client and resolution shows **Paused**. Client replies → back to Due with the paused time added on.
- [ ] Internal note only → first response is still due.
- [ ] Put a ticket's client on a plan, set that plan's Urgent first response to 0.5 → the ticket's SLA box says "for the <plan> plan" and the target is 30m.
- [ ] Change priority Normal → Urgent → the Due time moves.
- [ ] Leave an Urgent ticket unanswered → at 15 minutes left the assignee gets "SLA at risk", then "SLA breached" once it's late. Each email arrives once. Unassigned → every agent gets them. Slack posts too if it's on.
- [ ] Sort the ticket list by Due → soonest first; tickets with no SLA at the end.
- [ ] Support → SLA report: this month's percentages by priority, plan and client. Export CSV opens in Excel.
- [ ] Turn on reminders only (SLAs off) with Urgent 0.25h / 0.25h → a New ticket emails a reminder after 15 minutes, then every 15 minutes. Client replies → the count restarts. Set it to On hold → reminders stop.
- [ ] Uptime-monitor ticket → no SLA box and no Due time.
- [ ] Switch both off → the cron event `bst_sla_check` is removed (WP Crontrol) and the SLA screens disappear.

## Canned responses
- [ ] Fresh install: Support → Canned responses lists the six starter replies, with tags. Nothing mentions a company.
- [ ] On a ticket: **Insert a canned response** → reply appears in the box with the client's name, the ticket reference and your name filled in; the dropdown resets.
- [ ] Insert into a half-written reply: it lands at the cursor with a blank line either side.
- [ ] Works with **Internal note** selected too.
- [ ] Client name with an apostrophe or "&" shows correctly (not `&amp;`).
- [ ] Add a reply as a **Support Agent** (not admin), give it a new tag → it appears under that tag on tickets. Another agent can edit and delete it.
- [ ] Add more than 10 replies → a filter box appears; typing narrows the list.
- [ ] Delete a starter reply, update the plugin → it doesn't come back.

## Uptime monitoring
- [ ] Settings → Uptime monitoring: both sources **Off** by default; with no promo URL set there's no "Don't have uptime monitoring yet?" line.
- [ ] Turn on the status monitor, save, **Generate secret**. Paste URL + secret into the uptime monitor's Settings → Support tickets, **Send test** → success there, and "Last alert" here shows `test`.
- [ ] Add a site's address to a client's Websites. Take that site down in the monitor (or point the monitor at a dead URL) → one **Urgent** "Site down" ticket under that client; agents emailed; Slack post (if on); **no** email to any client.
- [ ] Site comes back → internal note "back up after …" on the same ticket, status unchanged. No second ticket.
- [ ] Solve the ticket, take the site down again → a **new** ticket.
- [ ] **Generate a new secret** asks to confirm; afterwards the monitor's Send test fails with a 401 message until the new secret is pasted in.
- [ ] Switch the status monitor off → the monitor's Send test reports 404.
- [ ] UptimeRobot: turn on, generate, add the webhook alert contact with the JSON body shown, pause/resume a monitor → down ticket, then up note.

## Security
- [ ] Second client account cannot open the first client's ticket URL ("could not find") or attachment URL (403).
- [ ] Logged-out attachment URL → login screen.

## Sign-off
- [ ] All above pass. Signed:

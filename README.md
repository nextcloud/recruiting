<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Recruiting

**Your whole hiring funnel inside Nextcloud — private, automated where it's
boring, human where it matters.**

Applications arrive by email — or through an opening's own public application
page — and become candidates on a pipeline board by themselves. The hiring team screens with unbiased votes, interviews are booked
against real calendars with the candidate picking a slot on a public page, and
offers run through a proper four-eyes approval before they go out. Rejections,
retention, anonymization — the compliance chores run on autopilot. Everything
stays on your own server; nothing about your candidates is ever replicated to
a third party.

![The pipeline board of an opening](screenshots/01-pipeline-board.png)

## Why it's cool

* 📥 **Zero-touch intake** — a mail to `jobs+backend-engineer@…` becomes a
  candidate card with CV attached, an AI summary, and an automatic
  confirmation to the applicant. When the candidate *replies* to any of your
  mails, the answer (attachments included) lands on their timeline — not in
  the inbox as a duplicate application. Nobody copies data around, ever.
* 🗳️ **Unbiased screening** — screeners vote 👍/🤔/👎 with a comment, and
  nobody sees the other votes until they cast their own. A "no" always needs
  a written reason.
* 📅 **Self-scheduling interviews** — the app reads the interviewers'
  calendars, proposes free slots, and the candidate picks one on a public
  page in their own timezone. Calendar event, invitations and (with Talk) the
  video room appear by themselves.
* 🤝 **Offers with four eyes** — salary data is invisible to interviewers,
  every offer needs a designated approver (never yourself), and expiring
  offers warn you three days ahead.
* 🔒 **GDPR on autopilot** — documents live in app storage (never in Files),
  rejected candidates and candidates left in closed openings are anonymized
  automatically after the retention period, unassigned triage mail is
  deleted after its own period, uploads are virus-scanned when
  files_antivirus is enabled, exports for data-access requests are one click, and the talent pool
  is strictly consent-based.
* 🪄 **AI where it removes busywork** (optional, via Nextcloud Assistant) —
  summaries, screening hints, mail drafts, interview questions. Always
  labeled, always human-reviewed, never a decision.
* 🚦 **A board that thinks along** — stuck candidates get a ⚠️ chip and a
  one-click filter (no votes? no interview booked? offer expiring?), the
  time-in-stage turns amber and then red as people wait, and the screener
  avatars on each card show who has voted — never what.
* 📊 **Honest reporting** — funnel, time-in-stage and time-to-hire are
  reconstructed from what actually happened, and keep working even after
  anonymization.
* 📖 **A real handbook** — a beautifully typeset PDF for non-technical HR
  people, linked right in the app's navigation.

| Screening without anchoring | The candidate picks their own slot |
|---|---|
| ![Screening votes with comments](screenshots/05-screening-votes.png) | ![The public slot-picking page](screenshots/18-public-slot-picker.png) |

## The handbook

The **user handbook** — a beautiful PDF for HR people and hiring managers with
no technical background — is linked at the bottom of the app navigation
("Handbook (PDF)"). Its source lives in [docs/handbook.html](docs/handbook.html);
`make handbook` re-renders the PDF via headless Chrome after UI changes.

## The people model

There is no second org chart. **Recruiters** are the members of one Nextcloud
group (default `recruiting`) and can do everything. Per opening you pick a
hiring team: **hiring managers** run the process for that opening,
**interviewers** only ever see the candidates assigned to them (and never any
salary data), **observers** may watch but not act. Being a Nextcloud admin
grants no candidate access at all.

## How it works — screen by screen

### Navigation (left sidebar)

| Entry | Who sees it | What it is |
|---|---|---|
| **New opening** | recruiters | Creates a job opening (see below) |
| **Triage inbox** | recruiters | Mail applications that matched no opening; the counter shows how many wait |
| **My reviews** | everyone | Candidates you were asked to screen — badge shows whether you still owe a vote |
| **My interviews** | everyone | Your interview panel appointments, incl. "pending" ones the candidate hasn't confirmed yet |
| **Mail templates** | recruiters | Manage the invitation/rejection/offer/confirmation mail templates |
| **Reports** | team members & recruiters | Funnel, time-to-hire, rejection reasons — scoped to what you may see |
| **Talent pool** | recruiters | Candidates who consented to be kept for future openings, searchable by name, email and CV text |
| **Refer someone** | everyone (if enabled) | Put a person forward for an open position and follow the coarse status of your referrals |
| **Openings** | team members & recruiters | One entry per opening with the count of active candidates; closed openings collapse at the bottom |
| **Handbook (PDF)** | everyone | The illustrated user handbook, at the bottom of the navigation |

### The opening (pipeline board)

The board shows the four active stages — **New → Screening → Interview →
Offer**, each with its own accent color. Column headers show the real totals;
busy stages load 100 cards and offer **Show more**. The filter box narrows the
loaded cards by name or email, and the **⚠️ Stuck** filter shows only
candidates where something needs attention — waiting in "New" for a week, no
votes yet, no interview booked, no active offer, or an offer about to expire
(hover a card's ⚠️ chip to see the reasons). Cards show the assigned screeners (a green ring =
has voted) and the time-in-stage turns amber, then red, as a candidate ages.
Drag a card to move a candidate (or use the card's ⋮ menu — fully
keyboard-accessible); every move is logged on the candidate's timeline and in
the Activity stream. **Hired / Rejected / Withdrawn** are shown as summary
chips below with their counts; their cards are only loaded when you click a
chip, which adds the stage as an extra column (and to the table view) — so an
old opening with hundreds of rejections still opens fast.

The board keeps itself current: while it is visible it checks every 30 seconds
whether someone else changed the opening and reloads in place (scroll position,
selection and the open sidebar stay). A move carries the stage you saw — if a
colleague moved the candidate in the meantime, nothing is changed and you get
"<name> was moved to <stage> by someone else".

The same candidates as a sortable table (click a column header). Managers can
select several candidates and move or reject them in bulk. The bulk rejection
takes one reason and one mail text whose placeholders are filled in per
candidate (with a preview for the first one), optionally asks them to join the
talent pool, and ends with a summary of who was rejected and who could not be:

![Table view of all candidates](screenshots/02-candidate-table.png)

Header actions (managers and recruiters):

* **Add candidate** — manual entry with drag-and-drop CV upload. The candidate
  starts in "New" and the hiring managers are notified.
* **Board/table toggle** — same data, two views.
* **💬 Team chat / Create team chat** — one click creates a Talk conversation
  with the current hiring team. (Talk's API fixes the member list at creation,
  so if the team changes, delete and recreate the room.)
* **⋮ → Edit opening & team** — title, description, requirements (these feed
  the AI features), the auto-confirmation toggle, the **public application
  page** toggle (see below), and the hiring team with roles.
* **⋮ → Put on hold / Reopen / Close** — if candidates are still active when
  you close, you can reject them all in one go with the rejection mail
  (reason preset to "Position was filled", talent-pool ask optional) or close
  without changing them.
* **⋮ → Delete opening** (recruiters) — only for an opening that never had a
  candidate, e.g. a typo. It removes the opening, its hiring team and its team
  chat. Openings with candidates can only be closed.

**Public application page.** Turn on *"Accept applications through a public
form on the web"* in the opening editor (⋮ → Edit opening & team) and the app
exposes a self-contained, login-free form at
`…/apps/recruiting/apply/<opening-slug>` — the link appears right below the
switch. Applicants see the opening and submit their name, email, an optional
cover letter and CV, and a consent checkbox; the submission lands in *New* just
like an emailed application, complete with auto-acknowledgement and the full
GDPR lifecycle. The page is server-rendered and hardened (rate limits,
brute-force protection, a honeypot, and a signed fill-time token: submissions
faster than 3 seconds or without a valid token are silently dropped, a form
left open for more than a day asks the applicant to submit again). Names are
limited to 120 characters and may not contain links or email addresses;
closed or opted-out openings return a neutral 404.

**Automatic confirmations are guarded against abuse.** In every outgoing
mail the candidate's name is cleaned up — line breaks, links and addresses
are removed and it is shortened to 80 characters ("applicant" stands in if
nothing is left); the stored name stays as it was. Each opening sends at most
a configurable number of automatic confirmations per day (100 by default, see
Settings); beyond that they are skipped and the HR group gets one
notification per opening and day.

The header also shows the opening's **mail tag** (e.g.
`[backend-engineer]`): applications sent to `jobs+backend-engineer@…` or with
the tag in the subject skip triage and land directly on this board.

### The candidate (detail sidebar)

Click any card and the sidebar opens with five tabs:

* **Profile** — contact details (editable by managers), source, an optional
  AI summary (labeled, regenerable), duplicate warning with a link to the
  earlier application and a **Merge** action (same opening only), "Also
  applied for …" links to the person's other applications, and — in triage —
  the "Assign to opening" control.
  At the bottom, **Documents**: CVs and attachments. PDFs, images and text
  open in the browser; Word and ODT files are downloaded. Stored in app
  storage: never visible in Files, never accessible to people without access
  to this candidate. With files_antivirus enabled, every upload is scanned
  first and infected files are rejected.

  ![Candidate profile tab](screenshots/03-candidate-profile.png)
  ![Candidate documents](screenshots/04-candidate-documents.png)

* **Votes** — cast or update your vote (👍/🤔/👎 + comment; a "no"
  requires a reason). Votes of others stay hidden until you've voted.
  Managers assign screeners here — each assignee is notified and gets the
  candidate in "My reviews". Optional AI screening hint against the opening's
  requirements ("verify yourself — the humans decide").

  ![Screening votes and assigned screeners](screenshots/05-screening-votes.png)

* **Interviews** — every round with its status. *Awaiting candidate* means the
  public link is out; copy it from here if you want to resend it yourself.
  The link stops working once the interview is cancelled, seven days after
  it ended, or when the candidate is rejected, withdraws or is anonymized.
  Cancelling notifies all interviewers and removes the calendar event.
  Optional AI interview-question suggestions.

  ![Interviews tab](screenshots/06-interviews-tab.png)

* **Offer** — visible only to recruiters, this opening's managers and the
  offer's approver. The whole offer lifecycle happens here (see below).

  ![Offer awaiting approval](screenshots/08-offer-approval.png)

* **Log** — the complete timeline (every stage move, mail with full text,
  vote, interview, offer step) merged with the team discussion thread.
  Candidate replies appear here too ("The candidate replied: …").
  @-mention a teammate to notify them.

  ![Timeline and team discussion](screenshots/07-activity-timeline.png)

**Reject …** is the red button at the bottom of the profile tab. The header
menu carries **Book interview**, **Move to …**, and for
recruiters **Export data (GDPR)** (ZIP with everything about this person —
without reviewer identities) and **Delete permanently (GDPR)** (removes the
candidate with all documents, votes, comments, interviews, notifications and
activity entries — irreversible, confirmation required). Deleting and
anonymizing also clean up the calendars: upcoming interviews are cancelled,
past interview events are renamed to "Interview: <opening>" without the
candidate's name or Talk link (for the organizer and every interviewer), and
interview Talk rooms are deleted. Activity entries and notifications always
show the candidate's current name.

### Booking an interview

![Booking an interview](screenshots/09-schedule-interview.png)

1. Pick interviewers and duration; choose whether a **Talk video room**
   should be created (if Talk is installed) — turned off, you can note a
   location or a video link from another tool instead.
2. **Find free slots** reads the interviewers' calendars (working days,
   9–17) and proposes times; deselect any, or add slots manually.
3. **Create & write invitation** creates the interview and prefills the
   invitation mail including the personal scheduling link. Edit freely — if
   the link gets lost, the app re-attaches it. Send, or copy the link and
   deliver it yourself.
4. The candidate opens the public page (times shown in *their* timezone,
   no account needed) and confirms one slot. That books the calendar event
   with all interviewers invited, creates the Talk room for video interviews,
   moves the card to "Interview", and notifies the panel. The confirmation
   page offers the appointment as an .ics download for the candidate's own
   calendar.
5. If none of the proposed times work, the candidate can say so on the same
   page (with an optional message); after confirming, they can ask to
   reschedule. Both land on the timeline and notify the organizer and the
   interviewers — nothing is cancelled automatically.
6. About 24 hours before a confirmed interview the candidate gets a reminder
   mail (template *Interview reminder*) with the time, the location or video
   link, and their interview and self-service links. An interview booked less
   than a day ahead gets it within the hour (unless it starts in less than
   two hours).

| Picking a slot — no account required | After confirming: details + calendar file |
|---|---|
| ![The public slot picker](screenshots/18-public-slot-picker.png) | ![The confirmation page with .ics download](screenshots/19-public-confirmed.png) |

### The candidate's self-service page

Every candidate has a personal link (`/apps/recruiting/portal/…`, in the
built-in confirmation-of-receipt mail via the `{{portal_link}}` placeholder)
where they see a deliberately coarse status — received, in review,
interview scheduled, offer, closed — and their upcoming interviews. No stage
names, votes, comments or rejection reasons are shown. From there they can
**withdraw** (the opening's hiring managers are notified), **ask for their data
to be deleted** (the HR group is notified and the candidate sidebar shows a
banner with the request date and a *Delete now* shortcut — the deletion itself
stays a recruiter's decision), and **add documents** (same types and size limit
as everywhere, at most 10 documents per candidate). The page works without an
account, is rate limited, and stops working when the candidate is anonymized
or deleted.

![The candidate's self-service page](screenshots/21-candidate-portal.png)

### Employee referrals

Any logged-in user can open **Refer someone**, pick an open position, and enter
the person's name, email, optional phone, a note why they recommend them and an
optional CV — after confirming that the person agreed to be referred. The
candidate lands in the opening's "New" column with source *Employee referral*;
the hiring managers are notified as for any application, the note is on the
timeline and the candidate sidebar shows *Referred by*. The referrer sees their
own referrals with the coarse status only and gets a notification when it
changes; they gain no access to the candidate. Admins can turn referrals off.

![Refer someone](screenshots/20-referrals.png)

### Rejecting

**Reject …** asks for an internal reason (feeds the reports), shows the
rejection mail prefilled from your template for review — nothing ever goes
out unreviewed — and optionally adds the **talent-pool consent link**. If
sending fails, the candidate is *not* marked rejected; nobody ends up
silently rejected-but-uninformed. Rejecting without an email is possible for
candidates without an address. The same rules hold for the bulk rejection in
the table view and when closing an opening: a candidate whose mail fails stays
where they are and is listed in the summary.

![Rejecting with a reviewed email](screenshots/10-reject-dialog.png)

| Rejecting several candidates at once | Closing an opening with active candidates |
|---|---|
| ![Bulk rejection](screenshots/22-bulk-reject.png) | ![Closing an opening](screenshots/23-close-opening.png) |

### The offer lifecycle

1. **Draft** — job title, salary (amount/currency/period), start date,
   validity date, internal notes. Editable only while a draft.
2. **Request approval** — pick any Nextcloud user as approver (never
   yourself). They're notified and get scoped access to exactly this
   candidate and offer; they **approve** or **send back to draft** with a
   note.
3. **Send** — only approved offers can go out: review the offer mail and send
   it, or "mark as sent" if it went out another way. The card moves to
   "Offer" and the validity clock starts.
4. **Track the response** — *Accepted* moves the candidate to "Hired" 🎉 (and
   reminds you to close the opening); *Negotiating* and *Declined* are
   recorded. Offers past their validity date expire automatically; the
   creator is warned three days ahead.
5. **Withdraw** is possible at any point before a final answer.

### Triage inbox

Mail applications that matched no opening. Open one and assign it to an
opening in its profile tab (moves it into that board's "New" column and
notifies the managers), or delete spam permanently from the row's ⋮ menu.
A heartbeat line shows when the mailbox was last checked — and warns when
the last check failed. Pro tip: put the mail tag into your job postings and triage stays
empty.

Automatic mail — bounces, out-of-office replies, mailing lists (recognised by
headers such as `Auto-Submitted`, `Precedence`, `List-Id`, an empty
`Return-Path` or a `MAILER-DAEMON` sender) — never becomes a candidate and is
never acknowledged; the heartbeat counts it as skipped. If it answers a mail
to a candidate, it is noted on that candidate's timeline as an automatic
reply, without notifying anyone. Mail whose `Authentication-Results` show a
forged sender (DMARC fail, or SPF fail without a valid DKIM signature) still
becomes a candidate but gets no automatic confirmation.

Each check works through new mail for up to a minute (at most 500 mails). A
temporary problem such as a database or storage outage leaves the mail
unread and ends the check; the next check retries it. A retry never creates a
second candidate: it continues on the candidate the first attempt created. A
mail that fails three times — or twelve times with a temporary problem — is
moved to the mailbox folder **Recruiting-Failed** (created when needed) and
marked as read, and the triage inbox shows how many mails wait there. Mails
larger than 30 MB go there straight away, without being downloaded. Moving a
mail back into the inbox and marking it unread tries it again.

![Triage inbox](screenshots/11-triage-inbox.png)

### My reviews & My interviews

The two personal to-do lists: what you still owe a vote on, and where you are
on the interview panel. "My reviews" sorts candidates you have not voted on to
the top; "My interviews" also shows rounds the candidate has not confirmed yet.

| My reviews | My interviews |
|---|---|
| ![My reviews](screenshots/12-my-reviews.png) | ![My interviews](screenshots/13-my-interviews.png) |

### Talent pool

Only candidates who clicked the consent link from their rejection mail appear
here — consent is never assumed. The search box matches names, email
addresses and the text of uploaded documents (e.g. "Kubernetes" finds every
pool member whose CV mentions it). **Add to opening** creates a fresh copy
(documents included) in the target opening's "New" column; the pool entry
stays for further openings. Membership expires automatically after the
configured period, after which the candidate is anonymized like everyone
else.

![Talent pool](screenshots/15-talent-pool.png)

### Reports

For the applications received in a period you pick (default: the last 12
months): headline tiles (candidates, hired, average days to hire, open
offers), applications per week (per month for periods over half a year),
stage conversion (how many applications reached at least each stage), the
source of applications, and per opening: the funnel with average
time-in-stage, open offers, rejection reasons and sources. **Export CSV**
downloads the per-opening table. All numbers are reconstructed from each
candidate's actual history and survive anonymization. You only see openings
you manage or observe (or all, as a recruiter); figures are cached for up to
ten minutes and refreshed as soon as a candidate of the opening changes.

![Reports](screenshots/14-reports.png)

### Mail templates

Per mail type (confirmation of receipt, interview invitation, rejection,
offer, interview reminder) the built-in template is always available; **Customize** copies it into
an editable one and **Make default** activates it. The editor offers each
type's placeholders as chips — click to insert — and validates them when you
save, so typos can't reach a candidate. Every send shows a
resolved preview first, and every sent mail lands on the candidate's timeline.

![Mail templates](screenshots/16-mail-templates.png)

### Settings

**Administration → Recruiting** (admins): the HR group, the sender identity
for candidate mail, the application mailbox (IMAP host/user/password with a
*Test connection* button; fetched every 5 minutes), the daily limit of
automatic confirmations per opening (0 means no limit), the GDPR retention
period (rejected and withdrawn candidates, and candidates left in openings
closed that long ago, are anonymized; 0 disables the automation), the triage
inbox retention (unassigned mail is deleted after this many days, default 90,
0 disables it), the talent-pool membership duration and whether employee
referrals are enabled (on by default).

**Personal settings → Groupware** (everyone): the **email digest** — off,
daily or weekly. It bundles new applications in your openings, your pending
reviews, your upcoming interviews and offers awaiting your approval, and is
only sent when there is something to report.

![Personal digest setting](screenshots/17-personal-digest.png)

### Fully integrated

Bell notifications for everything that concerns you (assignments, interview
confirmations, candidate replies, offer approvals, @-mentions, expiring
offers), Activity stream entries per candidate, unified search over candidates
and openings (permission-scoped in the database query, anonymized candidates
excluded, paged; terms of three or more characters also match the text of
candidates' documents), and deep links from every notification
straight to the right candidate. The interface ships in English and German.

## Setup

1. Enable the app: `occ app:enable recruiting`.
2. In **Administration settings → Recruiting**: pick the HR group, set the
   sender identity, configure the application mailbox and enable fetching.
3. Create your first opening, add the hiring team — done.

Interview scheduling uses the interviewers' Nextcloud calendars. Optional
integrations light up automatically when available: **Talk** (video interview
rooms, team chats) and **Nextcloud Assistant** with an AI provider (summaries,
hints, drafts). Without them, the corresponding buttons simply don't appear.

The AI features read the application mail or form text plus the text of the
most recent PDF, Word (DOCX), OpenDocument or text document — usually the CV.
Email addresses and phone numbers on file are never sent to the AI provider;
the name only for the intake extraction while it is still unknown (drafts
still use it in the salutation). PDF text comes from `pdftotext`
(poppler-utils) when it is installed, otherwise from a built-in reader that
handles common PDFs and discards anything that does not look like text.
The text is extracted by a background job shortly after the upload (cron
must run); for a new application the AI intake analysis waits for it.

## occ commands

| Command | Purpose |
|---|---|
| `recruiting:fetch-mail` | Fetch the applications mailbox now |
| `recruiting:retention:run [--dry-run]` | Anonymize or delete candidates whose retention period is over; lists each group (triage, rejected/withdrawn, closed openings, expired pool consent) with count and reason |
| `recruiting:documents:extract-text [--limit=N]` | Extract the text of documents that have none yet (stored before text extraction existed, or whose extraction failed) |

## Development

```bash
npm ci && npm run build   # frontend (Vue 3 + @nextcloud/vue 9, Vite)
```

Backend unit tests live in `tests/Unit` (plain PHPUnit, no server bootstrap
required). The complete behaviour, data model, permission matrix and design
decisions are documented in the [specification](Recruiting-SPEC.md); §10 of the
spec records how the implementation turned out, including the confidentiality
rules the code enforces and the known deviations.

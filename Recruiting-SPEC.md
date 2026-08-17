# Recruiting — App Specification

**App ID:** `recruiting` · **Namespace:** `OCA\Recruiting` · **Category:** office
**Target platform:** Nextcloud 35 (`min-version="35"`; revisit before app-store release)
**Status:** Specification v1.2 (2026-08-17) — **Phases 1–3 implemented and verified**, plus the v0.4–v0.7 releases (reply threading, board pagination and attention lens, bulk actions, German translation, user handbook). §10 records the implementation: refinements beyond the original text, the deviations (notably §4.10 Talk bot events), and the surface map. The user-facing walkthrough is in the [README](README.md).

## 1. Vision

Recruiting is an HR tool for managing incoming job applications inside Nextcloud. It
covers the full hiring funnel: applications arrive (by email or manual entry), the
hiring team screens them, interviews are booked against interviewers' real calendars,
offers are made and tracked, and rejections go out — all from one place.

The guiding principle: **remove all boring work and overhead from hiring managers.**
Every step that is mechanical (parsing a CV out of an email, finding a free interview
slot, drafting a rejection mail, remembering to follow up on an expiring offer,
deleting stale candidate data) is automated or reduced to a single review-and-confirm
action. The human stays in charge of every decision; the app does the legwork.

Quality bar: this app must feel like a first-party Nextcloud app. It follows the
Nextcloud design system (`@nextcloud/vue` components, NC HIG), is fully translatable,
accessible, and works in light and dark mode without special-casing.

## 2. Personas & roles

| Role | Granted by | Capabilities |
|---|---|---|
| **HR admin / Recruiter** | Membership in an admin-configured group (default: `recruiting`) | Everything: manage openings, all candidates, templates, triage inbox, talent pool, GDPR actions, reports across all openings |
| **Hiring manager** | Per-opening assignment | Their openings only: see all candidates, move stages, assign screeners/interviewers, schedule interviews, create offers, send mails, see offer data |
| **Interviewer / Screener** | Per-opening (or per-candidate) assignment | Only candidates assigned to them: read profile + documents, submit vote + comment, participate in comment thread, see their own interviews. **No access to offer/salary data or other candidates** |
| **Read-only observer** | Per-opening assignment | View pipeline, candidates, and reports of that opening. No actions, no offer data |
| **Nextcloud admin** | instance admin | Admin settings only (IMAP, HR group, retention, sender identity). Being an NC admin grants **no** candidate access by itself |

Permission checks live in a single `PermissionService` used by every controller and
service — never inline group checks. All candidate data is invisible to regular
Nextcloud users.

## 3. Core concepts & data model

Fixed pipeline (not configurable): **New → Screening → Interview → Offer → Hired / Rejected / Withdrawn**.
The three terminal stages are flags on the candidate, not columns the user drags past.

Database tables (all prefixed `recruiting_`, via `OCP\Migration`, entities/mappers via `OCP\AppFramework\Db`):

- **`openings`** — id, title, department, location, employment_type, description (longtext),
  requirements (longtext, feeds AI matching), status (`open` / `on_hold` / `closed`),
  mail_slug (unique, for plus-addressing), talk_token (nullable), created_by, created_at, closed_at.
- **`team`** — opening_id, uid, role (`manager` / `interviewer` / `observer`). Recruiters are global via group, not listed here.
- **`candidates`** — id, opening_id (**nullable** — null = triage inbox), display_name, email,
  phone, source (`email` / `manual` / `pool`), stage, stage_changed_at, ai_summary (nullable),
  rejection_reason (nullable, from fixed list), rejected_at, hired_at, withdrawn_at,
  pool_member (bool), pool_consent_at, pool_consent_token, reply_token (nullable — the
  per-candidate Reply-To secret), anonymized_at, created_at, created_by.
- **`documents`** — candidate_id, name, mime, size, appdata path, uploaded_by, created_at.
- **`assignments`** — candidate_id, uid, kind (`screener`), assigned_by, created_at
  (which screeners were asked to review this candidate).
- **`votes`** — candidate_id, uid, vote (`yes` / `maybe` / `no`), comment (text), created_at, updated_at.
  One row per user per candidate (unique index).
- **`interviews`** — candidate_id, title (e.g. "Technical interview"), status
  (`proposed` / `confirmed` / `done` / `cancelled`), start/end (null until confirmed),
  location (text) or talk_link, calendar_object_uid, public_token, created_by, created_at.
- **`interview_attendees`** — interview_id, uid.
- **`interview_slots`** — interview_id, start, end, chosen (bool). Proposed options for candidate self-scheduling.
- **`offers`** — candidate_id, job_title, salary_amount, salary_currency, salary_period,
  start_date, valid_until, notes, status (`draft` / `pending_approval` / `approved` / `sent` /
  `accepted` / `declined` / `negotiating` / `expired` / `withdrawn`), approver_uid, approved_at,
  responded_at, created_by. Offer rows are only ever readable by recruiters + that opening's managers.
- **`events`** — candidate_id, type, actor_uid (nullable for system), data (json), created_at.
  The candidate **timeline**: every stage change, vote, mail sent (with subject + snapshot of body),
  interview booked, offer action, GDPR action. Append-only.
- **`templates`** — type (`interview_invite` / `rejection` / `offer` / `pool_consent` / `receipt_confirmation`),
  name, subject, body, is_default. Placeholder syntax `{{candidate_name}}`, `{{opening_title}}`,
  `{{company}}`, `{{sender_name}}`, `{{interview_date}}`, `{{offer_valid_until}}` — rendered
  server-side, unknown placeholders are an error at save time.

**Comments/discussion** use `OCP\Comments\ICommentsManager` with object type
`recruiting_candidate` (no own table) — this reuses NC's mention parsing; an own
`ICommentsEventHandler` turns @-mentions into notifications.

**Documents** are stored in `IAppData` (never in user Files), streamed through a
controller with permission checks + correct Content-Disposition; inline preview for
PDFs via browser viewer. This guarantees interviewers can never stumble on documents
of candidates not assigned to them.

## 4. Features

### 4.1 Openings

- CRUD for openings by recruiters; hiring managers can edit their own.
- Opening view = pipeline board (columns per stage, candidate cards with name, days-in-stage,
  vote tally dots, next-interview chip) plus a sortable table view toggle.
- Assign hiring team (manager / interviewers / observers) with an NC user picker
  (`NcSelect` with user search provider).
- Closing an opening prompts for what to do with open candidates (reject-with-mail flow, bulk).

### 4.2 Intake

**Email ingestion (primary channel):**
- Admin configures a dedicated IMAP mailbox (host, port, security, user, password) in
  admin settings; password encrypted at rest via `OCP\Security\ICrypto`. A "test
  connection" button validates before save.
- A background job (`TimedJob`, every 5 min) fetches unseen messages. Each message becomes
  a candidate: sender name/email extracted, mail body stored as the first timeline event,
  attachments stored as documents. The message is marked seen (never deleted).
- **Opening matching:** plus-address (`jobs+<mail_slug>@…`) or `[<mail_slug>]` in the
  subject routes the candidate directly into that opening's *New* column. Unmatched mail
  lands in the **Triage inbox** (recruiters only); opening a row offers the opening
  assignment in its profile tab — or rejects spam (hard delete, no GDPR trail needed).
- **Ingestion heartbeat:** every fetch run records when it ran, how many mails it
  saw and whether it failed; the triage inbox shows the status and warns on failure,
  so a broken mailbox cannot silently swallow applications.
- **Reply threading:** outgoing candidate mail carries a per-candidate Reply-To
  (`jobs+c{id}.{token}@…`). A mail to that address (token checked, `hash_equals`)
  is appended to the existing candidate's timeline — attachments become documents,
  the hiring managers are notified — instead of becoming a "new" application in
  triage. **Idempotency:** every processed Message-ID is recorded in
  `recruiting_mail_seen` *after* successful processing; a crash between processing
  and the IMAP seen-flag retries the mail instead of duplicating or losing it.
- Optional auto-acknowledgement: send the `receipt_confirmation` template to the
  candidate on ingestion (per-opening toggle).
- Duplicate detection: same email address + same opening within 90 days → flagged as
  duplicate on the card, linked to the earlier application.

**Manual entry:** recruiters and hiring managers can create a candidate with a small
modal (name, email, phone, opening, documents drag-and-drop).

### 4.3 Screening

- The hiring manager (or recruiter) assigns screeners to a candidate; each assigned
  screener gets a notification and sees the candidate in **"My reviews"**.
- A screener submits exactly one **vote — 👍 yes / 🤔 maybe / 👎 no — plus a comment**
  (comment required for *no*). Votes are editable by their author.
- To avoid anchoring, a screener does not see others' votes until they have submitted
  their own (comment thread stays visible — it's a collaboration space, not a ballot).
- The candidate card shows the aggregated tally; the detail view lists each vote + comment.
- No configurable scorecards, criteria, or weights — deliberately simple.

### 4.4 Interview scheduling (the flagship busywork-killer)

Flow for "book an interview":
1. Hiring manager picks interviewers and duration.
2. The app reads the interviewers' **free/busy via the Nextcloud Calendar API**
   (`OCP\Calendar\IManager` availability lookup) within working hours and proposes the
   next N common free slots. The manager deselects any and confirms — or types slots manually.
3. The candidate receives the `interview_invite` mail containing a **public link**
   (token, brute-force protected, no account needed) showing the proposed slots; the
   candidate picks one and confirms on a Nextcloud guest-styled page.
4. On confirmation the app books a **calendar event** in the organizer's calendar with
   all interviewers as attendees (standard iTIP so they can accept), creates a
   **Talk room** for the interview if Talk is installed and "video interview" was chosen
   (link included in the event + candidate confirmation mail), and moves the candidate
   to *Interview* if still earlier in the pipeline. The confirmation page offers the
   appointment as an **.ics download** so the candidate's own calendar knows about it.
5. Rescheduling/cancelling from the app updates/cancels the event and notifies everyone.

Talk is a **soft dependency**: everything works without it, the video-room option
simply doesn't appear. Interviewers see their upcoming interviews in "My interviews".

### 4.5 Communication

- All candidate-facing mail is sent server-side via `OCP\Mail\IMailer` with the
  admin-configured sender identity (e.g. `HR <jobs@company.com>`, Reply-To the mailbox).
- Template management UI for recruiters (per type, multiple named templates, one default).
  Sending always shows a **preview with placeholders resolved**; the user can edit the
  concrete mail before sending. Nothing goes out unreviewed.
- Every sent mail is logged on the candidate timeline with subject + body snapshot.
- **Rejection flow:** started from the red *Reject …* button at the bottom of the
  candidate's profile tab; pick template → pick rejection reason (fixed list: *not qualified,
  better candidate chosen, position filled, withdrawn, other*) → preview → send. Moves the
  candidate to *Rejected*. Bulk-reject supported from the board (same preview per candidate).

### 4.6 Offer process

- Offer record on the candidate: job title, salary (amount/currency/period), start date,
  valid-until date, free-text notes. **Visible only to recruiters and that opening's
  hiring managers** — enforced server-side, offer data never serialized to other roles.
- **Approval step:** the offer creator picks an approver (any NC user, e.g. department
  head); the approver gets a notification with an approve/decline action (approver gets
  scoped read access to exactly this offer + candidate profile). Only `approved` offers
  can be marked sent.
- The offer mail itself is sent by the recruiter (offer template; the actual contract/
  letter is handled outside the app — no letter generation in scope).
- **Response tracking:** status accepted / declined / negotiating set manually;
  a daily background job flags offers expiring within 3 days (notification to creator)
  and marks overdue ones `expired`.
- Accepting an offer moves the candidate to *Hired* and prompts the close-out flow for
  remaining candidates of the opening.

### 4.7 AI assistance (via `OCP\TaskProcessing`)

All AI features are **optional and degrade silently** when no TaskProcessing provider
(Nextcloud Assistant / an LLM backend) is configured — the buttons simply don't render.
AI never acts autonomously; every output is a suggestion a human reviews.

- **CV parsing & summary:** on email ingestion (and on demand), extract
  name/email/phone/current role/key skills from the mail body + text-extractable
  attachments, prefill the candidate fields (marked "AI-suggested" until a human
  touches them), and produce a 3-sentence `ai_summary` shown on the card hover + detail top.
- **Drafting assistance:** "Draft with Assistant" button in every mail compose view
  (rejection with reason-aware tone, interview invite, offer mail) and an "interview
  question suggestions" generator on the interview view fed by the opening's requirements.
- **Screening hints:** on demand per candidate, a match assessment of candidate vs. the
  opening's `requirements` field, rendered as a clearly-labeled hint box ("AI hint —
  verify yourself"), never as a score in the tally and never auto-moving anyone.

### 4.8 GDPR & data lifecycle

- **Retention:** admin-configured retention period (default 180 days). A daily job
  anonymizes candidates rejected/withdrawn longer ago than that (unless pool member):
  name → "Anonymized candidate", email/phone nulled, documents deleted, mail bodies and
  comments purged, votes kept as anonymous aggregates for statistics, `anonymized_at` set.
- **Delete on request:** recruiters can hard-delete a candidate (confirmation dialog);
  wipes rows, documents, comments, calendar references. Timeline of the *deletion* is
  written to the NC admin audit log.
- **Export:** one click produces a ZIP (JSON of all structured data + documents) for a
  candidate data-access request.
- **Talent pool consent:** during rejection the recruiter can tick "ask to stay in our
  talent pool" — the rejection mail then includes a consent link (public page, token).
  Only after the candidate confirms does `pool_member` become true (`pool_consent_at`
  recorded); the retention clock restarts and the pool membership itself expires after
  an admin-configured period (default 12 months) with automatic anonymization.
- **Talent pool view** (recruiters only): searchable list of consented candidates;
  "add to opening" copies them into a new opening's *New* stage (source `pool`).

### 4.9 Notifications, Activity & digest

- `OCP\Notification\INotifier`: you were assigned as screener / interviewer; interview
  confirmed or cancelled by candidate; a candidate replied by email (managers); offer
  awaiting your approval; offer approved/declined; offer expiring; @-mention in a
  comment; new application in your opening (managers, toggleable).
- `OCP\Activity\IProvider`: stage changes, votes, mails sent, offers — per candidate and
  per opening, feeding the standard Activity app.
- **Digest:** personal setting (off / daily / weekly, default off): one summary mail —
  new applications in your openings, your pending reviews, your upcoming interviews,
  offers needing action. Sent by a background job, respects the user's NC language.

### 4.10 Talk integration (beyond interview rooms)

Soft dependency. Per opening, the hiring manager can create a linked **Talk
conversation** for the hiring team (members synced from the opening team). App events
(new application, interview confirmed, offer accepted) are posted into it as bot
messages via the Talk bot API. Entirely optional per opening.

### 4.11 Reporting (basic dashboard)

A **Reports** view per opening + an overview across openings (role-scoped: observers
and managers see their openings, recruiters see all):

- Funnel: candidates currently/ever per stage.
- Average time-in-stage and total time-to-hire (for hired candidates).
- Rejection reasons breakdown.
- Simple counts: applications per week (last 12 weeks), open offers.

Charts rendered with a small inline SVG/chart approach consistent with NC design —
no heavyweight chart library unless already used in the ecosystem. No CSV export,
source tracking, or cross-instance analytics in v1 (data model must not preclude them).

### 4.12 Search

`OCP\Search\IProvider` for unified search: candidates by name/email (permission-scoped),
openings by title. Recruiters/managers only.

## 5. UX / information architecture

Follows the standard NC app layout (`NcAppNavigation` / `NcAppContent` / `NcAppSidebar`):

- **Navigation (left):** Triage inbox (with unread counter, recruiters only), My reviews,
  My interviews, Mail templates, Reports, Talent pool, list of open openings (with
  candidate counts), closed openings collapsed at the bottom, and the **user handbook
  (PDF)** pinned to the footer.
- **Main content:** per opening the pipeline **board** (default) or **table**;
  Triage/pool/reports as list/dashboard views. Each stage carries an accent color used
  consistently on board columns, table chips and the reports funnel. The board header
  offers a name/email filter and the **"Stuck" attention lens** (§10.6); cards show
  screener avatars with a voted-ring and an aging time-in-stage (amber ≥ 14 d,
  red ≥ 30 d). Stages page at 100 cards ("Show more"); the table sorts per column and
  supports bulk move / bulk reject for managers.
- **Candidate detail:** opens in `NcAppSidebar` over the board with tabs:
  **Profile** (contact, AI summary, source, duplicate link, documents section:
  list + preview + upload), **Votes** (votes + own vote form + assigned
  screeners), **Interviews**, **Offer** (role-gated tab), **Log** (timeline +
  comment thread with mentions). Five tabs, because `NcAppSidebar` gives every
  tab an equal share of the strip and ellipsizes the labels beyond that.
- **Empty states** everywhere with `NcEmptyContent` and a clear next action.
- Public pages (slot picking, pool consent) use the NC guest page layout, minimal,
  mobile-friendly, no login required, brute-force protected.
- Keyboard accessible (board operable without drag-and-drop: stage change also via
  card menu), ARIA labels, focus management in modals — target WCAG 2.1 AA like NC core.
- All strings translatable (`t('recruiting', …)`), RTL-safe layout; a complete
  German translation ships in `l10n/`.

## 6. Architecture & technical requirements

- **Backend:** PHP 8.2+, `OCP\AppFramework` (Controllers → Services → Mappers), strict
  types, DI via `Application::register`. No direct DB access outside mappers; all
  queries via `IQueryBuilder`. OpenAPI-annotated OCS endpoints
  (`#[ApiRoute]`/attribute routing, NC 35 style) so `openapi.json` can be generated.
- **Frontend:** Vue 3 + `@nextcloud/vue` (v9), Pinia for state, Vite build via
  `@nextcloud/vite-config`. `@nextcloud/axios`, `@nextcloud/router`, `@nextcloud/l10n`,
  `@nextcloud/moment` for dates. No custom CSS colors — NC CSS variables only
  (dark mode for free). Icons from `vue-material-design-icons`.
- **Background jobs:** `MailFetchJob` (5 min), `RetentionJob` (daily), `OfferExpiryJob`
  (daily), `DigestJob` (hourly, sends when a user's slot is due).
- **occ commands:** `recruiting:fetch-mail`, `recruiting:retention:run [--dry-run]`
  — same services as the jobs, for ops/debugging. (The GDPR export is a
  permission-checked HTTP endpoint only; see §10.2.)
- **Security:** CSRF default-on, rate limiting (`#[BruteForceProtection]`,
  `#[AnonRateLimit]`) on public endpoints, tokens ≥ 32 random chars via `ISecureRandom`,
  IMAP password via `ICrypto`, per-request permission checks server-side (the frontend
  only *hides*, never *protects*). Uploaded documents: size limit (default 20 MB),
  allow-list of mime types (pdf, common office/image formats, txt).
- **Capabilities/limits:** designed for small-to-mid volume (thousands of candidates,
  dozens of openings) — proper indexes, paginated lists, no N+1 queries on the board.
- **Repo layout:** standard NC app skeleton (`appinfo/`, `lib/`, `src/`, `js/` (built),
  `templates/`, `tests/`, `l10n/`). AGPL-3.0-or-later. `info.xml` category `office`,
  screenshots + description for app store.

## 7. Quality bar

- **Tests:** PHPUnit unit tests for all services (permission logic, mail rendering,
  IMAP parsing with fixture .eml files, retention, offer state machine, slot
  computation); Vitest component tests for vote form, board stage moves, mail preview.
  CI must run php-cs-fixer (nextcloud/coding-standard), Psalm, PHPUnit, ESLint, Vitest.
- **The offer state machine and the permission matrix are the two highest-risk areas —
  test them exhaustively** (every role × every endpoint class).
- No PHP warnings, no browser console errors, Lighthouse-clean public pages.
- Documentation: `README.md` (user-facing, with screenshots) and this spec kept current
  with every behavior change.

## 8. Implementation phases

**Phase 1 — Core hiring flow (MVP, usable end-to-end):**
skeleton + roles/permissions, openings CRUD + team, manual intake, IMAP ingestion +
triage inbox + auto-acknowledgement, pipeline board + table, candidate detail sidebar,
documents (appdata storage + preview), screening assignments/votes/comments,
interview scheduling incl. free/busy slots, public candidate confirmation, calendar
events + Talk rooms, mail templates + preview + rejection flow, timeline, notifications
+ Activity, manual candidate delete, unified search.

**Phase 2 — Offers, compliance & insight:**
offer record + approval workflow + response tracking + expiry job, GDPR automation
(retention job, anonymization, export ZIP), rejection reasons everywhere, basic
reports dashboard.

**Phase 3 — Automation & delight:**
AI features (CV parsing/summaries, drafting, screening hints), talent pool +
consent flow, digest mails, per-opening Talk conversations + bot events,
duplicate detection polish, app store release prep.

Each phase ships independently releasable and fully tested; later-phase concerns
(e.g. `pool_member`, `ai_summary`, rejection_reason columns) are already in the
Phase 1 schema to avoid churn.

## 9. Explicit non-goals (v1)

- No public application web form / careers page (email + manual only).
- No configurable pipelines or scorecards.
- No offer-letter/contract document generation or e-signing.
- No multi-tenant separation beyond openings (one HR org per instance).
- No job-board integrations (LinkedIn etc.).
- No automated decisions of any kind by AI.

## 10. As built — implementation record (v0.3.x)

All three phases are implemented and verified (unit tests + live end-to-end
tests against a real instance). This section records how the spec materialized
in the UI, decisions taken during implementation, and the few places where
reality forced a deviation. The user-facing walkthrough of every screen lives
in the [README](README.md#how-it-works--screen-by-screen); this section is the
engineering record.

### 10.1 Refinements beyond the original text

- **Anti-anchoring is enforced everywhere** (§4.3): vote values are hidden
  server-side from interviewers without an own vote — in the detail view, on
  the board card tally, in timeline/activity entries (which never carry the
  vote value), so the rule cannot be bypassed through any surface.
- **Fail-safe rejection mail** (§4.5): the mail is sent *before* the stage
  changes; if sending fails the candidate is *not* marked rejected — no
  silently-rejected-but-uninformed state can occur.
- **Self-scheduling link resilience** (§4.4): if the (editable) invitation
  mail loses the scheduling link, the backend re-appends it before sending.
  The public page renders slot times in the *candidate's* browser timezone.
- **AI extraction never overwrites** (§4.7): intake analysis fills only
  *empty* contact fields, validates formats (email/phone), skips anonymized
  candidates, and stores at most a 1000-character summary. Vote-permission
  users get hints; manage-permission users get drafts and summaries. With no
  TaskProcessing provider, every AI surface disappears and the API returns
  clean errors.
- **Offer state machine is explicit** (§4.6): the transition table lives in
  `Offer::TRANSITIONS` (draft → pending_approval → approved → sent →
  accepted/declined/negotiating; expiry only from open states; withdraw from
  any non-terminal state) and every endpoint validates against it.
  Self-approval is rejected; the approver gains *scoped* read access to
  exactly that candidate; only one active offer per candidate exists at a
  time.
- **Anonymization keeps statistics honest** (§4.8): stage history, dates,
  rejection reasons and bare vote values survive; name, contact data,
  documents, mail bodies, comments, AI summary and pool state are wiped.
  Pool membership expiry reuses the same anonymization path.
- **Exports exclude reviewer identities** (§4.8): the data-access ZIP contains
  the candidate's profile, correspondence, interviews, offer terms and
  documents, plus vote *values* only — reviewer names and internal team
  comments are the reviewers' personal data, not the candidate's.
- **Duplicate detection** (§4.2): same address + same opening within 90 days
  flags the card and links to the earlier application; triage assignment
  re-runs the check.
- **Every consequential action explains itself in the UI**: hint texts and
  tooltips on the board, triage, pool, reports, all sidebar tabs, the offer
  workflow, the reject dialog and both settings pages state what a click does
  (who gets notified, what is logged, what is irreversible).

### 10.2 Deviations from the original text

- **Talk bot events (§4.10) are not implemented.** Talk's public server API
  (`OCP\Talk\IBroker`) can create/delete conversations but can neither post
  messages nor modify members after creation. The team conversation is
  created with the current hiring team and linked from the opening header;
  changing the team means recreating the room. Bot event messages would
  require registering a webhook-based Talk bot — deferred.
- **Digest cadence** (§4.9) is interval-based (daily ≈ every 22 h, weekly
  ≈ every 6.8 days, checked hourly) rather than fixed-time delivery, and a
  digest is skipped entirely when it would be empty.
- **Reporting** (§4.11) ships without CSV export and source tracking, as
  phased; "ever per stage" is expressed through avg time-in-stage rather than
  a second funnel.
- **The GDPR export is HTTP-only**, not an occ command as §6 originally
  suggested: it is recruiter-gated per candidate, and a shell command would
  sit outside that permission check.

### 10.5 Second hardening pass (v0.3.3)

A review of the v0.3.2 changes themselves plus a release-readiness check:

- **The activity stream obeys the confidentiality rules too.** Offer entries
  only reach people who may see offer data, no entry reaches somebody who may
  not open that candidate, and mail subjects never travel with an event —
  §10.4 had closed the timeline but not this second surface.
- **"My reviews" re-checks visibility**, because a screening assignment
  outlives removal from a hiring team.
- **Cancelling an interview really cancels the calendar event.** `OCP\Calendar`
  can only create objects (`createFromString` refuses an existing UID), so the
  event silently survived cancellation; it now goes through the CalDAV backend,
  which also triggers the iTIP CANCEL to attendees. This is the one place where
  the app deliberately uses a non-OCP API.
- **Confirming a slot is atomic** — the public page has no session and can be
  submitted twice, which produced two calendar events and two Talk rooms.
- **The sidebar ignores out-of-order responses**; hiring-team roles are cached
  per request, removing the last per-candidate query from board rendering.
- Long document names keep their beginning and extension, non-ASCII names
  download correctly (RFC 6266), and a failed digest send is retried instead of
  consuming the day's window.
- **Release scaffolding added**: `composer.json` (lint/psalm/test scripts),
  `psalm.xml`, ESLint/Stylelint/php-cs-fixer configs, `REUSE.toml` + `LICENSES/`,
  `CHANGELOG.md`, a `Makefile` with an `appstore` target, and `tests/phpunit.xml`
  + `tests/bootstrap.php` so the unit suite runs from the repo without booting a
  server. Still missing before an app-store submission: `screenshots/` (and the
  matching `<screenshot>` entries in `info.xml`) and translations, which are
  generated from Transifex at release time.

### 10.4 Hardening pass (v0.3.2)

A full multi-angle review of the finished app produced these corrections; the
notable ones are recorded because they document rules the code now enforces:

- **Confidentiality of the timeline.** Sent-mail bodies (an offer mail states
  the salary) and offer events are stripped for anyone who may not see offer
  data — the timeline can no longer be used as a way around `canSeeOffers`.
- **Offer approvers** get access to their candidate whatever role they
  otherwise hold; previously an interviewer picked as approver was locked out
  of the decision they were asked to make.
- **Reports are restricted to managers, observers and recruiters.**
  Interviewers are scoped to their assigned candidates, so whole-opening
  funnels and offer counts are not theirs to see.
- **People search** is limited to recruiters and hiring managers; it used to
  be an account-enumeration endpoint for every user of the instance.
- **Terminal stages are final by default.** `StageService` refuses to leave
  hired/rejected/withdrawn unless a human moves the candidate explicitly, so a
  late offer response or an old interview link can no longer resurrect
  somebody; recording an acceptance for a finished candidate now returns a
  clear error instead.
- **Ingestion is loss-proof:** over-long sender names are clamped to the
  column size (an insert failure used to drop the application permanently),
  and every `[tag]` in a subject is considered, so relay prefixes like
  `[EXTERNAL]` no longer defeat opening routing.
- **Anonymization** also strips document names (a CV filename identifies the
  person) and offer notes, and **hard delete** removes interview slots and
  attendee rows that used to be orphaned.
- **File endpoints** (document view, GDPR export) are reachable from the
  browser again — they are plain navigations and were failing the CSRF check —
  and now share the API error mapping, so a storage failure is a logged 500
  rather than a silent 404. Export ZIPs disambiguate duplicate document names
  and use `ITempManager`.
- **Performance:** board, triage and review lists are rendered from batched
  queries (previously four queries per candidate), the openings list fetches
  all teams at once, and the digest queries new applications directly instead
  of loading every candidate.

### 10.6 Scale, replies and polish (v0.5.0)

- **Reply threading + idempotent ingestion** (§4.2) — see the intake section;
  new `reply_token` column, `recruiting_mail_seen` table, `candidate_replied`
  notification, replies render as "The candidate replied" on the timeline.
- **Board pagination:** `GET …/candidates` returns up to 100 cards per stage
  (`{cards, page}`); `?stage=&offset=` pages within a stage. Column headers show
  the true stage totals (from `stageCounts`), a "Show N more" button loads the
  rest. Interviewers still get their full (assignment-bounded) list in one
  response. Every batch `IN()` lookup is chunked to 500 ids (`ChunkedInQuery`)
  for Oracle/SQLite parameter limits.
- **Board filter:** a client-side name/email filter over the loaded cards, in
  both board and table view. While filtering, "show more" is hidden — the
  filter only searches what is loaded.
- **CI:** `.github/workflows/ci.yml` runs php-lint, cs:check, psalm and the
  unit tests (PHP 8.2/8.4) plus eslint, stylelint and the vite build;
  `make lint` now includes psalm. New guard tests pin the offer permission
  matrix and the reply-threading rules.
- **German translation** (`l10n/de.{js,json}`): informal ("du") toward staff,
  formal ("Sie") in candidate-facing mail templates and public pages.
- **Native `confirm()` replaced** with `@nextcloud/dialogs` confirmation
  dialogs (themed, translatable) at all eight destructive-action sites.
- **Document responses** send `X-Content-Type-Options: nosniff` and an
  explicit CSP; **AI polling** backs off (1s → 5s) instead of a fixed 2s
  hammer.

### 10.7 Board & candidate-experience polish (v0.6.0)

- **The "Stuck" attention lens** (§4.1): `serializeCards` computes per-card reasons
  server-side (`waiting` ≥7 d in New, `no_votes` ≥5 d in Screening with zero
  votes, `no_interview` ≥3 d in Interview without an upcoming slot,
  `no_offer` ≥3 d in Offer without an active offer, `offer_expiring` when a
  sent offer's validity ends within 3 days, `stale` ≥30 d in any stage).
  Board and table filter on it; the card shows a ⚠️ chip with the reasons.
- **Screener piles**: cards carry `screeners` (uid, display name, voted flag)
  — the voted flag is the only vote information that crosses the
  anti-anchoring line (§4.3), never the value.
- **Stage accent colors** shared via `src/css/_stages.scss` between board
  columns, table chips and the reports funnel.
- **Bulk actions** (§4.5): table selection with bulk move and bulk reject;
  bulk reject walks the RejectModal per candidate so every mail is reviewed.
- **Sortable table** per column, third click restores server order.
- **Candidate .ics** (§4.4): `GET /i/{token}/event.ics` (same brute-force
  and rate limits as the other public routes) for confirmed interviews.
- **Ingestion heartbeat**: every fetch run stores `ingestion_status`
  (ranAt/fetched/created/error) in app config; recruiters see it in triage
  via `GET /api/ingestion-status`.

### 10.8 User handbook (v0.7.0)

- `docs/handbook.html` (+ `docs/img/` UI crops) is the source of
  `docs/handbook.pdf` — twelve chapters for non-technical HR users, rendered
  with headless Chrome (`make handbook`). Served by
  `GET /apps/recruiting/handbook` (`page#handbook`, logged-in users) and
  linked in the app navigation footer. Screenshots are generated against
  devel via the seed/shoot scripts (see the session scratchpad; demo login
  `hrtest`/`devtest`).

### 10.3 Surface map (routes → screens)

| Surface | Route | Access |
|---|---|---|
| Pipeline board / table | `#/openings/{id}` | opening team + recruiters |
| Candidate sidebar (5 tabs) | any view + card click, deep link `#/candidate/{id}` | per-role, offers gated separately |
| Triage inbox | `#/triage` | recruiters |
| My reviews / My interviews | `#/my-reviews`, `#/my-interviews` | everyone |
| Mail templates | `#/templates` | recruiters |
| Reports | `#/reports` | team members + recruiters (scoped) |
| Talent pool | `#/pool` | recruiters |
| Public slot picking | `/apps/recruiting/i/{token}` | tokenized, no account, rate-limited |
| Interview calendar file (.ics) | `/apps/recruiting/i/{token}/event.ics` | tokenized, confirmed interviews only |
| Public pool consent | `/apps/recruiting/pool/{token}` | tokenized, no account, rate-limited |
| User handbook (PDF) | `/apps/recruiting/handbook` | logged-in users |
| Ingestion status | `GET /api/ingestion-status` | recruiters |
| Admin settings | Administration → Recruiting | admins |
| Digest setting | Personal settings → Groupware | everyone |

Background jobs: mail fetch (5 min), offer expiry (daily), retention (daily),
digest (hourly). occ: `recruiting:fetch-mail`,
`recruiting:retention:run [--dry-run]`.

<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Changelog

All notable changes to this app are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[semantic versioning](https://semver.org/).

## [Unreleased]

## [0.7.1] - 2026-08-17

### Fixed

- CI actually runs on a plain checkout: the vendor-bin tool manifests
  (php-cs-fixer, psalm, nextcloud/ocp) are committed, the composer scripts
  call the tools by their real paths, the npm lockfile is in sync with
  package.json, and the unit-test bootstrap loads OCP and its psr/*
  dependencies from vendor-bin outside a server checkout. CI runs on
  PHP 8.3/8.4 and Node 24, matching the toolchain requirements.
- First full eslint/stylelint/psalm pass over the codebase: style-only
  fixes throughout, two redundant casts, a dead null-coalesce, explicit
  float casts in the report averages, and the IMAP socket is nulled before
  closing.

## [0.7.0] - 2026-08-17

### Added

- A user handbook (PDF): twelve chapters covering every feature, written for
  HR people and hiring managers, with annotated screenshots. Linked at the
  bottom of the app navigation and served at /apps/recruiting/handbook.
  Source and images live in docs/; `make handbook` regenerates the PDF.

### Changed

- All screenshots retaken with the 0.6 features (stage colors, Stuck lens,
  screener rings, bulk actions, heartbeat, reply threading, .ics download)
  and a richer demo dataset; a 19th screenshot shows the candidate's
  confirmation page.
- The board's attention filter is labeled "Stuck · N" (matching the card
  chips) so it no longer truncates; toolbar buttons never shrink below their
  label.
- Table stage chips use a colored dot instead of an edge accent.
- Screener avatars on cards no longer show the person's online status —
  only the voted ring — and the ring color is stronger.

## [0.6.0] - 2026-08-17

### Added

- A "Needs attention" lens on the board and table: candidates stuck without
  votes, without a booked interview, without an active offer or with an offer
  about to expire get a ⚠️ chip and can be filtered with one click. Computed
  server-side so every surface agrees on what "stuck" means.
- Screener avatars on the board cards, with a green ring once that person has
  voted — only the fact that they voted, never the value.
- Bulk actions in the table view: select candidates, move them to a stage or
  reject them — the rejection mail preview still appears per candidate,
  nothing goes out unreviewed.
- The table view is sortable by every column (third click restores the
  original order).
- Candidates can add their confirmed interview to their own calendar: the
  public page offers an .ics download.
- The triage inbox shows the mailbox heartbeat: when it was last checked, how
  many mails arrived — and a warning when the last check failed.

### Changed

- Each pipeline stage has an accent color, used consistently by the board
  columns, the table chips and the reports funnel.
- Time-in-stage on cards turns amber after two weeks and red after a month.
- Board cards glide between columns instead of teleporting; empty columns
  center their message and show a dashed outline while a card is dragged.
- Reports: stat tiles show the weekly trend, the funnel uses the stage
  colors, and the current week is highlighted in the applications chart.

## [0.5.2] - 2026-08-17

### Changed

- The booking dialog states the Talk decision explicitly: the switch now reads
  "Create a Talk video room for this interview" (instead of "Video
  interview…"), explains when the room is created, and the location field
  mentions it also takes a video link from another tool.

## [0.5.1] - 2026-08-17

### Changed

- The mail templates page uses the full width: one column per mail type,
  side by side, instead of four sections stacked into a narrow strip.
- The placeholder list moved from the page intro into the template editor,
  as clickable chips that insert at the cursor — each mail type only offers
  the placeholders it can actually resolve.

## [0.5.0] - 2026-08-17

### Added

- Candidate replies thread: mails to candidates carry a per-candidate
  Reply-To address, and an answer lands on that candidate's timeline
  (attachments become documents, the hiring managers are notified) instead
  of opening a duplicate application in triage.
- The board shows real per-stage totals, loads 100 cards per stage and pages
  the rest with "Show more"; a filter box narrows the loaded cards by name
  or email.
- German translation (informal toward staff, formal in candidate-facing
  mails and public pages).
- CI on GitHub Actions: php-lint, php-cs-fixer, psalm and the unit tests on
  PHP 8.2/8.4, plus eslint, stylelint and the frontend build. `make lint`
  now runs psalm too. New unit tests pin the offer permission matrix and
  the reply-threading rules.

### Changed

- All eight native browser confirm() prompts are proper Nextcloud dialogs:
  themed, translatable, with an explicit red destructive button.
- The AI status polling backs off from 1s to 5s instead of firing every 2s.

### Fixed

- Ingestion is idempotent: a crash between processing a mail and marking it
  seen no longer ingests the same application twice (Message-ID ledger,
  written only after successful processing — a mail can be retried, never
  lost or duplicated).
- Batched board lookups chunk their IN() clauses (500 ids), so openings with
  thousands of candidates no longer exceed Oracle/SQLite parameter limits.
- Candidate documents are served with X-Content-Type-Options: nosniff and an
  explicit Content-Security-Policy.

## [0.4.0] - 2026-08-17

### Changed

- Rejecting a candidate is a red button at the bottom of the profile tab
  instead of an entry in the sidebar's ⋮ menu. It is hidden once a candidate is
  rejected, hired or withdrawn.

## [0.3.9] - 2026-08-17

### Changed

- Triage rows are a single line: the attachment count sits next to the name and
  the waiting time next to the row menu, instead of on a row of their own.

## [0.3.8] - 2026-08-17

### Changed

- Candidate cards show the attachment-count chip again.

## [0.3.7] - 2026-08-17

### Changed

- The triage inbox no longer carries an "Assign to …" dropdown per row: opening
  the application offers the same assignment in its profile tab. Deleting spam
  stays in the row's ⋮ menu.
- Candidate cards no longer show the attachment-count chip.

## [0.3.6] - 2026-08-17

### Fixed

- Descenders in the candidate sidebar tab labels ("Log") were sliced off: the
  label span clips its overflow for an ellipsis it no longer needs, over a line
  box too short for the glyph.

## [0.3.5] - 2026-08-16

### Changed

- The candidate sidebar has five tabs instead of six: documents moved into the
  profile tab, where they sit below the contact details. "Screening" is now
  "Votes" and "Activity" is now "Log".

### Fixed

- The candidate sidebar tab labels were cut off ("Docu…", "Screen…",
  "Intervi…"): the tab strip gave every tab the same width regardless of its
  label. Tabs are now as wide as their label and the strip scrolls if a narrow
  sidebar or a long translation needs more room.

## [0.3.4] - 2026-08-16

### Added

- Screenshots of every screen in `screenshots/`, linked from the README and
  referenced in `info.xml` for the app store.

### Fixed

- The "Reports" and "Talent pool" headlines were hidden behind the floating
  navigation toggle.
- Labels and values in the candidate profile and offer details ran into each
  other, because the server stylesheet right-aligns `dt` in the two-column
  layout.
- The candidate sidebar stayed open when switching to another view (reports,
  talent pool, …) and showed a candidate that had nothing to do with it.

## [0.3.3] - 2026-08-16

### Fixed

- The activity stream now follows the same confidentiality rules as the
  candidate view: offer entries only reach people who may see offer data, no
  entry reaches somebody who may not open that candidate, and mail subjects
  never travel with an event.
- "My reviews" re-checks visibility, so a screener dropped from a hiring team
  no longer keeps seeing candidate cards their detail view would refuse.
- The candidate sidebar ignores out-of-order responses; clicking through
  candidates quickly can no longer show one candidate's data under another's.
- Confirming an interview slot is now atomic — a double-submitted public
  confirmation can no longer create two calendar events and two Talk rooms.
- Long document names keep their beginning (and extension) instead of their
  tail, and non-ASCII names download correctly (RFC 6266).
- A failed digest send no longer consumes that digest window; the next run
  retries.
- Cancelling an interview now really removes the calendar event (and sends the
  cancellation to the interviewers). The event used to stay in everybody's
  calendar because the public calendar API cannot overwrite an existing
  object — cancellation goes through the CalDAV backend instead.

### Changed

- Hiring-team roles are cached per request, removing the last per-candidate
  query from board rendering.

## [0.3.2] - 2026-08-16

### Fixed

- Candidate documents and the GDPR export are reachable from the browser
  again (they are plain navigations and were rejected by the CSRF check).
- Outgoing mail bodies and offer events are stripped from the timeline for
  roles that may not see offer data — an offer mail states the salary.
- An interviewer designated as offer approver can see and decide on that
  offer instead of receiving a 403.
- Applications with very long sender names are no longer lost during mail
  ingestion, and every `[tag]` in a subject is considered when routing, so
  relay prefixes like `[EXTERNAL]` no longer defeat it.
- Terminal candidates stay terminal: a late offer acceptance or an old
  interview link cannot resurrect somebody who was rejected or withdrew.
- GDPR: hard delete removes interview slots and attendees, anonymization also
  strips document names and offer notes, and the export keeps documents that
  share a name.
- Reports are limited to hiring managers, observers and recruiters; people
  search is limited to recruiters and hiring managers.
- The offer mail template is manageable in the templates view.

### Changed

- Board, triage and review lists render from batched queries instead of
  several queries per candidate.

## [0.3.0] - 2026-08-16

### Added

- AI assistance via the Assistant/TaskProcessing API: application summaries
  and contact extraction on intake, screening hints, mail drafts and
  interview question suggestions — optional, labeled and human-reviewed.
- Consent-based talent pool with a public consent page and automatic
  expiry.
- Optional personal email digest (daily or weekly).
- A Talk conversation per opening for the hiring team.

## [0.2.0] - 2026-08-16

### Added

- Offers with a four-eyes approval workflow, response tracking and automatic
  expiry warnings.
- GDPR automation: configurable retention with automatic anonymization, plus
  a one-click data-access export.
- Reports: funnel, time-in-stage, time-to-hire, rejection reasons and
  applications per week.

## [0.1.0] - 2026-08-16

### Added

- First release: job openings with hiring teams, a pipeline board, email and
  manual intake with a triage inbox, screening votes with comments, interview
  scheduling with candidate self-service, mail templates, notifications,
  activity and unified search.

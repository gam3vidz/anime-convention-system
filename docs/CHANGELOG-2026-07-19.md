Delta H Command Center update — July 19, 2026

Interface
- Refreshed the app shell, branding, cards, responsive behavior, focus states,
  and mobile layouts.
- Matched the compact v0 command-center style with a narrow flat sidebar,
  dense tables, subtle borders, and purple active navigation states.
- Added a dedicated Safety navigation item and incident operations workspace.

Volunteer T-shirt handout
- Active Accounts now shows every volunteer's requested T-shirt size.
- Size summary chips show picked-up and total counts at the top of the roster.
- Managers can filter by name, department, or pickup status and mark a shirt as
  picked up with one click.
- Pickup time and the manager who recorded it are stored, and each change is
  written to the system log.

Safety incidents
- Role-protected incident queue with search, status, and severity filters.
- Create and edit reports with occurrence time, location, narrative, response,
  involved people, witnesses, medical attention, and law-enforcement flags.
- Upload protected JPG, PNG, WebP, GIF, or PDF evidence up to 12 MB.
- Attach Imgur or other HTTPS evidence links.
- Evidence removal and incident edits are preserved in the incident audit trail.
- Uploaded evidence is denied direct web access and streamed only after a
  Safety authorization check.

Missed shift alerts
- Configure an actual event date and 5- or 10-minute grace period per shift.
- Select one or more Discord-linked department heads or designated recipients.
- Customize messages with shift and volunteer placeholders.
- DMs are de-duplicated per rule/date/volunteer/recipient and failures retry up
  to three times.
- Added manual alert scans and recent delivery history in Management.
- Added a secret-protected endpoint for a once-per-minute cron task.

Deployment
- The API creates the five Safety/alert tables and new T-shirt pickup columns automatically.
- Guest Relations now records airline confirmation and flight numbers, assigns
  an approved Discord-linked pickup person, masks confirmation numbers in the
  flight list, and sends privacy-safe Discord updates when flight status or
  arrival timing changes.
- Expanded guest_flights.flight_status to 255 characters to prevent truncation
  errors from flight-provider messages.
- Pickup assignment choices are restricted to approved, non-blacklisted users
  with linked Discord accounts and the configured Guest Relations Discord role.
- Added private volunteer management profiles with strengths, coaching areas,
  next-year recommendations, management summaries, and an author-attributed,
  year-specific note history. Department managers can document only their own
  volunteers; admins retain organization-wide access.
- Corrected external API error handling so a flight-provider 401/403 is no
  longer mislabeled as a Discord member-role failure.
- delta-h-safety-alerts.sql is included for manual migration workflows.
- Set discord_safety_role_id and follow SAFETY-ALERTS-SETUP.txt before go-live.

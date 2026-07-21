# Project brief

## Purpose

Anime Convention System is intended to give convention organizers one coherent operational system for planning the event, accepting applications, coordinating staff, publishing schedules, checking in attendees, and communicating changes.

The first release should be useful to a real small convention while remaining simple enough for a new organizer to understand without formal training.

## Primary users

1. **Convention administrators** — configure the event and control access.
2. **Programming staff** — manage panel applications, rooms, and schedules.
3. **Registration staff** — find attendees and perform check-in.
4. **Volunteer coordinators** — organize roles and shifts.
5. **Vendors, artists, and panelists** — submit and track applications.
6. **Attendees** — browse the public schedule and receive updates.

## Proposed first vertical slice

The first implementation milestone should prove the complete publishing loop:

1. An administrator creates a convention.
2. The administrator defines dates, rooms, and operating hours.
3. Staff creates scheduled events.
4. The system detects room and time conflicts.
5. Staff publishes the schedule.
6. Attendees can browse the published schedule on desktop and mobile.
7. Staff changes an event and the public schedule reflects the update.

This establishes the core data model and a visible, testable user outcome before registration, payments, or application workflows add complexity.

## Later MVP modules

- Attendee registration and check-in
- Panel and guest applications
- Vendor and artist-alley applications
- Staff roles and permissions
- Volunteer shifts
- Announcements and notifications
- CSV import/export
- Operational dashboard and audit log

## Initial non-goals

These should not block the first working release:

- Hotel-room booking
- Full accounting or general-ledger functions
- Native mobile applications
- Badge-printing hardware integrations
- Complex multi-convention organization hierarchies
- Building a custom payment processor

## Data and safety baseline

- Collect only information needed to operate the convention.
- Keep attendee and applicant data private by default.
- Separate public schedule data from administrative records.
- Use explicit roles rather than a single shared administrator password.
- Record important administrative changes in an audit log.
- Never commit production credentials or attendee data to this repository.

## Decisions to settle during implementation

- Expected convention size and peak concurrent users
- Whether the first release needs online payments
- Badge and QR-code requirements
- Email or SMS notification provider
- Single-event versus recurring annual convention model
- Preferred hosting environment and domain

These decisions can be made incrementally; none are required to begin the schedule-publishing vertical slice.

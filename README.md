# Anime Convention System

A practical, self-hostable system for running small and medium-sized anime conventions without stitching together a dozen spreadsheets and disconnected services.

## Project status

**Bootstrap stage.** The repository foundation is in place; product requirements and the first implementation milestone come next.

## Planned MVP

- Convention setup: name, dates, venue, rooms, and operating hours
- Schedule management for panels, screenings, workshops, and special events
- Attendee registration and fast check-in
- Staff and volunteer accounts with role-based access
- Panelist, vendor, and artist-alley applications
- Announcements and schedule-change notifications
- Mobile-friendly public schedule
- Basic operational reports and exports

## Product principles

- Easy for nontechnical convention staff to operate
- Mobile-first for attendees and staff working the floor
- Accessible and keyboard-friendly
- Private by default with clear role permissions
- Self-hostable without depending on expensive SaaS products
- Auditable: important administrative changes should be traceable

## Repository layout

- `docs/PROJECT_BRIEF.md` — initial scope and product direction
- `scripts/validate_repository.py` — repository foundation check
- `tests/` — automated tests
- `.github/workflows/validate.yml` — GitHub Actions validation

## Development

Run the current foundation checks with:

```bash
python3 -m unittest discover -s tests -v
python3 scripts/validate_repository.py
```

## Next milestone

Turn the product brief into the first working vertical slice: create a convention, define rooms and time slots, publish a public schedule, and verify it on desktop and mobile.

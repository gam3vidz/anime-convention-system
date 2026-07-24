"""Capture desktop + mobile evidence for the eight Pages screen-set routes.

Drives the real index.html/core.js with a real-SHAPED session fixture
(intercepted at ./api/api.php) so every renderer runs against live-shaped
session collections. Screenshots each of the eight routes at desktop and
mobile widths and reports per-view render results. This is evidence capture,
not a fixture that ships — it lives under tests/ and writes to session image cache.
"""

import json
import threading
import functools
import http.server
import socketserver
import pathlib
from playwright.sync_api import sync_playwright

ROOT = pathlib.Path(__file__).resolve().parents[1]
OUT = pathlib.Path("/home/hermes/.hermes/cache/images/delta-h-pages-evidence")
OUT.mkdir(parents=True, exist_ok=True)

# ── real-shaped session fixture (matches applyState() field names) ──
USERS = [
    {"id": 1, "name": "Coordinator One", "email": "coord@delta-h.test", "discord": "coord#0001",
     "rank": "Coordinator", "role": "admin", "status": "approved", "department": "Con Ops",
     "gender": "Female", "allergies": "None", "hotelNeeded": "Yes", "hotelRoom": "Room 301",
     "hotelCheckedIn": True, "shirtSize": "M", "clockedIn": True, "shiftIds": ["1", "2"],
     "canGuestRelations": True, "canSafety": True, "canVendorHall": True,
     "availability": {"Thursday": ["8:00 AM", "9:00 AM"], "Friday": ["1:00 PM", "2:00 PM"]}},
    {"id": 2, "name": "Bela Toth", "email": "bela@delta-h.test", "discord": "bela#1142",
     "rank": "Volunteer", "role": "user", "status": "approved", "department": "Tech Maids",
     "gender": "Male", "allergies": "Gluten intolerance", "hotelNeeded": "Yes", "hotelRoom": "Room 301",
     "hotelCheckedIn": True, "shirtSize": "L", "clockedIn": True, "shiftIds": ["3"], "buddy_request": "Coordinator One"},
    {"id": 3, "name": "Priya Nair", "email": "priya@delta-h.test", "discord": "priya#7733",
     "rank": "Volunteer", "role": "user", "status": "approved", "department": "Con Suite",
     "gender": "Female", "allergies": "Vegan", "hotelNeeded": "No", "hotelRoom": "",
     "hotelCheckedIn": False, "shirtSize": "S", "clockedIn": False, "shiftIds": ["2"]},
    {"id": 4, "name": "Sam Okafor", "email": "sam@delta-h.test", "discord": "sam#5591",
     "rank": "Volunteer", "role": "user", "status": "approved", "department": "Safety",
     "gender": "Other", "allergies": "None", "hotelNeeded": "Yes", "hotelRoom": "Room 302",
     "hotelCheckedIn": False, "shirtSize": "XL", "clockedIn": False, "shiftIds": []},
]
SHIFTS = [
    {"id": "1", "title": "Registration Desk", "department": "Con Ops", "day": "Thursday",
     "time": "8:00 AM - 12:00 PM", "hours": 4, "capacity": 3, "note": "Check-in and wristbands"},
    {"id": "2", "title": "Con Suite Lunch", "department": "Con Suite", "day": "Friday",
     "time": "11:00 AM - 2:00 PM", "hours": 3, "capacity": 5, "note": "Hot lunch + dietary station"},
    {"id": "3", "title": "Tech Room Monitor", "department": "Tech Maids", "day": "Friday",
     "time": "4:00 PM - 8:00 PM", "hours": 4, "capacity": 2, "note": "Watch equipment"},
    {"id": "4", "title": "Teardown Crew", "department": "Con Ops", "day": "Sunday",
     "time": "2:00 PM - 8:00 PM", "hours": 6, "capacity": 8, "note": "Strike and load-out"},
]
STATE = {
    "csrfToken": "test-csrf",
    "user": USERS[0],
    "volunteers": USERS,
    "shifts": SHIFTS,
    "hotelRooms": [
        {"id": 1, "room_name": "Room 301", "capacity": 4, "gender": ""},
        {"id": 2, "room_name": "Room 302", "capacity": 2, "gender": "Other"},
        {"id": 3, "room_name": "Room 303", "capacity": 4, "gender": "Female"},
    ],
    "logs": [{"id": 1, "action": "login", "actor_name": "Coordinator One",
              "details": "Discord OAuth login", "created_at": "2026-07-22 14:32:18"}],
    "guestFlights": [
        {"id": 1, "guest_name": "Rev. Guest Speaker", "flight_number": "DL1247", "flight_date": "2026-09-18",
         "flight_status": "arrived", "assigned_user_id": 1, "confirmation_number": "ABC123",
         "departure_airport": "ATL", "arrival_airport": "ORD"},
        {"id": 2, "guest_name": "Worship Band", "flight_number": "AA8821", "flight_date": "2026-09-18",
         "flight_status": "in transit", "assigned_user_id": None, "confirmation_number": "",
         "departure_airport": "LAX", "arrival_airport": "ORD"},
        {"id": 3, "guest_name": "Dr. Panelist", "flight_number": "UA3356", "flight_date": "2026-09-19",
         "flight_status": "scheduled", "assigned_user_id": None, "confirmation_number": "",
         "departure_airport": "", "arrival_airport": ""},
    ],
    "pickupStaff": [{"id": 1, "name": "Coordinator One", "department": "Con Ops"},
                    {"id": 4, "name": "Sam Okafor", "department": "Safety"}],
    "incidents": [], "alertRules": [], "alertDeliveries": [], "vendorHallAssignments": [],
}

VIEWS = [
    ("availabilityPageView", "Availability"),
    ("shiftBoardView", "Shift Board"),
    ("myShiftsPageView", "My Shifts"),
    ("manageShiftsView", "Manage Shifts"),
    ("rosterPageView", "Volunteer Roster"),
    ("foodPageView", "Food & Counts"),
    ("hotelsPageView", "Hotels"),
    ("guestRelationsView", "Guest Relations"),
]


def main():
    results = []
    with sync_playwright() as p:
        browser = p.chromium.launch()
        context = browser.new_context()
        page = context.new_page()
        console_errors = []
        page.on("console", lambda m: console_errors.append(m.text) if m.type == "error" else None)
        page.on("pageerror", lambda e: console_errors.append(f"PAGEERROR: {e}"))

        def handle(route):
            url = route.request.url
            if "api.php" in url:
                route.fulfill(status=200, content_type="application/json", body=json.dumps(STATE))
            elif any(h in url for h in ("googleapis", "gstatic", "jsdelivr")):
                route.abort()
            else:
                route.continue_()

        page.route("**/*", handle)

        Handler = functools.partial(http.server.SimpleHTTPRequestHandler, directory=str(ROOT))
        httpd = socketserver.TCPServer(("127.0.0.1", 0), Handler)
        port = httpd.server_address[1]
        threading.Thread(target=httpd.serve_forever, daemon=True).start()
        page.goto(f"http://127.0.0.1:{port}/index.html")
        page.wait_for_selector("body.logged-in", timeout=8000)

        for view_id, label in VIEWS:
            page.eval_on_selector(f'.nav-item[data-view="{view_id}"]', "el => el.click()")
            page.wait_for_selector(f"#{view_id}.active", timeout=5000)
            content = page.eval_on_selector(f"#{view_id}", "el => el.innerText")
            has_h1 = page.eval_on_selector(f"#{view_id}", "el => !!el.querySelector('h1') || !!el.querySelector('.page-header')")
            # desktop
            page.set_viewport_size({"width": 1440, "height": 900})
            page.wait_for_timeout(120)
            page.screenshot(path=str(OUT / f"{view_id}-desktop.png"))
            # mobile
            page.set_viewport_size({"width": 390, "height": 844})
            # The source drawer deliberately animates its close transition;
            # wait past --t-med before assessing the settled mobile state.
            page.wait_for_timeout(350)
            mobile_geometry = page.evaluate("""() => {
                const sidebar = document.querySelector('#sidebar').getBoundingClientRect();
                const main = document.querySelector('#mainContent').getBoundingClientRect();
                return {
                    sidebarRight: Math.round(sidebar.right),
                    mainLeft: Math.round(main.left),
                    pageOverflow: document.documentElement.scrollWidth > window.innerWidth,
                    menuVisible: !!document.querySelector('#sidebarToggle') &&
                      getComputedStyle(document.querySelector('#sidebarToggle')).display !== 'none'
                };
            }""")
            page.screenshot(path=str(OUT / f"{view_id}-mobile.png"))
            page.set_viewport_size({"width": 1440, "height": 900})
            results.append((view_id, label, has_h1, len(content.strip()), mobile_geometry))

        browser.close()

    print(f"{'VIEW':<22}{'LABEL':<18}{'HEADER':<8}{'TEXT_LEN':<9}MOBILE RESULT")
    ok = True
    for view_id, label, has_h1, text_len, mobile in results:
        mobile_ok = (mobile["sidebarRight"] <= 0 and mobile["mainLeft"] == 0
                     and not mobile["pageOverflow"] and mobile["menuVisible"])
        passed = has_h1 and text_len > 40 and mobile_ok
        ok = ok and passed
        print(f"{view_id:<22}{label:<18}{str(has_h1):<8}{text_len:<9}{mobile} {'PASS' if passed else 'FAIL'}")
    errs = [e for e in console_errors if "Failed to load resource" not in e]
    print("\nconsole/page errors (excluding blocked external assets):", errs or "none")
    print("screenshots written to", OUT)
    if not ok or errs:
        raise SystemExit(1)


if __name__ == "__main__":
    main()

"""Browser evidence for the interactive Vendor Hall booth map.

Drives the real index.html/core.js against a real-shaped session fixture
(intercepted at ./api/api.php), navigates to the Vendor Hall view, and captures
desktop + mobile screenshots plus a booth-drawer screenshot. Verifies all 115
booth targets render, the searchable list is present, and the drawer shows the
append-only note history. Evidence only — writes outside the repo, ships nothing.
"""
import json
import threading
import functools
import http.server
import socketserver
import pathlib
from playwright.sync_api import sync_playwright

ROOT = pathlib.Path(__file__).resolve().parents[1]
OUT = pathlib.Path("/home/hermes/.hermes/cache/images/vendor-hall-evidence")
OUT.mkdir(parents=True, exist_ok=True)

USER = {
    "id": 1, "name": "Coordinator One", "email": "coord@delta-h.test", "discord": "coord#0001",
    "rank": "Coordinator", "role": "admin", "status": "approved", "department": "Con Ops",
    "gender": "Female", "allergies": "None", "hotelNeeded": "Yes", "shirtSize": "M",
    "clockedIn": True, "shiftIds": [], "canGuestRelations": True, "canSafety": True,
    "canVendorHall": True,
}
PENDING = {
    "id": 9, "name": "Pending Applicant", "email": "pending@delta-h.test", "discord": "pend#0009",
    "rank": "Volunteer", "role": "volunteer", "status": "pending", "department": "",
    "applied_department": "Con Ops", "appliedDepartment": "Con Ops", "gender": "Other",
    "hotelNeeded": "No", "shirtSize": "L", "clockedIn": False, "shiftIds": [],
}
ASSIGNMENTS = [
    {"spotCode": "A001", "vendorName": "Sakura Prints", "notes": "", "updatedBy": 1,
     "updatedByName": "Coordinator One", "updatedAt": "2026-07-24 10:15:00"},
    {"spotCode": "D001", "vendorName": "Neko Collectibles", "notes": "", "updatedBy": 1,
     "updatedByName": "Coordinator One", "updatedAt": "2026-07-24 11:02:00"},
    {"spotCode": "SG5", "vendorName": "Indie Alley Studio", "notes": "", "updatedBy": 1,
     "updatedByName": "Coordinator One", "updatedAt": "2026-07-24 12:20:00"},
]
NOTES = {
    "A001": [
        {"id": 1, "spotCode": "A001", "noteText": "Needs two 20A power drops on the back wall.",
         "authorName": "Coordinator One", "createdBy": 1, "createdAt": "2026-07-24 10:16:00"},
        {"id": 2, "spotCode": "A001", "noteText": "Confirmed corner booth; extra table approved.",
         "authorName": "Coordinator One", "createdBy": 1, "createdAt": "2026-07-24 13:40:00"},
    ],
    "D001": [
        {"id": 3, "spotCode": "D001", "noteText": "Large freight — schedule early load-in.",
         "authorName": "Coordinator One", "createdBy": 1, "createdAt": "2026-07-24 11:03:00"},
    ],
}
LOG_ROWS = [
    {"id": 30, "actor_user_id": 1, "actor_name": "Coordinator One", "action": "vendor_hall_note",
     "details": "Added a Vendor Hall note for position A001: Needs two 20A power drops on the back wall.",
     "created_at": "2026-07-24 10:16:00"},
    {"id": 29, "actor_user_id": 1, "actor_name": "Coordinator One", "action": "vendor_hall_save",
     "details": "Assigned Sakura Prints to Vendor Hall position A001.",
     "created_at": "2026-07-24 10:15:00"},
]
STATE = {
    "csrfToken": "test-csrf", "user": USER, "volunteers": [USER, PENDING], "shifts": [],
    "hotelRooms": [], "logs": [], "guestFlights": [], "pickupStaff": [], "incidents": [],
    "alertRules": [], "alertDeliveries": [], "vendorHallAssignments": ASSIGNMENTS,
    "vendorHallNotes": NOTES,
}
LOG_PAGE = {"logs": LOG_ROWS, "total": 73, "page": 1, "pageSize": 25, "totalPages": 3, "query": ""}


def main():
    results = {}
    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_context().new_page()
        console_errors = []
        page.on("console", lambda m: console_errors.append(m.text) if m.type == "error" else None)
        page.on("pageerror", lambda e: console_errors.append(f"PAGEERROR: {e}"))

        def handle(route):
            url = route.request.url
            if "api.php" in url:
                if "action=search_logs" in url:
                    request_body = route.request.post_data_json or {}
                    log_page = dict(LOG_PAGE)
                    log_page["query"] = request_body.get("q", "")
                    route.fulfill(status=200, content_type="application/json", body=json.dumps(log_page))
                else:
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

        # Navigate to the Vendor Hall view.
        page.eval_on_selector('.nav-item[data-view="vendorHallView"]', "el => el.click()")
        page.wait_for_selector("#vendorHallView.active", timeout=5000)
        page.wait_for_selector("#vendorHallMap .vendor-hall-spot", timeout=5000)

        results["targets"] = page.eval_on_selector_all("#vendorHallMap .vendor-hall-spot", "els => els.length")
        results["list_items"] = page.eval_on_selector_all("#vendorHallList .vendor-hall-list-item", "els => els.length")
        results["occupied_targets"] = page.eval_on_selector_all("#vendorHallMap .vendor-hall-spot.is-occupied", "els => els.length")
        results["notes_targets"] = page.eval_on_selector_all("#vendorHallMap .vendor-hall-spot.has-notes", "els => els.length")
        results["image_loaded"] = page.eval_on_selector("#vendorHallImage", "img => img.complete && img.naturalWidth > 0")
        results["all_buttons"] = page.eval_on_selector_all(
            "#vendorHallMap .vendor-hall-spot", "els => els.every(e => e.tagName === 'BUTTON' && e.getAttribute('aria-label'))")

        page.set_viewport_size({"width": 1440, "height": 900})
        page.wait_for_timeout(200)
        page.screenshot(path=str(OUT / "vendor-hall-desktop.png"))

        # Open a booth drawer with notes (A001).
        page.eval_on_selector('#vendorHallMap [data-vendor-spot="A001"]', "el => el.click()")
        page.wait_for_selector("#vendorHallDrawer:not([hidden])", timeout=4000)
        page.wait_for_timeout(150)
        results["drawer_notes"] = page.eval_on_selector_all("#vendorHallNoteList .vendor-hall-note", "els => els.length")
        results["drawer_title"] = page.eval_on_selector("#vendorHallDrawerTitle", "el => el.textContent")
        results["note_has_author"] = page.eval_on_selector(
            "#vendorHallNoteList .vendor-hall-note .vendor-hall-note-meta", "el => el.textContent.trim().length > 0")
        page.screenshot(path=str(OUT / "vendor-hall-drawer-desktop.png"))

        # Mobile: page must not overflow horizontally.
        page.set_viewport_size({"width": 390, "height": 844})
        page.wait_for_timeout(300)
        page.eval_on_selector("#vendorHallDrawerClose", "el => el.click()")
        page.wait_for_timeout(250)
        results["mobile_overflow"] = page.evaluate("() => document.documentElement.scrollWidth > window.innerWidth")
        page.screenshot(path=str(OUT / "vendor-hall-mobile.png"))
        page.eval_on_selector('#vendorHallMap [data-vendor-spot="D001"]', "el => el.click()")
        page.wait_for_selector("#vendorHallDrawer:not([hidden])", timeout=4000)
        page.wait_for_timeout(200)
        page.screenshot(path=str(OUT / "vendor-hall-drawer-mobile.png"))

        # User-facing proof for the two adjacent requirements: pending applicants
        # appear in the main Volunteer Roster, and logs stay paginated/searchable.
        page.eval_on_selector("#vendorHallDrawerClose", "el => el.click()")
        page.set_viewport_size({"width": 1440, "height": 900})
        page.eval_on_selector('.nav-item[data-view="rosterPageView"]', "el => el.click()")
        page.wait_for_selector("#rosterPageView.active", timeout=4000)
        page.wait_for_timeout(500)
        results["pending_roster_rows"] = page.eval_on_selector_all(
            "#rosterPageTableBody .roster-row-pending", "els => els.length")
        results["pending_badge"] = page.eval_on_selector(
            "#rosterPageTableBody .roster-row-pending", "el => el.textContent.includes('Pending Applicant') && el.textContent.includes('Pending')")
        page.locator("#rosterPageContent").screenshot(path=str(OUT / "volunteer-roster-pending-desktop.png"))

        page.eval_on_selector('.nav-item[data-view="adminView"]', "el => el.click()")
        page.wait_for_selector("#adminView.active", timeout=4000)
        page.wait_for_selector("#systemLogList .admin-list-card", timeout=4000)
        results["bounded_log_rows"] = page.eval_on_selector_all("#systemLogList .admin-list-card", "els => els.length")
        results["log_total_label"] = page.eval_on_selector("#systemLogStatus", "el => el.textContent")
        results["log_page_label"] = page.eval_on_selector("#systemLogPageLabel", "el => el.textContent")
        page.fill("#systemLogSearch", "A001")
        page.wait_for_timeout(500)
        results["log_search_value"] = page.input_value("#systemLogSearch")
        results["searchable_note_text"] = page.eval_on_selector(
            "#systemLogList", "el => el.textContent.includes('Sakura Prints') && el.textContent.includes('power drops')")
        page.eval_on_selector("#systemLogSearch", "el => el.closest('aside').scrollIntoView({block: 'center'})")
        page.wait_for_timeout(150)
        page.locator("#systemLogSearch").locator("xpath=ancestor::aside[1]").screenshot(path=str(OUT / "system-log-search-desktop.png"))

        browser.close()

    errs = [e for e in console_errors if "Failed to load resource" not in e]
    print("RESULTS:")
    for k, v in results.items():
        print(f"  {k:20} {v}")
    print("console/page errors:", errs or "none")
    print("screenshots ->", OUT)

    ok = (results["targets"] == 115 and results["list_items"] == 115
          and results["occupied_targets"] == 3 and results["notes_targets"] == 2
          and results["image_loaded"] and results["all_buttons"]
          and results["drawer_notes"] == 2 and "A001" in results["drawer_title"]
          and results["note_has_author"] and not results["mobile_overflow"]
          and results["pending_roster_rows"] == 1 and results["pending_badge"]
          and results["bounded_log_rows"] == 2 and "73" in results["log_total_label"]
          and results["log_page_label"] == "Page 1 of 3"
          and results["log_search_value"] == "A001" and results["searchable_note_text"])
    if not ok or errs:
        raise SystemExit(1)
    print("\nVENDOR HALL EVIDENCE: PASS")


if __name__ == "__main__":
    main()

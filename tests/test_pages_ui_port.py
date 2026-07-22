"""Regression tests for the exact Delta H Pages UI port.

These lock in two things the port must not regress:

(a) the *actual* Pages login card and app-shell/sidebar DOM is used
    structurally (not a stylistic substitute), and the unmodified Pages
    stylesheet remains the base; and
(b) the functional app's real API action wiring and DOM controls are
    still present after the reskin.
"""

from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[1]


class PagesUiPortTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.html = (ROOT / "index.html").read_text(encoding="utf-8")
        cls.css = (ROOT / "styles.css").read_text(encoding="utf-8")
        cls.js = (ROOT / "core.js").read_text(encoding="utf-8")
        cls.api = (ROOT / "api/api.php").read_text(encoding="utf-8")
        cls.release_builder = (ROOT / "scripts/build-release.py").read_text(encoding="utf-8")

    # ── (a) exact Pages login card DOM ──────────────────────────────
    def test_login_card_uses_pages_dom(self):
        for token in (
            'id="authView"',
            'class="login-screen"',
            'class="login-bg-grid"',
            'class="login-card"',
            'class="login-logo"',
            'class="login-subtitle"',
            'id="discordLoginBtn"',
            'class="login-oauth-btn"',
            'class="login-scopes"',
        ):
            self.assertIn(token, self.html, f"login card missing {token}")
        # The Pages scope chips are present verbatim.
        self.assertRegex(
            self.html,
            re.compile(r"login-scopes.*?<span>identify</span>.*?<span>email</span>", re.S),
        )

    def test_login_has_original_anime_dance_scene_without_rejected_copy(self):
        self.assertIn('class="anime-dance-stage"', self.html)
        self.assertIn('class="anime-dance-gif"', self.html)
        self.assertNotIn('class="anime-dancer"', self.html)
        self.assertIn('aria-hidden="true"', self.html)
        self.assertIn('@media (prefers-reduced-motion: reduce)', self.css)
        for rejected in ('Your convention command center', 'Role checked', 'Schedule ready'):
            self.assertNotIn(rejected, self.html)

    def test_login_uses_local_animated_gif_and_release_allows_it(self):
        self.assertIn('src="assets/anime-dancer.gif"', self.html)
        self.assertTrue((ROOT / 'assets' / 'anime-dancer.gif').is_file())
        self.assertIn('assets/anime-dancer.gif', self.release_builder)

    # ── (a) exact Pages app-shell / sidebar DOM ─────────────────────
    def test_app_shell_uses_pages_dom(self):
        for token in (
            'id="appShell"',
            'class="app-shell"',
            'class="sidebar"',
            'id="sidebar"',
            'class="sidebar-header"',
            'class="sidebar-nav"',
            'id="sidebarNav"',
            'class="sidebar-footer"',
            'class="user-mini"',
            'id="userMini"',
            'id="userAvatar"',
            'id="userName"',
            'id="userRoleBadge"',
            'class="main-content"',
            'id="mainContent"',
            'id="viewContainer"',
        ):
            self.assertIn(token, self.html, f"app shell missing {token}")

    def test_rejected_substitute_markup_is_gone(self):
        # The prior stylistic substitute must not linger.
        self.assertNotIn('class="app-header"', self.html)
        self.assertNotIn("auth-card-single", self.html)
        self.assertNotIn('<nav class="tabs"', self.html)
        self.assertNotIn('class="tab"', self.html)

    # ── (a) unmodified Pages stylesheet is the base ─────────────────
    def test_pages_stylesheet_base_intact(self):
        # Distinctive Pages base component rules are still present.
        for rule in (".app-shell {", ".sidebar {", ".nav-item {", ".login-card {", ".user-mini {"):
            self.assertIn(rule, self.css, f"Pages base rule missing: {rule}")
        # Functional additions live below a clearly labelled extension banner.
        self.assertIn("FUNCTIONAL EXTENSION", self.css)
        base, _, extension = self.css.partition("FUNCTIONAL EXTENSION")
        # The shared Pages Discord button keeps its base blurple fill — the
        # extension must not redesign it back to a gradient.
        self.assertIn("background: #5865F2;", base)
        self.assertNotIn("linear-gradient(135deg, #5865f2", extension)
        self.assertNotIn("body.logged-in main {\n  max-width: 1700px;", self.css)

    # ── (b) sidebar is populated dynamically from session state ─────
    def test_core_js_populates_pages_sidebar(self):
        for token in (
            "renderSidebarNav",
            "SIDEBAR_NAV_ITEMS",
            '"#sidebarNav"',
            "renderUserMini",
            '"#userName"',
            '"#userAvatar"',
        ):
            self.assertIn(token, self.js, f"core.js missing {token}")
        # No mock/demo role bypass was introduced with the reskin.
        self.assertNotIn("Demo Mode", self.html)
        self.assertNotIn("role-switch-btn", self.html)

    # ── (b) real API action wiring survives the reskin ──────────────
    def test_api_action_wiring_present(self):
        client_actions = set(re.findall(r'apiRequest\(\s*["\']([a-z0-9_]+)["\']', self.js))
        # A representative slice of the functional surface must remain wired.
        for action in (
            "logout",
            "save_application",
            "update_profile_photo",
            "save_availability",
            "create_shift",
            "save_guest_flight",
            "save_incident",
            "save_vendor_hall_assignment",
        ):
            self.assertIn(action, client_actions, f"lost API wiring for '{action}'")
        # And every client action still maps to a server handler.
        server_actions = set(re.findall(r"case\s+[\"']([a-z0-9_]+)[\"']\s*:", self.api))
        self.assertEqual([], sorted(client_actions - server_actions))

    def test_key_dom_controls_present(self):
        for control in (
            'id="discordLoginBtn"',
            'id="logoutBtn"',
            'id="submitApplicationBtn"',
            'id="createShiftForm"',
            'id="incidentForm"',
            'id="guestFlightForm"',
            'id="vendorHallForm"',
        ):
            self.assertIn(control, self.html, f"missing control {control}")

    # ── (c) Command Center dashboard — static region ────────────────
    def test_dashboard_view_region_present(self):
        # A static #dashboardView region lives under #viewContainer and hosts
        # the JS-rendered dashboard content.
        self.assertIn('id="dashboardView"', self.html)
        self.assertIn('id="dashboardContent"', self.html)
        container_idx = self.html.index('id="viewContainer"')
        dashboard_idx = self.html.index('id="dashboardView"')
        self.assertGreater(dashboard_idx, container_idx,
                           "#dashboardView must sit under #viewContainer")
        # It is a real view section so switchView can activate it.
        self.assertRegex(
            self.html,
            re.compile(r'id="dashboardView"[^>]*class="view"', re.S),
        )

    # ── (c) Command Center dashboard — real-data renderer ───────────
    def test_dashboard_renderer_uses_pages_structural_classes(self):
        # The renderer emits the exact Pages dashboard structural regions.
        self.assertIn("function renderDashboard(", self.js)
        for token in (
            "Command Center",
            "page-header",
            "page-title-group",
            "page-actions",
            "stat-grid",
            "stat-card",
            "stat-value",
            "stat-label",
            "stat-sub",
            "grid-2",
            "card-header",
            "card-title",
            "log-entry",
            "progress-fill",
            "segmented",
        ):
            self.assertIn(token, self.js, f"dashboard missing Pages class/region: {token}")

    def test_dashboard_metrics_in_screenshot_order(self):
        # Active Volunteers → Total Shifts → Rooms Occupied → Tracked Guests.
        self.assertRegex(
            self.js,
            re.compile(
                r'"Active Volunteers".*?"Total Shifts".*?"Rooms Occupied".*?"Tracked Guests"',
                re.S,
            ),
        )

    def test_dashboard_uses_real_session_data_only(self):
        # The renderer derives everything from live session collections.
        renderer = self.js[self.js.index("function renderDashboard("):]
        for source in ("users", "shifts", "hotelRooms", "guestFlights", "systemLogs"):
            self.assertIn(source, renderer, f"dashboard ignores real source: {source}")
        # External data flows through escapeHtml.
        self.assertIn("escapeHtml", renderer)
        # No canonical mock/demo values leaked into the port.
        for mock in (
            "Sarah Chen",
            "Marcus Reid",
            "Priya Patel",
            "DL1247",
            "LOG_ENTRIES",
            "VOLUNTEERS",
            "AVATAR_GRADIENTS",
            "(demo)",
            "schedule_template",
            "showToast",
        ):
            self.assertNotIn(mock, self.js, f"mock/demo value leaked: {mock}")

    def test_dashboard_day_filter_is_real_state_without_toast(self):
        self.assertIn("let dashboardDayFilter", self.js)
        self.assertIn("const DASHBOARD_DAYS", self.js)
        self.assertIn("function setDashboardDay(", self.js)
        # The filter re-renders real content; it must not fake a toast/dialog.
        setter = self.js[self.js.index("function setDashboardDay("):]
        setter = setter[: setter.index("\n}")]
        self.assertIn("renderDashboard()", setter)
        self.assertNotIn("showDialog", setter)

    # ── (c) sidebar default + SVG icons ─────────────────────────────
    def test_dashboard_is_default_view_and_first_nav_item(self):
        # Approved users default to the dashboard.
        self.assertIn('switchView("dashboardView")', self.js)
        # Dashboard is the first sidebar item.
        nav = self.js[self.js.index("const SIDEBAR_NAV_ITEMS"):]
        nav = nav[: nav.index("];")]
        first_item = nav[: nav.index("},")]
        self.assertIn('view: "dashboardView"', first_item)
        # Capability filtering + server authority is preserved.
        self.assertIn("item.show(currentUser)", self.js)
        self.assertIn("isManagementUser(user)", self.js)
        self.assertIn("isFullAdmin(user)", self.js)

    def test_sidebar_uses_canonical_svg_icons_not_letter_badges(self):
        self.assertIn("const NAV_ICONS", self.js)
        self.assertIn("<svg", self.js)
        # Nav renders SVG glyphs, not escaped letter badges.
        self.assertIn("NAV_ICONS[item.icon]", self.js)
        self.assertNotIn("escapeHtml(item.icon)", self.js)
        # The old single-letter icons are gone from the nav model.
        nav = self.js[self.js.index("const SIDEBAR_NAV_ITEMS"):]
        nav = nav[: nav.index("];")]
        self.assertNotRegex(nav, re.compile(r'icon:\s*"[A-Z]"'))

    def test_sidebar_uses_reference_volunteer_and_management_groups(self):
        for token in (
            'section: "Volunteer"', 'section: "Management"',
            'label: "My Profile"', 'label: "Availability"',
            'label: "Shift Board"', 'label: "My Shifts"',
            'label: "Manage Shifts"', 'label: "Volunteer Roster"',
            'label: "Food & Counts"', 'label: "Hotels"',
            'label: "Guest Relations"', 'label: "System Log"',
            'data-nav-key', 'dataset.navKey',
        ):
            self.assertIn(token, self.js)

    def test_dashboard_lower_panels_keep_reference_height(self):
        self.assertIn('#dashboardContent .grid-2 > .card', self.css)
        self.assertIn('min-height: 430px', self.css)

    # ── (c) + New Shift authorization + View All routing ────────────
    def test_new_shift_is_management_only_and_routes_to_workflow(self):
        # The button only exists in the management dashboard branch.
        mgmt = self.js[self.js.index("function managementDashboardHtml("):]
        mgmt = mgmt[: mgmt.index("\nfunction ")]
        self.assertIn("dashboardNewShiftBtn", mgmt)
        vol = self.js[self.js.index("function volunteerDashboardHtml("):]
        vol = vol[: vol.index("\nfunction ")]
        self.assertNotIn("dashboardNewShiftBtn", vol)
        # Routing guards on management and reuses the real create-shift form.
        goto = self.js[self.js.index("function goToCreateShift("):]
        goto = goto[: goto.index("\n}")]
        self.assertIn("isManagementUser(currentUser)", goto)
        self.assertIn('switchView("managementView")', goto)
        self.assertIn("#createShiftForm", goto)

    def test_view_all_routes_to_existing_system_log_view(self):
        self.assertIn("dashboardViewAllBtn", self.js)
        self.assertIn('switchView("adminView")', self.js)
        # #systemLogList (the System Log) really lives in that view.
        admin_idx = self.html.index('id="adminView"')
        log_idx = self.html.index('id="systemLogList"')
        self.assertGreater(log_idx, admin_idx)


if __name__ == "__main__":
    unittest.main()

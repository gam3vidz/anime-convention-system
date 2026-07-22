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


if __name__ == "__main__":
    unittest.main()

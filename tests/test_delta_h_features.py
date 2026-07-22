from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[1]


def php_function(source: str, name: str) -> str:
    """Return the top-level PHP function body from `function <name>` to the next top-level function."""
    start = source.index(f"function {name}")
    nxt = source.find("\nfunction ", start + 1)
    return source[start:] if nxt == -1 else source[start:nxt]


def switch_case(source: str, case: str) -> str:
    """Return a router switch case body from `case '<case>':` up to its terminating break;."""
    start = source.index(f"case '{case}':")
    end = source.index("break;", start)
    return source[start:end]


class VendorHallBackendTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.api = (ROOT / "api/api.php").read_text(encoding="utf-8")
        cls.schema = (ROOT / "api/schema.php").read_text(encoding="utf-8")
        cls.config = (ROOT / "api/config.example.php").read_text(encoding="utf-8")

    def test_vendor_hall_assignment_table_is_defined_in_schema(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS vendor_hall_assignments", self.schema)
        table = self.schema[self.schema.index("vendor_hall_assignments"):]
        table = table[:table.index("ENGINE=InnoDB")]
        for column in ("spot_code", "vendor_name", "notes", "updated_by", "created_at", "updated_at"):
            self.assertIn(column, table)
        self.assertIn("PRIMARY KEY (spot_code)", table)

    def test_vendor_hall_spot_allowlist_is_enforced_server_side(self):
        self.assertIn("function vendorHallSpotCodes(): array", self.api)
        self.assertIn("function isValidVendorHallSpot(string", self.api)
        spots = php_function(self.api, "vendorHallSpotCodes")
        self.assertIn("['A', 'B', 'C', 'D']", spots)
        self.assertIn("$n <= 12", spots)
        # Validation must gate both the save and clear helpers, not just the UI.
        self.assertGreaterEqual(self.api.count("isValidVendorHallSpot("), 3)
        save = php_function(self.api, "saveVendorHallAssignment")
        self.assertNotIn("mb_strlen", save)
        self.assertIn("cleanManagementText", save)

    def test_vendor_hall_capability_is_config_driven_and_fail_closed(self):
        self.assertIn("discord_vendor_hall_role_id", self.config)
        self.assertIn("'vendorHallRoleId' => trim((string)($config['discord_vendor_hall_role_id']", self.api)
        self.assertIn("function isVendorHallRow(array $user, array $config): bool", self.api)
        body = php_function(self.api, "isVendorHallRow")
        self.assertIn("if (isFullAdminRow($user)) return true;", body)
        self.assertIn("!== 'approved'", body)
        self.assertIn("blacklisted", body)
        self.assertLess(body.index("!== 'approved'"), body.index("isFullAdminRow($user)"))
        self.assertLess(body.index("blacklisted"), body.index("isFullAdminRow($user)"))
        self.assertIn("discord_vendor_hall_role_id", body)
        # Fail closed: a blank role id denies every non-admin.
        self.assertIn("if ($roleId === '') return false;", body)
        self.assertIn("hasDiscordRole(userDiscordRoles($user), $roleId)", body)
        # Capability is surfaced per user row and gates the returned assignments.
        self.assertIn("$u['canVendorHall'] = isVendorHallRow($u", self.api)
        self.assertIn("isVendorHallRow($currentUser", self.api)
        self.assertIn("'vendorHallAssignments'", self.api)

    def test_vendor_hall_mutations_require_post_csrf_and_authorization(self):
        self.assertIn("function requireVendorHall(PDO $pdo, array $config): array", self.api)
        get_actions = re.search(r"\$getActions = \[[^\]]*\]", self.api).group(0)
        self.assertNotIn("vendor_hall", get_actions)
        for case in ("save_vendor_hall_assignment", "clear_vendor_hall_assignment"):
            body = switch_case(self.api, case)
            self.assertIn("requireVendorHall($pdo, $config)", body)
            self.assertIn("logAction($pdo", body)


class ManagementPortalTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.api = (ROOT / "api/api.php").read_text(encoding="utf-8")
        cls.html = (ROOT / "index.html").read_text(encoding="utf-8")
        cls.client = (ROOT / "core.js").read_text(encoding="utf-8")
        cls.css = (ROOT / "styles.css").read_text(encoding="utf-8")

    def test_management_portal_reuses_existing_profile_and_note_controls(self):
        # Select-a-volunteer + profile facts + editable fields + note history all exist.
        for marker in (
            'id="volunteerProfileDialog"',
            'id="volunteerProfileMeta"',
            'id="volunteerProfileForm"',
            'id="volunteerProfileStrengths"',
            'id="volunteerProfileGrowth"',
            'id="volunteerProfileSummary"',
            'id="volunteerProfileRecommendation"',
            'id="volunteerProfileNoteForm"',
            'id="volunteerProfileNoteType"',
            'id="volunteerProfileNoteYear"',
            'id="volunteerProfileNoteText"',
            'id="volunteerProfileNoteList"',
            'id="volunteerProfileBlacklistState"',
        ):
            self.assertIn(marker, self.html)
        # Reuse the existing management API actions rather than duplicating them.
        self.assertIn('apiRequest("save_volunteer_management_profile"', self.client)
        self.assertIn('apiRequest("add_volunteer_management_note"', self.client)

    def test_blacklist_restore_ui_and_server_are_manager_scoped(self):
        # UI: destructive control, visible state, and explicit confirmation.
        self.assertIn('id="volunteerProfileBlacklistToggle"', self.html)
        self.assertIn('apiRequest("set_user_blacklist"', self.client)
        self.assertIn("toggleVolunteerBlacklist", self.client)
        self.assertIn('confirm(`Blacklist ${user.name', self.client)
        # Server: manager-scoped and logged, reusing users.blacklisted.
        body = switch_case(self.api, "set_user_blacklist")
        self.assertIn("requireManager($pdo)", body)
        self.assertIn("requireManageableVolunteer", body)
        self.assertIn("blacklisted = ?", body)
        self.assertIn("logAction($pdo", body)
        manageable = php_function(self.api, "requireManageableVolunteer")
        self.assertIn("isFullAdminRow($target) && !isFullAdminRow($manager)", manageable)

    def test_new_portals_have_responsive_stateful_styles(self):
        for marker in (
            ".vendor-hall-map",
            ".vendor-hall-spot",
            ".vendor-hall-spot.is-occupied",
            ".vendor-hall-spot.is-selected",
            ".volunteer-profile-access",
            ".volunteer-profile-access-state.is-blacklisted",
        ):
            self.assertIn(marker, self.css)
        self.assertIn("styles.css?v=46", self.html)
        self.assertIn("core.js?v=46", self.html)
        self.assertIn("grid-template-columns: repeat(12, minmax(48px, 1fr));", self.css)
        self.assertIn("grid-template-columns: repeat(4, minmax(60px, 1fr));", self.css)
        self.assertNotIn("V0 COMMAND CENTER SHELL + ROSTER HANDOUTS", self.css)
        self.assertNotIn("--sidebar-w: 200px", self.css)
        self.assertNotIn(".brand-block { min-height: 56px; padding: 12px 14px; }", self.css)
        self.assertNotIn(".tabs,\n  .stats-grid,", self.css)
        self.assertRegex(self.css, r"(?s)#vendorHallForm\s*\{[^}]*grid-template-columns:\s*1fr")
        self.assertRegex(self.css, r"@media\s*\(max-width:\s*(?:760|768|800)px\)")


class DiscordTimeClockTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.api = (ROOT / "api/api.php").read_text(encoding="utf-8")
        cls.schema = (ROOT / "api/schema.php").read_text(encoding="utf-8")
        cls.config = (ROOT / "api/config.example.php").read_text(encoding="utf-8")
        cls.html = (ROOT / "index.html").read_text(encoding="utf-8")
        cls.client = (ROOT / "core.js").read_text(encoding="utf-8")

    def test_on_duty_role_and_atomic_discord_wrapper_are_configured(self):
        self.assertIn("discord_on_duty_role_id", self.config)
        self.assertIn("'onDutyRoleId'", self.api)
        self.assertIn("function setDiscordOnDutyRole", self.api)
        wrapper = php_function(self.api, "setDiscordClockStatus")
        self.assertIn("setDiscordOnDutyRole", wrapper)
        self.assertIn("$pdo->beginTransaction()", wrapper)
        self.assertIn("$pdo->rollBack()", wrapper)
        self.assertIn("setClockStatus($pdo, $user, $clockedIn, 'discord'", wrapper)
        self.assertIn("Manage Roles", self.api)

    def test_discord_commands_and_buttons_use_role_synced_clock_path(self):
        interactions = php_function(self.api, "handleDiscordInteraction")
        self.assertEqual(interactions.count("setDiscordClockStatus($pdo, $discord, $user"), 2)
        self.assertIn("delta_clock_in", interactions)
        self.assertIn("delta_clock_out", interactions)
        self.assertIn("delta_view_time", interactions)
        self.assertIn("'clock-panel'", interactions)
        self.assertIn('id="registerDiscordClockCommandsBtn"', self.html)
        self.assertIn('apiRequest("discord_register_commands"', self.client)

    def test_time_entries_are_scope_loaded_for_management_profiles(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS time_clock_entries", self.schema)
        self.assertIn("WHERE user_id IN ($placeholders)", self.api)
        self.assertIn("$visibleUser['timeClockEntries']", self.api)
        self.assertIn("timeClockEntries", self.client)

    def test_management_profile_renders_time_clock_ledger(self):
        for marker in (
            'id="volunteerProfileClockStatus"',
            'id="volunteerProfileClockSummary"',
            'id="volunteerProfileClockLedger"',
        ):
            self.assertIn(marker, self.html)
        self.assertIn("renderVolunteerTimeClock", self.client)
        self.assertIn("escapeHtml(entry.source", self.client)


class CustomAvailabilityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.api = (ROOT / "api/api.php").read_text(encoding="utf-8")
        cls.html = (ROOT / "index.html").read_text(encoding="utf-8")
        cls.client = (ROOT / "core.js").read_text(encoding="utf-8")

    def test_application_and_profile_use_all_day_or_custom_time_ranges(self):
        self.assertIn("data-availability-all-day", self.client)
        self.assertIn('type="time"', self.client)
        self.assertIn("availabilityRangeFromInputs", self.client)
        self.assertNotIn('data-availability-scope="${scope}" data-availability-day="${day}" value="${hour}"', self.client)

    def test_backend_normalizes_and_persists_custom_ranges(self):
        self.assertIn("function normalizeAvailabilityRanges", self.api)
        self.assertIn("function isValidAvailabilityTime", self.api)
        save = php_function(self.api, "saveAvailability")
        self.assertIn("normalizeAvailabilityRanges", save)
        self.assertIn("all-day", save)
        self.assertIn("['start'] . '-' . $range['end']", save)

    def test_shift_matching_uses_range_containment_not_hourly_checkbox_overlap(self):
        self.assertIn("availabilityCoversShift", self.client)
        matcher = self.client[self.client.index("function shiftMatchesAvailability"):self.client.index("function updateClockStatus")]
        self.assertIn("availabilityCoversShift", matcher)
        self.assertNotIn("coveredHours.some", matcher)


class SessionAndErrorHardeningTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.api = (ROOT / "api/api.php").read_text(encoding="utf-8")

    def test_blacklisted_sessions_are_centrally_revoked_before_routing(self):
        switch_pos = self.api.index("switch ($action)")
        guard_pos = self.api.index("blacklisted_session_revoked")
        self.assertLess(guard_pos, switch_pos, "central revocation must run before the action switch")
        region = self.api[self.api.index("$validCronSecret = in_array($action"):self.api.index("try {\n    switch")]
        self.assertIn("blacklisted", region)
        self.assertIn("session_destroy()", region)
        self.assertIn("fail(", region)

    def test_raw_discord_exceptions_are_not_returned_to_the_browser(self):
        self.assertNotIn("'Could not verify your Discord server roles: ' . $e->getMessage()", self.api)
        self.assertNotIn("'Auto-join failed: ' . $e->getMessage()", self.api)
        self.assertNotIn("'Lookup failed: ' . $e->getMessage()", self.api)
        self.assertIn("Discord role lookup is temporarily unavailable.", self.api)
        self.assertIn("error_log('Discord role lookup failed:", self.api)
        self.assertIn("error_log('Flight status lookup failed:", self.api)


if __name__ == "__main__":
    unittest.main()

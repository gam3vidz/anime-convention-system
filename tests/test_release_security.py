from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[1]


class ReleaseSecurityTests(unittest.TestCase):
    def test_public_maintenance_endpoints_are_not_shipped(self):
        forbidden = [
            "api/setup_admin.php",
            "api/debug.php",
            "api/check_db.php",
            "api/db_upgrade.php",
            "api/test.php",
            "api/db.php",
        ]
        present = [path for path in forbidden if (ROOT / path).exists()]
        self.assertEqual([], present, f"unsafe public endpoints present: {present}")

    def test_master_password_bypass_is_not_present(self):
        api_source = (ROOT / "api/api.php").read_text(encoding="utf-8")
        self.assertNotIn("delta2026", api_source)
        self.assertNotIn("$isMasterKey", api_source)

    def test_legacy_password_routes_are_not_exposed(self):
        api_source = (ROOT / "api/api.php").read_text(encoding="utf-8")
        client_source = (ROOT / "core.js").read_text(encoding="utf-8")
        html_source = (ROOT / "index.html").read_text(encoding="utf-8")
        for action in ("login", "apply", "request_password_reset", "admin_reset_password"):
            self.assertNotIn(f"case '{action}':", api_source)
        for marker in ("request_password_reset", "admin_reset_password", "resetUserPassword"):
            self.assertNotIn(marker, client_source)
        self.assertNotIn('id="passwordResetForm"', html_source)

    def test_csrf_and_session_hardening_are_wired_end_to_end(self):
        api_source = (ROOT / "api/api.php").read_text(encoding="utf-8")
        client_source = (ROOT / "core.js").read_text(encoding="utf-8")
        for marker in (
            "session_set_cookie_params",
            "'httponly' => true",
            "'samesite' => 'Lax'",
            "HTTP_X_CSRF_TOKEN",
            "hash_equals",
            "REQUEST_METHOD",
            "'csrfToken'",
        ):
            self.assertIn(marker, api_source)
        self.assertIn('"X-CSRF-Token": csrfToken', client_source)
        self.assertIn("csrfToken = data.csrfToken", client_source)

    def test_discord_account_linking_requires_verified_email(self):
        api_source = (ROOT / "api/api.php").read_text(encoding="utf-8")
        self.assertIn("$discordEmailVerified = !empty($discordUser['verified']);", api_source)
        self.assertIn("$discordEmailVerified ? 1 : 0", api_source)
        self.assertGreaterEqual(api_source.count("session_regenerate_id(true);"), 2)

    def test_internal_exception_details_are_not_returned_to_clients(self):
        api_source = (ROOT / "api/api.php").read_text(encoding="utf-8")
        self.assertNotIn("echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()])", api_source)
        self.assertNotIn("fail($e->getMessage(), 400)", api_source)
        self.assertIn("error_log(", api_source)
        self.assertIn("An internal error occurred.", api_source)

    def test_schema_mutations_are_cli_only(self):
        api_source = (ROOT / "api/api.php").read_text(encoding="utf-8")
        migration_source = (ROOT / "scripts/migrate.php").read_text(encoding="utf-8")
        self.assertNotIn("ensureSchema($pdo);", api_source)
        self.assertTrue((ROOT / "api/schema.php").is_file())
        self.assertIn("PHP_SAPI !== 'cli'", migration_source)
        self.assertIn("ensureSchema($pdo);", migration_source)

    def test_migration_requires_explicit_database_target(self):
        migration_source = (ROOT / "scripts/migrate.php").read_text(encoding="utf-8")
        guard_position = migration_source.find("Refusing migration: configured database")
        mutation_position = migration_source.find("ensureSchema($pdo);")
        self.assertNotEqual(-1, guard_position)
        self.assertNotEqual(-1, mutation_position)
        self.assertLess(guard_position, mutation_position)
        self.assertIn("$argv[1]", migration_source)
        self.assertIn("hash_equals($database, $expectedDatabase)", migration_source)

    def test_cron_actions_require_post_and_csrf_or_cron_secret(self):
        api_source = (ROOT / "api/api.php").read_text(encoding="utf-8")
        self.assertNotIn("array_merge($getActions, $cronActions)", api_source)
        self.assertNotIn("$_GET['key']", api_source)
        self.assertIn("$validCronSecret", api_source)
        self.assertIn("HTTP_X_DELTA_H_ALERT_KEY", api_source)

    def test_incident_evidence_downloads_as_attachment(self):
        api_source = (ROOT / "api/api.php").read_text(encoding="utf-8")
        self.assertIn("Content-Disposition: attachment;", api_source)
        self.assertNotIn("Content-Disposition: inline;", api_source)

    def test_apache_blocks_source_and_secret_files(self):
        htaccess = (ROOT / ".htaccess").read_text(encoding="utf-8")
        self.assertIn("Options -Indexes", htaccess)
        self.assertIn("config\\.php", htaccess)
        self.assertIn("\\.(sql|", htaccess)
        self.assertIn("^(tests|scripts|migrations|docs|\\.github)", htaccess)
        self.assertIn("X-Content-Type-Options", htaccess)
        self.assertIn("Content-Security-Policy", htaccess)

    def test_live_configuration_is_not_versioned(self):
        self.assertFalse((ROOT / "api/config.php").exists())
        example = ROOT / "api/config.example.php"
        self.assertTrue(example.exists())
        text = example.read_text(encoding="utf-8")
        self.assertNotIn("password123", text)
        self.assertIn("CHANGE_ME", text)


if __name__ == "__main__":
    unittest.main()

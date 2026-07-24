from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[1]


class PublicEventWebsiteTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.html = (ROOT / "index.html").read_text(encoding="utf-8")
        cls.client = (ROOT / "core.js").read_text(encoding="utf-8")
        cls.css = (ROOT / "styles.css").read_text(encoding="utf-8")
        cls.api = (ROOT / "api/api.php").read_text(encoding="utf-8")
        cls.config = (ROOT / "api/config.example.php").read_text(encoding="utf-8")
        cls.schema = (ROOT / "api/schema.php").read_text(encoding="utf-8")
        cls.release = (ROOT / "scripts/build-release.py").read_text(encoding="utf-8")
        cls.workflow = (ROOT / ".github/workflows/validate.yml").read_text(encoding="utf-8")

    def test_logged_out_root_is_a_complete_convention_website(self):
        for marker in (
            'id="publicSite"',
            'id="publicNav"',
            'id="home"',
            'id="schedule"',
            'id="guests"',
            'id="vendors"',
            'id="venue"',
            'id="tickets"',
            'id="volunteer"',
            'id="sponsors"',
            'id="faq"',
            'id="staffLogin"',
            'id="ticketGrid"',
            'id="checkoutStatus"',
        ):
            self.assertIn(marker, self.html)

    def test_public_site_is_api_driven_and_checkout_redirects_to_stripe(self):
        for marker in (
            'loadPublicEvent',
            'action=public_event',
            'renderPublicEvent',
            'create_checkout',
            'window.location.assign',
            'checkout_status',
            'crypto.randomUUID',
        ):
            self.assertIn(marker, self.client)
        self.assertNotIn('sk_test_', self.client)
        self.assertNotIn('sk_live_', self.client)

    def test_anime_visual_system_is_responsive_and_accessible(self):
        for marker in (
            '.public-site',
            '.manga-panel',
            '.ticket-card',
            '.program-card',
            '.guest-card',
            '.vendor-card',
            '.public-nav',
            '@media (max-width:',
            ':focus-visible',
            'prefers-reduced-motion',
        ):
            self.assertIn(marker, self.css)

    def test_public_payment_routes_are_explicit_and_webhook_bypasses_csrf_only_by_signature(self):
        self.assertIn("'public_event'", self.api)
        self.assertIn("'checkout_status'", self.api)
        self.assertIn("'create_checkout'", self.api)
        self.assertIn("'stripe_webhook'", self.api)
        self.assertIn("stripeVerifyWebhookSignature", self.api)
        self.assertIn("file_get_contents('php://input')", self.api)
        self.assertIn("Stripe-Signature", self.api)
        self.assertIn("require_once __DIR__ . '/stripe.php'", self.api)

    def test_order_and_ticket_schema_are_idempotent(self):
        for marker in (
            'CREATE TABLE IF NOT EXISTS ticket_orders',
            'CREATE TABLE IF NOT EXISTS ticket_order_items',
            'CREATE TABLE IF NOT EXISTS event_tickets',
            'stripe_checkout_session_id',
            'idempotency_key',
            'payment_status',
            'ticket_code',
            'UNIQUE KEY uq_ticket_orders_idempotency',
            'UNIQUE KEY uq_ticket_orders_stripe_session',
            'UNIQUE KEY uq_event_tickets_code',
        ):
            self.assertIn(marker, self.schema)

    def test_stripe_configuration_is_placeholder_only(self):
        for marker in (
            "'stripe_secret_key' => ''",
            "'stripe_webhook_secret' => ''",
            "'stripe_currency' => 'usd'",
            "'stripe_ticket_catalog'",
            "'public_event'",
        ):
            self.assertIn(marker, self.config)
        self.assertNotIn('sk_test_', self.config)
        self.assertNotIn('sk_live_', self.config)

    def test_checkout_retry_key_tracks_the_actual_order(self):
        self.assertIn('const checkoutKey = `${sku}:${email.toLowerCase()}:${quantity}`;', self.client)
        self.assertIn('checkoutTokens.get(checkoutKey)', self.client)
        self.assertIn('checkoutTokens.set(checkoutKey, idempotencyKey)', self.client)

    def test_mobile_menu_and_staff_login_do_not_compete(self):
        self.assertIn(
            'function openStaffLogin(trigger = document.activeElement) {\n  if (currentUser) return;\n  setPublicMenu(false);',
            self.client,
        )
        self.assertIn('document.body.classList.add("staff-login-open")', self.client)

    def test_payment_module_is_linted_tested_and_packaged(self):
        self.assertIn('"api/stripe.php"', self.release)
        self.assertIn('"docs/EVENTENY_INSPIRED_OVERHAUL.md"', self.release)
        self.assertIn('php tests/stripe_unit.php', self.workflow)


if __name__ == "__main__":
    unittest.main()

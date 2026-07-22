Delta H Scheduling System Upload Notes

Upload the contents of this folder to the public web root for asimple.email.

Important files:
- index.html, styles.css, core.js: the web app.
- api/: PHP backend, Discord login, MySQL connection, and setup helper.
- delta-h-mysql-updates.sql: database updates for the scheduling features.
- delta-h-shift-import-template.xlsx: shift import template.
- DISCORD-SETUP.txt: Discord OAuth and bot setup notes.
- SAFETY-ALERTS-SETUP.txt: Safety role, evidence, and missed check-in alert setup.
- delta-h-safety-alerts.sql: optional manual migration for new Safety and alert tables.
- CREDITS.txt: UI inspiration and license credit.

Do not upload the old app.js file if you still have one from an older package.
The current app uses core.js.

After upload:
1. Confirm api/config.php has the live database and Discord settings.
2. Import delta-h-mysql-updates.sql into the MySQL database if those updates have not been run yet.
3. Use the site login button instead of manually generated Discord URLs, because the app creates its own secure login state.
4. Set discord_safety_role_id and schedule the alert processor as described in SAFETY-ALERTS-SETUP.txt.

T-shirt pickup tracking:
- No manual migration is required when the API database user can alter the users table.
- For restricted database accounts, run delta-h-mysql-updates.sql once to add
  shirt_picked_up, shirt_picked_up_at, and shirt_picked_up_by.

Guest Relations flight tracking:
- Guest flights now store both the private airline confirmation number and the
  public flight number. Confirmation numbers are masked in the tracked-flight
  list and remain available only to the Guest Relations role.
- Each flight requires an approved pickup person with a linked Discord account.
  Assignment and flight-change DMs include the flight status and timing, but do
  not include the guest name or confirmation number.
- Set flight_api_key in api/config.php, then schedule this URL every five minutes
  using the same private key configured as shift_alert_cron_secret:
  https://asimple.email/api/api.php?action=process_flight_updates&key=YOUR_SECRET
- A command-line cron example is:
  */5 * * * * curl -fsS -H "X-Delta-H-Alert-Key: YOUR_SECRET" "https://asimple.email/api/api.php?action=process_flight_updates" >/dev/null 2>&1

Volunteer management profiles:
- Open Management > Active Accounts and select Create profile or View profile.
- Managers can record strengths, improvement areas, a next-year recommendation,
  a private summary, and dated history notes for volunteers in their department.
- Admins can manage profiles across all departments. Volunteers cannot view these
  internal records.
- The API creates the profile tables automatically when its database account has
  ALTER/CREATE permission. Otherwise import delta-h-mysql-updates.sql once.

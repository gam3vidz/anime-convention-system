Delta H Safety and missed shift alert setup

1. Safety access
   In api/config.php, set discord_safety_role_id to the Discord role ID that
   should see the Safety tab. Admin accounts always have Safety access. Until a
   role ID is entered, approved users assigned to the Safety department are
   allowed as a temporary fallback.

2. Database
   Run `php scripts/migrate.php` once for each environment before serving the
   application. Normal API requests never create or alter database tables.

3. Evidence storage
   Keep api/uploads/.htaccess in place. It blocks direct web access so uploaded
   evidence is served only through the authenticated Safety endpoint. The PHP
   process needs write permission to api/uploads/incidents. Accepted files are
   JPG, PNG, WebP, GIF, and PDF up to 12 MB each.

4. Reliable Discord alerts
   The Management tab includes a manual "Run alert scan now" button. For alerts
   to run even when no manager has the website open, configure a scheduled POST
   request once per minute. The cron secret must be sent in a request header; it
   is intentionally rejected in the URL so it cannot leak through access logs:

   * * * * * curl -fsS -X POST -H "X-Delta-H-Alert-Key: YOUR_SECRET" "https://asimple.email/api/api.php?action=process_shift_alerts" >/dev/null 2>&1

   Replace YOUR_SECRET with shift_alert_cron_secret from api/config.php and keep
   it private. The same POST/header pattern applies to process_flight_updates.

5. Configure each monitored shift
   In Management > Missed shift check-in alerts, select the shift, enter its
   actual event date, choose a 5- or 10-minute grace period, select one or more
   linked Discord recipients, and save the rule.

   A volunteer counts as checked in if they are currently clocked in or have a
   time-clock entry that covers the shift start. Each missed check-in DM is
   de-duplicated per shift, date, volunteer, and recipient. Failed deliveries
   can retry up to three times.

6. Available message placeholders
   {volunteer} {shift} {department} {date} {day} {time} {grace}

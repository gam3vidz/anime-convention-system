# Delta H Volunteer Operations

Private convention volunteer-management portal for schedules, Discord-linked access, shift check-in, hotel coordination, Guest Relations flight assignments, Safety incidents, missed-shift alerts, and internal coordinator notes.

## Security model

- Discord OAuth is the only interactive login path.
- Guild roles gate coordinator, Guest Relations, and Safety surfaces.
- Mutating API actions require POST plus a per-session CSRF token.
- Session cookies are HTTP-only, SameSite=Lax, and Secure on HTTPS.
- Uploaded incident evidence is MIME-checked, randomly named, stored under a non-executable directory, and downloaded as an attachment.
- Database schema changes run only through the CLI migration command.
- `api/config.php` and uploaded evidence are runtime-only and must never be committed.

## Verify locally

```bash
python3 -m unittest discover -s tests -v
node --check core.js
podman run --rm -v "$PWD:/app:ro,Z" -w /app php:8.2-cli-alpine \
  sh -lc 'find . -type f -name "*.php" -print0 | sort -z | xargs -0 -n1 php -l'
python3 scripts/build-release.py
```

The release builder writes:

- `dist/delta-h-release.zip`
- `dist/release-manifest.json`

The ZIP contains only allowlisted runtime files. It deliberately excludes live configuration, uploads, tests, documentation, migrations, and maintenance/debug endpoints.

## Configure a deployment

1. Copy `api/config.example.php` to `api/config.php` on the server.
2. Fill the server copy with database and Discord credentials.
   - Set `discord_vendor_hall_role_id` to the Discord role allowed to manage Vendor Hall positions.
   - Leaving `discord_vendor_hall_role_id` blank denies Vendor Hall access to non-admin accounts.
3. Keep `api/config.php` out of Git and deployment archives.
4. Run `php scripts/migrate.php EXPECTED_DATABASE` once for that environment. The explicit database name is a fail-closed guard against running a migration on the wrong database.
5. Deploy to an isolated staging hostname before production.

See [`docs/DEPLOY-PLESK.md`](docs/DEPLOY-PLESK.md) for the staging and Plesk Git procedure.

## Important credential notice

The July 19 source ZIP supplied for recovery contained live credentials in multiple files. Those values are not present in this repository or its release archive. Rotate the database password, Discord client secret, Discord bot token, and cron secret before the hardened release is promoted to production.

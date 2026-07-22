# Safe Plesk staging deployment

This procedure keeps `asimple.email` live while the recovered application is tested separately.

## 1. Create an isolated staging site

In Plesk, create a subdomain such as `staging.asimple.email` with:

- its own document root;
- PHP 8.2;
- HTTPS enabled;
- directory listing disabled;
- no deployment path pointing at the production `asimple.email` document root.

Do not extract the release ZIP over the current live site.

## 2. Create staging data safely

Use a separate staging database and database user. Do not point staging at the production database.

For realistic tests, clone the production database only after confirming the staging hostname is access-controlled. Prefer a sanitized or minimal dataset when possible because volunteer records include personal and Safety information.

## 3. Deploy the allowlisted release

Build or download `delta-h-release.zip`, then extract it into the staging document root. The archive intentionally excludes:

- `api/config.php`;
- user uploads;
- setup/debug/database-dump endpoints;
- tests and documentation;
- repository metadata.

Copy `api/config.example.php` to `api/config.php` **inside staging only**, then enter the staging database and Discord values. Never upload the original July 19 `config.php` to GitHub.

## 4. Run the migration once

Run the migration with Plesk's PHP 8.2 binary:

```text
php scripts/migrate.php EXPECTED_DATABASE
```

Replace `EXPECTED_DATABASE` with the exact configured database name. The migration refuses to connect or alter tables when the argument does not match `api/config.php`.

If shell access is unavailable, use Plesk **Scheduled Tasks** to run `scripts/migrate.php` once with the exact database name in **with arguments**, confirm the success output, and cancel the form without saving a recurring task.

Normal web requests no longer perform `CREATE TABLE` or `ALTER TABLE` operations.

## 5. Staging smoke checks

Verify all of the following on the staging hostname:

1. The login page loads without console errors.
2. Discord OAuth returns to the staging callback URL.
3. An unapproved Discord user sees the application flow but no roster data.
4. A volunteer sees only their own account and applicable shifts.
5. A coordinator sees only their department unless they are a full Admin.
6. Guest Relations flight confirmation numbers appear only to Guest Relations.
7. Safety incident evidence can be uploaded, downloaded, and removed.
8. Logout works and a stale POST without a valid CSRF token returns HTTP 403.
9. Requests for `api/config.php`, SQL files, `tests/`, `scripts/`, and `docs/` return HTTP 403.
10. `api/setup_admin.php`, `api/debug.php`, `api/check_db.php`, `api/db_upgrade.php`, `api/test.php`, and `api/db.php` return HTTP 404.

## 6. Configure Plesk Git after staging passes

Use Plesk's Git extension with the private GitHub repository over SSH:

1. Add the read-only SSH public key shown by Plesk as a GitHub **Deploy key**.
2. Clone the repository into a non-production repository directory.
3. Select the reviewed branch only for staging; use `main` only after the pull request is merged.
4. Set the deployment path to the staging document root.
5. Preserve the server-owned `api/config.php` and `api/uploads/incidents/` directory.
6. Run `python3 scripts/build-release.py` and deploy the contents of the generated release ZIP rather than copying the entire repository into the document root.

Do not enable automatic production deployment until staging has passed and a rollback snapshot exists.

## 7. Rotate credentials before production

Because the supplied recovery ZIP contained live values, rotate all of these before promotion:

- Plesk database-user password;
- Discord OAuth client secret;
- Discord bot token;
- shift-alert cron secret;
- flight API key, if a real key was ever stored in the package.

Update only the server-side `api/config.php` after rotation.

## 8. Production promotion and rollback

Before promotion:

- take a Plesk file backup;
- take a database backup;
- record the release ZIP SHA-256 from `dist/release-manifest.json`;
- keep the previous working application files available for rollback;
- never overwrite production `api/config.php` or `api/uploads/incidents/`.

Promote the exact staging-tested artifact. If smoke checks fail, restore both the previous files and the database snapshot rather than partially rolling files backward.

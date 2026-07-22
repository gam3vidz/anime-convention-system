Delta H Discord login setup

1. In Discord Developer Portal > OAuth2, add this redirect URL:
   https://asimple.email/api/discord-callback.php

   Save this under Redirects. The generated Discord install/login URL is not the
   one volunteers should use for login.

2. The client ID, client secret, redirect URL, server ID, bot token, and role IDs
   have already been entered in this local package.

3. The role IDs are already filled in:
   Volunteer role: REDACTED_DISCORD_VOLUNTEER_ROLE_ID
   Coordinator role: REDACTED_DISCORD_COORDINATOR_ROLE_ID
   Guest Relations role: REDACTED_DISCORD_GUEST_RELATIONS_ROLE_ID
   Safety role: set discord_safety_role_id in api/config.php

4. discord_login_required is set to true, so email/password login is disabled.
   Use the Log in with Discord button for all access.

5. Important: if a bot token was shown in a screenshot, chat, stream, or shared anywhere,
   regenerate it in Discord Developer Portal and use the new token.

The bot must be installed in the Discord server so the site can verify member roles.
For auto-join, invite the bot with Create Invite permission, not Administrator.
Minimum invite permission value: permissions=1
In Discord Developer Portal > Bot, enable Server Members Intent.
If role verification says Discord rejected the bot token, regenerate the bot token
and update api/config.php.

Volunteers and coordinators should log in from the Delta H website button:
Log in with Discord

The website creates the correct Discord URL with state protection and scopes:
identify email guilds.join guilds.members.read

There is no manual signup form in the live flow. New volunteers click Log in with
Discord, the site auto-joins/checks the Discord server, and then creates a
pending volunteer account for coordinator approval.

Guest Relations flight tracking:
The Guest Relations tab appears only for users with Discord role REDACTED_DISCORD_GUEST_RELATIONS_ROLE_ID.
Guest flight records save without a flight API key. To enable live lookup, add a
flight_api_key in api/config.php for the configured flight_api_url.

Safety incident tracking:
The Safety tab appears for Admin accounts and users with the configured Safety
Discord role. See SAFETY-ALERTS-SETUP.txt for evidence storage and scheduled
missed shift alert instructions.

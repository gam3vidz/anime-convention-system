<?php
return [
    'host' => 'localhost',
    'database' => 'delta_h',
    'username' => 'delta_h_user',
    'password' => 'CHANGE_ME',
    'charset' => 'utf8mb4',

    'discord_client_id' => 'CHANGE_ME',
    'discord_client_secret' => 'CHANGE_ME',
    'discord_public_key' => '',
    // Leave blank to derive the callback URL from the current HTTPS host.
    'discord_redirect_uri' => '',
    'discord_login_required' => true,
    'discord_guild_id' => 'CHANGE_ME',
    'discord_bot_token' => 'CHANGE_ME',
    'discord_volunteer_role_id' => 'CHANGE_ME',
    'discord_coordinator_role_id' => 'CHANGE_ME',
    'discord_guest_relations_role_id' => 'CHANGE_ME',
    'discord_safety_role_id' => 'CHANGE_ME',
    // Optional: grants access to the Vendor Hall portal only. Leave blank to deny non-admins (fail closed).
    'discord_vendor_hall_role_id' => '',

    'app_timezone' => 'America/Chicago',
    // Generate with: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    'shift_alert_cron_secret' => 'GENERATE_A_RANDOM_64_HEX_SECRET',

    'flight_api_provider' => 'aviationstack',
    'flight_api_key' => '',
    'flight_api_url' => 'https://api.aviationstack.com/v1/flights'
];

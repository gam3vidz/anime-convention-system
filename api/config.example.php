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
    // Temporary role assigned only while a volunteer is clocked in through Discord.
    'discord_on_duty_role_id' => '',
    // Optional: grants access to the Vendor Hall portal only. Leave blank to deny non-admins (fail closed).
    'discord_vendor_hall_role_id' => '',

    'app_timezone' => 'America/Chicago',

    // Public convention site. These are polished sample values only; replace
    // every deployment-specific fact before publishing the site.
    'public_event' => [
        'sample_content' => true,
        'name' => 'Delta H Anime & Manga Festival',
        'short_name' => 'Delta H',
        'tagline' => 'Three days where every fandom finds its panel.',
        'description' => 'A fan-powered weekend of anime, manga, cosplay, games, art, music, and late-night surprises.',
        'date_label' => 'August 13–15, 2027',
        'start_date' => '2027-08-13',
        'end_date' => '2027-08-15',
        'canonical_url' => 'https://convention.example',
        'accent' => 'Electric violet',
        'venue' => [
            'name' => 'Northstar Convention Center',
            'city' => 'Example City, USA',
            'address' => '100 Convention Way',
            'accessibility' => 'Step-free entrances, accessible seating, quiet room, and service-animal access are available throughout the event.',
            'transit' => 'The venue is a short walk from Central Station. Rideshare pickup uses the east entrance.',
        ],
        'highlights' => [
            ['value' => '3', 'label' => 'packed days'],
            ['value' => '120+', 'label' => 'programs'],
            ['value' => '80+', 'label' => 'artists & vendors'],
            ['value' => 'All ages', 'label' => 'until 9 PM'],
        ],
        'announcements' => [
            'Sample event content is active. Organizers must replace dates, venue, policies, and tickets before launch.',
        ],
        'schedule' => [
            ['day' => 'Friday', 'time' => '1:00 PM', 'category' => 'Welcome', 'title' => 'Opening Ceremony: Power Up!', 'location' => 'Main Stage', 'description' => 'Meet the hosts and preview the weekend’s biggest moments.'],
            ['day' => 'Friday', 'time' => '3:30 PM', 'category' => 'Anime', 'title' => 'Beyond the Opening Credits', 'location' => 'Panel Room A', 'description' => 'A visual tour through the art and language of memorable anime openings.'],
            ['day' => 'Friday', 'time' => '7:00 PM', 'category' => 'Cosplay', 'title' => 'Cosplay Craft Lab', 'location' => 'Workshop Studio', 'description' => 'Practical foam, fabric, and finish techniques for every skill level.'],
            ['day' => 'Saturday', 'time' => '10:30 AM', 'category' => 'Manga', 'title' => 'Make a Manga Page', 'location' => 'Creator Classroom', 'description' => 'Build a readable page from thumbnails through lettering.'],
            ['day' => 'Saturday', 'time' => '2:00 PM', 'category' => 'Guests', 'title' => 'Voices Behind the Adventure', 'location' => 'Main Stage', 'description' => 'A playful conversation about performance, direction, and fandom.'],
            ['day' => 'Saturday', 'time' => '6:30 PM', 'category' => 'Cosplay', 'title' => 'Cosplay Championship', 'location' => 'Main Stage', 'description' => 'Craftsmanship, performance, and a runway full of unforgettable builds.'],
            ['day' => 'Sunday', 'time' => '11:00 AM', 'category' => 'Games', 'title' => 'Indie Arcade Showdown', 'location' => 'Game Hall', 'description' => 'Friendly finals featuring locally made games and crowd challenges.'],
            ['day' => 'Sunday', 'time' => '3:30 PM', 'category' => 'Community', 'title' => 'Fandom Forward', 'location' => 'Panel Room B', 'description' => 'How clubs and creators can build welcoming year-round communities.'],
        ],
        'guests' => [
            ['name' => 'Nova Kitsu', 'role' => 'Voice performer', 'initials' => 'NK', 'bio' => 'A fictional sample guest known for fearless heroes, chaotic rivals, and lively convention storytelling.'],
            ['name' => 'Mika Quill', 'role' => 'Manga artist', 'initials' => 'MQ', 'bio' => 'A fictional sample illustrator sharing expressive character design and page-composition techniques.'],
            ['name' => 'Pixel Ronin', 'role' => 'Cosplay creator', 'initials' => 'PR', 'bio' => 'A fictional sample maker blending armor builds, electronics, and stage-ready performance.'],
        ],
        'vendors' => [
            ['name' => 'Moon Rabbit Press', 'type' => 'Manga & zines', 'booth' => 'A12', 'description' => 'Indie comics, mini prints, and small-run anthologies.'],
            ['name' => 'Kaiju Corner', 'type' => 'Collectibles', 'booth' => 'B04', 'description' => 'Figures, model kits, enamel pins, and display accessories.'],
            ['name' => 'Starlight Stitchery', 'type' => 'Artist alley', 'booth' => 'C18', 'description' => 'Handmade plush, embroidered patches, and soft fandom goods.'],
            ['name' => 'Mana Potion Café', 'type' => 'Food & drink', 'booth' => 'F02', 'description' => 'Colorful sparkling drinks with clearly labeled ingredients.'],
        ],
        'map_zones' => [
            ['name' => 'Main Stage', 'code' => 'MS', 'detail' => 'Ceremonies, guests, and cosplay'],
            ['name' => 'Artist Alley', 'code' => 'AA', 'detail' => 'Independent art and commissions'],
            ['name' => 'Vendor Hall', 'code' => 'VH', 'detail' => 'Collectibles, fashion, and food'],
            ['name' => 'Game Hall', 'code' => 'GH', 'detail' => 'Arcade, tabletop, and tournaments'],
            ['name' => 'Quiet Room', 'code' => 'QR', 'detail' => 'Low-sensory rest space'],
        ],
        'sponsors' => [
            ['name' => 'Sakura Level', 'tier' => 'Presenting partner'],
            ['name' => 'Mecha Works', 'tier' => 'Community partner'],
            ['name' => 'Moonbeam Media', 'tier' => 'Program partner'],
            ['name' => 'Central Arcade', 'tier' => 'Game room partner'],
        ],
        'faq' => [
            ['question' => 'Can children attend?', 'answer' => 'Yes. The daytime event is designed for all ages. A parent or guardian should review program ratings and accompany younger attendees.'],
            ['question' => 'What should I bring?', 'answer' => 'Bring your ticket code, photo ID when required by policy, a refillable water bottle, and a secure way to carry purchases.'],
            ['question' => 'Are cosplay props allowed?', 'answer' => 'Costumes are welcome. Props must follow the published safety policy and may be inspected before entering event spaces.'],
            ['question' => 'Are tickets refundable?', 'answer' => 'The organizer’s final refund and transfer policy must be configured and published before sales open.'],
            ['question' => 'How do accessibility requests work?', 'answer' => 'Contact the organizer before the event for accommodation coordination. On site, the accessibility desk can help with seating and routes.'],
        ],
        'calls_to_action' => [
            'volunteer_title' => 'Help make the weekend legendary',
            'volunteer_text' => 'Join the crew that welcomes fans, supports programs, and keeps the convention moving.',
            'vendor_title' => 'Bring your work to the hall',
            'vendor_text' => 'Future application tools will connect creators and vendors with the public directory.',
        ],
    ],

    // Stripe hosted Checkout is intentionally disabled in the example. P0
    // accepts test keys only; adding code does not enable live payments.
    'stripe_secret_key' => '',
    'stripe_webhook_secret' => '',
    'stripe_currency' => 'usd',
    'stripe_ticket_catalog' => [
        'friday' => [
            'name' => 'Friday Discovery Pass',
            'price_cents' => 3500,
            'description' => 'Friday admission and opening-night programs.',
            'max_quantity' => 6,
        ],
        'weekend' => [
            'name' => 'Weekend Adventure Pass',
            'price_cents' => 7500,
            'description' => 'General admission for all three convention days.',
            'max_quantity' => 6,
        ],
        'vip' => [
            'name' => 'Starlight VIP Pass',
            'price_cents' => 14500,
            'description' => 'Weekend admission, priority seating line, and a commemorative badge.',
            'max_quantity' => 4,
        ],
    ],

    // Generate with: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    'shift_alert_cron_secret' => 'GENERATE_A_RANDOM_64_HEX_SECRET',

    'flight_api_provider' => 'aviationstack',
    'flight_api_key' => '',
    'flight_api_url' => 'https://api.aviationstack.com/v1/flights'
];

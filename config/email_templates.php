<?php

return [
    'default_layout' => 'mytherapist',

    // Mirrors the current Mytherapist.ng mail shell in the main backend.
    'mytherapist' => [
        'brand_name' => 'mytherapist',
        'brand_suffix' => '.ng',
        'tagline' => 'Licensed therapy, made for Africa.',
        'logo_url' => 'https://mytherapist.ng/assets/images/email/logo.png',
        'website_url' => 'https://mytherapist.ng',
        'support_email' => 'support@mytherapist.ng',
        'background_color' => '#FFFAF4',
        'card_color' => '#FFFFFF',
        'heading_color' => '#1B1D3E',
        'text_color' => '#4A4D6E',
        'muted_color' => '#8B8EAC',
        'footer_color' => '#1B1D3E',
        'footer_divider_color' => '#34375F',
        'accent_color' => '#FED325',
        'show_app_badges' => true,
        'badge_caption' => 'Book, chat and join sessions from your phone',
        'app_store_url' => 'https://apps.apple.com/ng/app/mytherapist-ng/id1660816566',
        'app_store_badge_url' => 'https://mytherapist.ng/assets/images/email/app-store-badge.png',
        'play_store_url' => 'https://play.google.com/store/apps/details?id=com.mytherapist.ng.mytherapistng',
        'play_store_badge_url' => 'https://mytherapist.ng/assets/images/email/google-play-badge.png',
        'show_social_links' => true,
        'instagram_url' => 'https://www.instagram.com/mytherapistng/',
        'youtube_url' => 'https://www.youtube.com/@mytherapistng/',
        'facebook_url' => 'https://web.facebook.com/mytherapyng',
        'tiktok_url' => 'https://www.tiktok.com/@mytherapistng?lang=en',
        'linkedin_url' => 'https://www.linkedin.com/company/mytherapistng/',
        'x_url' => 'https://twitter.com/MyTherapistng',
        'footer_note' => "You're receiving this because you opted in to updates from Mytherapist.ng.",
        'crisis_text' => "If you're in crisis and need help right now, please contact your local emergency services — don't wait for a scheduled session.",
        'company_line' => 'Mytherapist.ng Mental Healthcare Solutions Limited · Lagos, Nigeria',
    ],

    'preview_context' => [
        'person' => [
            'external_id' => 'preview-person',
            'first_name' => 'Ada',
            'email' => 'ada@example.com',
            'phone' => '+2348012345678',
            'active' => true,
        ],
        'event' => [
            'name' => 'preview_event',
            'plan' => 'wellness',
            'appointment_id' => 'APT-1001',
        ],
    ],
];

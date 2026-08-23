<?php
/**
 * config/secrets.example.php  —  تێمپلەیت (بێ نرخی ڕاستەقینە)
 * ---------------------------------------------------------------------------
 * ئەم فایلە کۆپی بکە و نرخە ڕاستەقینەکان دابنێ. دوو ڕێگا هەیە:
 *
 *   ✅ پێشنیارکراو (سێرڤەر): فایلەکە بخە **دەرەوەی** فۆڵدەری پڕۆژە و docroot،
 *      بۆ نموونە /home/USER/private/secrets.local.php، پاشان ڕێڕەوەکەی
 *      دیاری بکە بە environment variable:
 *        SetEnv KASHER_SECRETS_FILE "/home/USER/private/secrets.local.php"
 *      بەم شێوەیە هیچ فایلی نهێنی لەناو repo نییە بۆ ئاشکراکردن.
 *
 *   ▪️ ئاسانی لۆکاڵ: کۆپی بکە بۆ `config/secrets.local.php` — ئەم فایلە لە git
 *      تۆمار ناکرێت (.gitignore). (لۆکاڵ بە بنەڕەت پێویستی پێی نییە، چونکە
 *      XAMPP پاسۆردی بەتاڵ بەکاردەهێنێت.)
 *
 * کۆدی tracked ئیتر هیچ پاسۆرد/توکنی hardcoded ـی تێدا نییە.
 * ---------------------------------------------------------------------------
 */
return [
    // ── پاسۆردی داتابەیس (nexoracore_db — هەموو پەیوەندییەکان یەک داتابەیس) ──
    'db_password'                 => '', // nexoracore_db
    'kasher_platform_db_password' => '', // nexoracore_db
    'zanyari_db_password'         => '', // nexoracore_db
    'kasher_logs_db_password'     => '', // nexoracore_db
    'media_db_password'           => '', // nexoracore_db

    // ── بۆتی تەلەگرام بۆ backup ی داتابەیس (cron) ──
    'telegram_bot_token'          => '',
    'telegram_chat_id'            => '',

    // ── بۆتی تەلەگرامی مۆنیتەری نرخی دۆلار (user/Tele_Bot) ──
    'nrx_bot_token'               => '',

    // ── Google OAuth بۆ چوونەژوورەوەی ڤیدیۆکان (videos/google-login.php) ──
    'google_client_id'            => '',
    'google_client_secret'        => '',

    // ── DigitalOcean Spaces (S3) — بۆ وێنە/ڤیدیۆکان ──
    'spaces_key'                  => '',
    'spaces_secret'               => '',
    'spaces_bucket'               => '',
    'spaces_region'               => 'fra1',
    'spaces_endpoint'             => '', // بۆ نموونە: https://fra1.digitaloceanspaces.com
    'spaces_public_base_url'      => '', // بۆ نموونە: https://your-bucket.fra1.cdn.digitaloceanspaces.com
];

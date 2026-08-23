<?php
/**
 * Shared helpers for shop WhatsApp order from cart.
 */

function shop_whatsapp_ensure_column(mysqli $conn): void
{
    $check = $conn->query("SHOW COLUMNS FROM website_settings LIKE 'enable_whatsapp_order'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE website_settings ADD COLUMN enable_whatsapp_order TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=دوگمەی واتسئاپ لە سەبەتەی کڕین' AFTER show_shop_exit_button");
    }
    if ($check) {
        $check->close();
    }
}

function shop_whatsapp_order_enabled(array $settings): bool
{
    return (int) ($settings['enable_whatsapp_order'] ?? 0) === 1;
}

function shop_whatsapp_owner_phone(array $settings): string
{
    return trim((string) ($settings['phone'] ?? ''));
}

/**
 * @return array{enabled:bool,available:bool,phone:string,business_name:string,slug:string}
 */
function shop_whatsapp_cart_config(array $settings, string $slug): array
{
    $enabled = shop_whatsapp_order_enabled($settings);
    $phone = shop_whatsapp_owner_phone($settings);
    $businessName = trim((string) ($settings['business_name'] ?? ''));

    return [
        'enabled' => $enabled,
        'available' => $enabled && $phone !== '',
        'phone' => $enabled && $phone !== '' ? $phone : '',
        'business_name' => $businessName !== '' ? $businessName : $slug,
        'slug' => $slug,
    ];
}

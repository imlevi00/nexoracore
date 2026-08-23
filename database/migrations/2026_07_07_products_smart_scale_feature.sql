-- بنەڕەت: قەپانی زیرەک ناچالاک بۆ هەموو پاکێجەکان
INSERT INTO package_feature_permissions (package_id, feature_key, is_enabled, lock_html)
SELECT p.id, 'products_smart_scale', 0, ''
FROM packages p
WHERE NOT EXISTS (
    SELECT 1 FROM package_feature_permissions pfp
    WHERE pfp.package_id = p.id AND pfp.feature_key = 'products_smart_scale'
);

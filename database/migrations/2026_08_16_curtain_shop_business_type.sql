-- جۆری ئیش و کاری «دوکانی پەردە» بۆ بەشی ڕێکخستنەکان
-- Idempotent بەهۆی UNIQUE(code)

INSERT IGNORE INTO `business_types` (`code`, `name_ku`, `sort_order`)
VALUES ('curtain_shop', 'دوکانی پەردە', 5);

UPDATE `business_types`
SET `name_ku` = 'دوکانی پەردە'
WHERE `code` = 'curtain_shop'
  AND `name_ku` <> 'دوکانی پەردە';

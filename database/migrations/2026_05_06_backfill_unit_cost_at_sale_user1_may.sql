-- Backfill unit_cost_at_sale for user_id = 514 in May 2026.
-- Goal: freeze monthly profit even if product buy prices change later.
-- NOTE: Run preview queries first. Use ROLLBACK instead of COMMIT if results look incorrect.

START TRANSACTION;

-- 1) Preview: total rows with missing unit_cost_at_sale in target window.
SELECT COUNT(*) AS target_null_rows
FROM sale_items si
JOIN sales s ON s.id = si.sale_id
WHERE s.user_id = 514
  AND s.sale_date >= '2026-05-01'
  AND s.sale_date < '2026-06-01'
  AND si.unit_cost_at_sale IS NULL;

-- 2) Preview: how many rows can be resolved from current product_units.buy_price.
-- Priority: exact (product_id + unit_id). Fallback: primary unit for the same product.
SELECT COUNT(*) AS resolvable_rows
FROM sale_items si
JOIN sales s ON s.id = si.sale_id
JOIN products p ON p.id = si.product_id AND p.user_id = 514
LEFT JOIN product_units pu_exact
  ON pu_exact.product_id = si.product_id
 AND pu_exact.unit_id = si.unit_id
LEFT JOIN product_units pu_primary
  ON pu_primary.product_id = si.product_id
 AND pu_primary.is_primary = 1
WHERE s.user_id = 514
  AND s.sale_date >= '2026-05-01'
  AND s.sale_date < '2026-06-01'
  AND si.unit_cost_at_sale IS NULL
  AND COALESCE(pu_exact.buy_price, pu_primary.buy_price) IS NOT NULL;

-- 3) Backfill update.
UPDATE sale_items si
JOIN sales s ON s.id = si.sale_id
JOIN products p ON p.id = si.product_id AND p.user_id = 514
LEFT JOIN product_units pu_exact
  ON pu_exact.product_id = si.product_id
 AND pu_exact.unit_id = si.unit_id
LEFT JOIN product_units pu_primary
  ON pu_primary.product_id = si.product_id
 AND pu_primary.is_primary = 1
SET si.unit_cost_at_sale = COALESCE(pu_exact.buy_price, pu_primary.buy_price)
WHERE s.user_id = 514
  AND s.sale_date >= '2026-05-01'
  AND s.sale_date < '2026-06-01'
  AND si.unit_cost_at_sale IS NULL
  AND COALESCE(pu_exact.buy_price, pu_primary.buy_price) IS NOT NULL;

-- 4) Result: number of updated rows.
SELECT ROW_COUNT() AS updated_rows;

-- 5) Verification A: remaining NULL rows after backfill in target window.
SELECT COUNT(*) AS remaining_null_rows
FROM sale_items si
JOIN sales s ON s.id = si.sale_id
WHERE s.user_id = 514
  AND s.sale_date >= '2026-05-01'
  AND s.sale_date < '2026-06-01'
  AND si.unit_cost_at_sale IS NULL;

-- 6) Verification B: sample updated rows.
SELECT
  si.id AS sale_item_id,
  si.sale_id,
  si.product_id,
  si.unit_id,
  si.unit_cost_at_sale,
  s.sale_date
FROM sale_items si
JOIN sales s ON s.id = si.sale_id
WHERE s.user_id = 514
  AND s.sale_date >= '2026-05-01'
  AND s.sale_date < '2026-06-01'
  AND si.unit_cost_at_sale IS NOT NULL
ORDER BY s.sale_date DESC, si.id DESC
LIMIT 50;

COMMIT;

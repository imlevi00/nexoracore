-- زیادکردنی ستوونی ڕەنگی قوماش بۆ دوکانی پەردە

ALTER TABLE `products`
  ADD COLUMN `fabric_color` VARCHAR(100) NULL DEFAULT NULL COMMENT 'ڕەنگی قوماش' AFTER `image_path`;


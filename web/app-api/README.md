# Kashery App API (بۆ بەرنامەی Flutter)

APIـیەکی سادەی JSON بۆ بەرنامەی مۆبایل/ویندۆز (Flutter) کە هەمان داتای فرۆشگا
ئۆنلاینەکە (کە لە `web/` دا پیشان دەدرێت) بە فۆرماتی JSON دەگەڕێنێتەوە.

> ئەم فۆڵدەرە **هیچ گۆڕانکارییەک** لە کۆدی سایتەکە ناکات — تەنها لایەرێکی نوێی
> API ـە کە هەمان داتابەیس و هەمان لۆژیکی سایتەکە بەکاردەهێنێت (هەمان
> `config/` و `web_orders`).

## Base URL

```
https://nexoracore.com/web/app-api
```

هەموو وەڵامەکان `Content-Type: application/json; charset=utf-8` ـن و CORS
چالاکە (`Access-Control-Allow-Origin: *`) بۆ ئەوەی بەرنامەکە لە هەر شوێنێکەوە
بانگی بکات.

## 🔑 کلیلی API (Authentication)

**هەموو** endpointـەکان پێویستیان بە کلیلی API هەیە. بەرنامەکە دەبێت لەگەڵ هەر
داواکارییەکدا کلیل بنێرێت بە یەکێک لەم دوو ڕێگایە:

```
X-API-Key: <کلیل>
```
یان:
```
Authorization: Bearer <کلیل>
```

بەبێ کلیلی دروست، کۆدی `401` دەگەڕێتەوە. (داواکاری `OPTIONS` ی preflight پێویستی بە
کلیل نییە.)

### ڕێکخستنی کلیل لەسەر سێرڤەر
کلیلەکان لە پەڕگەی **`web/app-api/keys.php`** خەزن دەکرێن (ئەم پەڕگەیە لە git ـدا
نایەت). ئەگەر بەردەست نەبوو، کۆپیی `keys.example.php` بکە بۆ `keys.php` و کلیلێکی
بەهێز دابنێ:

```bash
php -r "echo bin2hex(random_bytes(24));"
```

> **ئاگاداری:** کلیلێک کە لەناو بەرنامەی بڵاوکراوەدا (APK/IPA) بێت، دەکرێت
> دەربهێنرێت — بۆیە کلیل «ڕێگرە» بۆ بەکارهێنانی هەڕەمەکی، نەک پاراستنی تەواو.
> پاراستنی سەرەکی دژی سپام لە ڕێگەی Rate Limit ـەوەیە (لای خوارەوە).

## ⏱️ سنووردارکردنی داواکاری (Rate Limit)

بۆ ڕێگری لە سپام و بارگرانی سەر سێرڤەر، ئەم سنوورانە هەن (بەپێی IP):

| بەش | سنوور |
|-----|-------|
| گشتی (هەموو endpointـەکان) | ١٨٠ داواکاری / ٦٠ چرکە |
| ناردنی داواکاری (هەر IP) | ١٥ داواکاری / کاتژمێر |
| ناردنی داواکاری (هەر مۆبایل) | ٨ داواکاری / کاتژمێر |

ئەگەر سنوور تێپەڕێنرا، کۆدی `429` لەگەڵ header ـی `Retry-After` دەگەڕێتەوە.

## شێوازی وەڵام (Response)

سەرکەوتوو:
```json
{ "success": true, "data": { ... }, "meta": { ... } }
```
هەڵە:
```json
{ "success": false, "error": { "code": "shop_not_found", "message": "..." } }
```

---

## 1) فرۆشگاکان — `GET /shops.php`

لیستی هەموو فرۆشگا چالاکەکان.

| پارامیتەر | جۆر | ڕوونکردنەوە |
|-----------|-----|-------------|
| `search`  | string | گەڕان بەپێی ناوی فرۆشگا |
| `page`    | int | ژمارەی لاپەڕە (بنەڕەت 1) |
| `per_page`| int | لە هەر لاپەڕەیەک (بنەڕەت 30، زۆرترین 100) |

**یەک فرۆشگا:** `GET /shops.php?slug=SHOP_SLUG`

نموونەی `data` بۆ یەک فرۆشگا:
```json
{
  "id": 4, "user_id": 12, "slug": "mystore",
  "business_name": "فرۆشگای من",
  "phone": "0770...", "address": "هەولێر",
  "banner_url": "https://.../banner.jpg",
  "product_count": 128,
  "settings": {
    "show_retail_price": true, "show_wholesale_price": true,
    "show_special_price": false, "show_stock_quantity": true,
    "show_by_category": true
  },
  "requires_google_login": false
}
```

## 2) کەتەگۆرییەکان — `GET /categories.php?slug=SHOP_SLUG`

```json
{ "success": true,
  "data": [ { "id": 3, "name": "خواردنەوە", "product_count": 12 } ],
  "meta": { "show_by_category": true } }
```

## 3) کاڵاکان — `GET /products.php?slug=SHOP_SLUG`

| پارامیتەر | جۆر | ڕوونکردنەوە |
|-----------|-----|-------------|
| `category`| int | فلتەر بەپێی id ی کەتەگۆری |
| `search`  | string | گەڕان بەپێی ناو یان بارکۆد |
| `sort`    | string | `newest`\|`oldest`\|`price_asc`\|`price_desc`\|`name` |
| `page`    | int | بنەڕەت 1 |
| `per_page`| int | بنەڕەت 20، زۆرترین 100 |

هەر کاڵایەک:
```json
{
  "id": 55, "name": "کۆکاکۆلا", "barcode": "111222",
  "image": "https://.../test.jpg",
  "category_id": 3, "category_name": "خواردنەوە",
  "currency": "IQD",
  "unit_id": 9, "unit_name": "دانە",
  "sell_price": 1500, "wholesale_price": 1200, "special_price": null,
  "discount_price": 1000,
  "sell_price_formatted": "1,500 دینار",
  "wholesale_price_formatted": "1,200 دینار",
  "discount_price_formatted": "1,000 دینار",
  "has_discount": true,
  "stock_quantity": 50, "in_stock": true
}
```

> **تێبینی:** نرخێک کە فرۆشگا لە سایتەکەدا پیشانی نەدات (وەک `show_special_price=0`)،
> لێرەش بە `null` دەگەڕێتەوە — تا هەمان ڕەفتاری سایتەکە بپارێزرێت.

**یەک کاڵای تەواو:** `GET /products.php?slug=SHOP_SLUG&id=PRODUCT_ID`
— وردەکاری (`description`)، گەلەری وێنەکان (`images`)، و هەموو یەکەکان (`units`)
لەگەڵ نرخەکانیان دەگەڕێنێتەوە.

## 4) ناردنی داواکاری — `POST /submit_order.php`

`Content-Type: application/json`

```json
{
  "website_slug": "mystore",
  "customer_name": "ئەمیر",
  "customer_phone": "07701234567",
  "customer_address": "هەولێر، شەقامی ٦٠",
  "notes": "زوو بگەیەنن",
  "request_token": "UUID-یەکجارەیی",
  "total_amount": 3000,
  "items": [
    { "product_id": 55, "name": "کۆکاکۆلا", "unit": "دانە",
      "unit_id": 9, "price": 1500, "quantity": 2 }
  ]
}
```

- خانە پێویستەکان: `website_slug`, `customer_name`, `customer_phone`, `items`.
- `request_token`: بۆ نەکردنی داواکاری دووبارە (idempotency). ئەگەر نەنێردرا،
  سێرڤەر خۆی دروستی دەکات — بەڵام باشترە بەرنامەکە بۆ هەر داواکارییەک UUIDـێکی
  یەکجارەیی بنێرێت.
- ئەگەر `total_amount` نەنێردرا، لە `items` ـەوە حیسابی دەکرێت.

وەڵام:
```json
{ "success": true,
  "data": { "order_number": "WO-20260803-AB12CD", "order_id": 42,
            "pdf_url": "https://.../web/api/generate_order_pdf.php?order_id=42",
            "duplicate": false } }
```

داواکارییەکان لە هەمان خشتەی `web_orders` تۆمار دەکرێن، بۆیە خاوەن فرۆشگا
لە داشبۆردی سایتەکە و لە تەلەگرام (ئەگەر ڕێکخرابێت) ئاگادار دەکرێتەوە.

## 5) دۆخی داواکاری — `GET /order_status.php?order_number=..&phone=..`

پشکنینی دۆخی داواکارییەک (بۆ کڕیاری میوان). دۆخ: `pending` یان `completed`.

## 6) ڤیدیۆکان — `GET /videos.php`

فیدی ڤیدیۆکان (وەک بەشی ڤیدیۆی سایتەکە `/videos/`). دوو جۆر ڤیدیۆ هەیە:
`free` (ڤیدیۆی ئازاد) و `product` (ڤیدیۆی بەستراو بە کاڵا).

| پارامیتەر | جۆر | ڕوونکردنەوە |
|-----------|-----|-------------|
| `slug`    | string | تەنها ڤیدیۆکانی ئەو فرۆشگایە (ئەگەر نەبوو، فیدی گشتی) |
| `page`    | int | ژمارەی لاپەڕە (بنەڕەت 1) |
| `per_page`| int | لە هەر لاپەڕەیەک (بنەڕەت 20، زۆرترین 50) |

> فیدی گشتی تەنها ڤیدیۆی ئەو فرۆشگایانە پیشان دەدات کە لە لاپەڕەی سەرەکیدا
> دەردەکەون (`show_on_index = 1`) — هەمان ڕەفتاری سایتەکە.

هەر ڤیدیۆیەک:
```json
{
  "id": 12, "video_type": "product",
  "video_url": "https://.../video.mp4",
  "description": "کۆکاکۆلای نوێ",
  "duration_seconds": 30, "audio_type": "music",
  "created_at": "2026-08-01 10:22:00",
  "view_count": 340, "like_count": 25,
  "shop": {
    "user_id": 12, "business_name": "فرۆشگای من",
    "slug": "mystore", "shop_url": "https://.../web/shop.php?slug=mystore",
    "logo_url": "https://.../logo.jpg"
  },
  "product": {
    "id": 55, "name": "کۆکاکۆلا", "image": "https://.../test.jpg",
    "currency": "IQD", "retail_price": 1500, "discount_price": 1000,
    "final_price": 1000, "retail_price_formatted": "1,500 دینار",
    "final_price_formatted": "1,000 دینار", "has_discount": true,
    "in_stock": true, "unit_id": 9, "unit_name": "دانە",
    "website_slug": "mystore"
  }
}
```

> `product` تەنها بۆ ڤیدیۆی جۆری `product` پڕ دەکرێتەوە (بۆ `free` دەبێتە `null`).
> ئەگەر کاڵاکە شاردراوە بێت، `product` دەبێتە `null` بەڵام ڤیدیۆکە دەمێنێتەوە.

**یەک ڤیدیۆی دیاریکراو:** `GET /videos.php?video_type=free&video_id=12`
— تەنها ئەو یەک ڤیدیۆیە بە هەمان فۆرمات دەگەڕێنێتەوە (بۆ deep-linking).

## 7) تۆمارکردنی بینین — `POST /video_view.php`

`Content-Type: application/json` یان form-encoded

```json
{ "video_id": 12, "video_type": "product" }
```

بینین بەپێی IP + User-Agent یەکجار تۆمار دەکرێت (میوان). وەڵام:
```json
{ "success": true, "data": { "view_count": 341 } }
```

> **تێبینی:** لایک (`video_likes`) پێویستی بە چوونەژوورەوەی Google هەیە لە
> سایتەکەدا، بۆیە لە بەرنامەکەوە پشتگیری ناکرێت — تەنها ژمارەی لایک لە
> `videos.php` بە خوێندنەوە دەگەڕێتەوە.

---

## تێبینی گرنگ بۆ فرۆشگا سنووردارەکان

هەندێک فرۆشگا لە سایتەکەدا سنووردارن بە چوونەژوورەوەی Google
(`shop_google_restrict = 1`). ئەم فرۆشگایانە لە `shops.php` بە
`"requires_google_login": true` دیاری دەکرێن، و endpointـەکانی
`products.php` و `submit_order.php` بۆیان کۆدی `403` دەگەڕێننەوە
(چونکە بەرنامەکە session ی Google ی نییە). ئەمە هەمان ئاسایشی سایتەکەیە.

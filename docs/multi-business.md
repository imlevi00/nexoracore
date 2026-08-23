# بەڕێوەبردنی چەندین بزنس بۆ یەک خاوەن (Multi-Business)

> پلانی **A** — جێبەجێکراو. مۆدێل: هەر بزنس = ڕیزێکی `users`، بەستراو بە
> `organizations`. خاوەن لەنێوان بزنسەکاندا دەگۆڕێت و ڕاپۆرتی گشتیان دەبینێت.

---

## ١. بیرۆکەی سەرەکی

هەموو سیستەمەکە داتای خۆی بە `$_SESSION['user_data']['id']` (کە هەمیشە
`main_user_id`ـە) scope دەکات. بۆیە:

> **گۆڕینی `user_data['id']` = گۆڕینی بزنس** — هەموو queryـیەکانی ئێستا خۆکارانە
> بۆ بزنسی چالاک کاردەکەن، **بەبێ گۆڕینی هیچ query ـیەک.**

هەر بزنسێک ئەکاونتێکی سەربەخۆی `users`ـە، بە products / sales / settings /
sub_users / business_type / پاکێج / باڵانسی **جیای** خۆیەوە. تەنها لایەرێکی نوێ
ئەم ئەکاونتانە بەیەکەوە دەبەستێتەوە و ڕێگە دەدات خاوەن بگۆڕێت و کۆیان بکاتەوە.

### بڕیارە دیزاینییەکان
- **پاکێج:** هەر بزنس پاکێجی خۆی هەیە (ڕێگای بێ-گۆڕانکاری).
- **باڵانس/ئاگادارکردنەوە:** هەر بزنس جیا (`ai_balance`, `support_balance`,
  Telegram هەمووی سەر بە ئەکاونتی خۆی).
- **کارمەند (sub_user):** قوفڵکراو بۆ یەک بزنس — ناتوانێت بگۆڕێت.
- **گۆڕین + ڕاپۆرتی گشتی:** تەنها **خاوەنی ڕێکخراو** (`organizations.owner_user_id`).
- **دروستکردنی بزنس:** تەنها **ئەدمین** (خاوەن لە خۆیەوە ناتوانێت).

---

## ٢. مۆدێلی داتا

```
organizations
  ├─ id
  ├─ name
  └─ owner_user_id   → users.id ی ئەکاونتی خاوەن (anchor)

users
  └─ organization_id → organizations.id   (NULL = بزنسی تاک، ڕەفتاری کۆن)
```

- **`organization_id` بنەڕەت NULL** → هەموو بەکارهێنەرانی ئێستا هەروەک خۆیان
  دەمێننەوە. هیچ خشتەیەکی تر دەستکاری نەکراوە. هیچ queryـیەک نەنووسراوەتەوە.
- Migration: [`database/migrations/2026_08_17_multi_business_organizations.sql`](../database/migrations/2026_08_17_multi_business_organizations.sql)

### کۆنترۆڵی پاکێج
Feature key ـی نوێ لە کاتالۆگی پاکێج (`includes/package_features.php`):

| feature_key | جۆر | بنەڕەت | مانا |
|-------------|-----|--------|------|
| `multi_business_max_count` | ژمارە | `1` | زۆرترین ژمارەی بزنس. `1` = تاک-بزنس (ڕەفتاری ئاسایی). ≥`2` = چەندین بزنس چالاک. |

نرخەکە لە کۆڵۆمی `package_feature_permissions.is_enabled` هەڵدەگیرێت (وەک
`employees_max_count`). ئەگەر ڕیز نەبێت → بنەڕەت `1`.

---

## ٣. فایلە نوێ/دەستکاریکراوەکان

### نوێ
| فایل | ئەرک |
|------|------|
| `includes/business_context.php` | دڵی لۆجیک: resolve، switch context، feature helper، createLinkedBusiness |
| `user/switch_business.php` | Endpointـی گۆڕینی بزنس (POST + CSRF + validation) |
| `user/reports/businesses_overview.php` | ڕاپۆرتی گشتی هەموو بزنسەکان (تەنها خاوەن) |
| `database/migrations/2026_08_17_multi_business_organizations.sql` | Schema |

### دەستکاریکراو
| فایل | گۆڕانکاری |
|------|-----------|
| `config/config.php` | بانگکردنی `resolveBusinessContext()` دوای `security.php` |
| `config/security.php` | پاککردنەوەی context لە کاتی `logout` |
| `includes/navigation.php` | Dropdownـی switcher + لینکی ڕاپۆرتی گشتی |
| `includes/package_features.php` | زیادکردنی `multi_business_max_count` + بەشی `organization` |
| `adminKx9mZpQa7WvRt4Ny6Lb3/user_manager/user_details.php` | کارتی دروستکردن/گرێدانی بزنس |
| `database/test_test.sql` | خشتەی `organizations` + کۆڵۆمی `organization_id` |

---

## ٤. چۆن کاردەکات (Flow)

### دەستنیشانکردنی بزنسی چالاک — `resolveBusinessContext()`
لە `config.php` لەسەر **هەموو داواکارییەک** بانگ دەکرێت (دوای session):

```
ئەگەر isUser() نەبوو            → هیچ (admin/web/api)
ئەگەر sub_user بوو              → هیچ (کارمەند قوفڵکراوە)
owner_user_id (یەکجار) = user_data['id']   ← anchorـی چوونەژوورەوە
org = ڕێکخراوێک کە owner_user_id == anchor?
   ├─ نەخێر → active = anchor، user_data['id'] = anchor (ڕەفتاری کۆن)
   └─ بەڵێ  → active = active_business_id (پشتڕاست ئەگەر سەر بە هەمان orgە)
              user_data['id']       = active   ← re-scope ی هەموو سیستەم
              user_data['business_name'] = ناوی بزنسی چالاک
```

**idempotent** و بێ-زیانە: بۆ بەکارهێنەری تاک/کارمەند هیچ ناکات.

### گۆڕین — `user/switch_business.php`
POST بە `business_id` + CSRF. پشکنین: main-user بێت، خاوەنی ڕێکخراو بێت، بزنسەکە
سەر بە هەمان ڕێکخراو بێت، `approved` و بەسەرنەچووبێت. پاشان
`active_business_id` نوێ دەکاتەوە.

### گۆڕەری UI
لە navbar، تەنها بۆ خاوەنی ڕێکخراو کە **≥٢ بزنسی** هەیە، dropdownـێک لیستی
بزنسەکان پیشان دەدات + لینکی ڕاپۆرتی گشتی.

---

## ٥. کۆنترۆڵی ئەدمین

لە `user_manager/user_details.php`ی خاوەن، کارتی **«چەندین بزنس»**:
1. سنووری پاکێج پیشان دەدات (`current / max`).
2. ئەگەر `multi_business_max_count ≥ 2` و سنوور پڕ نەبووبێت → فۆرمی
   **«زیادکردنی بزنسی نوێ»** (ناو، ئیمەیڵ، پاسۆرد، مۆبایل، بەروار، پاکێج).
3. لیستی بزنسەکانی ڕێکخراو (بە لینک بۆ وردەکاری هەریەکەیان).

پشت ئەستوورە بە:
- `createLinkedBusiness($ownerId, $data)` — ڕیزی `users` دروست دەکات (status
  `approved`)، ڕێکخراو دروست/پەیدا دەکات (`ensureOrganizationForOwner`)، سنوور و
  یەکتایی ئیمەیڵ جێبەجێ دەکات.

> **خاوەن لە خۆیەوە هیچ توانایەکی دروستکردنی بزنسی نییە** — هیچ دووگمەیەک لە
> بەشی `user/`دا نییە. تەنها ئەدمین + پاکێجی ڕێگەپێدەر.

---

## ٦. ڕاپۆرتی گشتی — `user/reports/businesses_overview.php`

- تەنها خاوەنی ڕێکخراو (≥٢ بزنس). ئەگەر نا → redirect.
- بۆ هەر بزنس `calculateProfitStatsByCurrency($conn, $bizId, ...)` بانگ دەکات.
- **دراوی IQD و USD بە تەواوی جیا** — هەرگیز تێکەڵ/گۆڕین نەکراو.
- پیشاندان: کۆی گشتی هەر دراو، «باشترین داهات»، «زۆرترین خەرجی»، و خشتەی
  وردەکاری بەپێی بزنس.

---

## ٧. دەستەبەری بێ-کێشەیی (Backward/Forward Compatible)

1. `organization_id = NULL` بنەڕەت → بەکارهێنەرانی ئێستا هیچیان ناگۆڕێت.
2. تەنها خشتەی `users` کۆڵۆمێکی زیاد بوو؛ هیچ queryـیەک نەنووسراوەتەوە.
3. Switcher تەنها کاتێک دەردەکەوێت کە ڕێکخراو ≥٢ بزنسی هەیە.
4. فلۆی کارمەند (sub_user) تەواو نەگۆڕاو.
5. ئەگەر feature لابردرا، هەموو بزنسەکان وەک ئەکاونتی سەربەخۆ کاردەکەن.
6. Admin/web/api: `resolveBusinessContext` بۆیان no-op ـە.

---

## ٨. تاقیکردنەوە

- **PHP lint:** هەموو ٨ فایلە دەستکاریکراوەکان بێ-هەڵە.
- **Migration:** لەسەر `test_test` بەسەرکەوتوویی جێبەجێکرا (خشتە + کۆڵۆم + index).
- **End-to-end (CLI):** دروستکردن، گرێدان، لیستکردن، سنووری پاکێج (`limit_reached`
  لە سنووردا)، و یەکتایی ئیمەیڵ — هەمووی سەرکەوتوو، DB پاش تاقیکردنەوە
  گەڕایەوە دۆخی سەرەتای.

---

## ٩. تێبینی بۆ داهاتوو (ئارەزوومەندانە)

- **بەڕێوەبەری چەند-بزنس:** ئێستا کارمەند بۆ یەک بزنس قوفڵکراوە. ئەگەر
  پێویست بوو بەڕێوەبەرێک چەند بزنس (نەک هەموویان) ببینێت، دەکرێت `business_id`
  زیاد بکرێت بۆ لیستی `$allowedFields`ـی ABAC (`includes/permissions.php`) و
  policyـی scoped بنووسرێت.
- **ئاگاداری:** ئەکاونتی سەرەکی (main) هەر بزنسێک با تەنها لای خاوەن بێت؛
  بەڕێوەبەرەکان دەبێت وەک `sub_user` دروست بکرێن (نەک ئەکاونتی سەرەکی) تاکو
  ناتوانن بگۆڕێن بۆ بزنسی تر.

# GSC Index Inspector

افزونه‌ی وردپرس برای بررسی وضعیت ایندکس صفحات سایت در گوگل با **Google Search Console URL Inspection API** — بدون Composer و کتابخانه‌ی جانبی.

## امکانات

- **داشبورد:** بررسی سریع هر آدرس، کارت‌های آمار، نوار پوشش ایندکس و بررسی‌های اخیر
- **نتیجه‌ی خوانا:** چک‌لیست robots.txt، دریافت صفحه، noindex، تطابق canonical و سایت‌مپ
- **بررسی گروهی:** صف با WP-Cron یا «اجرای سریع در مرورگر» با نوار پیشرفت زنده
- **اولویت‌بندی هوشمند صف:**
  1. دسته‌بندی‌ها (مثل دسته‌بندی محصولات ووکامرس) — دسته‌های پرمحصول‌تر اول
  2. صفحات هرگز بررسی‌نشده
  3. صفحاتی که آخرین بار ایندکس نبودند
  4. صفحات تازه منتشر/ویرایش‌شده
  5. به‌روزرسانی بقیه (قدیمی‌ترین بررسی اول)
  - امکان انتخاب انواع محتوای مهم‌تر (مثلاً محصولات)
- **ستون «ایندکس گوگل»** با فیلتر وضعیت و کار دسته‌جمعی در لیست نوشته‌ها، محصولات و دسته‌بندی‌ها
- **متاباکس** در ویرایشگر نوشته
- **تب مشکلات — «چرا ایندکس نشده؟»:** صفحات بر اساس دلیل گوگل گروه‌بندی می‌شوند (URL is unknown، Discovered، Crawled – not indexed، canonical، noindex، Soft 404، ۴۰۴، ریدایرکت، خطای سرور) با توضیح، راه‌حل، خروجی، «بررسی دوباره» و «ارسال به Indexing API»
- **سایر بررسی‌ها:** خارج از سایت‌مپ، canonical متفاوت، خطاهای پرتکرار Rich Results
- **یکپارچگی با [Simple Google Indexing API](https://github.com/mohammadmahdidoosti/simple-google-indexing-api):** ارسال صفحات قابل ارسال به صف آن، ارسال خودکار روزانه، جلوگیری از ارسال تکراری، بررسی دوباره بعد از ۳ روز و گزارش اثربخشی
- **دسته‌های خالی:** noindex در Rank Math برای دسته‌هایی که کل زیرشاخه‌شان خالی است (قانون خود Rank Math آن‌ها را پوشش نمی‌دهد)، با برداشتن خودکار وقتی محصول بگیرند
- **هم‌گام‌سازی سایت‌مپ دسته‌ها:** دسته‌های والد (محصولات در زیردسته‌ها) در سایت‌مپ Rank Math، و تازه‌سازی خودکار کش سایت‌مپ با هر تغییر دسته‌ها
- **روند و هشدار:** نمودار پوشش ایندکس، لیست خارج‌شده‌ها، اعلان ایمیل/تلگرام و گزارش هفتگی
- **اولویت با ترافیک و فروش:** کلیک/نمایش ۲۸ روز Search Console و تعداد فروش ووکامرس
- **افزودن خودکار به صف:** با انتشار/ویرایش هر محصول (همراه با دسته‌ها) و اسکن روزانه — دسته‌ای که تازه محصول بگیرد خودش وارد صف می‌شود
- **خروجی:** CSV کامل (سازگار با اکسل)، TXT و «کپی آدرس‌ها» بر اساس وضعیت (ایندکس‌نشده، با هشدار، ایندکس‌شده، بررسی‌نشده) — با حذف خودکار صفحات noindex/مسدود و سقف تعداد، برای ارسال به افزونه‌های Indexing API
- کنترل سهمیه‌ی ۲۰۰۰ بررسی در روز، کش نتایج، توقف خودکار صف در خطای احراز هویت یا سهمیه
- راه‌اندازی مرحله‌ای با کشیدن و رها کردن فایل کلید JSON و تست اتصال
- رابط راست‌به‌چپ و سازگار با موبایل

## نصب

1. از [Releases](../../releases) یا با `git archive` فایل zip بسازید:
   ```bash
   git archive --format=zip --prefix=gsc-index-inspector/ -o gsc-index-inspector.zip HEAD
   ```
2. پیشخوان وردپرس ← افزونه‌ها ← افزودن ← بارگذاری افزونه ← فعال‌سازی
3. منوی «ایندکس گوگل» شما را به مراحل راه‌اندازی می‌برد.

## راه‌اندازی گوگل

1. در [Google Cloud Console](https://console.cloud.google.com/projectcreate) یک پروژه بسازید (تأیید دومرحله‌ای حساب گوگل باید فعال باشد).
2. [Google Search Console API](https://console.cloud.google.com/apis/library/searchconsole.googleapis.com) را فعال کنید.
3. یک Service Account بسازید (نقش IAM لازم نیست) و از تب Keys یک کلید JSON بگیرید.
4. ایمیل Service Account را در Search Console ← Settings ← Users and permissions با دسترسی **Restricted** اضافه کنید.
5. در تنظیمات افزونه فایل JSON را رها کنید، Property را انتخاب کنید (`sc-domain:example.com` یا `https://example.com/`) و «تست اتصال» را بزنید.

روش امن‌تر برای نگهداری کلید (به‌جای دیتابیس):

```php
define( 'GSCII_SERVICE_ACCOUNT_FILE', '/path/outside/public_html/gsc-key.json' );
```

> ⚠️ فایل کلید JSON را هرگز در این مخزن commit نکنید.

## ساختار

```
gsc-index-inspector.php            فایل اصلی افزونه
includes/
  class-gscii-google-client.php    احراز هویت JWT (RS256) و فراخوانی API
  class-gscii-store.php            ذخیره/کش نتایج نوشته‌ها و دسته‌بندی‌ها، سهمیه، لاگ
  class-gscii-queue.php            صف WP-Cron و اولویت‌بندی هوشمند
  class-gscii-admin.php            رابط مدیریت، ستون‌ها، متاباکس، AJAX
  class-gscii-export.php           خروجی CSV/TXT و فیلترها (وضعیت، دلیل، پرچم)
  class-gscii-auto.php             افزودن خودکار به صف و اسکن روزانه
  class-gscii-reports.php          طبقه‌بندی دلایل، پرچم‌ها، تب مشکلات
  class-gscii-indexing.php         پل اتصال به Simple Google Indexing API
  class-gscii-empty.php            دسته‌های خالی و noindex در Rank Math
  class-gscii-history.php          روند روزانه و خروج از ایندکس
  class-gscii-alerts.php           اعلان ایمیل/تلگرام
  class-gscii-traffic.php          ترافیک Search Analytics و اولویت فروش
  class-gscii-sitemap.php          هم‌گام‌سازی سایت‌مپ دسته‌ها در Rank Math
assets/                            CSS و JS رابط کاربری
uninstall.php                      پاک‌سازی کامل داده‌ها
```

## محدودیت‌های گوگل

- ۲۰۰۰ بررسی در روز و ۶۰۰ در دقیقه برای هر Property
- این API فقط وضعیت را می‌خواند؛ «Request Indexing» از طریق API عمومی ممکن نیست.

## رفع خطا

| خطا | علت |
|---|---|
| `403 Permission denied` | ایمیل Service Account به Property اضافه نشده یا Property اشتباه است |
| `invalid_grant` | ساعت سرور نادرست است یا کلید باطل شده |
| `429` | سهمیه تمام شده |

## مجوز

GPL-2.0-or-later

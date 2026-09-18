# اسپید‌پالس پرو اولترا (SpeedPulse Pro Ultra)

سامانه مانیتورینگ بلادرنگ وردپرس، عیب‌یاب PHP، جراح ووکامرس و تولید پچ بهینه‌سازی با هوش مصنوعی — رابط و متون کاملاً فارسی و راست‌چین.

## نویسنده و مخزن

| مورد | مقدار |
|------|--------|
| نویسنده | [Mojtaba Fallah](https://github.com/mojtabafallah) |
| مخزن GitHub | [mojtabafallah/speed_plus](https://github.com/mojtabafallah/speed_plus) |
| کلون | `https://github.com/mojtabafallah/speed_plus.git` |

## نصب

1. افزونه را از مسیر `wp-content/plugins/speedpulse-pro-ultra` فعال کنید.
2. هنگام فعال‌سازی، فایل MU به `wp-content/mu-plugins/speedpulse-early.php` کپی می‌شود.
3. از منوی **اسپید‌پالس پرو** تنظیمات AI را وارد کنید.
4. از نوار بالای وردپرس مانیتورینگ را روشن کنید و صفحه را تازه‌سازی نمایید.

## ساختار

```
speedpulse-pro-ultra/
├── speedpulse-pro-ultra.php
├── uninstall.php
├── mu-bootstrap/speedpulse-early.php
├── src/
│   ├── Autoloader.php / Plugin.php
│   ├── Core/          پروفایلر، هوک، شبکه، دارایی
│   ├── Database/      کوئری، EXPLAIN، ایندکس
│   ├── WooCommerce/   جراح فروشگاه
│   ├── Cron/          کرون و حافظه
│   ├── Debug/         استریم debug.log
│   ├── Frontend/      بازرس DOM
│   ├── Stress/        تست فشار
│   ├── Crawler/       خزش با Pause/Resume
│   ├── AI/            کلاینت، پچ، Canary
│   └── Admin/         نوار، پنل، AJAX، گزارش
├── assets/css|js
└── templates/drawer.php
```

## نکات ایمنی

- پچ‌های AI قبل از اعمال از فیلتر الگوهای خطرناک عبور می‌کنند.
- سیستم Canary در ساب‌ریکوئست ایزوله تست می‌کند و در صورت Fatal یا افت شدید، Rollback می‌کند.
- ایجاد ایندکس و پاک‌سازی ووکامرس فقط با نقش `manage_options` مجاز است.
- تست فشار را روی سرور تولید با احتیاط و در ساعات کم‌ترافیک اجرا کنید.

## نیازمندی‌ها

- PHP 7.4+
- وردپرس 5.8+
- برای لاگ خطا: `WP_DEBUG_LOG`

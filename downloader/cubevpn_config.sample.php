<?php
/**
 * این فایل را با نام  cubevpn_config.php  کنار cubevpn.php ذخیره کنید.
 *
 * ساخت توکن (فقط یک بار):
 *   github.com → Settings → Developer settings
 *   → Personal access tokens → Fine-grained tokens → Generate new token
 *
 *   Repository access : Only select repositories →  cubepy/CubeVpn
 *   Permissions       : Repository permissions → Contents → Read-only
 *                       (همین یکی کافی است، چیز دیگری ندهید)
 *   Expiration        : هر چه می‌خواهید — یادتان باشد سررسیدش را تمدید کنید
 *
 * این توکن فقط اجازه‌ی «خواندن» همین یک مخزن را دارد و هیچ‌وقت به مرورگر
 * بازدیدکننده نمی‌رسد؛ فقط سرور شما از آن استفاده می‌کند.
 */

return [
    'token' => 'github_pat_XXXXXXXXXXXXXXXXXXXXXXXX',
];

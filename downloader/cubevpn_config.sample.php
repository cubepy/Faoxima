<?php
/**
 * این فایل را با نام  cubevpn_config.php  کنار cubevpn.php ذخیره کنید.
 *
 * ── update_url — روشِ درست ─────────────────────────────────────────────
 * آدرسِ همان update.json که پنل سرو می‌کند و خودِ اپلیکیشن از آن آپدیت
 * می‌گیرد. همان مقداری که در گیت‌هاب به‌عنوان سکرتِ UPDATE_URL ثبت کرده‌اید.
 *
 *   Settings → Secrets and variables → Actions → UPDATE_URL
 *
 * یا روی سرورِ بیلد، داخل  /var/lib/cubevpn-brands/publish.sh ،
 * مقدارِ BASE_URL را ببینید؛ آدرس می‌شود:
 *
 *   <BASE_URL>/CubeVPN/update.json
 *
 * وقتی این پر باشد، صفحه از فید می‌خواند و گیت‌هاب اصلاً صدا زده نمی‌شود —
 * که درست است، چون ورک‌فلوی بیلد عمداً فایلی به ریلیزِ گیت‌هاب پیوست
 * نمی‌کند و آنجا همیشه روی نسخه‌ی قدیمی می‌ماند.
 *
 * ── token — فقط حالتِ پشتیبان ───────────────────────────────────────────
 * اگر update_url خالی باشد، از ریلیزهای گیت‌هاب خوانده می‌شود و آن‌وقت این
 * توکن لازم است (مخزن خصوصی است). Fine-grained با دسترسیِ
 * Contents: Read-only روی فقط cubepy/CubeVpn.
 */

return [
    'update_url' => 'https://example.com/downloads/CubeVPN/update.json',
    'token'      => '',
];

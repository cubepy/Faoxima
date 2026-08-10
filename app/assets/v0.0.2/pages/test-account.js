import { call } from '../api.js';
import { escapeHtml, toast } from '../utils.js';
import { hapticImpact, hapticNotify } from '../telegram.js';
import { icon } from '../icons.js';

export async function testAccount(view) {
    view.innerHTML = `
        <article class="card card-window">
            <header class="card-window-bar">
                <span class="dots"><span></span><span></span><span></span></span>
                <span class="window-url">faoxima/test-account</span>
            </header>
            <div class="card-body">
                <p class="section-title">${icon('sparkles')} دریافت اکانت تست رایگان</p>
                <p class="muted" style="font-size:13px;margin:6px 0 14px">
                    برای امتحان کردن کیفیت سرویس، می‌توانید یک اکانت تست رایگان دریافت کنید.
                </p>

                <div id="test-username-row" class="form-row" style="display:none">
                    <label class="muted" style="font-size:12px">نام کاربری دلخواه</label>
                    <input id="test-username" type="text" maxlength="32" placeholder="مثلاً: myuser123" dir="ltr" />
                </div>

                <button id="test-account-submit" type="button" class="btn btn-primary btn-block mt-md">
                    ${icon('sparkles', 'class="ico ico-leading"')}
                    <span class="submit-label">دریافت اکانت تست</span>
                </button>
            </div>
        </article>

        <div id="test-account-result" class="mt-md"></div>

        <div class="row-spread mt-md gap-sm">
            <a href="#/" class="btn btn-ghost btn-block">
                ${icon('home', 'class="ico ico-leading"')}
                <span>بازگشت به خانه</span>
            </a>
        </div>
    `;

    const $btn = view.querySelector('#test-account-submit');
    const $label = $btn.querySelector('.submit-label');
    const $username = view.querySelector('#test-username');
    const $result = view.querySelector('#test-account-result');

    $btn.addEventListener('click', async () => {
        const old = $label.textContent;
        $btn.disabled = true;
        $label.textContent = 'در حال ساخت اکانت…';
        hapticImpact('light');
        try {
            const res = await call('test_account_create', {
                method: 'POST',
                body: { username: ($username.value || '').trim() },
            });
            const obj = res?.obj || {};
            hapticNotify('success');
            $result.innerHTML = `
                <article class="card card-window">
                    <div class="card-body" style="text-align:center;padding:22px">
                        <div style="font-size:42px;line-height:1;color:var(--green);margin:4px 0 10px">${icon('checkCircle', 'class="ico ico-xxl ico-success"')}</div>
                        <h2 style="margin:6px 0;font-size:18px">اکانت تست ساخته شد</h2>
                        <div class="kv mt-md"><span class="kv-label">نام کاربری</span><span class="kv-value mono gold">${escapeHtml(obj.username || '—')}</span></div>
                        <div class="row-spread mt-md stack-on-mobile" style="gap:10px">
                            <a href="#/services/${encodeURIComponent(obj.username || '')}" class="btn btn-primary btn-block" style="flex:1">مشاهده کانفیگ</a>
                        </div>
                    </div>
                </article>
            `;
            $btn.style.display = 'none';
        } catch (err) {
            hapticNotify('error');
            const msg = err && err.message ? err.message : 'خطا در ساخت اکانت تست';
            toast(msg, 'error', 4000);
            // [FEATURE] وقتی پنل تست نیاز به نام‌کاربری دلخواه داشته باشه، سرور با خطای 400 و پیام
            // مشخص جواب می‌ده؛ اون موقع فیلد نام کاربری رو نشون می‌دیم تا کاربر دوباره امتحان کنه.
            if (msg.includes('نام کاربری')) {
                view.querySelector('#test-username-row').style.display = '';
            }
        } finally {
            $btn.disabled = false;
            $label.textContent = old;
        }
    });
}

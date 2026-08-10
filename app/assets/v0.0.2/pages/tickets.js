import { call } from '../api.js';
import { escapeHtml, skeletonList, toast } from '../utils.js';
import { hapticImpact, hapticNotify } from '../telegram.js';
import { icon } from '../icons.js';

const DEPARTMENTS = [
    'پشتیبانی فنی',
    'مالی و پرداخت',
    'فروش',
    'سایر',
];

const STATUS_LABEL = {
    open: 'در حال بررسی',
    closed: 'بسته شده',
};

export async function tickets(view) {
    view.innerHTML = `
        <article class="card card-window">
            <div class="card-body">
                <p class="section-title">${icon('headset')} ارسال تیکت جدید</p>
                <p class="muted" style="font-size:13px;margin:4px 0 12px">پیام شما مستقیم برای ادمین ارسال می‌شود و از همین‌جا هم می‌توانید پیگیری کنید.</p>

                <div class="form-row">
                    <label class="muted" style="font-size:12px">دپارتمان</label>
                    <select id="ticket-department">
                        ${DEPARTMENTS.map((d) => `<option value="${escapeHtml(d)}">${escapeHtml(d)}</option>`).join('')}
                    </select>
                </div>
                <div class="form-row mt-sm">
                    <label class="muted" style="font-size:12px">موضوع</label>
                    <input id="ticket-subject" type="text" maxlength="200" placeholder="مثلاً: مشکل در اتصال" />
                </div>
                <div class="form-row mt-sm">
                    <label class="muted" style="font-size:12px">متن پیام</label>
                    <textarea id="ticket-message" rows="4" maxlength="4000" placeholder="پیام خود را بنویسید..."></textarea>
                </div>

                <button id="ticket-submit" type="button" class="btn btn-primary btn-block mt-md">
                    ${icon('send', 'class="ico ico-leading"')}
                    <span class="submit-label">ارسال تیکت</span>
                </button>
            </div>
        </article>

        <p class="section-title mt-md">${icon('fileText')} تیکت‌های قبلی من</p>
        <div id="ticket-list" class="mt-sm">${skeletonList(3)}</div>

        <div class="row-spread mt-md gap-sm stack-on-mobile">
            <a href="#/" class="btn btn-ghost btn-block">
                ${icon('home', 'class="ico ico-leading"')}
                <span>بازگشت به خانه</span>
            </a>
        </div>
    `;

    const $btn = view.querySelector('#ticket-submit');
    const $label = $btn.querySelector('.submit-label');
    const $subject = view.querySelector('#ticket-subject');
    const $message = view.querySelector('#ticket-message');
    const $department = view.querySelector('#ticket-department');
    const $list = view.querySelector('#ticket-list');

    async function loadList() {
        try {
            const res = await call('ticket_list', { method: 'GET' });
            const items = (res && res.obj && res.obj.tickets) || [];
            renderList(items);
        } catch (err) {
            $list.innerHTML = `<div class="empty"><p class="muted">خطا در دریافت تیکت‌ها</p></div>`;
        }
    }

    function renderList(items) {
        if (!items || items.length === 0) {
            $list.innerHTML = `<div class="empty"><p class="muted">هنوز تیکتی ثبت نکرده‌اید</p></div>`;
            return;
        }
        $list.innerHTML = items.map((t) => `
            <article class="card card-window mt-sm">
                <div class="card-body">
                    <div class="row-spread">
                        <strong>${escapeHtml(t.subject || '—')}</strong>
                        <span class="badge ${t.status === 'open' ? 'badge-active' : 'badge-gray'}">${STATUS_LABEL[t.status] || t.status}</span>
                    </div>
                    <p class="muted" style="font-size:12px;margin-top:4px">${escapeHtml(t.department || '')}</p>
                    ${t.admin_reply ? `<p style="margin-top:8px;font-size:13px"><strong>پاسخ پشتیبانی:</strong> ${escapeHtml(t.admin_reply)}</p>` : ''}
                </div>
            </article>
        `).join('');
    }

    $btn.addEventListener('click', async () => {
        const subject = ($subject.value || '').trim();
        const message = ($message.value || '').trim();
        if (!subject || !message) {
            toast('لطفاً موضوع و متن پیام را وارد کنید', 'error', 3000);
            return;
        }
        const old = $label.textContent;
        $btn.disabled = true;
        $label.textContent = 'در حال ارسال…';
        hapticImpact('light');
        try {
            await call('ticket_create', {
                method: 'POST',
                body: { department: $department.value, subject, message },
            });
            hapticNotify('success');
            toast('تیکت با موفقیت ارسال شد', 'success', 3000);
            $subject.value = '';
            $message.value = '';
            await loadList();
        } catch (err) {
            hapticNotify('error');
            toast(err.message || 'خطا در ارسال تیکت', 'error', 4000);
        } finally {
            $btn.disabled = false;
            $label.textContent = old;
        }
    });

    await loadList();
}

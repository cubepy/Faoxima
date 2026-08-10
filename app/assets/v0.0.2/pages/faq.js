import { icon } from '../icons.js';
import { openBot, getBotUsername, hapticImpact } from '../telegram.js';

const FAQ_ITEMS = [
    {
        q: 'چطور سرویس بخرم؟',
        a: 'از پایین صفحه روی «خرید» بزنید، لوکیشن، دسته‌بندی، مدت‌زمان و پلن دلخواه رو انتخاب کنید و پرداخت رو تکمیل کنید. سرویس بلافاصله بعد از تأیید پرداخت براتون ساخته می‌شه.',
    },
    {
        q: 'چطور کیف پول رو شارژ کنم؟',
        a: 'از منوی «حساب» گزینه‌ی «شارژ کیف پول» رو بزنید و یکی از روش‌های پرداخت موجود (کارت‌به‌کارت، ارز دیجیتال و…) رو انتخاب کنید.',
    },
    {
        q: 'کد هدیه دارم، کجا واردش کنم؟',
        a: 'در صفحه‌ی «شارژ کیف پول» بخش «کد هدیه» رو باز کنید و کد رو وارد کنید؛ مبلغ بلافاصله به کیف پولتون اضافه می‌شه.',
    },
    {
        q: 'اگه پرداخت انجام شد ولی سرویس/شارژ ثبت نشد چی کار کنم؟',
        a: 'چند دقیقه صبر کنید تا تراکنش تأیید بشه؛ وضعیت دقیق رو از بخش «سرویس‌های من» یا بنر «پرداخت در انتظار» در صفحه‌ی خانه می‌تونید پیگیری کنید. اگه بعد از چند دقیقه چیزی تغییر نکرد، با پشتیبانی در ارتباط باشید.',
    },
    {
        q: 'مصرف حجم سرویس هر چند وقت به‌روز می‌شه؟',
        a: 'مصرف به‌صورت لحظه‌ای از پنل شما خونده می‌شه؛ برای دیدن جزئیات وارد «سرویس‌های من» بشید و روی سرویس موردنظر بزنید.',
    },
    {
        q: 'می‌تونم سرویسم رو تمدید یا تغییر بدم؟',
        a: 'بله، از داخل جزئیات هر سرویس گزینه‌های تمدید و تغییرات موجود سرویس در دسترسه.',
    },
];

export async function faq(view) {
    view.innerHTML = `
        <article class="card card-window">
            <div class="card-body">
                <p class="section-title">${icon('headset')} پشتیبانی آنلاین</p>
                <p class="muted" style="margin:6px 0 14px">پاسخ سوالات رایج رو اینجا پیدا کنید یا مستقیم با پشتیبانی در ارتباط باشید.</p>
                <button type="button" class="btn btn-primary btn-block" id="faq-support-btn">
                    ${icon('headset', 'class="ico ico-leading"')}
                    <span>شروع گفتگو با پشتیبانی</span>
                </button>
                <a href="#/tickets" class="btn btn-ghost btn-block mt-sm">
                    ${icon('fileText', 'class="ico ico-leading"')}
                    <span>ارسال / پیگیری تیکت</span>
                </a>
            </div>
        </article>

        <p class="section-title mt-md">${icon('helpCircle')} سوالات متداول</p>
        <div class="card card-window mt-sm">
            <div class="card-body" style="padding:8px">
                ${FAQ_ITEMS.map((item, i) => `
                    <details class="faq-item"${i === 0 ? ' open' : ''}>
                        <summary class="faq-q">
                            <span>${icon('helpCircle', 'class="ico ico-leading"')}${escapeHtml(item.q)}</span>
                            ${icon('chevronDown', 'class="ico faq-chevron"')}
                        </summary>
                        <div class="faq-a muted">${escapeHtml(item.a)}</div>
                    </details>
                `).join('')}
            </div>
        </div>

        <div class="row-spread mt-md gap-sm stack-on-mobile">
            <a href="#/" class="btn btn-ghost btn-block">
                ${icon('home', 'class="ico ico-leading"')}
                <span>بازگشت به خانه</span>
            </a>
        </div>
    `;

    const btn = view.querySelector('#faq-support-btn');
    if (btn) {
        btn.addEventListener('click', () => {
            hapticImpact('light');
            const uname = getBotUsername();
            if (uname) {
                openBot(uname);
            } else {
                window.location.hash = '#/';
            }
        });
    }
}

function escapeHtml(value) {
    if (value === null || value === undefined) return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

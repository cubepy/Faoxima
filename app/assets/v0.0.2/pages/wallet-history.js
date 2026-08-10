import { call } from '../api.js';
import { escapeHtml, fmtPrice, skeletonList } from '../utils.js';
import { icon } from '../icons.js';

function fmtDate(ts) {
    if (!ts) return '—';
    try {
        return new Date(ts * 1000).toLocaleString('fa-IR', {
            year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
        });
    } catch (_) {
        return '—';
    }
}

export async function walletHistory(view) {
    view.innerHTML = `
        <article class="card card-window">
            <header class="card-window-bar">
                <span class="dots"><span></span><span></span><span></span></span>
                <span class="window-url">faoxima/wallet-history</span>
            </header>
            <div class="card-body">
                <p class="section-title">${icon('chart')} تاریخچه کیف پول</p>
                <div id="wallet-history-list" class="mt-sm">${skeletonList(5)}</div>
            </div>
        </article>
        <div class="row-spread mt-md gap-sm">
            <a href="#/account" class="btn btn-ghost btn-block">
                ${icon('arrowRight', 'class="ico ico-leading"')}
                <span>بازگشت به حساب</span>
            </a>
        </div>
    `;

    const $list = view.querySelector('#wallet-history-list');

    try {
        const res = await call('wallet_history', { params: { limit: 30, page: 1 } });
        const items = (res && res.obj && res.obj.items) || [];
        if (items.length === 0) {
            $list.innerHTML = `<div class="empty"><p class="muted">هنوز تراکنشی ثبت نشده است</p></div>`;
            return;
        }
        $list.innerHTML = items.map((it) => {
            const isCredit = it.type === 'credit';
            const sign = isCredit ? '+' : '−';
            const colorClass = isCredit ? 'ico-success' : 'ico-warn';
            return `
                <div class="row-spread" style="padding:10px 0;border-bottom:1px solid var(--border, rgba(255,255,255,0.08))">
                    <div>
                        <div>${escapeHtml(it.title || (isCredit ? 'شارژ کیف پول' : 'خرید سرویس'))}</div>
                        <div class="muted" style="font-size:12px">${escapeHtml(it.subtitle || '')} · ${fmtDate(it.ts)}</div>
                    </div>
                    <div class="${colorClass}" style="font-weight:700;white-space:nowrap">
                        ${sign} ${escapeHtml(fmtPrice(Math.abs(it.amount || 0)))}
                    </div>
                </div>
            `;
        }).join('');
    } catch (err) {
        $list.innerHTML = `
            <div class="empty">
                ${icon('alert', 'class="ico ico-xxl ico-warn"')}
                <p class="muted">${escapeHtml(err.message || 'خطا در دریافت تاریخچه')}</p>
            </div>
        `;
    }
}

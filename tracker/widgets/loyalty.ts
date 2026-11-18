/*
 * Mis recompensas (igr-w-loyalty.js, M13 y M14): puntos, nivel, saldo a favor,
 * recompensas para canjear y el enlace para invitar amigos. Solo con `igrUser.token`
 * (Mi cuenta, página que no se cachea).
 */
import { copyButton, h, loc, money, register, shadow, uid } from './lib/dom';
import type { Coupon, Ctx } from './lib/types';

interface Me {
    points?: { balance?: number; pending?: number; currency_name?: string; expiring?: { points?: number; at?: string } | null };
    tier?: { name?: string; next_name?: string | null; progress_pct?: number; benefits?: string[] } | null;
    wallet?: { balance?: string; currency?: string } | null;
    referral?: { code?: string; link?: string; share_text?: string; friend_reward?: string; referrer_reward?: string } | null;
    rewards?: { id: string; name: string; points: number; kind?: string }[];
}

const CSS =
    '.lc{display:grid;gap:16px}.cd{border:1px solid #e5e7eb;border-radius:12px;padding:16px}.big{font-size:32px;font-weight:700;line-height:1.1;margin:0}' +
    '.sm{font-size:13px;color:#4b5563}.pg{height:10px;border-radius:5px;background:#e5e7eb;overflow:hidden;margin:8px 0}.pg i{display:block;height:100%;background:var(--a)}' +
    '.rw{list-style:none;margin:0;padding:0}.rw li{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;border-top:1px solid #e5e7eb;padding:10px 0}' +
    '.cp{display:flex;gap:8px;align-items:center;border:2px dashed var(--a);border-radius:8px;padding:6px 10px;width:100%}.cp strong{letter-spacing:1px}' +
    '.lk{display:flex;gap:8px}.lk input{flex:1}.wa{display:inline-block;margin-top:8px;padding:9px 16px;border-radius:8px;background:#128c3a;color:#fff;text-decoration:none;font-weight:600}' +
    'ul.bn{margin:6px 0 0;padding-left:18px}';

function date(iso?: string): string {
    const d = iso ? new Date(iso + (iso.length === 10 ? 'T12:00:00' : '')) : null;
    return d && !isNaN(d.getTime()) ? d.toLocaleDateString(loc(), { day: 'numeric', month: 'long', year: 'numeric' }) : '';
}

const num = (n: number): string => n.toLocaleString(loc());

export function loyalty(host: HTMLElement | null, x: Ctx): void {
    if (!host) return;
    const root = shadow(host, CSS);
    const token = x.token();
    const say = (t: string): void => {
        root.replaceChildren(root.firstChild as Node, h('p', { role: 'status' }, t));
    };
    if (!token) return say('Inicia sesión para ver tus recompensas.');
    const syncs: (() => void)[] = [];
    void x.get<Me>('me', { token }).then((r) => {
        if (r.status === 401) return say('Tu sesión venció. Recarga la página para ver tus recompensas.');
        if (r.status !== 200) return say('No pudimos cargar tus recompensas. Inténtalo más tarde.');
        const me = r.data;
        const pts = me.points ?? {};
        const name = pts.currency_name || 'puntos';
        let balance = Number(pts.balance) || 0;
        const id = uid('l');
        const bal = h('p', { class: 'big' }, num(balance));
        const out = h('div', { class: 'lc' });
        out.append(
            h(
                'section',
                { class: 'cd', 'aria-labelledby': id },
                h('h2', { id }, 'Mis ' + name),
                bal,
                Number(pts.pending) > 0 ? h('p', { class: 'sm' }, num(Number(pts.pending)) + ' por confirmar (se suman cuando se entregue tu pedido).') : null,
                pts.expiring && Number(pts.expiring.points) > 0 ? h('p', { class: 'sm' }, num(Number(pts.expiring.points)) + ' vencen el ' + date(pts.expiring.at) + '.') : null,
            ),
        );
        const tier = me.tier;
        if (tier && tier.name) {
            const pct = Math.max(0, Math.min(100, Number(tier.progress_pct) || 0));
            out.append(
                h(
                    'section',
                    { class: 'cd' },
                    h('h3', null, 'Tu nivel: ' + tier.name),
                    tier.next_name ? h('div', { class: 'pg', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': pct, 'aria-label': 'Avance hacia ' + tier.next_name }, h('i', { style: 'width:' + pct + '%' })) : null,
                    tier.next_name ? h('p', { class: 'sm' }, pct + '% del camino a ' + tier.next_name + '.') : null,
                    tier.benefits && tier.benefits.length ? h('ul', { class: 'bn' }, ...tier.benefits.map((b) => h('li', null, b))) : null,
                ),
            );
        }
        if (me.wallet && Number(me.wallet.balance) > 0) {
            out.append(h('section', { class: 'cd' }, h('h3', null, 'Saldo a favor'), h('p', { class: 'big' }, money(me.wallet.balance, me.wallet.currency || x.currency)), h('p', { class: 'sm' }, 'Úsalo en tu próxima compra con el botón «Usar saldo» del checkout.')));
        }
        const rewards = (me.rewards ?? []).filter((rw) => rw && rw.id && rw.name);
        if (rewards.length) {
            const items = rewards.map((rw) => {
                const btn = h('button', { type: 'button' }, 'Canjear');
                const why = h('span', { class: 'sm' });
                const li = h('li', null, h('span', null, h('strong', null, rw.name), h('br'), h('span', { class: 'sm' }, num(rw.points) + ' ' + name)), btn, why);
                const sync = (): void => {
                    const short = rw.points - balance;
                    btn.disabled = short > 0;
                    why.textContent = short > 0 ? 'Te faltan ' + num(short) + '.' : '';
                };
                btn.addEventListener('click', () => {
                    btn.disabled = true;
                    void x.post<{ coupon?: Coupon; balance?: number; message?: string }>('me/redeem', { token, reward_id: rw.id }).then((res) => {
                        if (res.status === 200 && res.data.coupon?.code) {
                            balance = Number(res.data.balance ?? balance - rw.points);
                            bal.textContent = num(balance);
                            const code = res.data.coupon.code;
                            li.append(h('div', { class: 'cp', role: 'status' }, h('span', null, 'Tu cupón: '), h('strong', null, code), copyButton(code)));
                            for (const f of syncs) f();
                        } else {
                            why.textContent = res.data.message || (res.status === 401 ? 'Tu sesión venció. Recarga la página.' : 'No pudimos canjear esta recompensa.');
                            btn.disabled = false;
                        }
                    });
                });
                syncs.push(sync);
                sync();
                return li;
            });
            out.append(h('section', { class: 'cd' }, h('h3', null, 'Canjea tus ' + name), h('ul', { class: 'rw' }, ...items)));
        }
        const ref = me.referral;
        if (ref && ref.link && /^https?:\/\//.test(ref.link)) {
            const lid = uid('k');
            const share = h('a', { class: 'wa', href: 'https://wa.me/?text=' + encodeURIComponent(((ref.share_text || '') + ' ' + ref.link).trim()), target: '_blank', rel: 'noopener' }, 'Compartir por WhatsApp');
            share.addEventListener('click', () => void x.post('me/share', { token, channel: 'whatsapp' }));
            const gift = [ref.friend_reward ? 'Tu amigo recibe ' + ref.friend_reward : '', ref.referrer_reward ? 'tú ganas ' + ref.referrer_reward : ''].filter(Boolean).join(' y ');
            out.append(
                h(
                    'section',
                    { class: 'cd' },
                    h('h3', null, 'Invita a tus amigos'),
                    gift ? h('p', null, gift + '.') : null,
                    h('label', { for: lid, class: 'sm' }, 'Tu enlace para compartir'),
                    h('div', { class: 'lk' }, h('input', { id: lid, type: 'text', readonly: true, value: ref.link }), copyButton(ref.link, 'Copiar enlace')),
                    share,
                ),
            );
        }
        root.replaceChildren(root.firstChild as Node, out);
    });
}

register({ loyalty: (host, _w, x) => loyalty(host, x) });

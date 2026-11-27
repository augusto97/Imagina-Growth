import { Minus, Plus, Search } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useDebounced } from '@/hooks/useDebounced';
import { useWorkspace } from '@/hooks/useWorkspace';
import { formatMoney } from '@/lib/format';
import { useInboxProducts, useInboxTemplates } from './api';
import { TemplateAsTextNote } from '@/features/channels/CapabilityNotes';
import type { ConversationAccount, InboxProduct, SendBody } from './types';

interface DialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSend: (body: Omit<SendBody, 'client_id'>) => void;
    busy: boolean;
}

/** Plantilla aprobada de WhatsApp (sirve fuera de la ventana de 24 h), con las variables que llena el agente. */
export function TemplateDialog({ open, onOpenChange, onSend, busy, initialId, account }: DialogProps & { initialId?: string | null; account?: ConversationAccount | null }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const templates = useInboxTemplates(workspace.id, open);
    const [picked, setId] = useState('');
    // Elegida desde los atajos del editor (ventana cerrada): se abre con esa plantilla.
    const id = picked !== '' ? picked : (initialId ?? '');
    const [allVars, setAllVars] = useState<Record<string, Record<string, string>>>({});
    const list = templates.data?.data ?? [];
    const selected = list.find((x) => x.id === id) ?? list[0];
    const vars = selected ? (allVars[selected.id] ?? {}) : {};
    const setVar = (name: string, value: string) =>
        selected &&
        setAllVars((a) => ({
            ...a,
            [selected.id]: { ...(a[selected.id] ?? {}), [name]: value },
        }));

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('inbox.template.title')}</DialogTitle>
                    <DialogDescription>{t('inbox.template.description')}</DialogDescription>
                </DialogHeader>
                {/* Un número sin plantillas de Meta (no oficial): la plantilla sale como texto, sin botones. */}
                {account ? <TemplateAsTextNote caps={account} name={account.name} /> : null}
                {list.length === 0 ? (
                    <p className="igr:text-sm igr:text-muted-foreground">{templates.isPending ? t('inbox.template.loading') : t('inbox.template.empty')}</p>
                ) : (
                    <div className="igr:space-y-3">
                        <div className="igr:space-y-1">
                            <Label htmlFor="inbox-template">{t('inbox.template.pick')}</Label>
                            <Select id="inbox-template" value={selected?.id ?? ''} onChange={(e) => setId(e.target.value)}>
                                {list.map((tpl) => (
                                    <option key={tpl.id} value={tpl.id}>
                                        {tpl.name} · {t(`inbox.template.category.${tpl.category}`, { defaultValue: tpl.category })}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        {(selected?.variables ?? []).map((v) => (
                            <div key={v} className="igr:space-y-1">
                                <Label htmlFor={`inbox-var-${v}`}>{t('inbox.template.variable', { name: v })}</Label>
                                <Input id={`inbox-var-${v}`} value={vars[v] ?? ''} onChange={(e) => setVar(v, e.target.value)} />
                            </div>
                        ))}
                    </div>
                )}
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>
                        {t('inbox.cancel')}
                    </Button>
                    <Button
                        disabled={!selected}
                        loading={busy}
                        onClick={() =>
                            selected &&
                            onSend({
                                type: 'template',
                                template_id: selected.id,
                                variables: vars,
                            })
                        }
                    >
                        {t('inbox.composer.send')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** Buscar productos y enviar la ficha (precio y stock en vivo), un carrito listo o un enlace de pago. */
export function ProductDialog({ open, onOpenChange, onSend, busy }: DialogProps) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const [search, setSearch] = useState('');
    const q = useDebounced(search, 300);
    const products = useInboxProducts(workspace.id, q, open);
    const [cart, setCart] = useState<Record<string, { p: InboxProduct; qty: number }>>({});
    const items = Object.values(cart);
    const add = (p: InboxProduct, delta: number) =>
        setCart((c) => {
            const qty = (c[p.id]?.qty ?? 0) + delta;
            const rest = Object.fromEntries(Object.entries(c).filter(([key]) => key !== p.id));
            return qty <= 0 ? rest : { ...rest, [p.id]: { p, qty: Math.min(99, qty) } };
        });
    const payload = items.map((i) => ({ product_id: i.p.id, qty: i.qty }));

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="igr:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('inbox.product.title')}</DialogTitle>
                    <DialogDescription>{t('inbox.product.description')}</DialogDescription>
                </DialogHeader>
                <div className="igr:relative">
                    <Search className="igr:pointer-events-none igr:absolute igr:left-2 igr:top-2 igr:size-4 igr:text-muted-foreground" aria-hidden />
                    <Input
                        className="igr:pl-8"
                        placeholder={t('inbox.product.search')}
                        aria-label={t('inbox.product.search')}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>
                <ul className="igr:max-h-64 igr:space-y-1 igr:overflow-y-auto" aria-label={t('inbox.product.results')}>
                    {(products.data?.data ?? []).map((p) => (
                        <li key={p.id} className="igr:flex igr:items-center igr:gap-2 igr:rounded-md igr:border igr:p-2">
                            {p.image ? (
                                <img src={p.image} alt="" className="igr:size-10 igr:rounded igr:object-cover" loading="lazy" />
                            ) : (
                                <div className="igr:size-10 igr:rounded igr:bg-muted" aria-hidden />
                            )}
                            <div className="igr:min-w-0 igr:flex-1">
                                <p className="igr:truncate igr:text-[13px] igr:font-medium">{p.name}</p>
                                <p className="igr:text-xs igr:text-muted-foreground">
                                    {p.price !== null ? formatMoney(p.price, p.currency ?? workspace.currency, workspace.locale) : '—'}
                                    {!p.in_stock ? (
                                        <Badge variant="warning" className="igr:ml-1.5">
                                            {t('inbox.product.outOfStock')}
                                        </Badge>
                                    ) : null}
                                </p>
                            </div>
                            <Button
                                size="sm"
                                variant="outline"
                                loading={busy}
                                onClick={() =>
                                    onSend({
                                        type: 'product',
                                        product_id: p.id,
                                    })
                                }
                            >
                                {t('inbox.product.sendCard')}
                            </Button>
                            <div className="igr:flex igr:items-center igr:gap-1">
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    aria-label={t('inbox.product.less', {
                                        name: p.name,
                                    })}
                                    onClick={() => add(p, -1)}
                                    disabled={!cart[p.id]}
                                >
                                    <Minus aria-hidden />
                                </Button>
                                <span className="igr:w-4 igr:text-center igr:text-xs igr:tabular-nums">{cart[p.id]?.qty ?? 0}</span>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    aria-label={t('inbox.product.more', {
                                        name: p.name,
                                    })}
                                    onClick={() => add(p, 1)}
                                >
                                    <Plus aria-hidden />
                                </Button>
                            </div>
                        </li>
                    ))}
                    {products.data && products.data.data.length === 0 ? (
                        <li className="igr:p-3 igr:text-center igr:text-xs igr:text-muted-foreground">{t('inbox.product.none')}</li>
                    ) : null}
                </ul>
                <p className="igr:text-xs igr:text-muted-foreground">
                    {items.length === 0
                        ? t('inbox.product.cartEmpty')
                        : t('inbox.product.cartCount', {
                              count: items.reduce((n, i) => n + i.qty, 0),
                          })}
                </p>
                <DialogFooter>
                    <Button variant="outline" disabled={items.length === 0} loading={busy} onClick={() => onSend({ type: 'payment_link', items: payload })}>
                        {t('inbox.product.sendPayment')}
                    </Button>
                    <Button disabled={items.length === 0} loading={busy} onClick={() => onSend({ type: 'cart_link', items: payload })}>
                        {t('inbox.product.sendCart')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** Cupón único para este contacto (máximo 50%). */
export function CouponDialog({ open, onOpenChange, onSend, busy }: DialogProps) {
    const { t } = useTranslation();
    const [kind, setKind] = useState<'percent' | 'fixed_cart' | 'free_shipping'>('percent');
    const [amount, setAmount] = useState('10');
    const [days, setDays] = useState('7');
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('inbox.coupon.title')}</DialogTitle>
                    <DialogDescription>{t('inbox.coupon.description')}</DialogDescription>
                </DialogHeader>
                <div className="igr:grid igr:grid-cols-3 igr:gap-2">
                    <div className="igr:space-y-1">
                        <Label htmlFor="inbox-coupon-kind">{t('inbox.coupon.kind')}</Label>
                        <Select id="inbox-coupon-kind" value={kind} onChange={(e) => setKind(e.target.value as typeof kind)}>
                            {(['percent', 'fixed_cart', 'free_shipping'] as const).map((k) => (
                                <option key={k} value={k}>
                                    {t(`inbox.coupon.kinds.${k}`)}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <div className="igr:space-y-1">
                        <Label htmlFor="inbox-coupon-amount">{t('inbox.coupon.amount')}</Label>
                        <Input id="inbox-coupon-amount" inputMode="decimal" disabled={kind === 'free_shipping'} value={amount} onChange={(e) => setAmount(e.target.value)} />
                    </div>
                    <div className="igr:space-y-1">
                        <Label htmlFor="inbox-coupon-days">{t('inbox.coupon.days')}</Label>
                        <Input id="inbox-coupon-days" inputMode="numeric" value={days} onChange={(e) => setDays(e.target.value)} />
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>
                        {t('inbox.cancel')}
                    </Button>
                    <Button
                        loading={busy}
                        onClick={() =>
                            onSend({
                                type: 'coupon',
                                kind,
                                amount: Number(amount.replace(',', '.')) || 0,
                                days: Number(days) || 7,
                            })
                        }
                    >
                        {t('inbox.coupon.send')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

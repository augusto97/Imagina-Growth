import { ShieldAlert } from 'lucide-react';
import { type SyntheticEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { useWorkspace } from '@/hooks/useWorkspace';
import { toast } from '@/stores/toast';
import { useConnectZender } from '../api';
import { CopyButton, fieldError } from '../shared';

/** Conexión no oficial (cumplimiento.md §5.2): solo el owner, aceptando el riesgo. */
export function ZenderDialog({ open, onClose, caps }: { open: boolean; onClose: () => void; caps: { hourly: number; daily: number } }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const connect = useConnectZender(workspace.id);
    const [form, setForm] = useState({ base_url: '', api_secret: '', account_id: '', phone: '', accept_risk: false });
    const [result, setResult] = useState<{ url: string | null; secret: string } | null>(null);

    const submit = (e: SyntheticEvent<HTMLFormElement>) => {
        e.preventDefault();
        connect.mutate(form, {
            onSuccess: (r) => {
                toast.success(t('channels.toast.connected'));
                setResult({ url: r.data.webhook_url, secret: r.webhook_secret });
            },
        });
    };
    const close = () => {
        setResult(null);
        connect.reset();
        onClose();
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && close()}>
            <DialogContent className="igr:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('channels.zender.title')}</DialogTitle>
                </DialogHeader>
                <DialogDescription className="igr:flex igr:gap-2 igr:rounded-md igr:bg-warning/10 igr:p-3 igr:text-warning">
                    <ShieldAlert className="igr:mt-0.5 igr:size-4 igr:shrink-0" aria-hidden="true" />
                    <span>
                        {t('channels.zender.risk')} {t('channels.zender.rules', caps)}
                    </span>
                </DialogDescription>
                {result ? (
                    <div className="igr:space-y-2">
                        <h3 className="igr:text-[13px] igr:font-semibold">{t('channels.zender.webhookTitle')}</h3>
                        <p className="igr:text-xs igr:text-muted-foreground">{t('channels.zender.webhookHelp')}</p>
                        {[result.url ?? '', result.secret].map((v) => (
                            <div key={v} className="igr:flex igr:items-center igr:gap-1 igr:rounded-md igr:border igr:p-2">
                                <code className="igr:flex-1 igr:break-all igr:text-xs">{v}</code>
                                <CopyButton value={v} />
                            </div>
                        ))}
                    </div>
                ) : (
                    <form id="igr-zender-form" onSubmit={submit} className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                        <div className="igr:space-y-1 igr:sm:col-span-2">
                            <Label htmlFor="z-url">{t('channels.zender.baseUrl')}</Label>
                            <Input id="z-url" type="url" required placeholder={t('channels.zender.baseUrlPlaceholder')} value={form.base_url} onChange={(e) => setForm({ ...form, base_url: e.target.value })} />
                            <FieldError message={fieldError(connect.error, 'base_url')} />
                        </div>
                        <div className="igr:space-y-1">
                            <Label htmlFor="z-secret">{t('channels.zender.secret')}</Label>
                            <Input id="z-secret" type="password" autoComplete="off" required value={form.api_secret} onChange={(e) => setForm({ ...form, api_secret: e.target.value })} />
                        </div>
                        <div className="igr:space-y-1">
                            <Label htmlFor="z-acc">{t('channels.zender.accountId')}</Label>
                            <Input id="z-acc" required value={form.account_id} onChange={(e) => setForm({ ...form, account_id: e.target.value })} />
                        </div>
                        <div className="igr:space-y-1 igr:sm:col-span-2">
                            <Label htmlFor="z-phone">{t('channels.zender.phone')}</Label>
                            <Input id="z-phone" type="tel" required value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
                            <FieldError message={fieldError(connect.error, 'phone')} />
                        </div>
                        <label className="igr:flex igr:items-start igr:gap-2 igr:text-[13px] igr:sm:col-span-2">
                            <input type="checkbox" required checked={form.accept_risk} onChange={(e) => setForm({ ...form, accept_risk: e.target.checked })} className="igr:mt-0.5" />
                            {t('channels.zender.accept')}
                        </label>
                        <FieldError message={fieldError(connect.error, 'accept_risk')} />
                    </form>
                )}
                <DialogFooter>
                    {result ? (
                        <Button onClick={close}>{t('common.close')}</Button>
                    ) : (
                        <>
                            <Button variant="outline" onClick={close}>
                                {t('common.cancel')}
                            </Button>
                            <Button type="submit" form="igr-zender-form" disabled={!form.accept_risk} loading={connect.isPending}>
                                {t('channels.wa.connect')}
                            </Button>
                        </>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

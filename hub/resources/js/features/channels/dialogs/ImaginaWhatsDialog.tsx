import { ExternalLink, ShieldAlert } from 'lucide-react';
import { type SyntheticEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { ChoiceCard } from '@/components/ui/choice-card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { useWorkspace } from '@/hooks/useWorkspace';
import { toast } from '@/stores/toast';
import { type ChannelsResponse, type ImaginaWhatsCheck, useCheckImaginaWhats, useConnectImaginaWhats } from '../api';
import { fieldError } from '../shared';

type IwNumber = ImaginaWhatsCheck['numbers'][number];

/** El estado del número en Imagina Whats en palabras (los desconocidos, tal cual). */
export function numberStatusKey(status: string): string {
    switch (status) {
        case 'connected':
        case 'disconnected':
        case 'logged_out':
        case 'banned':
            return status;
        case 'registering':
        case 'pending_pair':
            return 'pairing';
        default:
            return 'other';
    }
}

/** Un número se puede usar si Imagina Whats lo tiene conectado y habilitado. */
export const usableNumber = (n: IwNumber): boolean => n.status === 'connected' && n.enabled;

/**
 * Imagina Whats (WhatsApp Web por API, cumplimiento.md §5.2): el dueño pega su clave de API, elige el número
 * vinculado allá y acepta el riesgo. El hub registra solo el webhook firmado de entrantes, estados y bajas.
 */
export function ImaginaWhatsDialog({ open, onClose, meta }: { open: boolean; onClose: () => void; meta: ChannelsResponse['meta'] | undefined }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const check = useCheckImaginaWhats(workspace.id);
    const connect = useConnectImaginaWhats(workspace.id);
    const info = meta?.imagina_whats;
    const caps = info?.caps ?? meta?.zender_caps ?? { hourly: 30, daily: 200 };
    const [apiKey, setApiKey] = useState('');
    const [found, setFound] = useState<ImaginaWhatsCheck | null>(null);
    const [number, setNumber] = useState<number | null>(null);
    const [name, setName] = useState('');
    const [accept, setAccept] = useState(false);
    const [problem, setProblem] = useState<string | null | undefined>(undefined);

    const close = () => {
        setApiKey('');
        setFound(null);
        setNumber(null);
        setName('');
        setAccept(false);
        setProblem(undefined);
        check.reset();
        connect.reset();
        onClose();
    };

    const verify = (e: SyntheticEvent<HTMLFormElement>) => {
        e.preventDefault();
        check.mutate(
            { api_key: apiKey.trim() },
            {
                onSuccess: (r) => {
                    setFound(r.data);
                    const first = r.data.numbers.find(usableNumber) ?? null;
                    setNumber(first?.id ?? null);
                    setName(first?.name ?? '');
                },
            },
        );
    };

    const submit = (e: SyntheticEvent<HTMLFormElement>) => {
        e.preventDefault();
        if (number === null) return;
        connect.mutate(
            { api_key: apiKey.trim(), wa_account_id: number, display_name: name.trim() || undefined, accept_risk: accept },
            {
                onSuccess: (r) => {
                    toast.success(t('channels.toast.connected'));
                    setProblem(r.webhook_problem);
                },
            },
        );
    };

    const ready = found !== null && found.missing.length === 0;
    const done = problem !== undefined;

    return (
        <Dialog open={open} onOpenChange={(next) => !next && close()}>
            <DialogContent className="igr:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('channels.imaginaWhats.title')}</DialogTitle>
                </DialogHeader>
                <DialogDescription className="igr:flex igr:gap-2 igr:rounded-md igr:bg-warning/10 igr:p-3 igr:text-warning">
                    <ShieldAlert className="igr:mt-0.5 igr:size-4 igr:shrink-0" aria-hidden="true" />
                    <span>
                        {t('channels.imaginaWhats.risk')} {t('channels.zender.rules', caps)}
                    </span>
                </DialogDescription>

                {done ? (
                    problem ? (
                        <Callout tone="warning" title={t('channels.imaginaWhats.webhookProblemTitle')}>
                            {problem}
                        </Callout>
                    ) : (
                        <Callout tone="success" title={t('channels.imaginaWhats.doneTitle')}>
                            {t('channels.imaginaWhats.done')}
                        </Callout>
                    )
                ) : !ready ? (
                    <form id="igr-iw-check" onSubmit={verify} className="igr:space-y-3">
                        <ol className="igr:list-decimal igr:space-y-1 igr:pl-5 igr:text-support igr:text-muted-foreground">
                            <li>{t('channels.imaginaWhats.step1')}</li>
                            <li>
                                {t('channels.imaginaWhats.step2')}{' '}
                                <span className="igr:inline-flex igr:flex-wrap igr:gap-1 igr:align-middle">
                                    {(info?.abilities ?? []).map((a) => (
                                        <code key={a} className="igr:rounded igr:bg-muted igr:px-1 igr:py-0.5 igr:text-caption igr:text-foreground">
                                            {a}
                                        </code>
                                    ))}
                                </span>
                            </li>
                            <li>{t('channels.imaginaWhats.step3')}</li>
                        </ol>
                        {info?.url ? (
                            <a href={`${info.url}/`} target="_blank" rel="noreferrer" className="igr:inline-flex igr:items-center igr:gap-1 igr:text-support igr:font-medium igr:text-primary igr:underline-offset-4 igr:hover:underline">
                                {t('channels.imaginaWhats.open')}
                                <ExternalLink className="igr:size-3.5" aria-hidden />
                            </a>
                        ) : null}
                        <div className="igr:space-y-1">
                            <Label htmlFor="iw-key">{t('channels.imaginaWhats.apiKey')}</Label>
                            <Input id="iw-key" type="password" autoComplete="off" required minLength={10} value={apiKey} onChange={(e) => setApiKey(e.target.value)} />
                            <FieldError message={fieldError(check.error, 'api_key')} />
                            <p className="igr:text-xs igr:text-muted-foreground">{t('channels.imaginaWhats.apiKeyHelp')}</p>
                        </div>
                        {found && found.missing.length > 0 ? (
                            <Callout tone="danger" title={t('channels.imaginaWhats.missingTitle')}>
                                {t('channels.imaginaWhats.missing', { list: found.missing.join(', ') })}
                            </Callout>
                        ) : null}
                    </form>
                ) : (
                    <form id="igr-iw-connect" onSubmit={submit} className="igr:space-y-3">
                        <p className="igr:text-support igr:text-muted-foreground">
                            {found.workspace ? t('channels.imaginaWhats.account', { name: found.workspace }) : null} {t('channels.imaginaWhats.pick')}
                        </p>
                        {found.numbers.length === 0 ? (
                            <Callout tone="warning" title={t('channels.imaginaWhats.noNumbersTitle')}>
                                {t('channels.imaginaWhats.noNumbers')}
                            </Callout>
                        ) : (
                            <fieldset className="igr:space-y-2">
                                <legend className="igr:sr-only">{t('channels.imaginaWhats.numberLegend')}</legend>
                                {found.numbers.map((n) => (
                                    <ChoiceCard
                                        key={n.id}
                                        name="iw-number"
                                        value={String(n.id)}
                                        size="sm"
                                        checked={number === n.id}
                                        disabled={!usableNumber(n)}
                                        onChange={(v) => {
                                            setNumber(Number(v));
                                            setName(n.name ?? '');
                                        }}
                                        title={n.name ?? n.phone ?? t('channels.imaginaWhats.noName')}
                                        description={[n.name ? n.phone : null, t(`channels.imaginaWhats.status.${numberStatusKey(n.status)}`, { status: n.status })].filter(Boolean).join(' · ')}
                                    />
                                ))}
                            </fieldset>
                        )}
                        <FieldError message={fieldError(connect.error, 'wa_account_id') ?? fieldError(connect.error, 'api_key')} />
                        <div className="igr:space-y-1">
                            <Label htmlFor="iw-name">{t('channels.wa.displayName')}</Label>
                            <Input id="iw-name" value={name} maxLength={120} onChange={(e) => setName(e.target.value)} />
                        </div>
                        <label className="igr:flex igr:items-start igr:gap-2 igr:text-[13px]">
                            <input type="checkbox" required checked={accept} onChange={(e) => setAccept(e.target.checked)} className="igr:mt-0.5" />
                            {t('channels.zender.accept')}
                        </label>
                        <FieldError message={fieldError(connect.error, 'accept_risk')} />
                    </form>
                )}

                <DialogFooter>
                    {done ? (
                        <Button onClick={close}>{t('common.close')}</Button>
                    ) : (
                        <>
                            <Button variant="outline" onClick={close}>
                                {t('common.cancel')}
                            </Button>
                            {ready ? (
                                <Button type="submit" form="igr-iw-connect" disabled={!accept || number === null} loading={connect.isPending}>
                                    {t('channels.wa.connect')}
                                </Button>
                            ) : (
                                <Button type="submit" form="igr-iw-check" disabled={apiKey.trim().length < 10} loading={check.isPending}>
                                    {t('channels.imaginaWhats.verify')}
                                </Button>
                            )}
                        </>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

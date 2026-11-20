import { CircleAlert, LogIn } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import type { ChannelAccount } from '@/features/channels/api';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { connectionReason, useWhatsAppSignupApi, type WhatsAppPhone } from './api';
import { runEmbeddedSignup } from './embeddedSignup';

type View =
    | { kind: 'idle' }
    | { kind: 'cancelled' }
    | { kind: 'error'; message: string; sessionId: string | null }
    | { kind: 'choose'; connectionId: string; phones: WhatsAppPhone[] };

/**
 * «Conectar WhatsApp con un clic» (Embedded Signup de Meta). Sirve también para un número que ya se usa en la app
 * de WhatsApp Business (coexistencia): la persona lo elige dentro de la ventana de Meta y la app sigue funcionando.
 */
export function WhatsAppOneClick({ configured, onConnected }: { configured: boolean; onConnected: (account: ChannelAccount) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const calls = useWhatsAppSignupApi(workspace.id);
    const [view, setView] = useState<View>({ kind: 'idle' });
    const [busy, setBusy] = useState(false);
    const [phone, setPhone] = useState('');

    const fail = (error: unknown) => {
        const reason = connectionReason(error);
        const message =
            reason === 'state'
                ? t('connections.whatsapp.expired')
                : isApiError(error) && error.message !== ''
                  ? error.message
                  : error instanceof Error && error.message === 'fb-sdk'
                    ? t('connections.whatsapp.sdkBlocked')
                    : t('connections.whatsapp.genericError');
        setView({ kind: 'error', message, sessionId: null });
    };

    const start = async () => {
        setBusy(true);
        setView({ kind: 'idle' });
        try {
            const outcome = await runEmbeddedSignup({
                start: () => calls.start.mutateAsync(),
                complete: (b) => calls.complete.mutateAsync(b),
                abandon: (b) => calls.abandon.mutateAsync(b),
            });
            if (outcome.kind === 'connected') onConnected(outcome.account);
            else if (outcome.kind === 'choose') {
                setPhone(outcome.phones[0]?.id ?? '');
                setView(outcome);
            } else if (outcome.kind === 'cancelled') setView(outcome);
            else setView({ kind: 'error', message: outcome.message ?? t('connections.whatsapp.genericError'), sessionId: outcome.sessionId });
        } catch (error) {
            fail(error);
        } finally {
            setBusy(false);
        }
    };

    const choose = () => {
        if (view.kind !== 'choose' || phone === '') return;
        calls.chooseNumber.mutate({ id: view.connectionId, phone_number_id: phone }, { onSuccess: (r) => onConnected(r.data), onError: fail });
    };

    if (!configured) {
        return <p className="igr:text-xs igr:text-muted-foreground">{t('connections.whatsapp.notConfigured')}</p>;
    }

    return (
        <div className="igr:space-y-2">
            <Button onClick={() => void start()} loading={busy}>
                <LogIn />
                {t('connections.whatsapp.button')}
            </Button>
            <p className="igr:text-xs igr:text-muted-foreground">{t('connections.whatsapp.coexistenceHelp')}</p>
            {view.kind === 'cancelled' ? <p className="igr:text-xs igr:text-muted-foreground">{t('connections.whatsapp.cancelled')}</p> : null}
            {view.kind === 'error' ? (
                <p role="alert" className="igr:flex igr:items-start igr:gap-1.5 igr:rounded-md igr:bg-destructive/10 igr:p-2 igr:text-xs igr:text-destructive">
                    <CircleAlert className="igr:mt-0.5 igr:size-3.5 igr:shrink-0" aria-hidden="true" />
                    <span>
                        {view.message}
                        {view.sessionId ? ` ${t('connections.whatsapp.supportCode', { code: view.sessionId })}` : ''}
                    </span>
                </p>
            ) : null}
            {view.kind === 'choose' ? (
                <fieldset className="igr:space-y-2 igr:rounded-md igr:border igr:p-2.5">
                    <legend className="igr:px-1 igr:text-xs igr:font-medium">{t('connections.whatsapp.choosePhone')}</legend>
                    {view.phones.map((p) => (
                        <label key={p.id} className="igr:flex igr:items-center igr:gap-2 igr:text-[13px]">
                            <input type="radio" name="igr-wa-phone" value={p.id} checked={phone === p.id} onChange={() => setPhone(p.id)} />
                            <span>
                                {p.display_phone_number ?? p.id} {p.verified_name ? `· ${p.verified_name}` : ''}
                                {p.is_on_biz_app ? ` · ${t('connections.whatsapp.inBusinessApp')}` : ''}
                            </span>
                        </label>
                    ))}
                    <Button size="sm" onClick={choose} loading={calls.chooseNumber.isPending} disabled={phone === ''}>
                        {t('connections.whatsapp.useNumber')}
                    </Button>
                </fieldset>
            ) : null}
        </div>
    );
}

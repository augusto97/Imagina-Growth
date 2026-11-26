import { CircleAlert, CircleCheck, Clock, Send, type LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { useInstallFromGallery } from '@/features/gallery/api';
import type { WaSubmission } from '@/features/gallery/types';
import { useCan, useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { cn } from '@/lib/cn';
import { toast } from '@/stores/toast';
import type { TemplateSummary } from './api';

/*
 * En qué va la aprobación de Meta de una plantilla de WhatsApp, dicho en palabras, y qué falta para poder enviarla:
 * conectar el número oficial, enviarla a revisión (las de la galería quedan en borrador), esperar la revisión, corregir
 * un rechazo o elegir la aprobada. Lo usan la lista de plantillas y el editor.
 */

export type WaState = 'approved' | 'pending' | 'rejected' | 'paused' | 'disabled' | 'connect' | 'submit' | 'choose';

export function waStateOf(tpl: Pick<TemplateSummary, 'channel' | 'wa_status' | 'library_key'>, connected: boolean): WaState | null {
    if (tpl.channel !== 'whatsapp') return null;
    switch (tpl.wa_status) {
        case 'APPROVED':
            return 'approved';
        case 'PENDING':
        case 'IN_APPEAL':
            return 'pending';
        case 'REJECTED':
            return 'rejected';
        case 'PAUSED':
            return 'paused';
        case 'DISABLED':
        case 'DELETED':
        case 'PENDING_DELETION':
        case 'LIMIT_EXCEEDED':
            return 'disabled';
        default:
            break;
    }
    if (tpl.library_key === null || !tpl.library_key.startsWith('wa_')) return 'choose';
    return connected ? 'submit' : 'connect';
}

const STATE_STYLE: Record<WaState, { icon: LucideIcon; tone: string }> = {
    approved: { icon: CircleCheck, tone: 'igr:text-success' },
    pending: { icon: Clock, tone: 'igr:text-warning' },
    rejected: { icon: CircleAlert, tone: 'igr:text-destructive' },
    paused: { icon: CircleAlert, tone: 'igr:text-warning' },
    disabled: { icon: CircleAlert, tone: 'igr:text-destructive' },
    connect: { icon: CircleAlert, tone: 'igr:text-warning' },
    submit: { icon: Send, tone: 'igr:text-info' },
    choose: { icon: CircleAlert, tone: 'igr:text-warning' },
};

/** Envía a revisión de Meta una de WhatsApp de la galería (el mismo «instalar», que es idempotente). */
export function useSubmitToMeta() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const install = useInstallFromGallery(workspace.id);
    const submit = (key: string) =>
        install.mutate(
            { key, submit: true },
            {
                onSuccess: (res) => notifySubmission(t, res.whatsapp_submission),
                onError: (e) => toast.error(t('templates.meta.submitFailed'), isApiError(e) ? e.message : undefined),
            },
        );
    return { submit, pending: install.isPending, key: install.variables?.key ?? null };
}

export function notifySubmission(t: ReturnType<typeof useTranslation>['t'], submission: WaSubmission | null): void {
    if (submission === null) return;
    if (submission.status === 'failed') {
        toast.error(t('templates.meta.toast.failed'), submission.message);
        return;
    }
    const kind = submission.status === 'linked' && submission.wa_status === 'APPROVED' ? 'linkedApproved' : submission.status;
    toast.success(t(`templates.meta.toast.${kind}`));
}

/** El estado en una línea, para la lista. */
export function WaStatusText({ template, connected }: { template: TemplateSummary; connected: boolean }) {
    const { t } = useTranslation();
    const state = waStateOf(template, connected);
    if (state === null) return null;
    const { icon: Icon, tone } = STATE_STYLE[state];
    return (
        <span className={cn('igr:inline-flex igr:items-start igr:gap-1.5 igr:text-support', tone)}>
            <Icon className="igr:mt-0.5 igr:size-3.5 igr:shrink-0" aria-hidden />
            <span>{t(`templates.meta.state.${state}`)}</span>
        </span>
    );
}

/** El botón para resolver lo que falta, si hay algo que hacer desde aquí. */
export function WaStatusAction({ template, connected, size = 'sm' }: { template: TemplateSummary; connected: boolean; size?: 'sm' | 'md' }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const canEdit = useCan('manageMarketing');
    const { submit, pending, key } = useSubmitToMeta();
    const state = waStateOf(template, connected);
    if (!canEdit || state === null) return null;
    if (state === 'connect') {
        return (
            <Button asChild size={size} variant="outline">
                <Link to={`/w/${workspace.id}/conexiones`}>{t('templates.meta.action.connect')}</Link>
            </Button>
        );
    }
    if (state === 'submit' && template.library_key) {
        const libraryKey = template.library_key;
        return (
            <Button size={size} loading={pending && key === libraryKey} onClick={() => submit(libraryKey)}>
                <Send />
                {t('templates.meta.action.submit')}
            </Button>
        );
    }
    return null;
}

/** En el editor: el estado con su explicación y la acción. */
export function WaStatusCallout({ template }: { template: TemplateSummary }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const connected = workspace.connections?.channels.includes('whatsapp') ?? false;
    const state = waStateOf(template, connected);
    if (state === null) return null;
    const tone = state === 'approved' ? 'success' : state === 'rejected' || state === 'disabled' ? 'danger' : state === 'submit' ? 'info' : 'warning';
    return (
        <Callout tone={tone} title={t(`templates.meta.state.${state}`)} action={<WaStatusAction template={template} connected={connected} />}>
            {t(`templates.meta.help.${state}`, { reason: template.wa_rejected_reason ?? t('templates.meta.noReason') })}
        </Callout>
    );
}

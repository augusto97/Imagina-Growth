import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { Callout } from '@/components/ui/callout';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { usePreviewTemplate, useTemplate, useWaSources } from '../api';
import { fillWaText, waBodyOf } from '../content';
import { EmailMock, PhoneMock, SmsBubble, WaBubble } from './MessageMock';

/**
 * Una plantilla guardada como le llega a la persona, con los datos de ejemplo de tu país: el chat de WhatsApp o de SMS
 * en el celular, o el correo en la bandeja. La usa el asistente de campañas («¿Qué les dices?» y «Revisar»).
 */
export function TemplatePreview({ templateId, channel }: { templateId: string; channel: 'email' | 'whatsapp' | 'sms' }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const template = useTemplate(workspace.id, templateId);
    const preview = usePreviewTemplate(workspace.id, templateId);
    const sources = useWaSources(workspace.id, channel === 'whatsapp');
    const { mutate } = preview;

    useEffect(() => {
        mutate(null);
    }, [templateId, mutate]);

    if (preview.isPending || preview.isIdle || template.isPending || (channel === 'whatsapp' && sources.isPending)) {
        return <Skeleton className={channel === 'email' ? 'igr:h-40 igr:rounded-xl' : 'igr:mx-auto igr:h-80 igr:max-w-[320px] igr:rounded-[30px]'} />;
    }
    const result = preview.data;
    if (preview.isError || !result) {
        return <Callout tone="warning">{t('templates.mock.failed')}</Callout>;
    }
    if (channel === 'email') {
        return <EmailMock business={workspace.name} subject={result.subject} preheader={result.preheader} text={result.text} />;
    }
    if (channel === 'sms') {
        return (
            <PhoneMock business={workspace.name} variant="sms">
                <SmsBubble text={result.text ?? ''} />
            </PhoneMock>
        );
    }
    const waBody = waBodyOf(sources.data?.find((s) => s.id === template.data?.wa_template_id));
    if (!waBody) {
        return <Callout tone="warning">{t('templates.mock.notApproved')}</Callout>;
    }
    const values = (result.whatsapp?.components ?? []).reduce<Record<string, string[]>>((acc, c) => {
        if (c.type === 'header' || c.type === 'body') acc[c.type] = c.parameters.map((p) => (typeof p.text === 'string' ? p.text : ''));
        return acc;
    }, {});
    return (
        <PhoneMock business={workspace.name}>
            <WaBubble
                header={waBody.header ? fillWaText(waBody.header, values.header ?? []) : null}
                body={fillWaText(waBody.body, values.body ?? [])}
                footer={waBody.footer ?? null}
                buttons={waBody.buttons.map((text, i) => ({ text, url: waBody.urlButtons[i] ?? false }))}
            />
        </PhoneMock>
    );
}

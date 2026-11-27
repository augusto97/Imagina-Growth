import { useTranslation } from 'react-i18next';
import { Callout } from '@/components/ui/callout';
import { type CapabilitySummary, capabilityNotes } from './capabilities';

/** Lo que hace distinto un número de WhatsApp (no oficial), en palabras. Un número oficial no muestra nada. */
export function CapabilityNotes({ caps, className }: { caps: CapabilitySummary; className?: string }) {
    const { t } = useTranslation();
    const notes = capabilityNotes(caps);
    if (notes.length === 0) return null;
    return (
        <Callout tone="neutral" title={t('channels.capabilities.title')} className={className}>
            <ul className="igr:list-disc igr:space-y-0.5 igr:pl-4">
                {notes.map((n) => (
                    <li key={n}>{t(`channels.capabilities.${n}`)}</li>
                ))}
            </ul>
        </Callout>
    );
}

/** El aviso de una plantilla de WhatsApp que sale por un número sin plantillas de Meta (`name`: el número). */
export function TemplateAsTextNote({ caps, name, className }: { caps: CapabilitySummary; name: string; className?: string }) {
    const { t } = useTranslation();
    if (caps.templates) return null;
    return (
        <Callout tone="info" className={className}>
            {t('channels.capabilities.templateNote', { name })} {caps.media ? t('channels.capabilities.templateNoteMedia') : t('channels.capabilities.templateNoteNoMedia')}
        </Callout>
    );
}

import { Monitor, Smartphone } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DeviceFrame, type PreviewDevice } from '@/components/ui/device-frame';
import { SegmentedControl } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { useQuoteAssistant } from './api';
import type { CalculatorKind, QuoteCaptureSettings, QuoteDefinition } from './types';

/**
 * Vista previa en vivo del cotizador público: la misma página que sirve el hub (`/p/{clave}/cotizar/…`), pintada con el
 * borrador sin guardar. Cada cambio guarda el borrador 30 minutos en el hub (con una pausa de medio segundo) y recarga
 * el marco; se puede recorrer paso a paso y ver el precio, sin pedir el contacto ni guardar nada.
 */
export function QuotePagePreview({
    name,
    kind,
    definition,
    capture,
    publicUrl,
    height,
}: {
    name: string;
    kind: CalculatorKind;
    definition: QuoteDefinition;
    capture: QuoteCaptureSettings;
    /** La dirección que se muestra en la barra del navegador (solo texto). */
    publicUrl?: string | null;
    height?: number;
}) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { pagePreview } = useQuoteAssistant(workspace.id);
    const { mutate } = pagePreview;
    const [device, setDevice] = useState<PreviewDevice>('mobile');
    const [url, setUrl] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const handle = window.setTimeout(() => {
            mutate(
                { name: name.trim() || t('quotes.wizard.untitled'), kind, definition, capture },
                {
                    onSuccess: (r) => {
                        setUrl(r.url);
                        setError(null);
                    },
                    onError: (e) => setError(isApiError(e) ? (Object.values(e.fieldErrors)[0] ?? e.message) : t('errors.generic')),
                },
            );
        }, 500);
        return () => window.clearTimeout(handle);
    }, [name, kind, definition, capture, mutate, t]);

    return (
        <div className="igr:space-y-3">
            <div className="igr:flex igr:flex-wrap igr:items-center igr:justify-between igr:gap-2">
                <p className="igr:text-support igr:text-muted-foreground">{t('quotes.preview.help')}</p>
                <SegmentedControl
                    size="sm"
                    label={t('quotes.preview.device')}
                    value={device}
                    onChange={setDevice}
                    options={[
                        { value: 'mobile', label: t('quotes.preview.mobile'), icon: Smartphone },
                        { value: 'desktop', label: t('quotes.preview.desktop'), icon: Monitor },
                    ]}
                />
            </div>
            <DeviceFrame device={device} label={t('quotes.preview.label')} url={publicUrl ?? null} height={height}>
                {error ? (
                    <p className="igr:p-6 igr:text-center igr:text-support igr:text-muted-foreground">{error}</p>
                ) : url ? (
                    <iframe key={url} src={url} title={t('quotes.preview.label')} className="igr:block igr:h-full igr:w-full igr:border-0 igr:bg-white" />
                ) : (
                    <div className="igr:space-y-3 igr:p-6">
                        <Skeleton className="igr:h-6 igr:w-2/3" />
                        <Skeleton className="igr:h-4 igr:w-1/2" />
                        <Skeleton className="igr:h-16" />
                        <Skeleton className="igr:h-16" />
                    </div>
                )}
            </DeviceFrame>
        </div>
    );
}

import { useEffect, useRef, useState } from 'react';
import type { PreviewDevice } from './device-frame';

/** Ancho al que se pinta la página dentro de la vista previa: el de un celular o el de una laptop. */
export const PREVIEW_WIDTH: Record<PreviewDevice, number> = { mobile: 390, desktop: 1280 };

/**
 * La página pública real dentro de una vista previa (va dentro de `DeviceFrame`): un `iframe` pintado a su ancho de
 * celular o de laptop y escalado para caber, así sus consultas de medios deciden el diseño igual que en el teléfono del
 * cliente. Recibe la URL de la página (`src`) o su HTML ya armado (`srcDoc`). `sandbox` por defecto no deja correr
 * scripts ni enviar formularios: la vista previa nunca agenda ni registra nada.
 */
export function PagePreviewFrame({
    device,
    title,
    height,
    src,
    srcDoc,
    sandbox = '',
    onLoad,
}: {
    device: PreviewDevice;
    title: string;
    height: number;
    src?: string;
    srcDoc?: string;
    sandbox?: string;
    onLoad?: () => void;
}) {
    const box = useRef<HTMLDivElement>(null);
    const [width, setWidth] = useState(0);
    useEffect(() => {
        const el = box.current;
        if (!el || typeof ResizeObserver === 'undefined') return;
        const observer = new ResizeObserver((entries) => setWidth(entries[0]?.contentRect.width ?? 0));
        observer.observe(el);
        return () => observer.disconnect();
    }, []);
    const virtual = PREVIEW_WIDTH[device];
    const scale = width > 0 ? Math.min(1, width / virtual) : 1;
    return (
        <div ref={box} className="igr:relative igr:w-full igr:overflow-hidden igr:bg-white" style={{ height }}>
            <iframe
                title={title}
                src={src}
                srcDoc={srcDoc}
                sandbox={sandbox}
                referrerPolicy="no-referrer"
                onLoad={onLoad}
                className="igr:block igr:border-0 igr:bg-white"
                style={{ width: virtual, height: height / scale, transform: `scale(${scale})`, transformOrigin: '0 0' }}
            />
        </div>
    );
}

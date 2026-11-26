import { Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

export type PreviewDevice = 'desktop' | 'mobile';

/**
 * Marco de una vista previa en vivo (rediseño v2 §7): una ventana de navegador en el escritorio o un celular, con el
 * contenido desplazable por dentro (también con el teclado: la región se puede enfocar). El contenido se pinta a su
 * ancho real y decide su diseño con `device` (no con las consultas de medios de la pantalla del panel), así el celular
 * se ve como en un celular aunque el panel esté en el escritorio.
 *
 * - `url`: la dirección que se muestra en la barra (solo texto).
 * - `height`: alto del área visible en px (por defecto 560 en el escritorio y 600 en el celular).
 */
export function DeviceFrame({
    device,
    label,
    url,
    height,
    children,
    className,
}: {
    device: PreviewDevice;
    label: string;
    url?: string | null;
    height?: number;
    children: ReactNode;
    className?: string;
}) {
    const mobile = device === 'mobile';
    const h = height ?? (mobile ? 600 : 560);
    return (
        <div
            className={cn(
                'igr:mx-auto igr:flex igr:flex-col igr:overflow-hidden igr:border igr:bg-card igr:shadow-md',
                mobile ? 'igr:w-[320px] igr:max-w-full igr:rounded-[28px] igr:border-[6px] igr:border-slate-800 igr:dark:border-slate-600' : 'igr:w-full igr:rounded-xl',
                className,
            )}
        >
            {mobile ? (
                <div className="igr:flex igr:h-6 igr:shrink-0 igr:items-center igr:justify-center igr:bg-slate-800 igr:dark:bg-slate-600" aria-hidden>
                    <span className="igr:h-1.5 igr:w-16 igr:rounded-full igr:bg-slate-600 igr:dark:bg-slate-400" />
                </div>
            ) : (
                <div className="igr:flex igr:h-9 igr:shrink-0 igr:items-center igr:gap-1.5 igr:border-b igr:bg-muted/60 igr:px-3" aria-hidden>
                    <span className="igr:size-2.5 igr:rounded-full igr:bg-muted-foreground/25" />
                    <span className="igr:size-2.5 igr:rounded-full igr:bg-muted-foreground/25" />
                    <span className="igr:size-2.5 igr:rounded-full igr:bg-muted-foreground/25" />
                    <span className="igr:ml-3 igr:flex igr:h-5 igr:min-w-0 igr:flex-1 igr:items-center igr:gap-1 igr:truncate igr:rounded-md igr:bg-card igr:px-2 igr:text-[11px] igr:text-muted-foreground">
                        {url ? <Lock className="igr:size-3 igr:shrink-0" /> : null}
                        <span className="igr:truncate">{url}</span>
                    </span>
                </div>
            )}
            <div
                role="region"
                aria-label={label}
                // Región desplazable: se recorre con el teclado.
                tabIndex={0}
                className="igr:min-h-0 igr:overflow-y-auto igr:overscroll-contain igr:focus-visible:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring igr:focus-visible:ring-inset"
                style={{ height: h }}
            >
                {children}
            </div>
        </div>
    );
}

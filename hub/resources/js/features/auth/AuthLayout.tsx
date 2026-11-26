import type { ReactNode } from 'react';
import { Logo } from '@/components/layout/Logo';

/**
 * Pantallas de ingreso (sistema v2): una sola tarjeta centrada sobre un fondo con el degradado suave de la marca. El
 * fondo es decorativo; la tarjeta conserva el contraste de siempre.
 */
export function AuthLayout({ title, description, children, footer }: { title: string; description?: ReactNode; children: ReactNode; footer?: ReactNode }) {
    return (
        <div className="igr:hero-surface igr:flex igr:min-h-full igr:flex-col igr:items-center igr:justify-center igr:px-4 igr:py-12">
            <div className="igr:mb-6">
                <Logo />
            </div>
            <div className="igr:w-full igr:max-w-sm igr:rounded-2xl igr:border igr:bg-card igr:p-7 igr:shadow-lg">
                <h1 className="igr:text-section igr:font-semibold igr:tracking-tight">{title}</h1>
                {description ? <p className="igr:mt-1 igr:text-support igr:text-muted-foreground">{description}</p> : null}
                <div className="igr:mt-6">{children}</div>
            </div>
            {footer ? <div className="igr:mt-5 igr:text-support igr:text-muted-foreground">{footer}</div> : null}
        </div>
    );
}

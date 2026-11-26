import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { IconTile } from './icon-tile';
import { Illustration, type IllustrationName } from './illustrations';

/**
 * Tarjeta héroe (rediseño v2 §5 y §7): lo más importante de una pantalla, sobre un degradado suave de marca (índigo y
 * violeta en una capa tenue: el texto conserva su contraste). Antetítulo, título o cifra héroe, una explicación,
 * acciones y, a la derecha en el escritorio, una ilustración o lo que se pase en `aside`.
 *
 * - `value`: la cifra héroe (36–44 px con `tabular-nums`), p. ej. «$ 4.250.000».
 * - `children`: contenido extra debajo (un desglose, una lista corta).
 */
export function HeroCard({
    eyebrow,
    title,
    value,
    description,
    actions,
    icon,
    illustration,
    aside,
    children,
    className,
}: {
    eyebrow?: ReactNode;
    title?: ReactNode;
    value?: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    icon?: LucideIcon;
    illustration?: IllustrationName;
    aside?: ReactNode;
    children?: ReactNode;
    className?: string;
}) {
    const side = aside ?? (illustration ? <Illustration name={illustration} className="igr:w-44 igr:xl:w-52" /> : null);
    return (
        <section className={cn('igr:relative igr:overflow-hidden igr:rounded-2xl igr:border igr:border-primary/15 igr:hero-surface igr:shadow-sm', className)}>
            <div className="igr:flex igr:flex-col igr:gap-6 igr:p-5 igr:sm:p-7 igr:md:flex-row igr:md:items-center">
                <div className="igr:min-w-0 igr:flex-1">
                    {eyebrow || icon ? (
                        <div className="igr:mb-2 igr:flex igr:items-center igr:gap-2">
                            {icon ? <IconTile icon={icon} tone="brand" size="sm" /> : null}
                            {eyebrow ? <p className="igr:text-caption igr:font-semibold igr:tracking-wide igr:text-brand-soft-foreground igr:uppercase">{eyebrow}</p> : null}
                        </div>
                    ) : null}
                    {title ? <h2 className="igr:text-section igr:font-semibold igr:text-foreground igr:sm:text-[20px] igr:sm:leading-7">{title}</h2> : null}
                    {value !== undefined && value !== null ? (
                        <p className="igr:mt-1 igr:text-[34px] igr:leading-10 igr:font-semibold igr:tracking-tight igr:text-foreground igr:tabular-nums igr:sm:text-hero">
                            {value}
                        </p>
                    ) : null}
                    {description ? <p className="igr:mt-2 igr:max-w-2xl igr:text-body igr:text-muted-foreground">{description}</p> : null}
                    {children ? <div className="igr:mt-4">{children}</div> : null}
                    {actions ? <div className="igr:mt-5 igr:flex igr:flex-wrap igr:items-center igr:gap-2">{actions}</div> : null}
                </div>
                {side ? <div className="igr:hidden igr:shrink-0 igr:md:block">{side}</div> : null}
            </div>
        </section>
    );
}

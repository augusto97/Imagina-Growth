import type { WorkspaceBranding } from '@/api/types';
import { usePublicBranding } from '@/features/agency/api';
import { cn } from '@/lib/cn';

/**
 * Logo del panel. Con la marca de una agencia (M22, marca blanca) muestra su
 * logo o su inicial y nombre; sin espacio de trabajo (pantallas de ingreso), la
 * marca del dominio propio verificado, si lo hay.
 */
export function Logo({ className, compact = false, brand }: { className?: string; compact?: boolean; brand?: WorkspaceBranding | null }) {
    const fromHost = usePublicBranding(brand === undefined);
    const b = brand ?? fromHost.data?.branding ?? null;
    if (b !== null && (b.white_label || b.logo_url !== null || b.name !== 'Imagina Growth')) {
        const color = b.primary_color && /^#[0-9a-f]{6}$/i.test(b.primary_color) ? b.primary_color : undefined;
        return (
            <div className={cn('igr:flex igr:items-center igr:gap-2', className)}>
                {b.logo_url ? (
                    <img src={b.logo_url} alt={b.name} className="igr:h-6 igr:max-w-32 igr:shrink-0 igr:object-contain" />
                ) : (
                    <span
                        className={cn('igr:flex igr:size-6 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-md igr:text-xs igr:font-semibold igr:text-white', color ? null : 'igr:bg-primary')}
                        style={color ? { background: color } : undefined}
                        aria-hidden
                    >
                        {b.name.slice(0, 1).toUpperCase()}
                    </span>
                )}
                {compact || b.logo_url ? null : <span className="igr:truncate igr:text-sm igr:font-semibold igr:tracking-tight">{b.name}</span>}
            </div>
        );
    }
    return (
        <div className={cn('igr:flex igr:items-center igr:gap-2', className)}>
            <svg viewBox="0 0 32 32" className="igr:size-6 igr:shrink-0" aria-hidden>
                <rect width="32" height="32" rx="8" className="igr:fill-primary" />
                <path d="M9 21.5 14 15l4 3.5 5-7.5" fill="none" stroke="white" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" />
                <circle cx="23" cy="11" r="2" fill="white" />
            </svg>
            {compact ? null : <span className="igr:text-sm igr:font-semibold igr:tracking-tight">Imagina Growth</span>}
        </div>
    );
}

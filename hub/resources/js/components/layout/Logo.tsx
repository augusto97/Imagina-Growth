import { cn } from '@/lib/cn';

export function Logo({ className, compact = false }: { className?: string; compact?: boolean }) {
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

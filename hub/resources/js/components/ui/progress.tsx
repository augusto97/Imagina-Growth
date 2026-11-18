import { cn } from '@/lib/cn';

export function Progress({ value, className, label }: { value: number | null; className?: string; label?: string }) {
    const pct = Math.max(0, Math.min(100, Math.round((value ?? 0) * 100)));
    return (
        <div
            role="progressbar"
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={pct}
            aria-label={label}
            className={cn('igr:h-1.5 igr:w-full igr:overflow-hidden igr:rounded-full igr:bg-muted', className)}
        >
            <div className="igr:h-full igr:rounded-full igr:bg-primary igr:transition-[width] igr:duration-500" style={{ width: `${pct}%` }} />
        </div>
    );
}

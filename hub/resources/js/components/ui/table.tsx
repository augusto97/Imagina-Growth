import type { HTMLAttributes, TdHTMLAttributes, ThHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

export function Table({ className, ...props }: HTMLAttributes<HTMLTableElement>) {
    return (
        <div className="igr:relative igr:w-full igr:overflow-x-auto">
            <table className={cn('igr:w-full igr:caption-bottom igr:text-[13px]', className)} {...props} />
        </div>
    );
}

export function THead({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
    return <thead className={cn('igr:[&_tr]:border-b', className)} {...props} />;
}

export function TBody({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
    return <tbody className={cn('igr:[&_tr:last-child]:border-0', className)} {...props} />;
}

export function TR({ className, ...props }: HTMLAttributes<HTMLTableRowElement>) {
    return <tr className={cn('igr:border-b igr:transition-colors igr:hover:bg-muted/40', className)} {...props} />;
}

export function TH({ className, ...props }: ThHTMLAttributes<HTMLTableCellElement>) {
    return <th className={cn('igr:h-8 igr:px-3 igr:text-left igr:align-middle igr:text-xs igr:font-medium igr:text-muted-foreground', className)} {...props} />;
}

export function TD({ className, ...props }: TdHTMLAttributes<HTMLTableCellElement>) {
    return <td className={cn('igr:px-3 igr:py-2 igr:align-middle', className)} {...props} />;
}

import type { HTMLAttributes, TdHTMLAttributes, ThHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

/*
 * Tablas del sistema v2: encabezado con fondo tenue y texto de apoyo, filas de 44 px con hover suave y cifras
 * alineadas a la derecha con `tabular-nums` (agrega `igr:text-right igr:tabular-nums` a la celda). El contenedor
 * desplaza de lado por su cuenta: una tabla ancha nunca desborda la pantalla del celular (hub/CLAUDE.md §14).
 * Dentro de una tarjeta, envuélvela en `Card` con `igr:overflow-hidden` para que el encabezado llegue a los bordes.
 */

export function Table({ className, ...props }: HTMLAttributes<HTMLTableElement>) {
    return (
        <div className="igr:relative igr:w-full igr:overflow-x-auto">
            <table className={cn('igr:w-full igr:caption-bottom igr:text-[13px]', className)} {...props} />
        </div>
    );
}

export function THead({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
    return <thead className={cn('igr:bg-muted/60 igr:[&_tr]:border-b igr:[&_tr]:hover:bg-transparent', className)} {...props} />;
}

export function TBody({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
    return <tbody className={cn('igr:[&_tr:last-child]:border-0', className)} {...props} />;
}

export function TR({ className, ...props }: HTMLAttributes<HTMLTableRowElement>) {
    return <tr className={cn('igr:border-b igr:transition-colors igr:hover:bg-muted/50', className)} {...props} />;
}

export function TH({ className, ...props }: ThHTMLAttributes<HTMLTableCellElement>) {
    return (
        <th
            className={cn('igr:h-9 igr:px-3 igr:text-left igr:align-middle igr:text-caption igr:font-medium igr:whitespace-nowrap igr:text-muted-foreground', className)}
            {...props}
        />
    );
}

export function TD({ className, ...props }: TdHTMLAttributes<HTMLTableCellElement>) {
    return <td className={cn('igr:h-11 igr:px-3 igr:py-2 igr:align-middle', className)} {...props} />;
}

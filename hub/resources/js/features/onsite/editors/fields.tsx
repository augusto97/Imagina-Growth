import type { ReactNode } from 'react';
import { useId } from 'react';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/cn';

/* Piezas de formulario del constructor de widgets. */

export function Field({ label, help, children, className }: { label: ReactNode; help?: ReactNode; children: (id: string) => ReactNode; className?: string }) {
    const id = useId();
    return (
        <div className={cn('igr:space-y-1.5', className)}>
            <Label htmlFor={id}>{label}</Label>
            {children(id)}
            {help ? <p className="igr:text-xs igr:text-muted-foreground">{help}</p> : null}
        </div>
    );
}

export function Check({ checked, onChange, children, help }: { checked: boolean; onChange: (v: boolean) => void; children: ReactNode; help?: ReactNode }) {
    return (
        <label className="igr:flex igr:items-start igr:gap-2 igr:text-[13px]">
            <input type="checkbox" className="igr:mt-0.5" checked={checked} onChange={(e) => onChange(e.target.checked)} />
            <span>
                {children}
                {help ? <span className="igr:block igr:text-xs igr:text-muted-foreground">{help}</span> : null}
            </span>
        </label>
    );
}

export function Section({ title, description, children }: { title: ReactNode; description?: ReactNode; children: ReactNode }) {
    return (
        <section className="igr:space-y-3 igr:border-b igr:pb-5 igr:last:border-b-0">
            <div>
                <h3 className="igr:text-sm igr:font-semibold">{title}</h3>
                {description ? <p className="igr:text-xs igr:text-muted-foreground">{description}</p> : null}
            </div>
            {children}
        </section>
    );
}

/** Lista de casillas para elegir varios valores. */
export function MultiCheck<T extends string>({ values, selected, onChange, label }: { values: T[]; selected: T[]; onChange: (v: T[]) => void; label: (v: T) => ReactNode }) {
    return (
        <div className="igr:flex igr:flex-wrap igr:gap-x-4 igr:gap-y-1.5">
            {values.map((v) => (
                <Check key={v} checked={selected.includes(v)} onChange={(on) => onChange(on ? [...selected, v] : selected.filter((s) => s !== v))}>
                    {label(v)}
                </Check>
            ))}
        </div>
    );
}

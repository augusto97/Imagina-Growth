import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import type { ConnectionStatus } from './api';

/** Lista desplegable nativa con el estilo del panel (sin dependencia nueva). */
export function SelectField({
    id,
    label,
    value,
    onChange,
    options,
    placeholder,
    help,
    disabled,
}: {
    id: string;
    label: ReactNode;
    value: string;
    onChange: (value: string) => void;
    options: { value: string; label: string }[];
    placeholder: string;
    help?: ReactNode;
    disabled?: boolean;
}) {
    return (
        <div className="igr:space-y-1">
            <Label htmlFor={id}>{label}</Label>
            <select
                id={id}
                className="igr:h-8 igr:w-full igr:rounded-md igr:border igr:border-input igr:bg-transparent igr:px-2 igr:text-[13px] igr:disabled:opacity-60"
                value={value}
                disabled={disabled}
                onChange={(e) => onChange(e.target.value)}
            >
                <option value="">{placeholder}</option>
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
            {help ? <p className="igr:text-xs igr:text-muted-foreground">{help}</p> : null}
        </div>
    );
}

export function StatusBadge({ status }: { status: ConnectionStatus | 'none' | 'unavailable' }) {
    const { t } = useTranslation();
    const variant = status === 'active' ? 'success' : status === 'expired' || status === 'error' ? 'warning' : 'outline';
    return <Badge variant={variant}>{t(`connections.status.${status}`)}</Badge>;
}

/** Iniciales con color, sin logos de marca. */
export function ProviderMark({ provider }: { provider: 'meta' | 'meta_whatsapp' | 'google' | 'tiktok' }) {
    const tone = {
        meta: 'igr:bg-blue-600',
        meta_whatsapp: 'igr:bg-green-600',
        google: 'igr:bg-emerald-600',
        tiktok: 'igr:bg-zinc-900 igr:dark:bg-zinc-100 igr:dark:text-zinc-900',
    }[provider];
    const letter = { meta: 'M', meta_whatsapp: 'W', google: 'G', tiktok: 'T' }[provider];
    return (
        <span
            aria-hidden
            className={`igr:inline-flex igr:size-8 igr:shrink-0 igr:items-center igr:justify-center igr:rounded-md igr:text-xs igr:font-semibold igr:text-white ${tone}`}
        >
            {letter}
        </span>
    );
}

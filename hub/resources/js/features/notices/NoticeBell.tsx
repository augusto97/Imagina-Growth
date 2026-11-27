import { useQueryClient } from '@tanstack/react-query';
import { Bell } from 'lucide-react';
import { lazy, Suspense, useCallback, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ROUTE_TEXTS } from '@/app/routeTexts';
import { Tooltip } from '@/components/ui/tooltip';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';
import { loadTexts } from '@/lib/i18n';
import { usePrivateChannel } from '@/lib/realtime';
import { noticeKeys, useUnreadNotices } from './api';
import { unreadBadge, useNoticeCenter } from './model';

/** El centro de avisos y sus textos se bajan al abrirlo por primera vez (o al pasar el puntero por la campana). */
const loadCenter = () => Promise.all([import('./NoticeCenter'), loadTexts(ROUTE_TEXTS.notices ?? [])]).then(([m]) => ({ default: m.NoticeCenter }));
const LazyCenter = lazy(loadCenter);

export function preloadNoticeCenter(): void {
    void loadCenter();
}

/**
 * La campana de avisos (lote 0.4.0): en la barra lateral (`sidebar`, contraída o no) y en la barra del celular
 * (`mobile`). El número de no leídos se consulta cada minuto y, con Reverb, llega en vivo por el canal privado de la
 * persona (`workspace.{ws}.notices.{usr}`).
 */
export function NoticeBell({ variant, className }: { variant: 'sidebar' | 'collapsed' | 'mobile'; className?: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const unread = useUnreadNotices(workspace.id);
    const setOpen = useNoticeCenter((s) => s.setOpen);
    const count = unread.data?.unread ?? 0;
    const badge = unreadBadge(count);
    const label = count > 0 ? t('nav.noticesUnread', { count }) : t('nav.notices');

    const button = (
        <button
            type="button"
            onClick={() => setOpen(true)}
            onMouseEnter={preloadNoticeCenter}
            onFocus={preloadNoticeCenter}
            aria-label={label}
            className={cn(
                'igr:relative igr:flex igr:shrink-0 igr:items-center igr:justify-center igr:rounded-md igr:text-muted-foreground igr:hover:bg-accent igr:hover:text-foreground',
                variant === 'mobile' ? 'igr:p-1.5 igr:text-foreground' : variant === 'collapsed' ? 'igr:mx-auto igr:mt-1.5 igr:size-9 igr:rounded-lg' : 'igr:size-8',
                className,
            )}
        >
            <Bell className={variant === 'mobile' ? 'igr:size-5' : 'igr:size-4'} aria-hidden />
            {badge !== null ? (
                <span
                    aria-hidden
                    className="igr:absolute igr:-top-0.5 igr:-right-0.5 igr:flex igr:h-4 igr:min-w-4 igr:items-center igr:justify-center igr:rounded-full igr:bg-destructive igr:px-1 igr:text-[10px] igr:font-semibold igr:leading-none igr:text-white igr:tabular-nums"
                >
                    {badge}
                </span>
            ) : null}
        </button>
    );

    return variant === 'mobile' ? (
        button
    ) : (
        <Tooltip content={label} side={variant === 'collapsed' ? 'right' : 'bottom'}>
            {button}
        </Tooltip>
    );
}

/**
 * Dentro de `WorkspaceLayout`: escucha el canal privado de avisos de la persona y monta el centro de avisos la primera
 * vez que se abre (queda montado para no perder la posición).
 */
export function NoticeHost() {
    const { workspace, me } = useWorkspace();
    const client = useQueryClient();
    const open = useNoticeCenter((s) => s.open);
    const [mounted, setMounted] = useState(open);
    if (open && !mounted) setMounted(true);
    const refresh = useCallback(() => void client.invalidateQueries({ queryKey: noticeKeys.all(workspace.id) }), [client, workspace.id]);
    usePrivateChannel(`workspace.${workspace.id}.notices.${me.user.id}`, 'notice.created', refresh);

    if (!mounted) return null;
    return (
        <Suspense fallback={null}>
            <LazyCenter />
        </Suspense>
    );
}

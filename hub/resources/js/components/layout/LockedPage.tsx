import { ArrowRight } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import type { Ability } from '@/api/types';
import { Button } from '@/components/ui/button';
import type { ChecklistStep } from '@/components/ui/checklist';
import type { IllustrationName } from '@/components/ui/illustrations';
import { LockedState } from '@/components/ui/locked-state';
import { PageHeader } from '@/components/ui/page-header';
import { sectionPath, type NavItem, type NavLock, type NavSection } from './nav';

/** Dónde se resuelve cada bloqueo y quién puede hacerlo (las rutas son las de hoy; Conexiones las unificará). */
export function lockResolution(lock: NavLock): { to: string; ability: Ability; labelKey: string } {
    switch (lock.kind) {
        case 'plan':
            return { to: 'facturacion', ability: 'manageBilling', labelKey: 'ui.locked.plan.action' };
        case 'site':
            return {
                to: lock.state === 'pending' ? 'sitios' : 'sitios?conectar=1',
                ability: 'manageSites',
                labelKey: lock.state === 'pending' ? 'ui.locked.site.finish' : 'ui.locked.site.action',
            };
        case 'channel':
            return { to: 'canales', ability: 'manageWorkspace', labelKey: 'ui.locked.channel.action' };
    }
}

const ILLUSTRATION: Record<NavLock['kind'], IllustrationName> = { plan: 'plan', site: 'web', channel: 'conversations' };

/**
 * La página de una entrada que todavía no puede servir (rediseño v2 §3, «bloqueos que enseñan»): el encabezado de
 * siempre y `LockedState` con qué hace, qué le falta y el botón para resolverlo (o a quién pedírselo, si el rol no
 * puede). La pone `WorkspaceLayout` en lugar de la página; la pantalla de verdad no se carga ni pide datos.
 */
export function LockedPage({ section, item, lock, base, abilities }: { section: NavSection; item: NavItem; lock: NavLock; base: string; abilities: readonly Ability[] }) {
    const { t } = useTranslation();
    const name = t(`nav.${item.key}`);
    const fix = lockResolution(lock);
    const canFix = abilities.includes(fix.ability);

    const requirements: ChecklistStep[] = [];
    if (lock.kind === 'plan') {
        requirements.push({
            key: 'plan',
            title: t('ui.locked.plan.requirement', { name }),
            description: lock.plan ? t('ui.locked.plan.included', { plan: lock.plan }) : undefined,
            status: 'current',
        });
    }
    if (lock.kind === 'site' || (lock.kind === 'plan' && item.requires?.includes('site'))) {
        requirements.push({
            key: 'site',
            title: t('ui.locked.site.requirement'),
            description: lock.kind === 'site' && lock.state === 'pending' ? t('ui.locked.site.pending') : t('ui.locked.site.how'),
            status: lock.kind === 'site' ? 'current' : 'todo',
        });
    }
    if (lock.kind === 'channel' || (lock.kind === 'plan' && item.requires?.includes('channel'))) {
        requirements.push({
            key: 'channel',
            title: t('ui.locked.channel.requirement'),
            description: t('ui.locked.channel.how'),
            status: lock.kind === 'channel' ? 'current' : 'todo',
        });
    }

    const title = lock.kind === 'plan' ? t('ui.locked.plan.title', { name }) : lock.kind === 'site' ? t('ui.locked.site.title', { name }) : t('ui.locked.channel.title', { name });

    return (
        <>
            <PageHeader title={name} description={t(`sections.items.${item.key}`)} />
            <LockedState
                variant={lock.kind === 'plan' ? 'plan' : 'requirement'}
                illustration={ILLUSTRATION[lock.kind]}
                planName={lock.kind === 'plan' ? lock.plan : null}
                title={title}
                description={t(`sections.unlocks.${item.key}`, { defaultValue: t(`sections.items.${item.key}`) })}
                requirements={requirements}
                action={
                    canFix ? (
                        <Button asChild variant="brand" size="lg">
                            <Link to={`${base}/${fix.to}`}>
                                {t(fix.labelKey)}
                                <ArrowRight aria-hidden />
                            </Link>
                        </Button>
                    ) : undefined
                }
                secondary={
                    <Button asChild variant="ghost" size="lg">
                        <Link to={sectionPath(base, section)}>{t('ui.locked.seeSection', { section: t(`nav.sections.${section.key}`) })}</Link>
                    </Button>
                }
                note={canFix ? undefined : t(`ui.locked.askAdmin.${lock.kind}`)}
            />
        </>
    );
}

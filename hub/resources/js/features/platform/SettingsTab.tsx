import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/input';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/cn';
import { AdminsPanel } from './AdminsPanel';
import { HolidaysPanel } from './HolidaysPanel';
import { SettingsSectionCard } from './SettingsSectionCard';
import { usePlatformSettings } from './settings-api';
import { SECTION_GROUPS, type SectionKey, isSettingSection, sectionFromParam } from './settings-model';
import { WaRatesPanel } from './WaRatesPanel';

/**
 * Plataforma → Ajustes (F17): todo lo que antes se configuraba en el .env del servidor o con comandos. Menú de secciones
 * a la izquierda (en el celular, una lista desplegable) y la sección elegida a la derecha; la sección va en la dirección
 * (`?pestana=ajustes&seccion=mail`) para que Instalación y Conexiones lleven directo a ella.
 */
export function SettingsTab() {
    const { t } = useTranslation();
    const [params, setParams] = useSearchParams();
    const section = sectionFromParam(params.get('seccion'));
    const settings = usePlatformSettings();

    const go = (key: SectionKey) => {
        const next = new URLSearchParams(params);
        next.set('pestana', 'ajustes');
        next.set('seccion', key);
        setParams(next, { replace: true });
    };

    let content;
    if (section === 'admins') content = <AdminsPanel />;
    else if (section === 'holidays') content = <HolidaysPanel />;
    else if (section === 'wa_rates') content = <WaRatesPanel />;
    else if (settings.isError) content = <ErrorState error={settings.error} onRetry={() => void settings.refetch()} />;
    else if (settings.isPending) content = <Skeleton className="igr:h-96" />;
    else {
        const data = isSettingSection(section) ? settings.data.sections.find((s) => s.key === section) : undefined;
        content = data ? <SettingsSectionCard key={data.key} section={data} /> : null;
    }

    return (
        <div className="igr:space-y-4">
            <div className="igr:space-y-1">
                <h2 className="igr:text-sm igr:font-semibold">{t('platformSettings.title')}</h2>
                <p className="igr:text-[13px] igr:text-muted-foreground">{t('platformSettings.description')}</p>
            </div>
            <div className="igr:grid igr:gap-4 igr:lg:grid-cols-[220px_minmax(0,1fr)]">
                <div className="igr:space-y-1.5 igr:lg:hidden">
                    <Label htmlFor="ps-section">{t('platformSettings.sectionLabel')}</Label>
                    <Select id="ps-section" value={section} onChange={(e) => go(sectionFromParam(e.target.value))}>
                        {SECTION_GROUPS.map((group) => (
                            <optgroup key={group.key} label={t(`platformSettings.groups.${group.key}`)}>
                                {group.sections.map((key) => (
                                    <option key={key} value={key}>
                                        {t(`platformSettings.sections.${key}.title`)}
                                    </option>
                                ))}
                            </optgroup>
                        ))}
                    </Select>
                </div>
                <nav aria-label={t('platformSettings.sectionLabel')} className="igr:hidden igr:space-y-4 igr:lg:block">
                    {SECTION_GROUPS.map((group) => (
                        <div key={group.key} className="igr:space-y-0.5">
                            <p className="igr:px-2 igr:text-[11px] igr:font-medium igr:uppercase igr:tracking-wide igr:text-muted-foreground">
                                {t(`platformSettings.groups.${group.key}`)}
                            </p>
                            {group.sections.map((key) => (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => go(key)}
                                    aria-current={section === key ? 'page' : undefined}
                                    className={cn(
                                        'igr:block igr:w-full igr:rounded-md igr:px-2 igr:py-1.5 igr:text-left igr:text-[13px] igr:transition-colors igr:hover:bg-accent',
                                        section === key ? 'igr:bg-accent igr:font-medium igr:text-foreground' : 'igr:text-muted-foreground',
                                    )}
                                >
                                    {t(`platformSettings.sections.${key}.title`)}
                                </button>
                            ))}
                        </div>
                    ))}
                </nav>
                <div className="igr:min-w-0">{content}</div>
            </div>
        </div>
    );
}

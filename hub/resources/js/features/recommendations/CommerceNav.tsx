import { ArrowLeft } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, NavLink } from 'react-router';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cn } from '@/lib/cn';

const LINKS = [
    { to: 'en-el-sitio/recomendaciones', key: 'recommendations' },
    { to: 'en-el-sitio/avisos', key: 'watches' },
    { to: 'en-el-sitio/banners', key: 'banners' },
] as const;

/** Navegación entre las pantallas de comercio en el sitio (M16), dentro de «En el sitio». */
export function CommerceNav() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const root = `/w/${workspace.id}`;

    return (
        <nav aria-label={t('onsiteCommerce.nav.label')} className="igr:mb-4 igr:flex igr:flex-wrap igr:items-center igr:gap-1 igr:border-b igr:pb-2">
            <Link to={`${root}/en-el-sitio`} className="igr:mr-2 igr:inline-flex igr:items-center igr:gap-1 igr:text-xs igr:text-muted-foreground igr:hover:text-foreground">
                <ArrowLeft className="igr:size-3.5" />
                {t('onsiteCommerce.nav.back')}
            </Link>
            {LINKS.map((link) => (
                <NavLink
                    key={link.key}
                    to={`${root}/${link.to}`}
                    className={({ isActive }) =>
                        cn(
                            'igr:rounded-md igr:px-2.5 igr:py-1 igr:text-[13px] igr:outline-none igr:focus-visible:ring-2 igr:focus-visible:ring-ring',
                            isActive ? 'igr:bg-muted igr:font-medium igr:text-foreground' : 'igr:text-muted-foreground igr:hover:text-foreground',
                        )
                    }
                >
                    {t(`onsiteCommerce.nav.${link.key}`)}
                </NavLink>
            ))}
        </nav>
    );
}

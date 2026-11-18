import { Copy, FileText } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/misc';
import { toast } from '@/stores/toast';
import type { Explainer, LoyaltyOverview } from './types';

/** El texto plano de la página del programa (para copiarlo a otra página o a un correo). */
export function explainerText(e: Explainer): string {
    return [e.title, '', e.intro, ...e.sections.flatMap((s) => ['', s.title, ...s.items.map((i) => `• ${i}`)])].join('\n');
}

/** La página explicativa que se genera sola con las reglas y que la tienda muestra a sus clientes. */
export function ExplainerTab({ data }: { data: LoyaltyOverview }) {
    const { t } = useTranslation();
    const e = data.explainer;
    if (e === null) {
        return <EmptyState icon={FileText} title={t('loyalty.explainer.emptyTitle')} description={t('loyalty.explainer.emptyHelp')} />;
    }
    const copy = () => {
        void navigator.clipboard?.writeText(explainerText(e)).then(
            () => toast.success(t('loyalty.explainer.copied')),
            () => toast.error(t('errors.generic')),
        );
    };

    return (
        <div className="igr:grid igr:items-start igr:gap-4 igr:lg:grid-cols-[minmax(0,1fr)_300px]">
            <Card>
                <CardHeader>
                    <CardTitle className="igr:text-lg">{e.title}</CardTitle>
                    <CardDescription>{e.intro}</CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-5">
                    {e.sections.map((s) => (
                        <section key={s.key}>
                            <h3 className="igr:mb-1.5 igr:text-sm igr:font-semibold">{s.title}</h3>
                            <ul className="igr:list-disc igr:space-y-1 igr:pl-5 igr:text-[13px]">
                                {s.items.map((item, i) => (
                                    <li key={i}>{item}</li>
                                ))}
                            </ul>
                        </section>
                    ))}
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>{t('loyalty.explainer.title')}</CardTitle>
                    <CardDescription>{t('loyalty.explainer.help')}</CardDescription>
                </CardHeader>
                <CardContent>
                    <Button variant="outline" size="sm" onClick={copy}>
                        <Copy />
                        {t('loyalty.explainer.copy')}
                    </Button>
                </CardContent>
            </Card>
        </div>
    );
}

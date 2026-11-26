import { Check, Copy, ExternalLink, FileText, Globe, QrCode } from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { keys } from '@/api/queries';
import { Button } from '@/components/ui/button';
import { Callout } from '@/components/ui/callout';
import { ChoiceCard } from '@/components/ui/choice-card';
import { Illustration } from '@/components/ui/illustrations';
import { qrDownloadUrl, useLandingMutations, useLandingPages } from '@/features/capture/api';
import type { LandingPage } from '@/features/capture/types';
import { ConnectCodePanel } from '@/features/connect/ConnectCodePanel';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { speaksServices, stepOf } from '../flow';
import { StepFrame } from '../StepFrame';
import type { Onboarding } from '../types';

/*
 * Paso 2 (docs/diseno/rediseno-v2.md §4.2 y §11.3): conectar la web con el plugin (descarga, código con «Copiar» y la
 * espera en vivo que celebra cuando el sitio se conecta, con lo que empieza a pasar). Para quien no tiene WordPress:
 * la página de contacto alojada de En tu web, publicada con un clic, con su enlace y su QR, y se sigue.
 */

function Connected({ services }: { services: boolean }) {
    const { t } = useTranslation();
    const items = t(services ? 'onboarding.wizard.connect.startsServices' : 'onboarding.wizard.connect.starts', { returnObjects: true }) as unknown;
    return (
        <div className="igr-enter igr:rounded-2xl igr:border igr:border-success/25 igr:bg-success/6 igr:p-5 igr:text-center igr:sm:p-7" role="status">
            <Illustration name="celebration" className="igr:mx-auto igr:w-40" />
            <p className="igr:mt-3 igr:text-section igr:font-semibold igr:text-foreground">{t(services ? 'onboarding.wizard.connect.doneTitleWeb' : 'onboarding.wizard.connect.doneTitle')}</p>
            <p className="igr:mt-1 igr:text-support igr:text-muted-foreground">{t('onboarding.wizard.connect.doneBody')}</p>
            <ul className="igr:mx-auto igr:mt-5 igr:max-w-md igr:space-y-2 igr:text-left">
                {(Array.isArray(items) ? (items as string[]) : []).map((item) => (
                    <li key={item} className="igr:flex igr:gap-2 igr:text-support igr:text-foreground">
                        <Check className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-success" aria-hidden />
                        <span className="igr:min-w-0">{item}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function PageReady({ page }: { page: LandingPage }) {
    const { t } = useTranslation();
    const [copied, setCopied] = useState(false);
    return (
        <div className="igr-enter igr:space-y-4 igr:rounded-2xl igr:border igr:border-success/25 igr:bg-success/6 igr:p-5" role="status">
            <p className="igr:text-body igr:font-semibold igr:text-foreground">{t('onboarding.wizard.connect.page.readyTitle')}</p>
            <div className="igr:flex igr:flex-wrap igr:items-center igr:gap-2 igr:rounded-lg igr:border igr:bg-card igr:px-3 igr:py-2">
                <span className="igr:min-w-0 igr:flex-1 igr:truncate igr:font-mono igr:text-support igr:text-foreground">{page.url}</span>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => {
                        void navigator.clipboard.writeText(page.url).then(() => setCopied(true));
                    }}
                >
                    {copied ? <Check aria-hidden /> : <Copy aria-hidden />}
                    {copied ? t('common.copied') : t('common.copy')}
                </Button>
            </div>
            <div className="igr:flex igr:flex-wrap igr:gap-2">
                <Button asChild size="sm" variant="outline">
                    <a href={page.url} target="_blank" rel="noreferrer">
                        <ExternalLink aria-hidden />
                        {t('onboarding.wizard.connect.page.open')}
                    </a>
                </Button>
                <Button asChild size="sm" variant="outline">
                    <a href={qrDownloadUrl(page.qr.png, 1024)} download>
                        <QrCode aria-hidden />
                        {t('onboarding.wizard.connect.page.qr')}
                    </a>
                </Button>
            </div>
            <p className="igr:text-caption igr:text-muted-foreground">{t('onboarding.wizard.connect.page.later')}</p>
        </div>
    );
}

function NoWordPress({ base }: { base: string }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const pages = useLandingPages(workspace.id);
    const m = useLandingMutations(workspace.id);
    const [error, setError] = useState<{ message: string; plan: boolean } | null>(null);
    const published = pages.data?.data.find((p) => p.status === 'published') ?? null;
    const canCreate = workspace.abilities.includes('manageMarketing');

    const create = async () => {
        setError(null);
        try {
            const draft = pages.data?.data.find((p) => p.status !== 'archived') ?? (await m.create.mutateAsync({ name: t('onboarding.wizard.connect.page.name'), title: workspace.name }));
            await m.publish.mutateAsync(draft.id);
            void pages.refetch();
        } catch (e) {
            setError({ message: isApiError(e) && e.message !== '' ? (Object.values(e.fieldErrors)[0] ?? e.message) : t('errors.generic'), plan: isApiError(e, 'plan_required') });
        }
    };

    if (published) {
        return <PageReady page={published} />;
    }
    return (
        <div className="igr:space-y-4">
            <div className="igr:flex igr:gap-4 igr:rounded-2xl igr:border igr:bg-card igr:p-5">
                <Illustration name="web" className="igr:w-28 igr:shrink-0 igr:max-sm:hidden" />
                <div className="igr:min-w-0">
                    <p className="igr:text-body igr:font-semibold igr:text-foreground">{t('onboarding.wizard.connect.page.title')}</p>
                    <p className="igr:mt-1 igr:text-support igr:text-muted-foreground">{t('onboarding.wizard.connect.page.body')}</p>
                    {canCreate ? (
                        <Button className="igr:mt-4" onClick={() => void create()} loading={m.create.isPending || m.publish.isPending || pages.isPending}>
                            <FileText aria-hidden />
                            {t('onboarding.wizard.connect.page.create')}
                        </Button>
                    ) : (
                        <p className="igr:mt-3 igr:text-caption igr:text-muted-foreground">{t('onboarding.wizard.connect.page.ask')}</p>
                    )}
                </div>
            </div>
            {error ? (
                <Callout
                    tone={error.plan ? 'brand' : 'warning'}
                    title={error.message}
                    action={
                        error.plan ? (
                            <Button asChild size="sm" variant="outline">
                                <Link to={`${base}/facturacion`}>{t('onboarding.wizard.connect.page.plans')}</Link>
                            </Button>
                        ) : undefined
                    }
                />
            ) : null}
        </div>
    );
}

export function ConnectStep({
    state,
    base,
    onBack,
    onNext,
    busy,
}: {
    state: Onboarding;
    base: string;
    onBack: () => void;
    onNext: () => void;
    busy: boolean;
}) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const services = speaksServices(state.business_type ?? workspace.business_type);
    const step = stepOf(state, 'connect');
    const connected = step?.done === true;
    const [path, setPath] = useState<'wordpress' | 'none'>('wordpress');
    const canConnect = workspace.abilities.includes('manageSites');
    const qc = useQueryClient();
    // Al conectarse, el menú (candados de En tu web y Resultados) y el Inicio se enteran.
    useEffect(() => {
        if (connected) {
            void qc.invalidateQueries({ queryKey: keys.me });
            void qc.invalidateQueries({ queryKey: ['ws', workspace.id, 'home'] });
        }
    }, [connected, qc, workspace.id]);

    return (
        <StepFrame
            step="connect"
            title={t(services ? 'onboarding.wizard.connect.titleWeb' : 'onboarding.wizard.connect.title')}
            description={t(services ? 'onboarding.wizard.connect.descriptionWeb' : 'onboarding.wizard.connect.description')}
            onBack={onBack}
            onNext={onNext}
            nextLabel={connected || path === 'none' ? t('onboarding.wizard.next') : t('onboarding.wizard.skipStep')}
            busy={busy}
            footerNote={connected ? undefined : t('onboarding.wizard.connect.footer')}
        >
            {connected ? (
                <Connected services={services} />
            ) : (
                <>
                    {canConnect ? (
                        <fieldset className="igr:mb-6">
                            <legend className="igr:sr-only">{t('onboarding.wizard.connect.pathLegend')}</legend>
                            <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                                <ChoiceCard
                                    name="connect-path"
                                    value="wordpress"
                                    checked={path === 'wordpress'}
                                    onChange={() => setPath('wordpress')}
                                    icon={Globe}
                                    title={t(services ? 'onboarding.wizard.connect.wordpress' : 'onboarding.wizard.connect.woocommerce')}
                                    description={t(services ? 'onboarding.wizard.connect.wordpressHint' : 'onboarding.wizard.connect.woocommerceHint')}
                                />
                                <ChoiceCard
                                    name="connect-path"
                                    value="none"
                                    checked={path === 'none'}
                                    onChange={() => setPath('none')}
                                    icon={FileText}
                                    tone="emerald"
                                    title={t('onboarding.wizard.connect.noWordpress')}
                                    description={t('onboarding.wizard.connect.noWordpressHint')}
                                />
                            </div>
                        </fieldset>
                    ) : null}
                    {path === 'none' ? (
                        <NoWordPress base={base} />
                    ) : canConnect ? (
                        <div className="igr:rounded-2xl igr:border igr:bg-card igr:p-5 igr:sm:p-6">
                            {step?.pending ? (
                                <Callout tone="info" className="igr:mb-5">
                                    {t('onboarding.wizard.connect.pending')}
                                </Callout>
                            ) : null}
                            <ConnectCodePanel workspaceId={workspace.id} waiting store={!services} />
                        </div>
                    ) : (
                        <Callout tone="neutral">{t('onboarding.wizard.connect.ask')}</Callout>
                    )}
                </>
            )}
        </StepFrame>
    );
}

import { ArrowRight, Plug, ShieldCheck, TriangleAlert } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { useMe } from '@/api/queries';
import type { ConnectCode } from '@/api/types';
import { Logo } from '@/components/layout/Logo';
import { FullPageSkeleton, needsTwoFactor } from '@/components/layout/guards';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { api, isApiError } from '@/lib/api';

function hostOf(url: string | null): string | null {
    if (!url) {
        return null;
    }
    try {
        return new URL(url).host.replace(/^www\./, '');
    } catch {
        return null;
    }
}

/**
 * /connect: el plugin manda aquí a la persona (docs/api-connector.md §3.1).
 * Elige el espacio de trabajo, el hub crea un código de un solo uso y el
 * navegador vuelve al sitio con ?igr_code=…&igr_nonce=….
 */
export function ConnectPage() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const [params] = useSearchParams();
    const { data: me } = useMe();
    const site = params.get('site');
    const nonce = params.get('nonce');
    const returnUrl = params.get('return');
    const platform = params.get('platform') === 'wordpress' ? 'wordpress' : 'woocommerce';
    const name = params.get('name');
    const siteHost = hostOf(site);
    const returnHost = hostOf(returnUrl);

    const eligible = useMemo(() => (me?.workspaces ?? []).filter((w) => w.abilities.includes('manageSites')), [me]);
    const [workspaceId, setWorkspaceId] = useState<string>('');
    const selected = eligible.find((w) => w.id === workspaceId) ?? eligible[0] ?? null;
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    if (!me) {
        return <FullPageSkeleton />;
    }

    const self = `/connect?${params.toString()}`;
    const invalid = !siteHost || !returnHost || siteHost !== returnHost || !nonce;

    const connect = async () => {
        if (!selected) {
            return;
        }
        if (needsTwoFactor(me, selected.requires_two_factor)) {
            await navigate(`/seguridad?requerido=1&next=${encodeURIComponent(self)}`);
            return;
        }
        setBusy(true);
        setError(null);
        try {
            const res = await api.post<ConnectCode>(`/api/w/${selected.id}/sites/connect-codes`, {
                site_url: site,
                nonce,
                return_url: returnUrl,
                platform,
                name,
            });
            if (res.redirect_url) {
                window.location.assign(res.redirect_url);
                return;
            }
            setError(t('errors.generic'));
        } catch (e) {
            if (isApiError(e, 'two_factor_required')) {
                await navigate(`/seguridad?requerido=1&next=${encodeURIComponent(self)}`);
                return;
            }
            setError(isApiError(e) && e.message !== '' ? (Object.values(e.fieldErrors)[0] ?? e.message) : t('errors.generic'));
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="igr:min-h-full igr:bg-muted/40 igr:px-4 igr:py-12">
            <div className="igr:mx-auto igr:max-w-lg">
                <div className="igr:mb-6">
                    <Logo />
                </div>
                <Card>
                    <CardHeader>
                        <div className="igr:mb-2 igr:flex igr:size-9 igr:items-center igr:justify-center igr:rounded-lg igr:bg-primary/10">
                            <Plug className="igr:size-4 igr:text-primary" />
                        </div>
                        <CardTitle className="igr:text-base">{t('connect.title', { name: name ?? siteHost ?? '' })}</CardTitle>
                        <CardDescription>{siteHost ? t('connect.description', { host: siteHost }) : t('connect.invalid')}</CardDescription>
                    </CardHeader>
                    <CardContent className="igr:space-y-4">
                        {invalid ? (
                            <div role="alert" className="igr:flex igr:gap-2 igr:rounded-lg igr:border igr:border-destructive/30 igr:bg-destructive/5 igr:p-3 igr:text-[13px]">
                                <TriangleAlert className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-destructive" />
                                <p>{t('connect.invalidLink')}</p>
                            </div>
                        ) : eligible.length === 0 ? (
                            <div className="igr:space-y-3 igr:text-[13px]">
                                <p>{t('connect.noWorkspace')}</p>
                                <Button asChild>
                                    <Link to={`/onboarding?next=${encodeURIComponent(self)}&nombre=${encodeURIComponent(name ?? '')}`}>{t('connect.createWorkspace')}</Link>
                                </Button>
                            </div>
                        ) : (
                            <>
                                <div className="igr:space-y-1.5">
                                    <Label htmlFor="connect-ws">{t('connect.workspace')}</Label>
                                    <Select id="connect-ws" value={selected?.id ?? ''} onChange={(e) => setWorkspaceId(e.target.value)}>
                                        {eligible.map((w) => (
                                            <option key={w.id} value={w.id}>
                                                {w.name}
                                            </option>
                                        ))}
                                    </Select>
                                    <p className="igr:text-xs igr:text-muted-foreground">
                                        {t('connect.orCreate')}{' '}
                                        <Link className="igr:underline igr:underline-offset-2" to={`/onboarding?next=${encodeURIComponent(self)}&nombre=${encodeURIComponent(name ?? '')}`}>
                                            {t('connect.createWorkspace')}
                                        </Link>
                                    </p>
                                </div>
                                <ul className="igr:space-y-1.5 igr:text-[13px] igr:text-muted-foreground">
                                    <li className="igr:flex igr:gap-2">
                                        <ShieldCheck className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-success" />
                                        {t('connect.point1')}
                                    </li>
                                    <li className="igr:flex igr:gap-2">
                                        <ShieldCheck className="igr:mt-0.5 igr:size-4 igr:shrink-0 igr:text-success" />
                                        {t('connect.point2')}
                                    </li>
                                </ul>
                                {error ? (
                                    <p role="alert" className="igr:rounded-md igr:bg-destructive/10 igr:px-3 igr:py-2 igr:text-[13px] igr:text-destructive">
                                        {error}
                                    </p>
                                ) : null}
                            </>
                        )}
                    </CardContent>
                    {!invalid && eligible.length > 0 ? (
                        <CardFooter className="igr:justify-between">
                            <span className="igr:text-xs igr:text-muted-foreground">{t('connect.returnTo', { host: returnHost })}</span>
                            <Button onClick={() => void connect()} loading={busy}>
                                {t('connect.submit')}
                                <ArrowRight />
                            </Button>
                        </CardFooter>
                    ) : null}
                </Card>
            </div>
        </div>
    );
}

import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/misc';
import { Skeleton } from '@/components/ui/skeleton';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { toast } from '@/stores/toast';
import { type Connection, type GoogleAssets, type MetaAssets, type Option, type TikTokAssets, useConnectionAssets, useUseForMeasurement, useUseForSocial } from './api';
import { SelectField } from './bits';

/** Después de autorizar: elegir las cuentas que usa cada función. */
export function AssetsDialog({ connection, onClose }: { connection: Connection | null; onClose: () => void }) {
    const { t } = useTranslation();
    return (
        <Dialog open={connection !== null} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="igr:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{t('connections.pick.title', { provider: connection ? t(`connections.provider.${connection.provider}.name`) : '' })}</DialogTitle>
                    <DialogDescription>{t('connections.pick.description')}</DialogDescription>
                </DialogHeader>
                {connection?.provider === 'google' ? <GooglePicker connection={connection} onDone={onClose} /> : null}
                {connection?.provider === 'meta' ? <MetaPicker connection={connection} onDone={onClose} /> : null}
                {connection?.provider === 'tiktok' ? <TikTokPicker connection={connection} onDone={onClose} /> : null}
            </DialogContent>
        </Dialog>
    );
}

function Loading() {
    return (
        <div className="igr:space-y-2">
            <Skeleton className="igr:h-8" />
            <Skeleton className="igr:h-8" />
        </div>
    );
}

const opt = (list: Option[]) => list.map((o) => ({ value: o.id, label: o.name === o.id ? o.id : `${o.name} (${o.id})` }));

function useSave(onDone: () => void) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const save = useUseForMeasurement(workspace.id);
    return {
        save,
        run: (body: Parameters<typeof save.mutate>[0]) =>
            save.mutate(body, {
                onSuccess: () => {
                    toast.success(t('connections.pick.saved'));
                    onDone();
                },
                onError: (e) => toast.error(t('connections.pick.saveError'), isApiError(e) ? e.message : undefined),
            }),
    };
}

function GooglePicker({ connection, onDone }: { connection: Connection; onDone: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const assets = useConnectionAssets<GoogleAssets>(workspace.id, connection.id);
    const [customer, setCustomer] = useState('');
    const [actions, setActions] = useState({ purchase: '', lead: '', lead_won: '', contact: '' });
    const [property, setProperty] = useState('');
    const [stream, setStream] = useState('');
    const chosen = assets.data?.ads_customers.find((c) => c.id === customer);
    const conversions = useConnectionAssets<{ conversion_actions: (Option & { category: string | null })[] }>(
        workspace.id,
        connection.id,
        chosen ? { customer: chosen.id, ...(chosen.login_customer_id ? { login: chosen.login_customer_id } : {}) } : {},
        chosen !== undefined,
    );
    const streams = useConnectionAssets<{ streams: (Option & { measurement_id: string })[] }>(workspace.id, connection.id, { property }, property !== '');
    const { save, run } = useSave(onDone);

    if (assets.isPending) return <Loading />;
    if (assets.isError) return <ErrorState error={assets.error} onRetry={() => void assets.refetch()} />;
    const data = assets.data;
    const actionOptions = opt(conversions.data?.conversion_actions ?? []);

    return (
        <form
            className="igr:space-y-4"
            onSubmit={(e) => {
                e.preventDefault();
                run({
                    id: connection.id,
                    ads_customer_id: customer || null,
                    ads_login_customer_id: chosen?.login_customer_id ?? null,
                    conversion_action_purchase: actions.purchase || null,
                    conversion_action_lead: actions.lead || null,
                    conversion_action_lead_won: actions.lead_won || null,
                    conversion_action_contact: actions.contact || null,
                    ga4_stream: stream || null,
                });
            }}
        >
            <section className="igr:space-y-3">
                <h3 className="igr:text-[13px] igr:font-semibold">{t('connections.pick.google.ads')}</h3>
                {!data.scopes.ads || !data.scopes.data_manager ? <p className="igr:text-xs igr:text-warning">{t('connections.pick.google.missingAds')}</p> : null}
                <SelectField
                    id="g-customer"
                    label={t('connections.pick.google.customer')}
                    value={customer}
                    onChange={setCustomer}
                    placeholder={t('connections.pick.none')}
                    options={data.ads_customers.filter((c) => !c.manager).map((c) => ({ value: c.id, label: `${c.name} (${c.id})` }))}
                    help={data.ads_customers.length === 0 ? t('connections.pick.google.noCustomers') : undefined}
                />
                {chosen ? (
                    <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                        {(['purchase', 'lead', 'lead_won', 'contact'] as const).map((k) => (
                            <SelectField
                                key={k}
                                id={`g-action-${k}`}
                                label={t(`connections.pick.google.action.${k}`)}
                                value={actions[k]}
                                onChange={(v) => setActions({ ...actions, [k]: v })}
                                placeholder={conversions.isPending ? t('common.loading') : t('connections.pick.none')}
                                options={actionOptions}
                                disabled={conversions.isPending}
                                help={k === 'contact' ? t('connections.pick.google.contactHelp') : undefined}
                            />
                        ))}
                    </div>
                ) : null}
            </section>
            <section className="igr:space-y-3">
                <h3 className="igr:text-[13px] igr:font-semibold">{t('connections.pick.google.analytics')}</h3>
                {!data.scopes.analytics ? <p className="igr:text-xs igr:text-warning">{t('connections.pick.google.missingAnalytics')}</p> : null}
                <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                    <SelectField
                        id="g-property"
                        label={t('connections.pick.google.property')}
                        value={property}
                        onChange={(v) => {
                            setProperty(v);
                            setStream('');
                        }}
                        placeholder={t('connections.pick.none')}
                        options={data.properties.map((p) => ({ value: p.id, label: `${p.name} · ${p.account}` }))}
                    />
                    <SelectField
                        id="g-stream"
                        label={t('connections.pick.google.stream')}
                        value={stream}
                        onChange={setStream}
                        placeholder={streams.isFetching ? t('common.loading') : t('connections.pick.none')}
                        options={(streams.data?.streams ?? []).map((s) => ({ value: s.id, label: `${s.name} (${s.measurement_id})` }))}
                        disabled={property === ''}
                        help={t('connections.pick.google.streamHelp')}
                    />
                </div>
            </section>
            <DialogFooter>
                <Button variant="outline" type="button" onClick={onDone}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" loading={save.isPending} disabled={customer === '' && stream === ''}>
                    {t('connections.pick.save')}
                </Button>
            </DialogFooter>
        </form>
    );
}

function MetaPicker({ connection, onDone }: { connection: Connection; onDone: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const assets = useConnectionAssets<MetaAssets>(workspace.id, connection.id);
    const [dataset, setDataset] = useState('');
    const [account, setAccount] = useState('');
    const { save, run } = useSave(onDone);
    const social = useUseForSocial(workspace.id);

    if (assets.isPending) return <Loading />;
    if (assets.isError) return <ErrorState error={assets.error} onRetry={() => void assets.refetch()} />;
    const data = assets.data;
    const connectPage = (channel: 'messenger' | 'instagram', pageId: string) =>
        social.mutate(
            { id: connection.id, channel, page_id: pageId },
            {
                onSuccess: (r) => {
                    toast.success(t(`connections.pick.meta.${channel}Connected`));
                    if (r.data.last_error) toast.info(r.data.last_error);
                },
                onError: (e) => toast.error(t('connections.pick.saveError'), isApiError(e) ? e.message : undefined),
            },
        );

    return (
        <div className="igr:space-y-4">
            <form
                className="igr:space-y-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    run({ id: connection.id, dataset_id: dataset, ad_account_id: account || null });
                }}
            >
                <h3 className="igr:text-[13px] igr:font-semibold">{t('connections.pick.meta.ads')}</h3>
                <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                    <SelectField
                        id="m-dataset"
                        label={t('connections.pick.meta.dataset')}
                        value={dataset}
                        onChange={(v) => {
                            setDataset(v);
                            const owner = data.datasets.find((d) => d.id === v)?.ad_account_id;
                            if (owner && account === '') setAccount(owner);
                        }}
                        placeholder={t('connections.pick.none')}
                        options={opt(data.datasets)}
                        help={data.datasets.length === 0 ? t('connections.pick.meta.noDatasets') : t('connections.pick.meta.datasetHelp')}
                    />
                    <SelectField
                        id="m-account"
                        label={t('connections.pick.meta.adAccount')}
                        value={account}
                        onChange={setAccount}
                        placeholder={t('connections.pick.none')}
                        options={opt(data.ad_accounts)}
                        help={t('connections.pick.meta.adAccountHelp')}
                    />
                </div>
                <div className="igr:flex igr:justify-end">
                    <Button type="submit" size="sm" loading={save.isPending} disabled={dataset === ''}>
                        {t('connections.pick.save')}
                    </Button>
                </div>
            </form>
            <section className="igr:space-y-2">
                <h3 className="igr:text-[13px] igr:font-semibold">{t('connections.pick.meta.pages')}</h3>
                {data.pages.length === 0 ? <p className="igr:text-xs igr:text-muted-foreground">{t('connections.pick.meta.noPages')}</p> : null}
                <ul className="igr:divide-y igr:rounded-md igr:border">
                    {data.pages.map((p) => (
                        <li key={p.id} className="igr:flex igr:flex-wrap igr:items-center igr:justify-between igr:gap-2 igr:p-2.5 igr:text-[13px]">
                            <span>
                                {p.name}
                                {p.instagram ? <span className="igr:text-muted-foreground"> · @{p.instagram.username ?? p.instagram.id}</span> : null}
                            </span>
                            <span className="igr:flex igr:gap-2">
                                <Button size="sm" variant="outline" loading={social.isPending} onClick={() => connectPage('messenger', p.id)}>
                                    {t('connections.pick.meta.useMessenger')}
                                </Button>
                                {p.instagram ? (
                                    <Button size="sm" variant="outline" loading={social.isPending} onClick={() => connectPage('instagram', p.id)}>
                                        {t('connections.pick.meta.useInstagram')}
                                    </Button>
                                ) : null}
                            </span>
                        </li>
                    ))}
                </ul>
            </section>
            <DialogFooter>
                <Button variant="outline" onClick={onDone}>
                    {t('common.close')}
                </Button>
            </DialogFooter>
        </div>
    );
}

function TikTokPicker({ connection, onDone }: { connection: Connection; onDone: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const assets = useConnectionAssets<TikTokAssets>(workspace.id, connection.id);
    const [advertiser, setAdvertiser] = useState('');
    const [pixel, setPixel] = useState('');
    const pixels = useConnectionAssets<{ pixels: Option[] }>(workspace.id, connection.id, { advertiser }, advertiser !== '');
    const { save, run } = useSave(onDone);

    if (assets.isPending) return <Loading />;
    if (assets.isError) return <ErrorState error={assets.error} onRetry={() => void assets.refetch()} />;

    return (
        <form
            className="igr:space-y-3"
            onSubmit={(e) => {
                e.preventDefault();
                run({ id: connection.id, advertiser_id: advertiser, pixel_code: pixel });
            }}
        >
            <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                <SelectField
                    id="t-advertiser"
                    label={t('connections.pick.tiktok.advertiser')}
                    value={advertiser}
                    onChange={(v) => {
                        setAdvertiser(v);
                        setPixel('');
                    }}
                    placeholder={t('connections.pick.none')}
                    options={opt(assets.data.advertisers)}
                />
                <SelectField
                    id="t-pixel"
                    label={t('connections.pick.tiktok.pixel')}
                    value={pixel}
                    onChange={setPixel}
                    placeholder={pixels.isFetching ? t('common.loading') : t('connections.pick.none')}
                    options={opt(pixels.data?.pixels ?? [])}
                    disabled={advertiser === ''}
                />
            </div>
            <DialogFooter>
                <Button variant="outline" type="button" onClick={onDone}>
                    {t('common.cancel')}
                </Button>
                <Button type="submit" loading={save.isPending} disabled={advertiser === '' || pixel === ''}>
                    {t('connections.pick.save')}
                </Button>
            </DialogFooter>
        </form>
    );
}

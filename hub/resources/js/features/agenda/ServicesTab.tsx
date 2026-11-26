import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input, Select, Textarea } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { EmptyState } from '@/components/ui/misc';
import { Table, TBody, TD, TH, THead, TR } from '@/components/ui/table';
import { useWorkspace } from '@/hooks/useWorkspace';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useAgendaMutations } from './api';
import { money } from './model';
import type { BookingOverview, Location, Service } from './types';

const LOCATIONS: Location[] = ['onsite', 'online', 'phone'];

/** Servicios que se pueden reservar: duración, margen entre citas, precio, lugar y quién atiende. */
export function ServicesTab({ data }: { data: BookingOverview }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { deleteService } = useAgendaMutations(workspace.id);
    const [editing, setEditing] = useState<Service | 'new' | null>(null);
    const [removing, setRemoving] = useState<Service | null>(null);
    const staffName = (id: string) => data.staff.find((s) => s.id === id)?.name ?? '—';

    return (
        <div className="igr:space-y-3">
            <div className="igr:flex igr:flex-wrap igr:items-center igr:justify-between igr:gap-2">
                <p className="igr:text-support igr:text-muted-foreground">{t('agenda.services.help')}</p>
                {data.can_manage ? (
                    <Button size="sm" onClick={() => setEditing('new')}>
                        <Plus />
                        {t('agenda.services.new')}
                    </Button>
                ) : null}
            </div>
            {data.services.length === 0 ? (
                <EmptyState icon={Plus} title={t('agenda.services.empty')} description={t('agenda.services.emptyHelp')} />
            ) : (
                <Table>
                    <THead>
                        <TR>
                            <TH>{t('agenda.services.name')}</TH>
                            <TH>{t('agenda.services.duration')}</TH>
                            <TH>{t('agenda.services.price')}</TH>
                            <TH>{t('agenda.services.location')}</TH>
                            <TH>{t('agenda.services.staff')}</TH>
                            <TH />
                        </TR>
                    </THead>
                    <TBody>
                        {data.services.map((s) => (
                            <TR key={s.id} className={s.active ? '' : 'igr:opacity-60'}>
                                <TD>
                                    <span className="igr:font-medium">{s.name}</span>
                                    {!s.active ? (
                                        <Badge variant="outline" className="igr:ml-2">
                                            {t('agenda.services.inactive')}
                                        </Badge>
                                    ) : null}
                                </TD>
                                <TD className="igr:tabular-nums">
                                    {t('agenda.services.minutes', {
                                        n: s.duration_min,
                                    })}
                                    {s.buffer_min > 0 ? <span className="igr:text-muted-foreground"> + {s.buffer_min}</span> : null}
                                </TD>
                                <TD className="igr:tabular-nums">{money(s.price, workspace.currency)}</TD>
                                <TD>{t(`agenda.location.${s.location}`)}</TD>
                                <TD className="igr:text-muted-foreground">{s.staff_ids.length === 0 ? t('agenda.services.anyone') : s.staff_ids.map(staffName).join(', ')}</TD>
                                <TD className="igr:text-right">
                                    {data.can_manage ? (
                                        <div className="igr:flex igr:justify-end igr:gap-1">
                                            <Button variant="ghost" size="icon" aria-label={t('agenda.edit')} onClick={() => setEditing(s)}>
                                                <Pencil />
                                            </Button>
                                            <Button variant="ghost" size="icon" aria-label={t('agenda.services.delete')} onClick={() => setRemoving(s)}>
                                                <Trash2 />
                                            </Button>
                                        </div>
                                    ) : null}
                                </TD>
                            </TR>
                        ))}
                    </TBody>
                </Table>
            )}
            {editing !== null ? <ServiceDialog data={data} service={editing === 'new' ? null : editing} onClose={() => setEditing(null)} /> : null}
            {removing !== null ? (
                <Dialog open onOpenChange={(open) => (!open ? setRemoving(null) : undefined)}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>{t('agenda.services.deleteTitle', { name: removing.name })}</DialogTitle>
                            <DialogDescription>{t('agenda.services.deleteHelp')}</DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button variant="outline" onClick={() => setRemoving(null)}>
                                {t('common.cancel')}
                            </Button>
                            <Button
                                variant="destructive"
                                loading={deleteService.isPending}
                                onClick={() =>
                                    deleteService.mutate(removing.id, {
                                        onSuccess: () => {
                                            toast.success(t('agenda.services.deleted'));
                                            setRemoving(null);
                                        },
                                        onError: (e) => toast.error(errorMessage(e, t)),
                                    })
                                }
                            >
                                {t('agenda.services.deleteConfirm')}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            ) : null}
        </div>
    );
}

function ServiceDialog({ data, service, onClose }: { data: BookingOverview; service: Service | null; onClose: () => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const { saveService } = useAgendaMutations(workspace.id);
    const [form, setForm] = useState({
        name: service?.name ?? '',
        description: service?.description ?? '',
        duration_min: service?.duration_min ?? 30,
        buffer_min: service?.buffer_min ?? 0,
        price: service?.price ?? '',
        location: service?.location ?? 'onsite',
        staff_ids: service?.staff_ids ?? [],
        min_notice_hours: service?.min_notice_hours ?? 2,
        max_days_ahead: service?.max_days_ahead ?? 60,
        active: service?.active ?? true,
    });
    const set = <K extends keyof typeof form>(k: K, v: (typeof form)[K]) => setForm((f) => ({ ...f, [k]: v }));
    const submit = () =>
        saveService.mutate(
            {
                id: service?.id ?? null,
                data: {
                    ...form,
                    description: form.description.trim() || null,
                    price: form.price === '' ? null : form.price,
                },
            },
            {
                onSuccess: () => {
                    toast.success(t('agenda.services.saved'));
                    onClose();
                },
                onError: (e) => toast.error(errorMessage(e, t)),
            },
        );
    const num = (v: string) => (v === '' ? 0 : Number(v));

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="igr:top-[6dvh] igr:max-h-[88dvh] igr:max-w-lg igr:overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{service ? t('agenda.services.edit') : t('agenda.services.new')}</DialogTitle>
                </DialogHeader>
                <div className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                    <div className="igr:sm:col-span-2">
                        <Label htmlFor="svc-name">{t('agenda.services.name')}</Label>
                        <Input id="svc-name" className="igr:mt-1" value={form.name} maxLength={160} onChange={(e) => set('name', e.target.value)} />
                    </div>
                    <div className="igr:sm:col-span-2">
                        <Label htmlFor="svc-desc">{t('agenda.services.descriptionLabel')}</Label>
                        <Textarea id="svc-desc" className="igr:mt-1" rows={2} value={form.description} maxLength={2000} onChange={(e) => set('description', e.target.value)} />
                    </div>
                    <div>
                        <Label htmlFor="svc-duration">{t('agenda.services.durationLabel')}</Label>
                        <Input id="svc-duration" type="number" min={5} max={600} step={5} className="igr:mt-1" value={form.duration_min} onChange={(e) => set('duration_min', num(e.target.value))} />
                    </div>
                    <div>
                        <Label htmlFor="svc-price">
                            {t('agenda.services.priceLabel', {
                                currency: workspace.currency,
                            })}
                        </Label>
                        <Input
                            id="svc-price"
                            type="number"
                            min={0}
                            className="igr:mt-1"
                            value={form.price}
                            placeholder={t('agenda.services.pricePlaceholder')}
                            onChange={(e) => set('price', e.target.value)}
                        />
                    </div>
                    <div>
                        <Label htmlFor="svc-location">{t('agenda.services.location')}</Label>
                        <Select id="svc-location" className="igr:mt-1" value={form.location} onChange={(e) => set('location', e.target.value as Location)}>
                            {LOCATIONS.map((l) => (
                                <option key={l} value={l}>
                                    {t(`agenda.location.${l}`)}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <details className="igr:group igr:rounded-lg igr:border igr:px-3 igr:py-2 igr:sm:col-span-2">
                        <summary className="igr:cursor-pointer igr:text-[13px] igr:font-medium igr:text-muted-foreground igr:outline-none igr:hover:text-foreground igr:focus-visible:underline">
                            {t('agenda.services.advanced')}
                        </summary>
                        <div className="igr:mt-3 igr:grid igr:gap-3 igr:sm:grid-cols-3">
                            <div>
                                <Label htmlFor="svc-buffer">{t('agenda.services.buffer')}</Label>
                                <Input id="svc-buffer" type="number" min={0} max={240} step={5} className="igr:mt-1" value={form.buffer_min} onChange={(e) => set('buffer_min', num(e.target.value))} />
                            </div>
                            <div>
                                <Label htmlFor="svc-notice">{t('agenda.services.notice')}</Label>
                                <Input id="svc-notice" type="number" min={0} max={720} className="igr:mt-1" value={form.min_notice_hours} onChange={(e) => set('min_notice_hours', num(e.target.value))} />
                            </div>
                            <div>
                                <Label htmlFor="svc-ahead">{t('agenda.services.ahead')}</Label>
                                <Input id="svc-ahead" type="number" min={1} max={365} className="igr:mt-1" value={form.max_days_ahead} onChange={(e) => set('max_days_ahead', num(e.target.value))} />
                            </div>
                        </div>
                    </details>
                    {data.staff.length > 0 ? (
                        <fieldset className="igr:sm:col-span-2">
                            <legend className="igr:text-[13px] igr:font-medium">{t('agenda.services.whoAttends')}</legend>
                            <p className="igr:text-xs igr:text-muted-foreground">{t('agenda.services.whoAttendsHelp')}</p>
                            <div className="igr:mt-1 igr:flex igr:flex-wrap igr:gap-3">
                                {data.staff
                                    .filter((s) => s.active)
                                    .map((s) => (
                                        <label key={s.id} className="igr:flex igr:items-center igr:gap-1.5 igr:text-[13px]">
                                            <input
                                                type="checkbox"
                                                checked={form.staff_ids.includes(s.id)}
                                                onChange={(e) => set('staff_ids', e.target.checked ? [...form.staff_ids, s.id] : form.staff_ids.filter((x) => x !== s.id))}
                                            />
                                            {s.name}
                                        </label>
                                    ))}
                            </div>
                        </fieldset>
                    ) : null}
                    <label className="igr:flex igr:items-center igr:gap-2 igr:text-[13px] igr:sm:col-span-2">
                        <input type="checkbox" checked={form.active} onChange={(e) => set('active', e.target.checked)} />
                        {t('agenda.services.activeLabel')}
                    </label>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {t('common.cancel')}
                    </Button>
                    <Button onClick={submit} loading={saveService.isPending} disabled={form.name.trim() === ''}>
                        {service ? t('agenda.services.saveExisting') : t('agenda.services.create')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

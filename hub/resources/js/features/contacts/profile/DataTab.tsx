import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/components/ui/misc';
import { useWorkspace } from '@/hooks/useWorkspace';
import { isApiError } from '@/lib/api';
import { toast } from '@/stores/toast';
import { useContactAbilities } from '../abilities';
import { type ContactProfile, type ContactUpdate, useUpdateContact } from '../api';
import { errorMessage } from '../errors';

interface AttributeRow {
    key: string;
    value: string;
    original: unknown;
}

const KEY_PATTERN = /^[a-z0-9_]{1,40}$/;

function display(value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }
    if (typeof value === 'string') {
        return value;
    }
    if (typeof value === 'number' || typeof value === 'boolean') {
        return String(value);
    }
    return JSON.stringify(value);
}

/** Atributos personalizados: conserva el tipo original de lo que no se tocó. */
export function buildAttributes(rows: readonly AttributeRow[]): { attributes: Record<string, unknown>; invalid: string[] } {
    const attributes: Record<string, unknown> = {};
    const invalid: string[] = [];
    for (const row of rows) {
        const key = row.key.trim();
        if (key === '' && row.value.trim() === '') {
            continue;
        }
        if (!KEY_PATTERN.test(key) || key in attributes) {
            invalid.push(key === '' ? '·' : key);
            continue;
        }
        attributes[key] = row.value === display(row.original) && row.original !== undefined ? row.original : row.value.trim();
    }
    return { attributes, invalid };
}

export function DataTab({ profile }: { profile: ContactProfile }) {
    const { t } = useTranslation();
    const can = useContactAbilities();
    const { workspace } = useWorkspace();
    const update = useUpdateContact(workspace.id, profile.id);
    const initial = {
        first_name: profile.first_name ?? '',
        last_name: profile.last_name ?? '',
        city: profile.city ?? '',
        region_code: profile.region_code ?? '',
        birthday: profile.birthday ?? '',
        renewal_at: profile.renewal_at ?? '',
    };
    const [fields, setFields] = useState(initial);
    const [rows, setRows] = useState<AttributeRow[]>(() => Object.entries(profile.custom_attributes).map(([key, value]) => ({ key, value: display(value), original: value })));
    const [attrError, setAttrError] = useState<string | null>(null);
    const fieldErrors = isApiError(update.error) ? update.error.fieldErrors : {};
    const readOnly = !can.edit;

    const save = () => {
        const { attributes, invalid } = buildAttributes(rows);
        if (invalid.length > 0) {
            setAttrError(t('contacts.data.invalidKeys', { keys: invalid.join(', ') }));
            return;
        }
        setAttrError(null);
        const data: ContactUpdate = {
            first_name: fields.first_name.trim() || null,
            last_name: fields.last_name.trim() || null,
            city: fields.city.trim() || null,
            region_code: fields.region_code.trim() || null,
            birthday: fields.birthday || null,
            renewal_at: fields.renewal_at || null,
            custom_attributes: attributes,
        };
        update.mutate(data, {
            onSuccess: () => toast.success(t('contacts.data.saved')),
            onError: (error) => toast.error(errorMessage(error, t)),
        });
    };

    const text = (name: keyof typeof initial, label: string, type = 'text') => (
        <div className="igr:space-y-1.5">
            <Label htmlFor={`contact-${name}`}>{label}</Label>
            <Input
                id={`contact-${name}`}
                type={type}
                value={fields[name]}
                readOnly={readOnly}
                aria-invalid={fieldErrors[name] ? true : undefined}
                onChange={(e) => setFields({ ...fields, [name]: e.target.value })}
            />
            <FieldError message={fieldErrors[name]} />
        </div>
    );

    return (
        <form
            className="igr:space-y-4"
            onSubmit={(e) => {
                e.preventDefault();
                save();
            }}
        >
            <Card>
                <CardHeader>
                    <CardTitle>{t('contacts.data.personal')}</CardTitle>
                    <CardDescription>{t('contacts.data.personalHelp')}</CardDescription>
                </CardHeader>
                <CardContent className="igr:grid igr:gap-3 igr:sm:grid-cols-2">
                    {text('first_name', t('contacts.data.firstName'))}
                    {text('last_name', t('contacts.data.lastName'))}
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="contact-email">{t('contacts.data.email')}</Label>
                        <Input id="contact-email" value={profile.email ?? '—'} readOnly disabled />
                    </div>
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="contact-phone">{t('contacts.data.phone')}</Label>
                        <Input id="contact-phone" value={profile.phone ?? '—'} readOnly disabled />
                    </div>
                    {text('city', t('contacts.data.city'))}
                    {text('region_code', t('contacts.data.region'))}
                    <div className="igr:space-y-1.5">
                        <Label htmlFor="contact-country">{t('contacts.data.country')}</Label>
                        <Input id="contact-country" value={profile.country ? t(`countries.${profile.country}`, { defaultValue: profile.country }) : '—'} readOnly disabled />
                    </div>
                    {text('birthday', t('contacts.data.birthday'), 'date')}
                    {text('renewal_at', t('contacts.data.renewalAt'), 'date')}
                    <p className="igr:text-xs igr:text-muted-foreground igr:sm:col-span-2">{t('contacts.data.identifiersHelp')}</p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{t('contacts.data.custom')}</CardTitle>
                    <CardDescription>{t('contacts.data.customHelp')}</CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-2">
                    {rows.length === 0 && <p className="igr:text-xs igr:text-muted-foreground">{t('contacts.data.noCustom')}</p>}
                    {rows.map((row, i) => (
                        <div key={i} className="igr:flex igr:items-center igr:gap-2">
                            <Input
                                aria-label={t('contacts.data.key')}
                                placeholder={t('contacts.data.keyPlaceholder')}
                                className="igr:w-48 igr:font-mono igr:text-xs"
                                value={row.key}
                                readOnly={readOnly}
                                onChange={(e) => setRows(rows.map((r, j) => (j === i ? { ...r, key: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_') } : r)))}
                            />
                            <Input
                                aria-label={t('contacts.data.value')}
                                placeholder={t('contacts.data.valuePlaceholder')}
                                value={row.value}
                                readOnly={readOnly}
                                onChange={(e) => setRows(rows.map((r, j) => (j === i ? { ...r, value: e.target.value } : r)))}
                            />
                            {!readOnly && (
                                <Button type="button" variant="ghost" size="icon" aria-label={t('contacts.data.removeAttribute')} onClick={() => setRows(rows.filter((_, j) => j !== i))}>
                                    <Trash2 />
                                </Button>
                            )}
                        </div>
                    ))}
                    <FieldError message={attrError ?? fieldErrors.custom_attributes} />
                    {!readOnly && (
                        <Button type="button" variant="ghost" size="sm" onClick={() => setRows([...rows, { key: '', value: '', original: undefined }])}>
                            <Plus />
                            {t('contacts.data.addAttribute')}
                        </Button>
                    )}
                </CardContent>
                {!readOnly && (
                    <CardFooter className="igr:justify-end">
                        <Button type="submit" loading={update.isPending}>
                            {t('common.save')}
                        </Button>
                    </CardFooter>
                )}
            </Card>
        </form>
    );
}

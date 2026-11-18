import { FileUp } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSites } from '@/api/queries';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input, Select } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useWorkspace } from '@/hooks/useWorkspace';
import { toast } from '@/stores/toast';
import { errorMessage } from '@/features/contacts/errors';
import { useImportReviews } from './api';
import type { ImportResult } from './types';

/** Importar reseñas de un CSV o de Judge.me. Las de WooCommerce llegan solas. */
export function ImportTab() {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const sites = useSites(workspace.id);
    const run = useImportReviews(workspace.id);
    const [file, setFile] = useState<File | null>(null);
    const [format, setFormat] = useState<'csv' | 'judgeme'>('csv');
    const [siteId, setSiteId] = useState('');
    const [result, setResult] = useState<ImportResult | null>(null);

    const submit = () => {
        if (file === null) return;
        run.mutate(
            { file, format, siteId: siteId === '' ? null : siteId },
            {
                onSuccess: (r) => {
                    setResult(r);
                    toast.success(t('reviews.import.done', { count: r.created }));
                },
                onError: (e) => toast.error(errorMessage(e, t)),
            },
        );
    };

    return (
        <div className="igr:grid igr:items-start igr:gap-4 igr:lg:grid-cols-[minmax(0,1fr)_360px]">
            <Card>
                <CardHeader>
                    <CardTitle>{t('reviews.import.title')}</CardTitle>
                    <CardDescription>{t('reviews.import.description')}</CardDescription>
                </CardHeader>
                <CardContent className="igr:space-y-4">
                    <div>
                        <Label htmlFor="rv-format">{t('reviews.import.format')}</Label>
                        <Select id="rv-format" className="igr:mt-1.5" value={format} onChange={(e) => setFormat(e.target.value as 'csv' | 'judgeme')}>
                            <option value="csv">{t('reviews.import.formats.csv')}</option>
                            <option value="judgeme">{t('reviews.import.formats.judgeme')}</option>
                        </Select>
                    </div>
                    {(sites.data ?? []).length > 1 ? (
                        <div>
                            <Label htmlFor="rv-site">{t('reviews.import.site')}</Label>
                            <Select id="rv-site" className="igr:mt-1.5" value={siteId} onChange={(e) => setSiteId(e.target.value)}>
                                <option value="">{t('reviews.import.firstSite')}</option>
                                {(sites.data ?? []).map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name ?? s.url}
                                    </option>
                                ))}
                            </Select>
                        </div>
                    ) : null}
                    <div>
                        <Label htmlFor="rv-file">{t('reviews.import.file')}</Label>
                        <Input id="rv-file" className="igr:mt-1.5" type="file" accept=".csv,text/csv" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
                        <p className="igr:mt-1 igr:text-xs igr:text-muted-foreground">{t('reviews.import.fileHelp')}</p>
                    </div>
                    <Button onClick={submit} disabled={file === null} loading={run.isPending}>
                        <FileUp aria-hidden />
                        {t('reviews.import.submit')}
                    </Button>
                    {result ? (
                        <div className="igr:rounded-md igr:border igr:p-3 igr:text-[13px]" aria-live="polite">
                            <p className="igr:font-medium">{t('reviews.import.result', { created: result.created, duplicates: result.duplicates, rows: result.rows })}</p>
                            {result.errors.length > 0 ? (
                                <ul className="igr:mt-2 igr:max-h-48 igr:list-disc igr:overflow-auto igr:pl-5 igr:text-muted-foreground">
                                    {result.errors.map((e) => (
                                        <li key={`${e.row}-${e.message}`}>{t('reviews.import.rowError', { row: e.row, message: e.message })}</li>
                                    ))}
                                </ul>
                            ) : null}
                        </div>
                    ) : null}
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>{t('reviews.import.columnsTitle')}</CardTitle>
                    <CardDescription>{t('reviews.import.columnsDescription')}</CardDescription>
                </CardHeader>
                <CardContent>
                    <ul className="igr:space-y-1 igr:text-[13px]">
                        {(['product', 'rating', 'title', 'body', 'author', 'email', 'date', 'photos', 'reply'] as const).map((c) => (
                            <li key={c}>
                                <code className="igr:rounded igr:bg-muted igr:px-1 igr:text-xs">{t(`reviews.import.columns.${c}.name`)}</code> {t(`reviews.import.columns.${c}.help`)}
                            </li>
                        ))}
                    </ul>
                    <p className="igr:mt-3 igr:text-xs igr:text-muted-foreground">{t('reviews.import.privacy')}</p>
                </CardContent>
            </Card>
        </div>
    );
}

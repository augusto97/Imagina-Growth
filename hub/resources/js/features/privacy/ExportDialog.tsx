import { Download, FileJson } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/misc';
import { useWorkspace } from '@/hooks/useWorkspace';
import { downloadUrl, useRunDataRequest } from './api';

/** Derecho de acceso: genera la exportación de los datos de la persona y ofrece descargarla. */
export function ExportDialog({ contactId, open, onOpenChange }: { contactId: string; open: boolean; onOpenChange: (open: boolean) => void }) {
    const { t } = useTranslation();
    const { workspace } = useWorkspace();
    const run = useRunDataRequest(workspace.id, contactId);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    run.reset();
                }
                onOpenChange(next);
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('privacy.export.title')}</DialogTitle>
                    <DialogDescription>{t('privacy.export.description')}</DialogDescription>
                </DialogHeader>
                {run.isError && <ErrorState error={run.error} />}
                {run.isSuccess ? (
                    <div className="igr:flex igr:items-center igr:gap-3 igr:rounded-md igr:border igr:bg-muted/40 igr:p-3">
                        <FileJson className="igr:size-5 igr:text-muted-foreground" />
                        <div className="igr:min-w-0 igr:flex-1 igr:text-[13px]">
                            <p className="igr:font-medium">{t('privacy.export.ready')}</p>
                            <p className="igr:text-xs igr:text-muted-foreground">{t('privacy.export.readyHelp')}</p>
                        </div>
                        <Button size="sm" asChild>
                            <a href={downloadUrl(workspace.id, run.data.id)} download>
                                <Download />
                                {t('privacy.requests.download')}
                            </a>
                        </Button>
                    </div>
                ) : (
                    <p className="igr:text-[13px] igr:text-muted-foreground">{t('privacy.export.audit')}</p>
                )}
                <DialogFooter>
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        {t('common.close')}
                    </Button>
                    {!run.isSuccess && (
                        <Button loading={run.isPending} onClick={() => run.mutate({ kind: 'access' })}>
                            {t('privacy.export.generate')}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

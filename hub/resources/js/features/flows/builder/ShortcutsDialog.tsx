import { useTranslation } from 'react-i18next';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Kbd } from '@/components/ui/kbd';
import { IS_MAC, MOD } from './CanvasToolbar';

/** Atajos del constructor. Las claves de texto están en flows.canvas.keys.* */
export const SHORTCUTS: { keys: string[][]; label: string }[] = [
    { keys: [[MOD, 'S']], label: 'save' },
    { keys: [[MOD, 'Z']], label: 'undo' },
    {
        keys: IS_MAC
            ? [['⌘', '⇧', 'Z']]
            : [
                  ['Ctrl', 'Y'],
                  ['Ctrl', '⇧', 'Z'],
              ],
        label: 'redo',
    },
    { keys: [['Tab'], ['Enter']], label: 'select' },
    { keys: [['←', '↑', '→', '↓']], label: 'move' },
    { keys: [[IS_MAC ? '⌫' : 'Supr']], label: 'delete' },
    { keys: [['Esc']], label: 'close' },
    { keys: [['+'], ['−']], label: 'zoom' },
    { keys: [['0']], label: 'fit' },
    { keys: [['O']], label: 'arrange' },
    { keys: [['P']], label: 'palette' },
    { keys: [['M']], label: 'minimap' },
    { keys: [['F']], label: 'fullscreen' },
    { keys: [['?']], label: 'help' },
];

export function ShortcutsDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    const { t } = useTranslation();
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('flows.canvas.shortcuts')}</DialogTitle>
                    <DialogDescription>{t('flows.canvas.shortcutsHelp')}</DialogDescription>
                </DialogHeader>
                <dl className="igr:divide-y igr:text-[13px]">
                    {SHORTCUTS.map((s) => (
                        <div key={s.label} className="igr:flex igr:items-center igr:justify-between igr:gap-4 igr:py-1.5">
                            <dt>{t(`flows.canvas.keys.${s.label}`)}</dt>
                            <dd className="igr:flex igr:shrink-0 igr:items-center igr:gap-1.5 igr:text-muted-foreground">
                                {s.keys.map((combo, i) => (
                                    <span key={combo.join('+')} className="igr:inline-flex igr:items-center igr:gap-1">
                                        {i > 0 ? <span className="igr:text-[11px]">{t('flows.canvas.or')}</span> : null}
                                        {combo.map((k) => (
                                            <Kbd key={k} className="igr:h-6 igr:min-w-6 igr:text-[11px]">
                                                {k}
                                            </Kbd>
                                        ))}
                                    </span>
                                ))}
                            </dd>
                        </div>
                    ))}
                </dl>
            </DialogContent>
        </Dialog>
    );
}

import { Download } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';

/** El zip del plugin de esta misma versión del hub (lo agrega infra/release/build.sh a public/downloads). */
export const PLUGIN_DOWNLOAD_URL = '/downloads/imagina-growth-connector.zip';

export function PluginDownloadButton({ variant = 'outline', size = 'md' }: { variant?: 'outline' | 'default' | 'secondary'; size?: 'sm' | 'md' }) {
    const { t } = useTranslation();
    return (
        <Button variant={variant} size={size} asChild>
            <a href={PLUGIN_DOWNLOAD_URL} download>
                <Download />
                {t('sites.downloadPlugin')}
            </a>
        </Button>
    );
}

import { useChannels } from '@/features/channels/api';
import { TemplateAsTextNote } from '@/features/channels/CapabilityNotes';
import { useWorkspace } from '@/hooks/useWorkspace';

/**
 * En una plantilla de WhatsApp: si hay un número activo sin plantillas de Meta (Imagina Whats, Zender), cómo sale por
 * él (texto, botones escritos o numerados, la imagen aparte o sin ella). Con solo el WhatsApp oficial no muestra nada.
 */
export function UnofficialWaNote() {
    const { workspace } = useWorkspace();
    const channels = useChannels(workspace.id);
    const account = (channels.data?.data ?? []).find((a) => a.channel === 'whatsapp' && a.status === 'active' && !a.capabilities.templates);
    return account ? <TemplateAsTextNote caps={account.capabilities} name={account.display_name} /> : null;
}

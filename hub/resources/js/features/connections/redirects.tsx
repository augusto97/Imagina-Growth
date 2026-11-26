import { Navigate, useParams, useSearchParams } from 'react-router';
import { useWorkspace } from '@/hooks/useWorkspace';
import { cardFromParam, channelsTabTarget } from './hub';

/*
 * Las pantallas de antes de Conexiones única (rediseño v2 §3) siguen funcionando: llevan a Conexiones con su tarjeta
 * abierta y conservan lo que venga en la URL (`conectar=1` de «Conectar tu web», la vuelta del consentimiento de Meta,
 * Google o TikTok, `?conexion=…&estado=…`).
 */

function hubUrl(ws: string, params: URLSearchParams, card: string | null): string {
    const p = new URLSearchParams(params);
    p.delete('tab');
    if (card !== null) p.set('tarjeta', card);
    const query = p.toString();
    return `/w/${ws}/conexiones${query === '' ? '' : `?${query}`}`;
}

/** `sitios` (y `sitios?conectar=1`) → la tarjeta de tu web. El detalle `sitios/:id` sigue en su pantalla. */
export function SitesRedirect() {
    const { workspace } = useWorkspace();
    const [params] = useSearchParams();
    return <Navigate replace to={hubUrl(workspace.id, params, 'web')} />;
}

/** `canales?tab=…` → la tarjeta del canal; la lista de supresión vive ahora en Privacidad y consentimiento. */
export function ChannelsRedirect() {
    const { workspace } = useWorkspace();
    const [params] = useSearchParams();
    const target = channelsTabTarget(params.get('tab'));
    if (target.to === 'suppressions') {
        return <Navigate replace to={`/w/${workspace.id}/consentimiento?seccion=supresion`} />;
    }
    return <Navigate replace to={hubUrl(workspace.id, params, target.to === 'card' ? target.card : null)} />;
}

/** `conexiones/whatsapp`, `conexiones/correo`… → la tarjeta abierta (lo desconocido, al centro de conexiones). */
export function CardRedirect() {
    const { workspace } = useWorkspace();
    const { card } = useParams();
    const [params] = useSearchParams();
    return <Navigate replace to={hubUrl(workspace.id, params, cardFromParam(card))} />;
}

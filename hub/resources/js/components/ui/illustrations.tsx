import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

/*
 * Ilustraciones propias y livianas (rediseño v2 §7): SVG en línea de unos cientos de bytes, sin imágenes ni
 * dependencias, pintadas con los tokens del tema (se ven bien en claro y en oscuro). Son decorativas: el título del
 * estado vacío dice lo que importa, así que van con aria-hidden.
 *
 * Para sumar una: una función más en ILLUSTRATIONS con el mismo lienzo (200 × 140), el fondo `Backdrop` y como
 * mucho tres colores además de los neutros (marca, violeta y uno de apoyo).
 */

export type IllustrationName = 'web' | 'messages' | 'customers' | 'results' | 'strategies' | 'reviews' | 'points' | 'conversations' | 'celebration' | 'search' | 'locked' | 'plan';

const card = 'igr:fill-card igr:stroke-border';
const line = 'igr:fill-muted';
const brand = 'igr:fill-primary';
const violet = 'igr:fill-brand-2';

/** Fondo común: un óvalo tenue de marca y la sombra del piso. */
function Backdrop() {
    return (
        <>
            <ellipse cx="100" cy="70" rx="78" ry="58" className="igr:fill-brand-soft" />
            <ellipse cx="100" cy="128" rx="54" ry="5" className="igr:fill-foreground/5" />
        </>
    );
}

function Sparkle({ x, y, s = 1, className = 'igr:fill-brand-2' }: { x: number; y: number; s?: number; className?: string }) {
    return <path transform={`translate(${x} ${y}) scale(${s})`} d="M0-6C.6-2 2-.6 6 0 2 .6.6 2 0 6-.6 2-2 .6-6 0-2-.6-.6-2 0-6Z" className={className} />;
}

const ILLUSTRATIONS: Record<IllustrationName, () => ReactNode> = {
    // Tu web conectada: una ventana del navegador enchufada a la plataforma.
    web: () => (
        <>
            <Backdrop />
            <rect x="34" y="30" width="92" height="70" rx="8" strokeWidth="1.5" className={card} />
            <rect x="34" y="30" width="92" height="14" rx="8" className={line} />
            <circle cx="43" cy="37" r="2" className="igr:fill-rose-400" />
            <circle cx="50" cy="37" r="2" className="igr:fill-amber-400" />
            <circle cx="57" cy="37" r="2" className="igr:fill-emerald-400" />
            <rect x="44" y="54" width="40" height="6" rx="3" className={line} />
            <rect x="44" y="66" width="62" height="5" rx="2.5" className={line} />
            <rect x="44" y="77" width="30" height="12" rx="4" className={brand} />
            <path d="M126 70h14a8 8 0 0 1 8 8v4" fill="none" strokeWidth="3" strokeLinecap="round" className="igr:stroke-primary" strokeDasharray="1 7" />
            <circle cx="152" cy="94" r="16" className={brand} />
            <path d="M146 94l4 4 8-8" fill="none" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" className="igr:stroke-primary-foreground" />
            <Sparkle x={170} y={70} />
        </>
    ),
    // Mensajes: un correo y un WhatsApp que salen solos.
    messages: () => (
        <>
            <Backdrop />
            <rect x="40" y="40" width="80" height="56" rx="8" strokeWidth="1.5" className={card} />
            <path d="M42 44l38 28 38-28" fill="none" strokeWidth="1.5" className="igr:stroke-border" />
            <rect x="96" y="62" width="70" height="34" rx="12" className={brand} />
            <path d="M104 96l-6 10 16-10Z" className={brand} />
            <rect x="106" y="72" width="44" height="5" rx="2.5" className="igr:fill-primary-foreground/80" />
            <rect x="106" y="82" width="28" height="5" rx="2.5" className="igr:fill-primary-foreground/50" />
            <path d="M150 30l20-8-8 20-4-8-8-4Z" className={violet} />
            <Sparkle x={34} y={34} s={0.8} />
        </>
    ),
    // Clientes: tres personas, una destacada.
    customers: () => (
        <>
            <Backdrop />
            <circle cx="62" cy="62" r="12" className="igr:fill-muted-foreground/30" />
            <path d="M40 100a22 22 0 0 1 44 0Z" className="igr:fill-muted-foreground/30" />
            <circle cx="138" cy="62" r="12" className="igr:fill-muted-foreground/30" />
            <path d="M116 100a22 22 0 0 1 44 0Z" className="igr:fill-muted-foreground/30" />
            <circle cx="100" cy="52" r="16" className={brand} />
            <path d="M70 106a30 30 0 0 1 60 0Z" className={brand} />
            <circle cx="124" cy="36" r="10" className={violet} />
            <path d="M120 36l3 3 6-6" fill="none" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" className="igr:stroke-primary-foreground" />
        </>
    ),
    // Resultados: barras que suben y la línea de la tendencia.
    results: () => (
        <>
            <Backdrop />
            <rect x="38" y="28" width="124" height="84" rx="10" strokeWidth="1.5" className={card} />
            <rect x="54" y="78" width="14" height="22" rx="3" className="igr:fill-primary/30" />
            <rect x="76" y="66" width="14" height="34" rx="3" className="igr:fill-primary/45" />
            <rect x="98" y="58" width="14" height="42" rx="3" className="igr:fill-primary/65" />
            <rect x="120" y="44" width="14" height="56" rx="3" className={brand} />
            <path d="M56 70l22-12 22 4 30-22" fill="none" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" className="igr:stroke-brand-2" />
            <circle cx="130" cy="40" r="4" className={violet} />
            <circle cx="160" cy="30" r="11" className="igr:fill-amber-400" />
            <path d="M160 24v12M157 27h5a2 2 0 0 1 0 4h-4a2 2 0 0 0 0 4h5" fill="none" strokeWidth="1.6" strokeLinecap="round" className="igr:stroke-amber-900" />
        </>
    ),
    // Estrategias: interruptores que se prenden y trabajan solos.
    strategies: () => (
        <>
            <Backdrop />
            <rect x="44" y="30" width="112" height="80" rx="10" strokeWidth="1.5" className={card} />
            {[44, 66, 88].map((y, i) => (
                <g key={y}>
                    <rect x="58" y={y} width="44" height="6" rx="3" className={line} />
                    <rect x="120" y={y - 4} width="24" height="14" rx="7" className={i < 2 ? brand : 'igr:fill-muted'} />
                    <circle cx={i < 2 ? 137 : 127} cy={y + 3} r="5" className="igr:fill-card" />
                </g>
            ))}
            <path d="M154 22l6 6m4-12v8m10 2h-8" fill="none" strokeWidth="2.5" strokeLinecap="round" className="igr:stroke-brand-2" />
            <Sparkle x={38} y={100} />
        </>
    ),
    // Reseñas: una tarjeta con estrellas y foto.
    reviews: () => (
        <>
            <Backdrop />
            <rect x="40" y="34" width="120" height="72" rx="10" strokeWidth="1.5" className={card} />
            <rect x="52" y="46" width="30" height="30" rx="6" className="igr:fill-brand-soft" />
            <path d="M54 74l9-10 7 7 4-4 6 7Z" className="igr:fill-primary/60" />
            <circle cx="74" cy="54" r="3" className="igr:fill-amber-400" />
            {[92, 104, 116, 128, 140].map((x) => (
                <path key={x} transform={`translate(${x} 52)`} d="M0-5l1.5 3.2 3.5.4-2.6 2.4.7 3.5L0 2.8-3.1 4.5l.7-3.5L-5-1.4l3.5-.4Z" className="igr:fill-amber-400" />
            ))}
            <rect x="90" y="64" width="56" height="5" rx="2.5" className={line} />
            <rect x="90" y="74" width="40" height="5" rx="2.5" className={line} />
            <rect x="52" y="86" width="94" height="8" rx="4" className="igr:fill-muted/70" />
            <Sparkle x={166} y={36} />
        </>
    ),
    // Puntos: un regalo y una moneda de puntos.
    points: () => (
        <>
            <Backdrop />
            <rect x="56" y="62" width="64" height="44" rx="6" className={brand} />
            <rect x="50" y="50" width="76" height="16" rx="5" className={violet} />
            <rect x="84" y="50" width="8" height="56" className="igr:fill-primary-foreground/70" />
            <path d="M88 50c-12-14-26-6-18 0Zm0 0c12-14 26-6 18 0Z" fill="none" strokeWidth="3" strokeLinejoin="round" className="igr:stroke-brand-2" />
            <circle cx="146" cy="56" r="18" className="igr:fill-amber-400" />
            <circle cx="146" cy="56" r="12" fill="none" strokeWidth="2" className="igr:stroke-amber-600" />
            <path transform="translate(146 56)" d="M0-6l1.8 3.8 4.2.5-3.1 2.9.8 4.2L0 3.3-3.7 5.4l.8-4.2-3.1-2.9 4.2-.5Z" className="igr:fill-amber-700" />
            <Sparkle x={40} y={40} />
        </>
    ),
    // Conversaciones: un celular con un chat.
    conversations: () => (
        <>
            <Backdrop />
            <rect x="70" y="20" width="60" height="104" rx="12" strokeWidth="1.5" className={card} />
            <rect x="92" y="26" width="16" height="4" rx="2" className={line} />
            <rect x="78" y="40" width="34" height="14" rx="7" className={line} />
            <rect x="88" y="60" width="34" height="14" rx="7" className={brand} />
            <rect x="78" y="80" width="28" height="14" rx="7" className={line} />
            <rect x="96" y="100" width="26" height="12" rx="6" className={violet} />
            <rect x="136" y="44" width="40" height="24" rx="10" className="igr:fill-emerald-500" />
            <path d="M144 68l-4 8 12-8Z" className="igr:fill-emerald-500" />
            <circle cx="148" cy="56" r="2.5" className="igr:fill-white" />
            <circle cx="156" cy="56" r="2.5" className="igr:fill-white" />
            <circle cx="164" cy="56" r="2.5" className="igr:fill-white" />
        </>
    ),
    // Celebración: un sello de listo con confeti.
    celebration: () => (
        <>
            <Backdrop />
            <circle cx="100" cy="70" r="30" className={brand} />
            <circle cx="100" cy="70" r="38" fill="none" strokeWidth="2" className="igr:stroke-primary/25" />
            <path d="M88 70l8 8 16-16" fill="none" strokeWidth="5" strokeLinecap="round" strokeLinejoin="round" className="igr:stroke-primary-foreground" />
            <rect x="44" y="34" width="8" height="4" rx="2" transform="rotate(-30 48 36)" className="igr:fill-amber-400" />
            <rect x="150" y="30" width="8" height="4" rx="2" transform="rotate(30 154 32)" className="igr:fill-rose-400" />
            <rect x="156" y="96" width="8" height="4" rx="2" transform="rotate(-20 160 98)" className="igr:fill-emerald-400" />
            <rect x="38" y="96" width="8" height="4" rx="2" transform="rotate(25 42 98)" className={violet} />
            <circle cx="62" cy="22" r="3" className="igr:fill-sky-400" />
            <circle cx="140" cy="116" r="3" className="igr:fill-amber-400" />
            <Sparkle x={164} y={58} />
            <Sparkle x={34} y={66} s={0.8} className="igr:fill-amber-400" />
        </>
    ),
    // Buscar: una lupa sobre una lista vacía.
    search: () => (
        <>
            <Backdrop />
            <rect x="46" y="32" width="88" height="76" rx="10" strokeWidth="1.5" className={card} />
            <rect x="58" y="46" width="48" height="6" rx="3" className={line} />
            <rect x="58" y="60" width="64" height="6" rx="3" className={line} />
            <rect x="58" y="74" width="36" height="6" rx="3" className={line} />
            <circle cx="128" cy="82" r="18" strokeWidth="6" className="igr:fill-card igr:stroke-primary" />
            <path d="M141 95l14 14" strokeWidth="8" strokeLinecap="round" className="igr:stroke-primary" />
        </>
    ),
    // Bloqueado: un candado amable.
    locked: () => (
        <>
            <Backdrop />
            <path d="M80 62V50a20 20 0 0 1 40 0v12" fill="none" strokeWidth="8" strokeLinecap="round" className="igr:stroke-muted-foreground/40" />
            <rect x="68" y="60" width="64" height="52" rx="12" className={brand} />
            <circle cx="100" cy="82" r="6" className="igr:fill-primary-foreground" />
            <rect x="97" y="84" width="6" height="14" rx="3" className="igr:fill-primary-foreground" />
            <Sparkle x={146} y={44} />
            <Sparkle x={52} y={92} s={0.7} />
        </>
    ),
    // Plan: una insignia con estrella (módulo de un plan superior).
    plan: () => (
        <>
            <Backdrop />
            <path d="M100 24l34 14v26c0 22-14 38-34 46-20-8-34-24-34-46V38Z" className={brand} />
            <path d="M100 24l34 14v26c0 22-14 38-34 46Z" className="igr:fill-brand-2/60" />
            <path transform="translate(100 64)" d="M0-14l4.2 8.6 9.4 1.2-6.9 6.5 1.8 9.3L0 7.1-8.5 11.6l1.8-9.3-6.9-6.5 9.4-1.2Z" className="igr:fill-primary-foreground" />
            <Sparkle x={148} y={34} />
            <Sparkle x={52} y={100} s={0.8} className="igr:fill-amber-400" />
        </>
    ),
};

export const ILLUSTRATION_NAMES = Object.keys(ILLUSTRATIONS) as IllustrationName[];

/** Ilustración por nombre. Decorativa (aria-hidden); el tamaño lo da la clase (ancho), el alto sigue la proporción. */
export function Illustration({ name, className }: { name: IllustrationName; className?: string }) {
    const draw = ILLUSTRATIONS[name];
    return (
        <svg viewBox="0 0 200 140" aria-hidden focusable="false" className={cn('igr:h-auto igr:w-40', className)}>
            {draw()}
        </svg>
    );
}

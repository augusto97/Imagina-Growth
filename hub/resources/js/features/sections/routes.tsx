import type { RouteObject } from 'react-router';
import { SECTIONS } from '@/components/layout/nav';

/*
 * Páginas de entrada de las secciones con subnavegación (docs/diseno/rediseno-v2.md §3: clientes, conversaciones,
 * mensajes, en-tu-web, resultados, configuracion). Son rutas nuevas: las pantallas de cada sección siguen en sus URL de
 * siempre. Salen de `SECTIONS`; la página sabe qué sección mostrar por la URL (`usePageContext`).
 */
const page = async () => ({ Component: (await import('./SectionHomePage')).SectionHomePage });

/** Secciones cuya entrada es una pantalla propia y no la página de tarjetas: Resultados (el Resumen, features/analytics). */
const OWN_ENTRY = ['results'];

export const workspaceRoutes: RouteObject[] = SECTIONS.filter((s) => s.items.length > 0 && !OWN_ENTRY.includes(s.key)).map((s) => ({ path: s.to, lazy: page }));

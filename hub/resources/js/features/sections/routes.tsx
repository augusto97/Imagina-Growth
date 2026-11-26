import type { RouteObject } from 'react-router';
import { SECTIONS } from '@/components/layout/nav';

/*
 * Páginas de entrada de las secciones con subnavegación (docs/diseno/rediseno-v2.md §3: clientes, conversaciones,
 * mensajes, en-tu-web, resultados, configuracion). Son rutas nuevas: las pantallas de cada sección siguen en sus URL de
 * siempre. Salen de `SECTIONS`; la página sabe qué sección mostrar por la URL (`usePageContext`).
 */
const page = async () => ({ Component: (await import('./SectionHomePage')).SectionHomePage });

export const workspaceRoutes: RouteObject[] = SECTIONS.filter((s) => s.items.length > 0).map((s) => ({ path: s.to, lazy: page }));

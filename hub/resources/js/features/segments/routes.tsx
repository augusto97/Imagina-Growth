import type { RouteObject } from 'react-router';

/** Segmentos (M04): lista con carpetas, constructor visual, detalle con miembros y exportación. */
export const workspaceRoutes: RouteObject[] = [
    { path: 'segmentos', lazy: async () => ({ Component: (await import('./SegmentsPage')).SegmentsPage }) },
    { path: 'segmentos/nuevo', lazy: async () => ({ Component: (await import('./SegmentBuilderPage')).SegmentBuilderPage }) },
    { path: 'segmentos/:segmentId', lazy: async () => ({ Component: (await import('./SegmentDetailPage')).SegmentDetailPage }) },
    { path: 'segmentos/:segmentId/editar', lazy: async () => ({ Component: (await import('./SegmentBuilderPage')).SegmentBuilderPage }) },
];

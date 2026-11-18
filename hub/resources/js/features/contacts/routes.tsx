import type { RouteObject } from 'react-router';

/* Contactos (M03): lista, importación CSV y perfil 360. */
export const workspaceRoutes: RouteObject[] = [
    { path: 'contactos', lazy: async () => ({ Component: (await import('./ContactsPage')).ContactsPage }) },
    { path: 'contactos/importar', lazy: async () => ({ Component: (await import('./import/ImportPage')).ImportPage }) },
    { path: 'contactos/importar/:importId', lazy: async () => ({ Component: (await import('./import/ImportStatusPage')).ImportStatusPage }) },
    { path: 'contactos/:contactId', lazy: async () => ({ Component: (await import('./profile/ProfilePage')).ProfilePage }) },
];

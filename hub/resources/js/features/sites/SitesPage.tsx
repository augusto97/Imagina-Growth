/*
 * `sitios` ya no es una pantalla aparte: tu web vive en Conexiones (rediseño v2 §3), con su estado, el plugin y «Conectar
 * tu web» en la hoja de su tarjeta. La ruta sigue funcionando y lleva ahí (también con `?conectar=1`). El detalle de
 * cada sitio (`sitios/:id`) sigue en SiteDetailPage.
 */
export { SitesRedirect as SitesPage } from '@/features/connections/redirects';

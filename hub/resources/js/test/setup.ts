import '@testing-library/jest-dom/vitest';
import { loadAllTexts } from '@/lib/i18n';

// En la app cada pantalla trae sus textos (lib/i18n.ts, withTexts); en las pruebas se cargan todos de una vez.
await loadAllTexts();

import { describe, expect, it } from 'vitest';
import { cn } from './cn';

describe('cn', () => {
    it('no confunde la escala tipográfica propia con un color', () => {
        expect(cn('igr:text-support igr:text-muted-foreground')).toBe('igr:text-support igr:text-muted-foreground');
        expect(cn('igr:text-caption', 'igr:text-foreground')).toBe('igr:text-caption igr:text-foreground');
    });

    it('sigue resolviendo los conflictos de tamaño y de color', () => {
        expect(cn('igr:text-support', 'igr:text-caption')).toBe('igr:text-caption');
        expect(cn('igr:text-muted-foreground', 'igr:text-foreground')).toBe('igr:text-foreground');
        expect(cn('igr:px-2', 'igr:px-4')).toBe('igr:px-4');
    });
});

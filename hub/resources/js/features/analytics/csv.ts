/*
 * Exportación CSV del lado del navegador. Pensada para Excel en español:
 * separador «;», coma decimal y BOM para que abra bien las tildes.
 */

export type CsvValue = string | number | null | undefined;

export interface CsvColumn<T> {
    label: string;
    value: (row: T) => CsvValue;
}

function cell(value: CsvValue): string {
    if (value === null || value === undefined) {
        return '';
    }
    const text = typeof value === 'number' ? (Number.isInteger(value) ? String(value) : String(Math.round(value * 10000) / 10000).replace('.', ',')) : value;
    return /[";\n\r]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

export function toCsv<T>(columns: CsvColumn<T>[], rows: T[]): string {
    const lines = [columns.map((c) => cell(c.label)).join(';'), ...rows.map((row) => columns.map((c) => cell(c.value(row))).join(';'))];
    return `﻿${lines.join('\r\n')}\r\n`;
}

export function csvFilename(parts: string[]): string {
    const slug = parts
        .filter(Boolean)
        .join('-')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9-]+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');
    return `${slug || 'export'}.csv`;
}

export function downloadCsv<T>(filename: string, columns: CsvColumn<T>[], rows: T[]): void {
    const blob = new Blob([toCsv(columns, rows)], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Support;

/** Lector de CSV tolerante: BOM, separador «,», «;» o tabulador, y filas desparejas. */
final class CsvReader
{
    /** @return array{headers: list<string>, rows: \Generator<int, list<string>>} */
    public static function read(string $content): array
    {
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($firstLine, $d))->first() ?? ',';
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('No se pudo leer el CSV.');
        }
        fwrite($handle, $content);
        rewind($handle);
        $headers = array_map(fn ($h) => trim((string) $h), fgetcsv($handle, null, $delimiter, '"', '') ?: []);

        $rows = (function () use ($handle, $delimiter): \Generator {
            $line = 1;
            while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $line++;
                if ($row === [null]) {
                    continue;
                }
                yield $line => array_map(fn ($v) => trim((string) $v), $row);
            }
            fclose($handle);
        })();

        return ['headers' => $headers, 'rows' => $rows];
    }

    /** Campo sugerido para un encabezado (es/en). */
    public static function suggest(string $header): string
    {
        $h = mb_strtolower(trim((string) preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($header, \Normalizer::FORM_D) ?: $header)));
        $map = [
            'email' => ['email', 'correo', 'e-mail', 'correo electronico', 'mail'],
            'phone' => ['telefono', 'phone', 'celular', 'whatsapp', 'movil', 'tel', 'numero'],
            'first_name' => ['nombre', 'nombres', 'first name', 'first_name', 'name'],
            'last_name' => ['apellido', 'apellidos', 'last name', 'last_name'],
            'city' => ['ciudad', 'city', 'municipio'],
            'region_code' => ['departamento', 'estado', 'region', 'state'],
            'country' => ['pais', 'country'],
            'birthday' => ['cumpleanos', 'fecha de nacimiento', 'nacimiento', 'birthday'],
            'tags' => ['etiquetas', 'tags', 'etiqueta'],
        ];
        foreach ($map as $field => $names) {
            if (in_array($h, $names, true)) {
                return $field;
            }
        }

        return 'ignore';
    }
}

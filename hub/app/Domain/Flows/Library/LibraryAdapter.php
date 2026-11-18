<?php

declare(strict_types=1);

namespace App\Domain\Flows\Library;

use App\Domain\Templates\Library\TemplateLibrary;
use Illuminate\Support\Facades\DB;

/**
 * Ajusta un flujo de la biblioteca al espacio de trabajo antes de crearlo:
 *
 * - Sin una cuenta de WhatsApp activa, quita los pasos de WhatsApp: quien
 *   llegaba a uno sigue por su rama «falló» (el correo de respaldo) o, si no
 *   tiene, por su salida normal; y las divisiones por canal dejan de ofrecerlo.
 *   Así una tienda que solo tiene correo activa el flujo en un clic.
 * - Las plantillas que faltan y existen en la biblioteca de M08 se instalan
 *   (`TemplateLibrary::install` es idempotente).
 */
final class LibraryAdapter
{
    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public static function forWorkspace(array $definition, string $workspaceId, ?bool $hasWhatsapp = null): array
    {
        if (! is_array($definition['graph'] ?? null) || ($hasWhatsapp ?? self::hasWhatsapp($workspaceId))) {
            return $definition;
        }
        $definition['graph'] = self::withoutWhatsapp($definition['graph']);

        return $definition;
    }

    public static function hasWhatsapp(string $workspaceId): bool
    {
        return DB::table('channel_accounts')->where('workspace_id', $workspaceId)->where('channel', 'whatsapp')->where('status', 'active')->exists();
    }

    /**
     * Claves de plantilla del grafo.
     *
     * @param  array<string, mixed>  $graph
     * @return list<string>
     */
    public static function templateKeys(array $graph): array
    {
        $keys = [];
        foreach ((array) ($graph['nodes'] ?? []) as $node) {
            $key = is_array($node) ? ($node['config']['template_key'] ?? null) : null;
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /** Las claves que se pueden instalar desde la biblioteca de plantillas de M08. */
    public static function installable(string $key): bool
    {
        return in_array($key, TemplateLibrary::keys(), true);
    }

    /**
     * @param  array<string, mixed>  $graph
     * @return array<string, mixed>
     */
    public static function withoutWhatsapp(array $graph): array
    {
        /** @var list<array<string, mixed>> $nodes */
        $nodes = array_values((array) ($graph['nodes'] ?? []));
        /** @var list<array{from: string, to: string, branch?: string}> $edges */
        $edges = array_values((array) ($graph['edges'] ?? []));

        foreach ($nodes as $node) {
            if (($node['type'] ?? null) !== 'send_whatsapp') {
                continue;
            }
            $id = (string) $node['id'];
            $out = array_values(array_filter($edges, fn (array $e) => $e['from'] === $id));
            $failed = array_values(array_filter($out, fn (array $e) => ($e['branch'] ?? null) === 'failed'))[0]['to'] ?? null;
            $next = array_values(array_filter($out, fn (array $e) => ! isset($e['branch'])))[0]['to'] ?? null;
            $target = $failed ?? $next;
            $kept = [];
            foreach ($edges as $edge) {
                if ($edge['from'] === $id) {
                    continue;
                }
                if ($edge['to'] === $id) {
                    if ($target === null) {
                        continue;
                    }
                    $edge['to'] = $target;
                }
                $kept[] = $edge;
            }
            $edges = $kept;
        }
        $nodes = array_values(array_filter($nodes, fn (array $n) => ($n['type'] ?? null) !== 'send_whatsapp'));

        // Las divisiones por canal dejan de ofrecer WhatsApp.
        foreach ($nodes as $i => $node) {
            if (($node['type'] ?? null) === 'channel_split') {
                $nodes[$i]['config']['channels'] = array_values(array_filter((array) ($node['config']['channels'] ?? []), fn ($c) => $c !== 'whatsapp'));
                $edges = array_values(array_filter($edges, fn (array $e) => ! ($e['from'] === $node['id'] && ($e['branch'] ?? null) === 'whatsapp')));
            }
        }

        // Una sola arista por salida y sin aristas repetidas.
        $seen = [];
        $edges = array_values(array_filter($edges, function (array $e) use (&$seen) {
            $key = $e['from'].'|'.($e['branch'] ?? '');
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;

            return true;
        }));

        // Quita lo que quedó sin conexión (p. ej. la espera de la respuesta por WhatsApp).
        $reachable = ['trigger' => true];
        for ($changed = true; $changed;) {
            $changed = false;
            foreach ($edges as $edge) {
                if (isset($reachable[$edge['from']]) && ! isset($reachable[$edge['to']])) {
                    $reachable[$edge['to']] = true;
                    $changed = true;
                }
            }
        }
        $nodes = array_values(array_filter($nodes, fn (array $n) => isset($reachable[(string) $n['id']])));
        $edges = array_values(array_filter($edges, fn (array $e) => isset($reachable[$e['from']])));

        $nodes = array_map(function (array $n) {
            unset($n['position']);

            return $n;
        }, $nodes);

        return ['nodes' => LibraryGraph::layout($nodes, $edges), 'edges' => $edges];
    }
}

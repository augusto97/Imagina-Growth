<?php

use App\Domain\Api\Http\Middleware\AuthenticateApiClient;
use App\Domain\Api\Http\Middleware\ThrottleApiClient;
use App\Domain\Mcp\GrowthServer;
use Laravel\Mcp\Facades\Mcp;

/*
 * Servidor MCP de Imagina Growth (M19, docs/api-publica.md §11). Transporte HTTP
 * («streamable HTTP») en /api/mcp con un token del espacio de trabajo con alcance
 * `mcp` (Authorization: Bearer igr_…). El plan debe incluir el módulo `mcp`.
 * [verificar] OAuth 2.1 para conectarlo como conector remoto sin pegar el token.
 */

Mcp::web('/api/mcp', GrowthServer::class)
    ->middleware([AuthenticateApiClient::class.':mcp', ThrottleApiClient::class])
    ->name('mcp.growth');

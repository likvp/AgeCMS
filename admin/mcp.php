<?php
declare(strict_types=1);

// MCP endpoint (Model Context Protocol over HTTP, JSON-RPC 2.0). Requires "Authorization: Bearer <token>".
// Supports initialize, ping, tools/list and tools/call, plus the direct method names from AGENT.md
// (for example {"jsonrpc":"2.0","method":"get_site_structure","id":1}).
require __DIR__ . '/../core/bootstrap.php';

use AgeCMS\{Actor, Api, Background, AgeCMSException, AntiSpam, Auth, Config, Http, Logger, NotInstalledException, Seo, ValidationException};

// After the answer is sent: finish queued work, ping search engines, re-cache the pages that changed. No cron needed.
Background::afterResponse(true);

/** @return array<string,mixed> */
function rpcError(mixed $id, int $code, string $message, ?array $data = null): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message] + ($data === null ? [] : ['data' => $data])];
}

/** @param array<string,mixed> $request @return array<string,mixed>|null null for notifications */
function handle(array $request, Actor $actor): ?array
{
    $id = $request['id'] ?? null;
    $isNotification = !array_key_exists('id', $request);
    $method = $request['method'] ?? null;
    if (($request['jsonrpc'] ?? null) !== '2.0' || !is_string($method)) {
        return rpcError($id, -32600, 'Invalid Request: expected JSON-RPC 2.0 with a method');
    }
    $params = is_array($request['params'] ?? null) ? $request['params'] : [];

    try {
        $result = match (true) {
            $method === 'initialize' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'agecms', 'version' => '1.0.0'],
                'instructions' => 'Call get_site_structure first. Read ' . Seo::siteUrl() . '/AGENT.md for the rules. Changes are validated, versioned and rate-limited; mass edits trip a circuit breaker.',
            ],
            $method === 'ping' => new stdClass(),
            str_starts_with($method, 'notifications/') => null,
            $method === 'tools/list' => ['tools' => Api::tools($actor)],
            $method === 'tools/call' => callTool($params, $actor),
            default => callDirect($method, $params, $actor),
        };
    } catch (ValidationException $e) {
        if ($method === 'tools/call') {
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => toolResult(['error' => 'validation', 'message' => $e->getMessage(), 'errors' => $e->errors], true)];
        }
        return $isNotification ? null : rpcError($id, -32602, 'Invalid params: ' . $e->getMessage(), ['errors' => $e->errors]);
    } catch (AgeCMSException $e) {
        // Tool failures are returned as tool results with isError, as MCP clients expect.
        if ($method === 'tools/call') {
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => toolResult(['error' => $e->kind(), 'message' => $e->getMessage()], true)];
        }
        return $isNotification ? null : rpcError($id, -32000 - min(99, Api::statusFor($e) % 100), $e->getMessage(), ['kind' => $e->kind()]);
    }
    return $isNotification ? null : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}

/** @param array<string,mixed> $params @return array<string,mixed> */
function callTool(array $params, Actor $actor): array
{
    $name = $params['name'] ?? null;
    if (!is_string($name)) {
        throw new ValidationException(['tools/call needs params.name']);
    }
    return toolResult(Api::call($actor, $name, is_array($params['arguments'] ?? null) ? $params['arguments'] : []), false);
}

/** @param array<string,mixed> $params @return array<string,mixed> */
function callDirect(string $method, array $params, Actor $actor): array
{
    $aliases = ['rollback' => 'rollback_revision'];
    try {
        return Api::call($actor, $aliases[$method] ?? $method, $params);
    } catch (AgeCMSException $e) {
        if ($e instanceof \AgeCMS\NotFoundException && str_starts_with($e->getMessage(), "Unknown tool")) {
            throw new JsonRpcMethodNotFound($method);
        }
        throw $e;
    }
}

/** @param array<string,mixed> $data @return array<string,mixed> */
function toolResult(array $data, bool $isError): array
{
    return [
        'content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR)]],
        'isError' => $isError,
    ];
}

final class JsonRpcMethodNotFound extends RuntimeException
{
    public function __construct(public readonly string $method)
    {
        parent::__construct("Method '{$method}' not found");
    }
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        Http::json(rpcError(null, -32600, 'Use POST with a JSON-RPC 2.0 body'), 405);
    }
    $bearer = Http::bearer();
    $actor = $bearer === null ? null : Auth::tokenActor($bearer);
    if ($actor === null) {
        header('WWW-Authenticate: Bearer realm="agecms"');
        Http::json(rpcError(null, -32001, Auth::authError()), 401);
    }
    if (($tokenInfo = Auth::currentToken()) !== null && $tokenInfo['warning'] !== null) {
        header('X-AgeCMS-Token-Warning: ' . $tokenInfo['warning']);
    }
    if (!AntiSpam::rateLimit('api:' . $actor->name, (int)Config::get('rate_limit.mcp_per_minute', 120), 60)) {
        Http::json(rpcError(null, -32002, 'Rate limit exceeded'), 429);
    }

    $payload = json_decode((string)file_get_contents('php://input', false, null, 0, 2097152), true);
    if (!is_array($payload) || $payload === []) {
        Http::json(rpcError(null, -32700, 'Parse error'), 400);
    }

    if (array_is_list($payload)) {
        $responses = [];
        foreach (array_slice($payload, 0, 50) as $request) {
            $response = is_array($request) ? safeHandle($request, $actor) : rpcError(null, -32600, 'Invalid Request');
            if ($response !== null) {
                $responses[] = $response;
            }
        }
        if ($responses === []) {
            http_response_code(202);
            exit;
        }
        Http::json($responses);
    }
    $response = safeHandle($payload, $actor);
    if ($response === null) {
        http_response_code(202);
        exit;
    }
    Http::json($response);
} catch (NotInstalledException $e) {
    Http::json(rpcError(null, -32003, 'Not installed'), 503);
} catch (Throwable $e) {
    Logger::log('error', 'mcp.failed', ['error' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
    Http::json(rpcError(null, -32603, 'Internal error'), 500);
}

/** @param array<string,mixed> $request @return array<string,mixed>|null */
function safeHandle(array $request, Actor $actor): ?array
{
    try {
        return handle($request, $actor);
    } catch (JsonRpcMethodNotFound $e) {
        return rpcError($request['id'] ?? null, -32601, $e->getMessage());
    } catch (Throwable $e) {
        Logger::log('error', 'mcp.method_failed', ['method' => $request['method'] ?? '', 'error' => $e->getMessage()]);
        return rpcError($request['id'] ?? null, -32603, 'Internal error');
    }
}

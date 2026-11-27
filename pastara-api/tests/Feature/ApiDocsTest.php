<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('hides Scalar API docs and OpenAPI spec with HTTP 404 when API_DOCS_ENABLED is false', function (): void {
    config()->set('pastara.api_docs_enabled', false);

    $this->get('/docs')->assertStatus(404);
    $this->get('/docs/openapi.yaml')->assertStatus(404);
});

it('serves Scalar API Reference UI and OpenAPI 3.0.3 YAML spec when API_DOCS_ENABLED is true', function (): void {
    config()->set('pastara.api_docs_enabled', true);

    $this->get('/docs')
        ->assertStatus(200)
        ->assertSee('@scalar/api-reference', false)
        ->assertSee('/docs/openapi.yaml', false);

    $specResponse = $this->get('/docs/openapi.yaml');

    $specResponse->assertStatus(200)
        ->assertHeader('Content-Type', 'application/yaml; charset=utf-8');

    expect($specResponse->getContent())
        ->toContain('openapi: 3.0.3')
        ->toContain('https://staging.afiefnoer.my.id/api/v1')
        ->toContain('https://afiefnoer.my.id/api/v1');
});

it('documents every registered api/v1 route in OpenAPI 3.0.3 spec and keeps docs/openapi.yaml in sync', function (): void {
    $specPath = resource_path('openapi/openapi.yaml');
    $specLines = file($specPath);
    expect($specLines)->toBeArray();

    $inPaths = false;
    $currentPath = null;
    $documentedOps = [];

    foreach ($specLines as $line) {
        if (preg_match('/^paths:\s*$/', $line) === 1) {
            $inPaths = true;

            continue;
        }
        if ($inPaths && preg_match('/^\S/', $line) === 1) {
            $inPaths = false;
        }
        if (! $inPaths) {
            continue;
        }
        if (preg_match('/^  (\/\S+):\s*$/', $line, $matches) === 1) {
            $currentPath = $matches[1];

            continue;
        }
        if ($currentPath !== null && preg_match('/^    (get|post|put|patch|delete):\s*$/i', $line, $matches) === 1) {
            $normalizedPath = (string) preg_replace('#\{[^}]+\}#', '{param}', $currentPath);
            $documentedOps[] = strtoupper($matches[1]).' '.$normalizedPath;
        }
    }

    $registeredOps = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/v1/')) {
            continue;
        }

        $relativeUri = (string) preg_replace('#^api/v1#', '', $uri);
        $normalizedUri = (string) preg_replace('#\{[^}]+\}#', '{param}', $relativeUri);

        foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
            $registeredOps[] = strtoupper($method).' '.$normalizedUri;
        }
    }

    sort($documentedOps);
    sort($registeredOps);

    expect($documentedOps)->toBe($registeredOps);

    $rootDocsSpecPath = base_path('../docs/openapi.yaml');
    if (is_file($rootDocsSpecPath)) {
        $normalizeNewlines = static fn (string $content): string => str_replace("\r\n", "\n", $content);
        expect($normalizeNewlines((string) file_get_contents($rootDocsSpecPath)))
            ->toBe($normalizeNewlines((string) file_get_contents($specPath)));
    }
});

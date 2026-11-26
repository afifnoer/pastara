<?php

declare(strict_types=1);

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

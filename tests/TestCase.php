<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OpenApi\SchemaValidator;

abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    private bool $openApiAssertions = true;

    public function withoutOpenApiAssertions(): static
    {
        $this->openApiAssertions = false;

        return $this;
    }

    /**
     * Hook the framework's TestResponse construction so every endpoint hit
     * inside a feature test is automatically validated against the bundled
     * OpenAPI spec. Tests that explicitly want to bypass (e.g. covering 5xx
     * paths the spec doesn't model) can call `withoutOpenApiAssertions()`.
     */
    protected function createTestResponse($response, $request): TestResponse
    {
        $testResponse = parent::createTestResponse($response, $request);

        if ($this->openApiAssertions
            && $request instanceof Request
            && $response instanceof Response
        ) {
            SchemaValidator::validate($request, $response);
        }

        return $testResponse;
    }
}

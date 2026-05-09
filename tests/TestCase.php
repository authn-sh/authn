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

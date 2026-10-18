<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_root_route_returns_not_found(): void
    {
        $response = $this->get('/');

        $response->assertNotFound();
    }
}

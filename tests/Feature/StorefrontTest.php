<?php

namespace Tests\Feature;

use Tests\TestCase;

class StorefrontTest extends TestCase
{
    public function test_home_page_returns_successful_response(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('Lechon Delights');
    }

    public function test_menu_page_returns_successful_response(): void
    {
        $response = $this->get('/menu');
        $response->assertStatus(200);
        $response->assertSee('Menu');
    }

    public function test_locations_page_returns_successful_response(): void
    {
        $response = $this->get('/locations');
        $response->assertStatus(200);
        $response->assertSee('Directory');
    }

    public function test_about_page_returns_successful_response(): void
    {
        $response = $this->get('/about');
        $response->assertStatus(200);
        $response->assertSee('Marketplace');
    }

    public function test_help_center_page_returns_successful_response(): void
    {
        $response = $this->get('/help-center');
        $response->assertStatus(200);
        $response->assertSee('Help Center');
    }

    public function test_faq_page_returns_successful_response(): void
    {
        $response = $this->get('/faq');
        $response->assertStatus(200);
        $response->assertSee('Frequently Asked Questions');
    }

    public function test_live_order_tracking_page_loads(): void
    {
        $response = $this->get('/track-order');
        $response->assertStatus(200);
        $response->assertSee('Live Order Tracking');
    }

    public function test_login_and_register_pages_load(): void
    {
        $loginRes = $this->get('/login');
        $loginRes->assertStatus(200);
        $loginRes->assertSee('Sign In');

        $registerRes = $this->get('/register');
        $registerRes->assertStatus(200);
        $registerRes->assertSee('Create an Account');
    }

    public function test_api_health_endpoint(): void
    {
        $response = $this->getJson('/api/health');
        $response->assertStatus(200);
        $response->assertJson(['status' => 'healthy']);
    }
}

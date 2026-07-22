<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests are redirected to login', function () {
    $response = $this->get('/');
    $response->assertRedirect('/login');
});

test('login page renders successfully', function () {
    $response = $this->get('/login');
    $response->assertOk();
});

test('users can authenticate and access dashboard', function () {
    $user = User::factory()->create([
        'email' => 'admin@example.com',
        'password' => bcrypt('password'),
    ]);

    $response = $this->post('/login', [
        'email' => 'admin@example.com',
        'password' => 'password',
    ]);

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);

    $dashboardResponse = $this->actingAs($user)->get('/');
    $dashboardResponse->assertOk();
});

test('users can log out', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $response->assertRedirect('/login');
    $this->assertGuest();
});

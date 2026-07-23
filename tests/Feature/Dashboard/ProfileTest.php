<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('guest cannot access profile page', function () {
    $response = $this->get('/profile');
    $response->assertRedirect('/login');
});

test('authenticated user can view profile page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/profile');

    $response->assertStatus(200);
});

test('user can update profile information', function () {
    $user = User::factory()->create([
        'name' => 'Old Name',
        'email' => 'old@example.com',
    ]);

    $response = $this->actingAs($user)->post('/profile', [
        'name' => 'New Name',
        'email' => 'new@example.com',
    ]);

    $response->assertRedirect();

    $user->refresh();
    expect($user->name)->toBe('New Name');
    expect($user->email)->toBe('new@example.com');
});

test('user can update password with correct current password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('old-password'),
    ]);

    $response = $this->actingAs($user)->post('/profile', [
        'name' => $user->name,
        'email' => $user->email,
        'current_password' => 'old-password',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ]);

    $response->assertRedirect();

    $user->refresh();
    expect(Hash::check('new-password-123', $user->password))->toBeTrue();
});

test('user cannot update password with incorrect current password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('old-password'),
    ]);

    $response = $this->actingAs($user)->post('/profile', [
        'name' => $user->name,
        'email' => $user->email,
        'current_password' => 'wrong-password',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ]);

    $response->assertSessionHasErrors(['current_password']);

    $user->refresh();
    expect(Hash::check('old-password', $user->password))->toBeTrue();
});

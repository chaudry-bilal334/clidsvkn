<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

test('the csrf cookie endpoint initializes a session', function () {
    $this->get('/api/v1/csrf-cookie')
        ->assertOk()
        ->assertJson(['message' => 'CSRF cookie initialized.']);
});

test('a user can log in with valid credentials', function () {
    $user = User::factory()->create([
        'email' => 'admin@clinic.test',
        'password' => 'password',
        'role' => 'Admin',
    ]);

    $this->post('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonStructure([
            'token',
            'user' => ['id', 'name', 'email', 'role', 'permissions'],
        ]);
});

test('login is rejected for invalid credentials', function () {
    $user = User::factory()->create([
        'email' => 'doctor@clinic.test',
        'password' => 'password',
    ]);

    $this->post('/api/v1/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('authentication endpoints are throttled', function () {
    $user = User::factory()->create([
        'email' => 'admin@clinic.test',
        'password' => 'password',
    ]);

    foreach (range(1, 5) as $attempt) {
        $this->post('/api/v1/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    $this->post('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertTooManyRequests();
});

test('an authenticated user can fetch their profile', function () {
    $user = User::factory()->create(['role' => 'Doctor']);

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('user.email', $user->email)
        ->assertJsonPath('user.role', 'Doctor');
});

test('unauthenticated users cannot access protected endpoints', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

test('an authenticated user can log out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/api/v1/logout')
        ->assertOk()
        ->assertJson(['message' => 'Signed out.']);

    $this->getJson('/api/v1/me')->assertUnauthorized();
});

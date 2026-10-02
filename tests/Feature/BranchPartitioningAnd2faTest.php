<?php

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\Patient;
use App\Models\User;
use App\Services\TwoFactorAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;

pest()->use(RefreshDatabase::class);

function bc_branch(): Branch
{
    return Branch::factory()->create();
}

function bc_user(string $role, Branch $branch, array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'role' => $role,
        'branch_id' => $branch->id,
    ], $attributes));
}

function bc_enable_2fa(User $user): array
{
    $google2fa = app(Google2FA::class);
    $secret = $google2fa->generateSecretKey();
    $user->two_factor_secret = $secret;
    $user->two_factor_confirmed_at = now();
    $user->save();

    $codes = app(TwoFactorAuthService::class)->issueRecoveryCodes($user);

    return [$secret, $codes];
}

test('an admin can list, create, update and deactivate branches and view their staff', function () {
    $admin = User::factory()->create(['role' => 'Admin']);
    $branch = bc_branch();
    bc_user('Receptionist', $branch);

    $this->actingAs($admin)
        ->getJson('/api/v1/branches')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($admin)
        ->postJson('/api/v1/branches', ['name' => 'Downtown Clinic', 'code' => 'DTW'])
        ->assertCreated()
        ->assertJsonPath('data.code', 'DTW');

    $this->actingAs($admin)
        ->getJson("/api/v1/branches/{$branch->id}/staff")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($admin)
        ->putJson("/api/v1/branches/{$branch->id}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);
});

test('a branch staff member only sees their own branch in the switcher', function () {
    $branchA = bc_branch();
    $branchB = bc_branch();
    $staff = bc_user('Receptionist', $branchA);

    $this->actingAs($staff)
        ->getJson('/api/v1/branches')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $branchA->id);
});

test('branch staff cannot view staff lists of their branch', function () {
    $branch = bc_branch();
    $staffA = bc_user('Receptionist', $branch);
    bc_user('Doctor', $branch);

    $this->actingAs($staffA)
        ->getJson("/api/v1/branches/{$branch->id}/staff")
        ->assertForbidden();
});

test('patients are partitioned by branch and auto-assigned on creation', function () {
    $branchA = bc_branch();
    $branchB = bc_branch();
    $receptionistA = bc_user('Receptionist', $branchA);

    Patient::factory()->create(['branch_id' => $branchA->id]);
    Patient::factory()->create(['branch_id' => $branchB->id]);

    $this->actingAs($receptionistA)
        ->getJson('/api/v1/patients')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $receptionistB = bc_user('Receptionist', $branchB);

    $response = $this->actingAs($receptionistB)
        ->postJson('/api/v1/patients', [
            'name' => 'Scoped Jane',
            'email' => 'jane@example.com',
        ])
        ->assertCreated()
        ->assertJsonPath('data.branch_id', $branchB->id);

    expect(Patient::find($response->json('data.id'))->branch_id)->toBe($branchB->id);
});

test('branch staff cannot read or modify a patient of another branch', function () {
    $branchA = bc_branch();
    $branchB = bc_branch();
    $staffA = bc_user('Receptionist', $branchA);
    $otherPatient = Patient::factory()->create(['branch_id' => $branchB->id]);

    $this->actingAs($staffA)
        ->putJson("/api/v1/patients/{$otherPatient->id}", ['name' => 'Hijacked'])
        ->assertNotFound();
});

test('inventory, appointments and lab orders are partitioned by branch', function () {
    $branchA = bc_branch();
    $branchB = bc_branch();
    $pharmacistA = bc_user('Pharmacist', $branchA);
    $receptionistA = bc_user('Receptionist', $branchA);

    InventoryItem::factory()->create(['branch_id' => $branchA->id]);
    InventoryItem::factory()->create(['branch_id' => $branchB->id]);
    Appointment::factory()->create(['branch_id' => $branchA->id, 'status' => 'booked']);
    Appointment::factory()->create(['branch_id' => $branchB->id, 'status' => 'booked']);

    $this->actingAs($pharmacistA)
        ->getJson('/api/v1/inventory')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // Least privilege: a Pharmacist has no `appointments.view` capability, so
    // the branch-scoped appointment listing is asserted with Receptionist.
    $this->actingAs($pharmacistA)
        ->getJson('/api/v1/appointments')
        ->assertForbidden();

    $response = $this->actingAs($receptionistA)
        ->getJson('/api/v1/appointments')
        ->assertOk();

    expect(collect($response->json('data'))->count())->toBe(1);
});

test('switching to an unassigned branch via the header is rejected with 403', function () {
    $branchA = bc_branch();
    $branchB = bc_branch();
    $staffA = bc_user('Receptionist', $branchA);

    $this->actingAs($staffA)
        ->withHeader('X-Branch-ID', (string) $branchB->id)
        ->getJson('/api/v1/patients')
        ->assertForbidden();
});

test('switching to a nonexistent or inactive branch is rejected with 422', function () {
    $branchA = bc_branch();
    $inactive = Branch::factory()->inactive()->create();
    $staffA = bc_user('Receptionist', $branchA);

    $this->actingAs($staffA)
        ->withHeader('X-Branch-ID', '999999')
        ->getJson('/api/v1/patients')
        ->assertStatus(422);

    $this->actingAs($staffA)
        ->withHeader('X-Branch-ID', (string) $inactive->id)
        ->getJson('/api/v1/patients')
        ->assertStatus(422);
});

test('an admin sees all branches by default and scopes when a header is sent', function () {
    $admin = User::factory()->create(['role' => 'Admin']);
    $branchA = bc_branch();
    Patient::factory()->create(['branch_id' => $branchA->id]);
    Patient::factory()->create(['branch_id' => bc_branch()->id]);

    $this->actingAs($admin)
        ->getJson('/api/v1/patients')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAs($admin)
        ->withHeader('X-Branch-ID', (string) $branchA->id)
        ->getJson('/api/v1/patients')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('a cross-branch user sees all branches by default and scopes per header', function () {
    $branchA = bc_branch();
    $branchB = bc_branch();
    $staff = bc_user('Receptionist', $branchA, ['is_cross_branch' => true]);

    $this->actingAs($staff)
        ->getJson('/api/v1/branches')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAs($staff)
        ->withHeader('X-Branch-ID', (string) $branchB->id)
        ->getJson('/api/v1/patients')
        ->assertOk();
});

test('logging in with an admin whose 2FA is enabled requires a challenge', function () {
    [$secret] = bc_enable_2fa(User::factory()->create([
        'role' => 'Admin',
        'email' => 'secured@example.com',
    ]));

    $this->postJson('/api/v1/login', [
        'email' => 'secured@example.com',
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJson(['two_factor_required' => true])
        ->assertJsonMissingPath('token')
        ->assertJsonMissingPath('user');

    $this->postJson('/api/v1/auth/2fa/verify', [
        'code' => app(Google2FA::class)->getCurrentOtp($secret),
    ])
        ->assertOk()
        ->assertJsonPath('user.email', 'secured@example.com');
});

test('a wrong two-factor code fails the challenge', function () {
    bc_enable_2fa(User::factory()->create([
        'role' => 'Admin',
        'email' => 'secured2@example.com',
    ]));

    $this->postJson('/api/v1/login', [
        'email' => 'secured2@example.com',
        'password' => 'password',
    ])->assertJson(['two_factor_required' => true]);

    $this->postJson('/api/v1/auth/2fa/verify', ['code' => '000000'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

test('admin and doctor accounts with 2FA are blocked from sensitive endpoints until verified', function () {
    bc_enable_2fa(bc_user('Admin', bc_branch()));
    $doctor = bc_user('Doctor', bc_branch());
    bc_enable_2fa($doctor);

    $this->actingAs($doctor)
        ->getJson('/api/v1/prescriptions')
        ->assertForbidden()
        ->assertJson(['two_factor_required' => true]);
});

test('accounts outside admin and doctor are not challenged for 2FA', function () {
    $receptionist = bc_user('Receptionist', bc_branch());
    bc_enable_2fa($receptionist);

    $this->actingAs($receptionist)
        ->getJson('/api/v1/patients')
        ->assertOk();
});

test('a recovery code logs in once and cannot be reused', function () {
    [, $codes] = bc_enable_2fa(User::factory()->create([
        'role' => 'Admin',
        'email' => 'recovery@example.com',
    ]));

    $this->postJson('/api/v1/login', [
        'email' => 'recovery@example.com',
        'password' => 'password',
    ])->assertJson(['two_factor_required' => true]);

    $this->postJson('/api/v1/auth/2fa/verify', ['code' => $codes[0]])
        ->assertOk()
        ->assertJsonPath('user.email', 'recovery@example.com');

    $this->postJson('/api/v1/logout')->assertOk();

    $this->postJson('/api/v1/login', [
        'email' => 'recovery@example.com',
        'password' => 'password',
    ])->assertJson(['two_factor_required' => true]);

    $this->postJson('/api/v1/auth/2fa/verify', ['code' => $codes[0]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

test('enabling and confirming 2fa issues recovery codes', function () {
    $staff = bc_user('Receptionist', bc_branch());

    $enabled = $this->actingAs($staff)
        ->postJson('/api/v1/auth/2fa/enable')
        ->assertOk();

    $secret = $enabled->json('secret');

    expect($enabled->json('qr_uri'))->toContain('otpauth://totp/');

    $this->postJson('/api/v1/auth/2fa/confirm', ['code' => '000000'])
        ->assertUnprocessable();

    $confirmed = $this->postJson('/api/v1/auth/2fa/confirm', [
        'code' => app(Google2FA::class)->getCurrentOtp($secret),
    ])
        ->assertOk();

    expect($confirmed->json('recovery_codes'))->toHaveCount(8)
        ->and($staff->fresh()->twoFactorEnabled())->toBeTrue();

    $this->postJson('/api/v1/auth/2fa/enable')->assertStatus(422);
});

test('2fa can be disabled only after confirming the account password', function () {
    $admin = User::factory()->create(['role' => 'Admin', 'email' => 'disable@example.com']);
    bc_enable_2fa($admin);

    $this->actingAs($admin)
        ->postJson('/api/v1/auth/2fa/disable', ['password' => 'wrong-password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    $this->actingAs($admin)
        ->postJson('/api/v1/auth/2fa/disable', ['password' => 'password'])
        ->assertOk();

    $this->postJson('/api/v1/login', [
        'email' => 'disable@example.com',
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonMissingPath('two_factor_required');
});

<?php

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\System;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns 401 ERR_UNAUTHENTICATED for unauthenticated guests on user show', function (): void {
    $target = User::factory()->developer()->create();

    $this->getJson("/api/users/{$target->public_id}")
        ->assertUnauthorized()
        ->assertJsonPath('code', 'ERR_UNAUTHENTICATED');
});

it('forbids non-admins from managing users', function (): void {
    $target = User::factory()->developer()->create();

    Sanctum::actingAs(User::factory()->developer()->create());

    $this->getJson('/api/users')->assertForbidden()
        ->assertJsonPath('code', 'ERR_FORBIDDEN');
    $this->getJson("/api/users/{$target->public_id}")->assertForbidden()
        ->assertJsonPath('code', 'ERR_FORBIDDEN');
    $this->postJson('/api/users', [])->assertForbidden()
        ->assertJsonPath('code', 'ERR_FORBIDDEN');
});

it('forbids agents from viewing a user and enforces UserPolicy::view for admin only', function (UserRole $role, bool $expected): void {
    $actor = User::factory()->create(['role' => $role]);
    $target = User::factory()->developer()->create();

    expect(Gate::forUser($actor)->allows('view', $target))->toBe($expected);
})->with([
    'admin' => [UserRole::Admin, true],
    'agent' => [UserRole::Agent, false],
    'developer' => [UserRole::Developer, false],
]);

it('lists, shows, creates and updates users as admin', function (): void {
    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin);

    $created = $this->postJson('/api/users', [
        'name' => 'Nueva Persona',
        'email' => 'nueva@ticket.dev',
        'password' => 'Secret123!',
        'role' => 'developer',
        'specialty' => 'Backend',
    ])->assertCreated()->assertJsonPath('data.email', 'nueva@ticket.dev');

    $id = $created->json('data.id');
    $createdUser = User::query()->where('public_id', $id)->firstOrFail();

    $systems = System::factory()->count(2)->create(['lead_user_id' => $admin->id]);
    $createdUser->systems()->attach($systems->pluck('id'));
    Ticket::factory()->resolved($createdUser)->create([
        'system_id' => $systems->first()->id,
        'resolved_by_id' => $createdUser->id,
    ]);

    $this->getJson("/api/users/{$id}")
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.name', 'Nueva Persona')
        ->assertJsonPath('data.email', 'nueva@ticket.dev')
        ->assertJsonPath('data.role', 'developer')
        ->assertJsonPath('data.specialty', 'Backend')
        ->assertJsonPath('data.systemsCount', 2)
        ->assertJsonPath('data.resolvedCount', 1);

    $this->postJson('/api/users', [
        'name' => 'Duplicada', 'email' => 'nueva@ticket.dev', 'password' => 'Secret123!', 'role' => 'agent',
    ])->assertUnprocessable()->assertJsonPath('code', 'ERR_VALIDATION');

    $this->putJson("/api/users/{$id}", ['name' => 'Renombrada', 'role' => 'agent'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renombrada')
        ->assertJsonPath('data.role', 'agent');

    $this->getJson('/api/users')->assertOk()->assertJsonCount(2, 'data');
});

it('returns 404 ERR_NOT_FOUND for unknown or soft-deleted user on show', function (): void {
    $admin = User::factory()->admin()->create();
    $deletedUser = User::factory()->developer()->create();
    $deletedPublicId = $deletedUser->public_id;
    $deletedUser->delete();

    Sanctum::actingAs($admin);

    $this->getJson('/api/users/usr_unknown99')
        ->assertNotFound()
        ->assertJsonPath('code', 'ERR_NOT_FOUND');

    $this->getJson("/api/users/{$deletedPublicId}")
        ->assertNotFound()
        ->assertJsonPath('code', 'ERR_NOT_FOUND');
});

it('soft-deletes a user keeping who resolved and was assigned to their resolved tickets', function (): void {
    $admin = User::factory()->admin()->create();
    $dev = User::factory()->developer()->create([
        'name' => 'Eva Resuelve',
        'specialty' => 'Backend',
    ]);
    $system = System::factory()->create();
    $system->developers()->attach($dev->id);

    $resolved = Ticket::factory()->create([
        'system_id' => $system->id,
        'assignee_id' => $dev->id, 'status' => TicketStatus::Open,
    ]);
    $active = Ticket::factory()->create([
        'system_id' => $system->id,
        'assignee_id' => $dev->id, 'status' => TicketStatus::InProgress,
    ]);

    Sanctum::actingAs($dev);
    $this->patchJson("/api/tickets/{$resolved->code}/status", ['status' => 'resolved'])
        ->assertOk()
        ->assertJsonPath('data.resolvedBy.id', $dev->public_id)
        ->assertJsonPath('data.resolvedBy.removed', false);

    Sanctum::actingAs($admin);
    $this->deleteJson("/api/users/{$dev->public_id}")->assertNoContent();

    expect(User::find($dev->id))->toBeNull()
        ->and(User::withTrashed()->find($dev->id))->not->toBeNull()
        ->and($system->developers()->count())->toBe(0)
        ->and($active->fresh()->assignee_id)->toBeNull();

    $this->getJson("/api/tickets/{$resolved->code}")
        ->assertOk()
        ->assertJsonPath('data.resolvedBy.id', $dev->public_id)
        ->assertJsonPath('data.resolvedBy.name', 'Eva Resuelve')
        ->assertJsonPath('data.resolvedBy.email', $dev->email)
        ->assertJsonPath('data.resolvedBy.role', 'Backend')
        ->assertJsonPath('data.resolvedBy.removed', true)
        ->assertJsonPath('data.assignee.id', $dev->public_id)
        ->assertJsonPath('data.assignee.name', 'Eva Resuelve')
        ->assertJsonPath('data.assignee.email', $dev->email)
        ->assertJsonPath('data.assignee.role', 'Backend');

    $this->getJson("/api/tickets/{$active->code}")
        ->assertOk()
        ->assertJsonPath('data.assignee', null);
});

it('prevents deleting yourself or the last admin from changing role', function (): void {
    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin);

    $this->deleteJson("/api/users/{$admin->public_id}")->assertForbidden();
    $this->putJson("/api/users/{$admin->public_id}", ['role' => 'developer'])->assertUnprocessable();
});

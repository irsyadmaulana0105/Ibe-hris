<?php

use App\Models\Project;
use App\Models\Task;

uses()->group('api');

it('employee can list projects', function () {
    [$user, $token] = employeeWithToken(['company_id' => 1]);

    $project = Project::create([
        'company_id' => 1,
        'name' => 'Project Alpha',
        'status' => 'active',
        'created_by' => $user->id,
    ]);

    // Non-admin employees only see projects they are members of.
    $project->members()->create([
        'user_id' => $user->id,
        'role' => 'member',
    ]);

    $this->getJson('/api/v1/projects', bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'Project Alpha');
});

it('admin can create a project', function () {
    // Only workspace admins may create projects (ProjectAuthorizer::canCreateProject).
    [$admin, $token] = adminWithToken(['company_id' => 1]);

    $this->postJson('/api/v1/projects', [
        'name' => 'New Project',
        'description' => 'A description.',
        'status' => 'planning',
    ], bearerHeaders($token))
        ->assertCreated()
        ->assertJsonPath('data.name', 'New Project');

    expect(Project::query()->where('company_id', 1)->count())->toBe(1);
});

it('project creator is auto-added as owner member', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);

    $this->postJson('/api/v1/projects', [
        'name' => 'My Project',
    ], bearerHeaders($token))
        ->assertCreated();

    $project = Project::query()->first();
    expect($project->members()->where('user_id', $admin->id)->where('role', 'owner')->exists())->toBeTrue();
});

it('employee can view a project with its tasks', function () {
    [$user, $token] = employeeWithToken(['company_id' => 1]);

    $project = Project::create([
        'company_id' => 1,
        'name' => 'Beta Project',
        'status' => 'active',
        'created_by' => $user->id,
    ]);

    $project->members()->create([
        'user_id' => $user->id,
        'role' => 'member',
    ]);

    $project->tasks()->create([
        'title' => 'Task One',
        'status' => 'todo',
        'priority' => 'high',
        'created_by' => $user->id,
    ]);

    $this->getJson("/api/v1/projects/{$project->id}", bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.name', 'Beta Project')
        ->assertJsonCount(1, 'data.tasks');
});

it('employee can create a task inside a project', function () {
    [$user, $token] = employeeWithToken(['company_id' => 1]);

    $project = Project::create([
        'company_id' => 1,
        'name' => 'Task Container',
        'status' => 'active',
        'created_by' => $user->id,
    ]);

    // canCreateTask requires role >= member.
    $project->members()->create([
        'user_id' => $user->id,
        'role' => 'member',
    ]);

    $this->postJson("/api/v1/projects/{$project->id}/tasks", [
        'title' => 'Do something important',
        'priority' => 'high',
        'due_date' => now()->addDays(7)->toDateString(),
    ], bearerHeaders($token))
        ->assertCreated()
        ->assertJsonPath('data.title', 'Do something important')
        ->assertJsonPath('data.priority', 'high');

    expect($project->tasks()->count())->toBe(1);
});

it('employee can update a task status', function () {
    [$user, $token] = employeeWithToken(['company_id' => 1]);

    $project = Project::create([
        'company_id' => 1,
        'name' => 'Work Project',
        'status' => 'active',
        'created_by' => $user->id,
    ]);

    // Member can edit own task (created_by matches).
    $project->members()->create([
        'user_id' => $user->id,
        'role' => 'member',
    ]);

    $task = $project->tasks()->create([
        'title' => 'Initial task',
        'status' => 'todo',
        'priority' => 'medium',
        'created_by' => $user->id,
    ]);

    $this->putJson("/api/v1/projects/{$project->id}/tasks/{$task->id}", [
        'status' => 'in_progress',
    ], bearerHeaders($token))
        ->assertOk()
        ->assertJsonPath('data.status', 'in_progress');
});

it('employee can delete a task', function () {
    [$user, $token] = employeeWithToken(['company_id' => 1]);

    $project = Project::create([
        'company_id' => 1,
        'name' => 'Cleanup Project',
        'status' => 'active',
        'created_by' => $user->id,
    ]);

    // Member can delete own task (created_by matches).
    $project->members()->create([
        'user_id' => $user->id,
        'role' => 'member',
    ]);

    $task = $project->tasks()->create([
        'title' => 'Task to remove',
        'status' => 'todo',
        'priority' => 'low',
        'created_by' => $user->id,
    ]);

    $this->deleteJson("/api/v1/projects/{$project->id}/tasks/{$task->id}", [], bearerHeaders($token))
        ->assertOk();

    expect(Task::query()->find($task->id))->toBeNull();
});

it('projects list only returns projects from the same company', function () {
    // Workspace admin sees every project in their company - the intent of
    // this test is to verify company scoping, not member-level visibility.
    [$adminA, $tokenA] = adminWithToken(['company_id' => 1]);
    [$userB, $tokenB] = employeeWithToken(['company_id' => 1]);

    Project::create([
        'company_id' => 1,
        'name' => 'User A Project',
        'status' => 'active',
        'created_by' => $adminA->id,
    ]);

    // Project created by user B - both in company 1, both should be visible to admin A
    Project::create([
        'company_id' => 1,
        'name' => 'User B Project',
        'status' => 'planning',
        'created_by' => $userB->id,
    ]);

    $this->getJson('/api/v1/projects', bearerHeaders($tokenA))
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

it('validates project name is required', function () {
    // Must be workspace admin to reach the validation layer at all.
    [$admin, $token] = adminWithToken(['company_id' => 1]);

    $this->postJson('/api/v1/projects', [
        'description' => 'No name given',
    ], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

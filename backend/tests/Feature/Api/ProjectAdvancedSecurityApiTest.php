<?php

use App\Models\Goal;
use App\Models\Company;
use App\Models\Project;

uses()->group('api');

function advancedProjectFor($user, string $name): Project
{
    $project = Project::create([
        'company_id' => $user->company_id,
        'name' => $name,
        'status' => 'active',
        'created_by' => $user->id,
    ]);
    $project->members()->create(['user_id' => $user->id, 'role' => 'owner']);

    return $project;
}

it('rejects a task bound through another project URL on every collaboration endpoint', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $projectA = advancedProjectFor($admin, 'Project A');
    $projectB = advancedProjectFor($admin, 'Project B');
    $task = $projectB->tasks()->create([
        'title' => 'Private B task',
        'status' => 'todo',
        'priority' => 'medium',
        'created_by' => $admin->id,
    ]);

    $base = "/api/v1/projects/{$projectA->id}/tasks/{$task->id}";
    $headers = bearerHeaders($token);

    $this->getJson($base, $headers)->assertNotFound();
    $this->putJson($base, ['title' => 'Leaked update'], $headers)->assertNotFound();
    $this->deleteJson($base, [], $headers)->assertNotFound();
    $this->getJson("{$base}/comments", $headers)->assertNotFound();
    $this->postJson("{$base}/comments", ['body' => 'Leaked comment'], $headers)->assertNotFound();
    $this->getJson("{$base}/attachments", $headers)->assertNotFound();
    $this->postJson("{$base}/dependencies", ['depends_on_task_id' => $task->id], $headers)->assertNotFound();

    expect($task->fresh()->title)->toBe('Private B task');
});

it('scopes goal parent and owner to the active company', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    $otherCompany = Company::create(['name' => 'Other Company', 'slug' => 'project-goal-other']);
    [$otherUser] = employeeWithToken(['company_id' => $otherCompany->id]);
    $foreignGoal = Goal::create([
        'company_id' => $otherCompany->id,
        'name' => 'Foreign goal',
        'owner_id' => $otherUser->id,
        'created_by' => $otherUser->id,
    ]);

    $this->postJson('/api/v1/goals', [
        'name' => 'Invalid tenant links',
        'owner_id' => $otherUser->id,
        'parent_goal_id' => $foreignGoal->id,
    ], bearerHeaders($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['owner_id', 'parent_goal_id']);
});

it('rejects inactive goal owners and transitive goal cycles', function () {
    [$admin, $token] = adminWithToken(['company_id' => 1]);
    [$inactive] = employeeWithToken([
        'company_id' => 1,
        'employment_status' => 'resigned',
        'resigned_at' => now(),
    ]);
    $headers = bearerHeaders($token);

    $this->postJson('/api/v1/goals', [
        'name' => 'Invalid inactive owner',
        'owner_id' => $inactive->id,
    ], $headers)->assertUnprocessable()->assertJsonValidationErrors('owner_id');

    $root = Goal::create(['company_id' => 1, 'name' => 'Root', 'owner_id' => $admin->id, 'created_by' => $admin->id]);
    $child = Goal::create(['company_id' => 1, 'name' => 'Child', 'parent_goal_id' => $root->id, 'owner_id' => $admin->id, 'created_by' => $admin->id]);
    $grandchild = Goal::create(['company_id' => 1, 'name' => 'Grandchild', 'parent_goal_id' => $child->id, 'owner_id' => $admin->id, 'created_by' => $admin->id]);

    $this->putJson("/api/v1/goals/{$root->id}", [
        'parent_goal_id' => $grandchild->id,
    ], $headers)->assertUnprocessable();
});

it('allows company members to view goals but only owner creator or admin to change them', function () {
    [$owner] = employeeWithToken(['company_id' => 1]);
    [$viewer, $viewerToken] = employeeWithToken(['company_id' => 1]);
    $goal = Goal::create([
        'company_id' => 1,
        'name' => 'Shared objective',
        'owner_id' => $owner->id,
        'created_by' => $owner->id,
    ]);

    $this->getJson("/api/v1/goals/{$goal->id}", bearerHeaders($viewerToken))->assertOk();
    $this->putJson("/api/v1/goals/{$goal->id}", ['name' => 'Unauthorized rename'], bearerHeaders($viewerToken))->assertForbidden();
    $this->deleteJson("/api/v1/goals/{$goal->id}", [], bearerHeaders($viewerToken))->assertForbidden();
});

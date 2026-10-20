<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Services\Notifier;
use App\Services\ProjectAuthorizer;
use App\Services\ProjectService;
use App\Services\TaskActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskCommentController extends ApiController
{
    public function __construct(
        private readonly ProjectService $service,
        private readonly TaskActivityLogger $logger,
        private readonly Notifier $notifier,
        private readonly ProjectAuthorizer $authorizer,
    ) {}

    /**
     * GET /api/v1/projects/{project}/tasks/{task}/comments
     */
    public function index(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorizer->ensureTaskBelongs($project, $task);
        $this->service->authorizeUserAccess($project, $request->user());

        $comments = $task->comments()->with('user:id,name,profile_photo_path')->get();

        return $this->success($comments);
    }

    /**
     * POST /api/v1/projects/{project}/tasks/{task}/comments
     */
    public function store(Request $request, Project $project, Task $task): JsonResponse
    {
        $this->authorizer->ensureTaskBelongs($project, $task);
        $this->service->authorizeUserAccess($project, $request->user());
        // Viewer tidak boleh comment - gate via authorizer
        $this->authorizer->ensure($request->user(), $project, 'comment');

        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        $comment = $task->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        $this->logger->commented($task, $request->user(), $data['body']);

        // Fire @mention notifications + assignee notification
        $this->notifier->notifyMentions($request->user(), $task, $data['body']);
        if ($task->assigned_to && $task->assigned_to !== $request->user()->id) {
            $this->notifier->notify(
                $request->user(),
                $task->assigned_to,
                'comment',
                ($request->user()->name ?? 'Seseorang') . ' berkomentar di "' . $task->title . '"',
                mb_strlen($data['body']) > 120 ? mb_substr($data['body'], 0, 117) . '…' : $data['body'],
                $task,
            );
        }

        $comment->load('user:id,name,profile_photo_path');

        return $this->created($comment, 'Comment posted.');
    }

    /**
     * DELETE /api/v1/projects/{project}/tasks/{task}/comments/{comment}
     */
    public function destroy(Request $request, Project $project, Task $task, TaskComment $comment): JsonResponse
    {
        $this->authorizer->ensureTaskBelongs($project, $task);
        $this->service->authorizeUserAccess($project, $request->user());

        if ($comment->task_id !== $task->id) {
            abort(404);
        }

        $user = $request->user();
        if ($comment->user_id !== $user->id && ! $user->isAdmin) {
            abort(403, 'Hanya pembuat komentar atau admin yang dapat menghapus.');
        }

        $comment->delete();

        return $this->success(message: 'Comment deleted.');
    }
}

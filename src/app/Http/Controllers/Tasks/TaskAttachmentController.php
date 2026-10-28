<?php

namespace App\Http\Controllers\Tasks;

use App\Files\AttachmentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\TaskAttachmentRequest;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Upload of a task attachment (private files, ADR-0006): P-24 on the task, only while it is open.
 */
class TaskAttachmentController extends Controller
{
    public function store(TaskAttachmentRequest $request, Task $task, AttachmentService $files): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        abort_unless($task->status->isOpen(), 409);

        $files->store($actor, $task, $request->file('file'), 'task_result');

        return back();
    }
}

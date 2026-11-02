<?php

use App\Actions\StateMachines\M07Task\T06ReassignTask;
use App\Actions\StateMachines\M16Appointment\T00ProposeAppointment;
use App\Actions\StateMachines\M16Appointment\T02ConfirmAppointment;
use App\Enums\TaskStatus;
use App\Models\Appointment;
use App\Models\Order;
use App\Models\User;
use App\Workflow\ActionResults;
use App\Workflow\StageEngine;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/**
 * Brings the fixture's order to its installation stage, books `$members` on a confirmed installation appointment
 * tomorrow linked to the installation task, and (when given) hands that task to `$assignee`.
 */
function installationAppointment(object $f, array $members, ?User $assignee = null, string $day = '+1 day'): Appointment
{
    $owner = $f->org->owner;
    $order = $f->order;
    while (app(StageEngine::class)->activeStage($order->refresh())?->key !== 'install') {
        finishActiveStage($owner, $order);
    }
    $task = taskOf($order, 'installation');
    if ($assignee !== null && $task->assignee_id !== $assignee->id) {
        app(T06ReassignTask::class)->handle($owner, $task, $assignee->id, 'فريق التركيب');
    }
    $start = CarbonImmutable::parse($day, 'Asia/Riyadh')->setTime(10, 0);
    $appointment = app(T00ProposeAppointment::class)->handle($owner, $order, [
        'kind' => 'installation', 'starts_at' => $start->format('Y-m-d H:i').' Asia/Riyadh', 'ends_at' => $start->addHours(3)->format('Y-m-d H:i').' Asia/Riyadh',
        'address' => 'الرياض، حي الملقا، شارع 7', 'latitude' => 24.81, 'longitude' => 46.61,
        'member_ids' => array_map(fn (User $u) => $u->id, $members), 'task_id' => $task->id,
    ])['appointment'];

    return app(T02ConfirmAppointment::class)->handle($owner, $appointment, 'phone')->refresh();
}

/** Verifies the install stage's other required work (payment, delivery) as their sources would. */
function settleOtherInstallWork(object $f, Order $order): void
{
    foreach (['final_payment', 'delivery'] as $key) {
        $action = actionOf($order, $key);
        if (! $action->status->satisfies()) {
            app(ActionResults::class)->verifyFromSource($order->refresh(), $action->type, purposeOf($action), 'test_source', $action->id, $f->org->owner);
        }
    }
}

function installPhoto(string $name = 'site.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, 800, 600);
}

/** @return array<string, mixed> */
function installPhotoForm(string $phase, ?string $uuid = null): array
{
    return ['phase' => $phase, 'client_uuid' => $uuid ?? (string) Str::uuid(), 'file' => installPhoto()];
}

function installSignaturePng(): string
{
    $image = imagecreatetruecolor(480, 160);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    $ink = (int) imagecolorallocate($image, 20, 30, 60);
    imagesetthickness($image, 4);
    imageline($image, 30, 120, 180, 40, $ink);
    imageline($image, 180, 40, 440, 130, $ink);
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

/** @return array<string, mixed> */
function installSignatureForm(): array
{
    return ['signer_name' => 'أحمد العميل', 'signature' => installSignaturePng(), 'consent' => true];
}

function openTaskStatus(Order $order): TaskStatus
{
    return taskOf($order, 'installation')->status;
}

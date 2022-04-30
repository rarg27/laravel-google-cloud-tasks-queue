<?php

namespace Stackkit\LaravelGoogleCloudTasksQueue;

use Exception;
use Google\Cloud\Tasks\V2\HttpRequest;
use Google\Cloud\Tasks\V2\Task;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function Safe\json_decode;

class DashboardService
{
    public static function make(): DashboardService
    {
        return new DashboardService();
    }

    private function getTaskBody(Task $task): string
    {
        $httpRequest = $task->getHttpRequest();

        if (! $httpRequest instanceof HttpRequest) {
            throw new Exception('Task does not have a HTTP request.');
        }

        return $httpRequest->getBody();
    }

    public function add(string $queue, Task $task): void
    {
        $metadata = new TaskMetadata();
        $metadata->payload = $this->getTaskBody($task);

        $data = [
            'queue' => $queue,
        ];

        $scheduleTime = $task->getScheduleTime();

        if ($scheduleTime) {
            $status = 'scheduled';
            $data['scheduled_at'] = $scheduleTime->toDateTime()->format('Y-m-d H:i:s');
        } else {
            $status = 'queued';
        }

        $metadata->addEvent($status, $data);

        $taskModel = new StackkitCloudTask;
        $taskModel->setRawAttributes([
            'task_uuid' => $this->getTaskUuid($task),
            'name' => $this->getTaskName($task),
            'queue' => $queue,
            'payload' =>  $this->getTaskBody($task),
            'status' => $status,
            'metadata' => $metadata->toJson(),
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);
        $taskModel->save();
    }

    public function markAsRunning(string $uuid): void
    {
        $task = StackkitCloudTask::findByUuid($uuid);

        $task->status = 'running';
        $task->addMetadataEvent([
            'status' => $task->status,
            'datetime' => now()->utc()->toDateTimeString(),
        ]);

        $task->save();
    }

    public function markAsSuccessful(string $uuid): void
    {
        $task = StackkitCloudTask::findByUuid($uuid);

        $task->status = 'successful';
        $task->addMetadataEvent([
            'status' => $task->status,
            'datetime' => now()->utc()->toDateTimeString(),
        ]);

        $task->save();
    }

    public function markAsError(JobExceptionOccurred $event): void
    {
        /** @var CloudTasksJob $job */
        $job = $event->job;

        try {
            $task = StackkitCloudTask::findByUuid($job->uuid());
        } catch (ModelNotFoundException $e) {
            return;
        }

        if ($task->status === 'failed') {
            return;
        }

        $task->status = 'error';
        $task->addMetadataEvent([
            'status' => $task->status,
            'datetime' => now()->utc()->toDateTimeString(),
        ]);
        $task->setMetadata('exception', (string) $event->exception);

        $task->save();
    }

    public function markAsFailed(JobFailed $event): void
    {
        /** @var CloudTasksJob $job */
        $job = $event->job;

        $task = StackkitCloudTask::findByUuid($job->uuid());

        $task->status = 'failed';
        $task->addMetadataEvent([
            'status' => $task->status,
            'datetime' => now()->utc()->toDateTimeString(),
        ]);

        $task->save();
    }

    private function getTaskName(Task $task): string
    {
        /** @var array $decode */
        $decode = json_decode($this->getTaskBody($task), true);

        return $decode['displayName'];
    }

    private function getTaskUuid(Task $task): string
    {
        /** @var array $task */
        $task = json_decode($this->getTaskBody($task), true);

        return $task['uuid'];
    }
}

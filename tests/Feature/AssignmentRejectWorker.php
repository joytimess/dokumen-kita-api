<?php

use App\Exceptions\ApiException;
use App\Models\SignerAssignment;
use App\Models\User;
use App\Services\AssignmentRejectionService;
use App\Services\RevisionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

[$script, $database, $userGuid, $assignmentGuid, $storageRoot, $gate] = $argv;
$operation = $argv[6] ?? 'reject';
if (preg_match('/\Adk_be05_test_[a-f0-9]{16}\z/', $database) !== 1) {
    exit(1);
}
config([
    'database.default' => 'mysql',
    'database.connections.mysql.url' => null,
    'database.connections.mysql.database' => $database,
    'documents.disk' => 'local',
    'filesystems.disks.local.root' => $storageRoot,
]);
DB::purge('mysql');
DB::setDefaultConnection('mysql');

$lockWaitMs = 0;
DB::listen(function (QueryExecuted $query) use (&$lockWaitMs): void {
    if (str_contains($query->sql, 'from `documents`') && str_contains($query->sql, 'for update')) {
        $lockWaitMs = $query->time;
        usleep(250_000);
    }
});
echo "ready\n";
flush();
$deadline = microtime(true) + 10;
while (! is_file($gate)) {
    if (microtime(true) >= $deadline) {
        exit(1);
    }
    usleep(1_000);
}

try {
    $actor = User::where('guid', $userGuid)->firstOrFail();
    $assignment = SignerAssignment::where('guid', $assignmentGuid)->firstOrFail();
    if ($operation === 'revision') {
        $result = $application->make(RevisionService::class)->request($actor, $assignment, 'Concurrent revision test.');
        echo json_encode(['version_status' => $result['previous']->status->value, 'draft_guid' => $result['draft']->guid, 'lock_wait_ms' => $lockWaitMs], JSON_THROW_ON_ERROR)."\n";
    } else {
        $assignment = $application->make(AssignmentRejectionService::class)->reject($actor, $assignment, 'Concurrent rejection test.');
        echo json_encode(['assignment_status' => $assignment->status, 'lock_wait_ms' => $lockWaitMs], JSON_THROW_ON_ERROR)."\n";
    }
} catch (ApiException $exception) {
    echo json_encode(['error' => $exception->errorCode, 'status' => $exception->status, 'lock_wait_ms' => $lockWaitMs], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception)."\n");
    exit(1);
}

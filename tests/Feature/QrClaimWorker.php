<?php

use App\Models\DocumentVersionQr;
use App\Models\SignerAssignment;
use App\Models\User;
use App\Services\QrSigningService;
use App\Services\QrTokenService;
use App\Services\ReadConfirmationService;
use App\Services\SignatureService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$application = require dirname(__DIR__, 2).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();
[$script, $database, $versionGuid, $userGuid, $assetGuid, $storageRoot, $gate] = $argv;
if (preg_match('/\Adk_qr_test_[a-f0-9]{16}\z/', $database) !== 1) {
    exit(1);
}
config([
    'database.default' => 'mysql', 'database.connections.mysql.url' => null,
    'database.connections.mysql.database' => $database,
    'documents.disk' => 'local', 'filesystems.disks.local.root' => $storageRoot,
]);
DB::purge('mysql');
DB::setDefaultConnection('mysql');
$lockWaitMs = 0;
DB::listen(function (QueryExecuted $query) use (&$lockWaitMs): void {
    if (str_contains($query->sql, 'from `documents`') && str_contains($query->sql, 'for update')) {
        $lockWaitMs = max($lockWaitMs, $query->time);
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
    usleep(1000);
}
try {
    $user = User::where('guid', $userGuid)->firstOrFail();
    $qr = DocumentVersionQr::where('version_guid', $versionGuid)->firstOrFail();
    $token = $application->make(QrTokenService::class)->token($qr);
    $claim = $application->make(QrSigningService::class)->claim($user, $token);
    if ($claim['status'] === 'active') {
        $assignment = SignerAssignment::where('guid', $claim['assignment_guid'])->firstOrFail();
        $hash = $assignment->version->content_hash;
        $application->make(ReadConfirmationService::class)->confirm($user, $assignment, $hash);
        $application->make(SignatureService::class)->sign($user, $assignment, $assetGuid, $hash, 'qr-concurrent-sign');
    }
    echo json_encode(['status' => $claim['status'], 'lock_wait_ms' => $lockWaitMs], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception)."\n");
    exit(1);
}

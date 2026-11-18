<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/MySqlTestDatabaseGuard.php';

use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\MySqlTestDatabaseGuard;

try {
    $app = MySqlTestDatabaseGuard::boot();
} catch (Throwable) {
    fwrite(STDERR, "Concurrent worker refused: active database verification failed.\n");
    exit(2);
}

$barrierDirectory = getenv('EVENTPASS_BARRIER_DIRECTORY');
$label = getenv('EVENTPASS_WORKER_LABEL');
if (! $barrierDirectory || ! $label || ! is_dir($barrierDirectory)) {
    fwrite(STDERR, "Concurrent worker refused: synchronization settings are missing.\n");
    exit(2);
}

$requestData = json_decode(stream_get_contents(STDIN), true);
if (
    ! is_array($requestData)
    || ! isset($requestData['method'], $requestData['uri'], $requestData['payload'], $requestData['token'])
    || ! is_string($requestData['token'])
    || $requestData['token'] === ''
    || ! is_string($requestData['uri'])
    || ! str_starts_with($requestData['uri'], '/api/')
    || ! in_array(strtoupper((string) $requestData['method']), ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true)
    || ! is_array($requestData['payload'])
) {
    fwrite(STDERR, "Concurrent worker refused: request input is invalid.\n");
    exit(2);
}

$connectionId = DB::connection('mysql')->selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;

$followup = $requestData['followup'] ?? null;
if ($followup !== null && (
    ! is_array($followup)
    || ! isset($followup['method'], $followup['uri'], $followup['payload'], $followup['token'])
    || ! is_string($followup['token'])
    || $followup['token'] === ''
    || ! is_string($followup['uri'])
    || ! str_starts_with($followup['uri'], '/api/')
    || ! in_array(strtoupper((string) $followup['method']), ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true)
    || ! is_array($followup['payload'])
)) {
    fwrite(STDERR, "Concurrent worker refused: follow-up request input is invalid.\n");
    exit(2);
}

file_put_contents($barrierDirectory.DIRECTORY_SEPARATOR.$label.'.ready', 'ready', LOCK_EX);
$deadline = microtime(true) + 40;
while (! is_file($barrierDirectory.DIRECTORY_SEPARATOR.'go')) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Concurrent worker timed out waiting for the test barrier.\n");
        exit(2);
    }
    usleep(10000);
}

$method = strtoupper($requestData['method']);
$uri = $requestData['uri'];
$payload = $requestData['payload'];
$bearer = $requestData['token'];

$kernel = $app->make(HttpKernel::class);
$dispatch = static function (string $method, string $uri, array $payload, string $bearer) use ($kernel): array {
    Auth::forgetGuards();
    $request = Request::create(
        $uri,
        strtoupper($method),
        [],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$bearer,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_HOST' => 'localhost',
            'REMOTE_ADDR' => '127.0.0.1',
        ],
        json_encode($payload, JSON_THROW_ON_ERROR),
    );
    $request->headers->set('Accept', 'application/json');
    $request->headers->set('Authorization', 'Bearer '.$bearer);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return [
        'status' => $response->getStatusCode(),
        'body' => json_decode($response->getContent(), true),
    ];
};

$serviceResult = null;
if (preg_match('#^/api/__integration/reservations/(\d+)/expire$#', $uri, $matches)) {
    $reservation = Reservation::query()->findOrFail((int) $matches[1]);
    $serviceResult = $app->make(ReservationService::class)->expire($reservation);
    $response = ['status' => $serviceResult ? 200 : 204, 'body' => null];
} else {
    $response = $dispatch($method, $uri, $payload, $bearer);
}
$followupStatuses = null;
if ($followup !== null && $response['status'] === 201) {
    $issuedQrToken = $response['body']['data']['qr_token'] ?? null;
    if (is_string($issuedQrToken) && $issuedQrToken !== '') {
        $followupPayload = $followup['payload'];
        $followupPayload['qr_token'] = $issuedQrToken;
        $followupResponse = $dispatch(
            $followup['method'],
            $followup['uri'],
            $followupPayload,
            $followup['token'],
        );
        $followupStatuses = [$followupResponse['status']];

        if (
            $followupResponse['status'] === 409
            && ($followupResponse['body']['message'] ?? null) === 'This ticket is already inside.'
        ) {
            $followupPayload['action'] = 'EXIT';
            $followupResponse = $dispatch(
                $followup['method'],
                $followup['uri'],
                $followupPayload,
                $followup['token'],
            );
            $followupStatuses[] = $followupResponse['status'];
        }
    } else {
        $followupStatuses = [];
    }
}

// Do not emit response bodies: reservation responses can contain one-time QR credentials.
fwrite(STDOUT, json_encode([
    'label' => $label,
    'status' => $response['status'],
    'followup_statuses' => $followupStatuses,
    'service_result' => $serviceResult,
    'pid' => getmypid(),
    'connection_id' => $connectionId,
], JSON_THROW_ON_ERROR).PHP_EOL);

<?php
/**
 * FILE: tests/diag_predict.php
 * WHAT IT DOES: Troubleshooting script that shows why the PHP-to-Python
 * prediction call might fail and fall back.
 * FIND: prediction diagnostic, debug model
 *
 * Temporary diagnostic: why does model_predict() fall back?
 *
 * Replicates modules/prediction.php::model_predict() exactly, except that
 * it READS the stderr pipe instead of closing it unread, and reports the
 * exit code and elapsed time.
 *
 * Place in qms\tests\ and run:
 *   C:\xampp\php\php.exe tests\diag_predict.php
 */
require __DIR__ . '/../config/config.php';

function line(string $k, string $v): void
{
    printf("%-16s: %s%s", $k, $v, PHP_EOL);
}

line('PYTHON_BIN', PYTHON_BIN);
line('ML_DIR', defined('ML_DIR') ? ML_DIR : '(not defined)');
line('MODEL_FILE', MODEL_FILE . '   exists=' . (is_file(MODEL_FILE) ? 'YES' : 'NO'));
line('PREDICT_SCRIPT', PREDICT_SCRIPT . '   exists=' . (is_file(PREDICT_SCRIPT) ? 'YES' : 'NO'));
line('TIMEOUT', PREDICTION_TIMEOUT . ' seconds');
echo str_repeat('-', 68), PHP_EOL;

if (!is_file(MODEL_FILE) || !is_file(PREDICT_SCRIPT)) {
    echo 'STOP: the guard in model_predict() returns null here, before', PHP_EOL;
    echo 'Python is ever launched. The missing file above is the cause.', PHP_EOL;
    exit(1);
}

$rows = [[
    'queue_length'     => 5,
    'arrival_rate'     => 12.0,
    'service_rate'     => 7.5,
    'avg_service_time' => 8.0,
    'time_of_day'      => 14,
]];
$payload = json_encode(['rows' => $rows]);

$cmd = [PYTHON_BIN, PREDICT_SCRIPT];
line('command', implode(' ', $cmd));

$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$start = microtime(true);
$proc  = @proc_open($cmd, $descriptors, $pipes);

if (!is_resource($proc)) {
    line('proc_open', 'FAILED — the interpreter could not be started');
    echo PHP_EOL, 'This is the cause: PHP cannot launch that command.', PHP_EOL;
    exit(1);
}
line('proc_open', 'started');

fwrite($pipes[0], $payload);
fclose($pipes[0]);

$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);   // the channel the real code discards
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($proc);
$ms   = (int)round((microtime(true) - $start) * 1000);

echo str_repeat('-', 68), PHP_EOL;
line('exit code', (string)$exit);
line('elapsed', $ms . ' ms  (budget ' . (PREDICTION_TIMEOUT * 1000) . ' ms)');
line('stdout', trim($out) === '' ? '(empty)' : trim($out));
line('stderr', trim($err) === '' ? '(empty)' : trim($err));
echo str_repeat('-', 68), PHP_EOL;

$decoded = json_decode((string)$out, true);
if (is_array($decoded) && isset($decoded['predictions'])
    && count($decoded['predictions']) === count($rows)) {
    echo 'RESULT: model returned ', count($decoded['predictions']),
         ' prediction(s): ', implode(', ', $decoded['predictions']), PHP_EOL;
    echo 'The model path works from PHP. ', PHP_EOL;
} else {
    echo 'RESULT: the response was rejected, so model_predict() returns null', PHP_EOL;
    echo 'and the caller uses the fallback. The stderr line above says why.', PHP_EOL;
}

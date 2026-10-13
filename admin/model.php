<?php
/**
 * Prediction model screen (administrator).
 *
 * Named in the navigation of Wireframe 8. Reports how the Random Forest
 * model was trained, how it is performing against the waiting times the
 * system has actually recorded, and whether the runtime path is healthy.
 * This is the operational counterpart to the evaluation in Chapter 5.
 */
define('QMS_ROOT', '../');
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../modules/prediction.php';
require_once __DIR__ . '/../modules/service_delivery.php';

$user = require_role('admin');

// ------------------------------------------------------- model artefacts
$modelFile  = MODEL_FILE;
$forestFile = ML_DIR . '/model/forest.npz';
$metricsFile = ML_DIR . '/model/training_metrics.json';
$datasetFile = ML_DIR . '/data/queue_dataset.csv';

$metrics = is_file($metricsFile)
    ? json_decode((string)file_get_contents($metricsFile), true) : null;

// ------------------------------------------------- live runtime health check
$probe = null;
if (isset($_GET['probe'])) {
    $started = microtime(true);
    $result = model_predict([[
        'queue_length'     => 5,
        'arrival_rate'     => 20.0,
        'service_rate'     => 10.0,
        'avg_service_time' => 6.0,
        'time_of_day'      => (int)date('G'),
    ]]);
    $probe = [
        'ok'      => $result !== null,
        'value'   => $result[0] ?? null,
        'ms'      => (int)round((microtime(true) - $started) * 1000),
    ];
}

// ------------------------------------------------- accuracy on live records
$live = q_one(
    'SELECT COUNT(*) AS n,
            AVG(ABS(predicted_wait_time -
                TIMESTAMPDIFF(SECOND, join_time, service_start_time)/60)) AS mae,
            SQRT(AVG(POW(predicted_wait_time -
                TIMESTAMPDIFF(SECOND, join_time, service_start_time)/60, 2))) AS rmse,
            AVG(ABS(predicted_wait_time -
                TIMESTAMPDIFF(SECOND, join_time, service_start_time)/60) <= 5) * 100
                AS within_5,
            AVG(predicted_wait_time -
                TIMESTAMPDIFF(SECOND, join_time, service_start_time)/60) AS bias
       FROM queue_entry
      WHERE status = "completed" AND prediction_source = "model"
        AND predicted_wait_time IS NOT NULL AND service_start_time IS NOT NULL') ?: [];

$byService = q_all(
    'SELECT service_type, COUNT(*) AS n,
            AVG(ABS(predicted_wait_time -
                TIMESTAMPDIFF(SECOND, join_time, service_start_time)/60)) AS mae
       FROM queue_entry
      WHERE status = "completed" AND prediction_source = "model"
        AND predicted_wait_time IS NOT NULL AND service_start_time IS NOT NULL
      GROUP BY service_type ORDER BY mae DESC');

$sourceSplit = q_all(
    'SELECT prediction_source, COUNT(*) AS n FROM queue_entry
      WHERE prediction_source IS NOT NULL GROUP BY prediction_source');
$sources = ['model' => 0, 'fallback' => 0];
foreach ($sourceSplit as $row) {
    $sources[$row['prediction_source']] = (int)$row['n'];
}
$sourceTotal = array_sum($sources);

// Current live feature values, so the administrator can see what the model
// is actually being asked about right now.
$featureRows = [];
foreach (q_all('SELECT DISTINCT service_type FROM service_counter ORDER BY service_type')
         as $row) {
    $featureRows[$row['service_type']] = queue_features($row['service_type']);
}

page_head('Prediction model', 'model', [
    'heading'    => 'Prediction model',
    'subheading' => 'Random Forest Regression — training, health and live accuracy',
]);
?>
<div class="side-layout">
  <nav class="sidenav">
    <a href="dashboard.php">Dashboard</a>
    <a href="monitor.php">Live queue monitor</a>
    <a href="counters.php">Service counters</a>
    <a href="reports.php">Reports &amp; analytics</a>
    <a class="on" href="model.php">Prediction model</a>
    <a href="notifications.php">Notifications log</a>
    <a href="gateway.php">SMS gateway</a>
    <a href="users.php">User accounts</a>
    <a href="../index.php">Public site</a>
  </nav>

  <div>
    <div class="panel">
      <h2>Runtime health</h2>
      <table>
        <tr><td>Trained model (<code>rf_model.joblib</code>)</td>
          <td class="right"><?= is_file($modelFile)
                ? '<span class="badge completed">PRESENT</span> <span class="tiny muted">'
                  . number_format(filesize($modelFile) / 1048576, 1) . ' MB, trained '
                  . date('d M Y H:i', filemtime($modelFile)) . '</span>'
                : '<span class="badge expired">MISSING</span>' ?></td></tr>
        <tr><td>Exported forest (<code>forest.npz</code>) &mdash; the fast runtime path</td>
          <td class="right"><?= is_file($forestFile)
                ? '<span class="badge completed">PRESENT</span> <span class="tiny muted">'
                  . number_format(filesize($forestFile) / 1024, 0) . ' KB</span>'
                : '<span class="badge waiting">ABSENT</span> <span class="tiny muted">'
                  . 'falls back to scikit-learn, roughly nine times slower</span>' ?></td></tr>
        <tr><td>Training dataset (<code>queue_dataset.csv</code>)</td>
          <td class="right"><?= is_file($datasetFile)
                ? '<span class="badge completed">PRESENT</span>'
                : '<span class="badge waiting">ABSENT</span>' ?></td></tr>
        <tr><td>Python interpreter</td>
          <td class="right"><code><?= e(PYTHON_BIN) ?></code></td></tr>
      </table>

      <div class="btn-row mt">
        <a class="btn ghost sm" href="model.php?probe=1">Run a live prediction probe</a>
      </div>

      <?php if ($probe !== null): ?>
        <div class="alert <?= $probe['ok'] ? 'success' : 'error' ?>" style="margin-top:14px;">
          <?php if ($probe['ok']): ?>
            <b>Model responded in <?= $probe['ms'] ?> ms.</b>
            For a queue of 5 ahead at <?= date('H') ?>:00 it predicts
            <b><?= e(fmt_minutes($probe['value'])) ?></b>.
            <?= $probe['ms'] <= 1000
                  ? 'This is inside the one-second budget set by NFR-02.'
                  : 'This exceeds the one-second budget set by NFR-02 — check that '
                    . 'forest.npz exists.' ?>
          <?php else: ?>
            <b>The model did not respond.</b> Waiting times are being produced by
            the deterministic fallback. Check that Python is on the path and that
            the model files above are present.
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h2>Training results</h2>
      <?php if (!$metrics): ?>
        <div class="empty">
          No training metrics found. Run <code>python train_model.py</code> in the
          <code>ml</code> folder.
        </div>
      <?php else: ?>
        <div class="grid g4" style="margin-bottom:16px;">
          <div class="stat"><div class="k">Training records</div>
            <div class="v"><?= number_format((int)$metrics['records']) ?></div>
            <div class="d"><?= (int)$metrics['train_size'] ?> train /
              <?= (int)$metrics['test_size'] ?> test</div></div>
          <div class="stat"><div class="k">Held-out MAE</div>
            <div class="v"><?= e($metrics['mae_minutes']) ?><small> min</small></div>
            <div class="d">RMSE <?= e($metrics['rmse_minutes']) ?> min</div></div>
          <div class="stat"><div class="k">R-squared</div>
            <div class="v"><?= e($metrics['r2']) ?></div>
            <div class="d">variance explained</div></div>
          <div class="stat"><div class="k">Within 5 minutes</div>
            <div class="v"><?= e($metrics['within_5_minutes_percent']) ?><small>%</small></div>
            <div class="d">baseline MAE <?= e($metrics['baseline_mae_minutes']) ?> min</div></div>
        </div>

        <h2 style="margin-top:22px;">Feature importance</h2>
        <?php
          $imp = $metrics['feature_importance'];
          arsort($imp);
          $maxImp = max($imp) ?: 1;
        ?>
        <table>
          <?php foreach ($imp as $feature => $value): ?>
            <tr>
              <td style="width:170px;"><code><?= e($feature) ?></code></td>
              <td>
                <div class="impbar">
                  <i style="width:<?= (int)round($value / $maxImp * 100) ?>%"></i>
                </div>
              </td>
              <td class="right" style="width:70px;"><b><?= number_format($value, 3) ?></b></td>
            </tr>
          <?php endforeach; ?>
        </table>
        <p class="tiny muted mt">
          The ordering matches queueing theory: the number of clients ahead
          dominates, followed by how fast new clients are arriving. That is a
          useful check that the model has learned the structure of the problem
          rather than noise in the dataset.
        </p>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h2>Accuracy against waiting times the system actually recorded</h2>
      <?php if ((int)($live['n'] ?? 0) === 0): ?>
        <div class="empty">
          No completed services with a model prediction yet. This panel fills in
          once clients have been served.
        </div>
      <?php else: ?>
        <div class="grid g4" style="margin-bottom:16px;">
          <div class="stat"><div class="k">Observations</div>
            <div class="v"><?= number_format((int)$live['n']) ?></div>
            <div class="d">completed, model-predicted</div></div>
          <div class="stat"><div class="k">Live MAE</div>
            <div class="v"><?= number_format((float)$live['mae'], 2) ?><small> min</small></div>
            <div class="d">RMSE <?= number_format((float)$live['rmse'], 2) ?> min</div></div>
          <div class="stat"><div class="k">Within 5 minutes</div>
            <div class="v"><?= number_format((float)$live['within_5'], 1) ?><small>%</small></div></div>
          <div class="stat"><div class="k">Bias</div>
            <div class="v"><?= ((float)$live['bias'] >= 0 ? '+' : '')
                  . number_format((float)$live['bias'], 2) ?><small> min</small></div>
            <div class="d"><?= (float)$live['bias'] >= 0
                  ? 'tends to over-estimate' : 'tends to under-estimate' ?></div></div>
        </div>

        <table>
          <thead><tr><th>Service type</th><th>Observations</th><th>MAE</th></tr></thead>
          <tbody>
          <?php foreach ($byService as $row): ?>
            <tr><td><?= e($row['service_type']) ?></td>
              <td><?= (int)$row['n'] ?></td>
              <td><?= number_format((float)$row['mae'], 2) ?> min</td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p class="tiny muted mt">
          Bias matters as much as error size. A model that consistently
          over-estimates is more forgivable in a queue than one that
          under-estimates, because a client told to expect longer and served
          sooner is not inconvenienced.
        </p>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h2>Prediction source split</h2>
      <?php if ($sourceTotal === 0): ?>
        <div class="empty">No predictions issued yet.</div>
      <?php else: ?>
        <table>
          <tr><td>Produced by the Random Forest model</td>
            <td class="right"><b><?= number_format($sources['model']) ?></b>
              (<?= round($sources['model'] / $sourceTotal * 100, 1) ?>%)</td></tr>
          <tr><td>Produced by the deterministic fallback</td>
            <td class="right"><b><?= number_format($sources['fallback']) ?></b>
              (<?= round($sources['fallback'] / $sourceTotal * 100, 1) ?>%)</td></tr>
        </table>
        <p class="tiny muted mt">
          A rising fallback share means the model is becoming unreachable.
          Fallback predictions are excluded from every accuracy figure on this
          page, so the numbers above measure the model and not the system's
          ability to guess.
        </p>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h2>Live feature values</h2>
      <p class="tiny muted" style="margin-bottom:12px;">
        What the model is being asked about right now, per service type.
      </p>
      <table>
        <thead><tr><th>Service</th><th>Arrival rate</th><th>Service rate</th>
          <th>Avg service time</th><th>Open counters</th><th>Hour</th></tr></thead>
        <tbody>
        <?php foreach ($featureRows as $service => $f): ?>
          <tr>
            <td><b><?= e($service) ?></b></td>
            <td><?= number_format($f['arrival_rate'], 1) ?>/hr</td>
            <td><?= number_format($f['service_rate'], 1) ?>/hr</td>
            <td><?= number_format($f['avg_service_time'], 1) ?> min</td>
            <td><?= (int)$f['open_counters'] ?></td>
            <td><?= sprintf('%02d:00', $f['time_of_day']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="tiny muted mt">
        <code>queue_length</code> is not shown here because it differs per
        client: each waiting entry is scored with the number of clients ahead
        of that particular entry.
      </p>
    </div>
  </div>
</div>
<?php page_foot(); ?>

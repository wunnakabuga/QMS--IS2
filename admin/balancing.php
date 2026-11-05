<?php
/**
 * Counter load balancing — administrator view.
 *
 * Shows where the demand actually is across the floor, which counters are
 * idle while it builds, how the overflow rule is configured, and what the
 * rule has achieved. Implements the administrative half of FR-21/FR-22.
 */
define('QMS_ROOT', '../');
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../modules/load_balancing.php';
require_once __DIR__ . '/../modules/settings.php';

require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? null)) {
    $threshold = (float)($_POST['overflow_threshold'] ?? 3);
    $minWait   = (int)($_POST['overflow_min_waiting'] ?? 2);

    if ($threshold < 1 || $threshold > 50) {
        flash('The congestion threshold must be between 1 and 50.', 'error');
    } elseif ($minWait < 1 || $minWait > 50) {
        flash('The minimum queue must be between 1 and 50.', 'error');
    } else {
        setting_set_many([
            'overflow_enabled'     => isset($_POST['overflow_enabled']) ? '1' : '0',
            'overflow_auto'        => isset($_POST['overflow_auto']) ? '1' : '0',
            'overflow_threshold'   => (string)$threshold,
            'overflow_min_waiting' => (string)$minWait,
        ]);
        flash('Load balancing settings saved.', 'success');
    }
    redirect('balancing.php');
}

$loads = service_load_snapshot();
$stats = overflow_stats($_GET['from'] ?? null, $_GET['to'] ?? null);

// Open counters with nobody in front of them, and what each could help with.
$idle = [];
foreach (q_all('SELECT counter_id, counter_name, service_type, overflow_enabled
                  FROM service_counter WHERE status = "open"
                 ORDER BY counter_id') as $c) {
    $id = (int)$c['counter_id'];
    if (!counter_is_idle($id)) {
        continue;
    }
    $c['recommendation'] = overflow_recommendation($id);
    $idle[] = $c;
}

$congested = array_values(array_filter(
    $loads, fn($l) => $l['congested'] || $l['unstaffed']
));

page_head('Counter load balancing', 'balancing', [
    'heading'    => 'Counter load balancing',
    'subheading' => 'Move spare counter capacity to where the queue is',
]);
?>
<div class="side-layout">
  <nav class="sidenav">
    <a href="dashboard.php">Dashboard</a>
    <a href="monitor.php">Live queue monitor</a>
    <a href="counters.php">Service counters</a>
    <a class="on" href="balancing.php">Load balancing</a>
    <a href="reports.php">Reports &amp; analytics</a>
    <a href="model.php">Prediction model</a>
    <a href="notifications.php">Notifications log</a>
    <a href="gateway.php">SMS gateway</a>
    <a href="users.php">User accounts</a>
    <a href="../index.php">Public site</a>
  </nav>

  <div>
    <?php if (!overflow_is_enabled()): ?>
      <div class="alert warn">
        Load balancing is switched off. Idle counters will wait for their own
        service type even while another queue builds up.
      </div>
    <?php elseif ($idle && $congested): ?>
      <div class="alert warn">
        <b><?= count($idle) ?> counter<?= count($idle) === 1 ? ' is' : 's are' ?>
        free</b> while <?= count($congested) ?>
        queue<?= count($congested) === 1 ? '' : 's' ?>
        <?= count($congested) === 1 ? 'is' : 'are' ?> congested.
        <?= overflow_is_automatic()
              ? 'Automatic mode is on, so the next Call next at those counters '
                . 'will take from the busiest queue.'
              : 'The overflow option is showing on those counter consoles now.' ?>
      </div>
    <?php endif; ?>

    <div class="grid g4" style="margin-bottom:16px;">
      <div class="stat"><div class="k">Open counters idle</div>
        <div class="v"><?= count($idle) ?></div>
        <div class="d">no client in front of them</div></div>
      <div class="stat"><div class="k">Congested queues</div>
        <div class="v"><?= count($congested) ?></div>
        <div class="d">above <?= e(overflow_threshold()) ?> per counter</div></div>
      <div class="stat"><div class="k">Served by overflow</div>
        <div class="v"><?= number_format($stats['served_by_overflow']) ?></div>
        <div class="d"><?= e($stats['share_percent']) ?>% of completions</div></div>
      <div class="stat"><div class="k">Counter time recovered</div>
        <div class="v"><?= number_format($stats['minutes_recovered']) ?><small> min</small></div>
        <div class="d">would have been idle</div></div>
    </div>

    <!-- ------------------------------------------------ live floor load -->
    <div class="panel">
      <h2>Load by service type</h2>
      <?php if (!$loads): ?>
        <div class="empty">No queues and no counters configured yet.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Service type</th>
              <th class="right">Waiting</th>
              <th class="right">Open counters</th>
              <th class="right">Per counter</th>
              <th class="right">Longest wait</th>
              <th>State</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($loads as $l): ?>
              <tr>
                <td><b><?= e($l['service_type']) ?></b></td>
                <td class="right"><?= (int)$l['waiting'] ?></td>
                <td class="right"><?= (int)$l['open_counters'] ?>
                  <span class="tiny muted">of <?= (int)$l['total_counters'] ?></span></td>
                <td class="right">
                  <?= $l['load_per_counter'] === null
                        ? '&mdash;' : e($l['load_per_counter']) ?>
                </td>
                <td class="right">
                  <?= $l['longest_wait_minutes'] > 0
                        ? (int)$l['longest_wait_minutes'] . ' min' : '&mdash;' ?>
                </td>
                <td class="tiny">
                  <?php if ($l['unstaffed']): ?>
                    <b>unstaffed &mdash; queue with no open counter</b>
                  <?php elseif ($l['congested']): ?>
                    <b>congested</b>
                  <?php elseif ($l['waiting'] === 0): ?>
                    <span class="muted">clear</span>
                  <?php else: ?>
                    <span class="muted">normal</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p class="tiny muted mt">
          Per counter is the queue depth divided by the number of open
          counters, which is the figure that decides whether a queue is in
          trouble: twelve clients across four counters is routine, twelve
          across one is not.
        </p>
      <?php endif; ?>
    </div>

    <!-- --------------------------------------------------- idle counters -->
    <div class="panel mt">
      <h2>Idle counters right now</h2>
      <?php if (!$idle): ?>
        <div class="empty">
          Every open counter has a client or a queue of its own. Nothing to
          reallocate.
        </div>
      <?php else: ?>
        <table>
          <thead><tr>
            <th>Counter</th><th>Own service</th><th>Overflow</th>
            <th>Best use right now</th>
          </tr></thead>
          <tbody>
            <?php foreach ($idle as $c): ?>
              <tr>
                <td><b><?= e($c['counter_name']) ?></b></td>
                <td class="tiny"><?= e($c['service_type']) ?></td>
                <td class="tiny">
                  <?= (int)$c['overflow_enabled'] === 1
                        ? 'allowed'
                        : '<span class="muted">own service only</span>' ?>
                </td>
                <td class="tiny">
                  <?php if (!overflow_is_enabled()): ?>
                    <span class="muted">load balancing is off</span>
                  <?php elseif ((int)$c['overflow_enabled'] !== 1): ?>
                    <span class="muted">not cleared for other services</span>
                  <?php elseif ($c['recommendation']): ?>
                    <b><?= e($c['recommendation']['service_type']) ?></b>
                    &mdash; <?= (int)$c['recommendation']['waiting'] ?> waiting
                  <?php else: ?>
                    <span class="muted">no congested queue to help</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p class="tiny muted mt">
          A counter appears here when it is open, has no client in front of
          it, and has nobody waiting for its own service. Its own queue is
          always served first, so overflow only ever uses spare capacity.
        </p>
      <?php endif; ?>
    </div>

    <!-- ------------------------------------------------------- settings -->
    <div class="panel mt">
      <h2>Rules</h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <label style="display:flex; align-items:flex-start; gap:9px;
                      font-weight:400; cursor:pointer; margin-bottom:12px;">
          <input type="checkbox" name="overflow_enabled" value="1"
                 style="margin-top:3px;"
                 <?= overflow_is_enabled() ? 'checked' : '' ?>>
          <span><b>Enable load balancing</b>
            <span class="tiny muted" style="display:block;">
              Offer idle counters the chance to serve a congested queue.
            </span>
          </span>
        </label>

        <label style="display:flex; align-items:flex-start; gap:9px;
                      font-weight:400; cursor:pointer; margin-bottom:16px;">
          <input type="checkbox" name="overflow_auto" value="1"
                 style="margin-top:3px;"
                 <?= overflow_is_automatic() ? 'checked' : '' ?>>
          <span><b>Reassign automatically</b>
            <span class="tiny muted" style="display:block;">
              With this off, the clerk is shown the option and chooses. With it
              on, Call next takes from the busiest queue without asking. Leave
              it off where clerks are not trained on every service.
            </span>
          </span>
        </label>

        <div class="grid g2">
          <div>
            <label for="overflow_threshold">Congestion threshold</label>
            <input type="number" id="overflow_threshold" name="overflow_threshold"
                   min="1" max="50" step="0.5"
                   value="<?= e(overflow_threshold()) ?>">
            <span class="tiny muted">Clients per open counter before a queue
              counts as congested.</span>
          </div>
          <div>
            <label for="overflow_min_waiting">Minimum queue</label>
            <input type="number" id="overflow_min_waiting" name="overflow_min_waiting"
                   min="1" max="50"
                   value="<?= (int)overflow_min_waiting() ?>">
            <span class="tiny muted">Never reallocate for a queue shorter than
              this, however few counters it has.</span>
          </div>
        </div>

        <div class="btn-row mt">
          <button class="btn" name="action" value="save">Save rules</button>
        </div>
      </form>
    </div>

    <!-- ------------------------------------------------------ efficiency -->
    <div class="panel mt">
      <h2>Effect on waiting time</h2>
      <p class="tiny muted" style="margin-top:-6px; margin-bottom:12px;">
        <?= e($stats['from']) ?> to <?= e($stats['to']) ?>.
        Overflow-served clients are compared against clients of the same
        service type served normally in the same period, because service
        types differ in how long they take.
      </p>
      <?php if (!$stats['by_service_type']): ?>
        <div class="empty">
          No client has been served by overflow in this period, so there is
          nothing to compare yet.
        </div>
      <?php else: ?>
        <table>
          <thead><tr>
            <th>Service type</th>
            <th class="right">Served by overflow</th>
            <th class="right">Their wait</th>
            <th class="right">Normal wait</th>
            <th class="right">Difference</th>
          </tr></thead>
          <tbody>
            <?php foreach ($stats['by_service_type'] as $r): ?>
              <tr>
                <td><b><?= e($r['service_type']) ?></b></td>
                <td class="right"><?= (int)$r['overflow_count'] ?></td>
                <td class="right">
                  <?= $r['overflow_wait'] === null
                        ? '&mdash;' : e($r['overflow_wait']) . ' min' ?></td>
                <td class="right">
                  <?= $r['normal_wait'] === null
                        ? '&mdash;' : e($r['normal_wait']) . ' min' ?></td>
                <td class="right">
                  <?php if ($r['saving'] === null): ?>
                    &mdash;
                  <?php elseif ($r['saving'] > 0): ?>
                    <b><?= e($r['saving']) ?> min shorter</b>
                  <?php elseif ($r['saving'] < 0): ?>
                    <?= e(abs($r['saving'])) ?> min longer
                  <?php else: ?>
                    no difference
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p class="tiny muted mt">
          A shorter wait for overflow-served clients is the expected result:
          they were taken from the front of a queue that had grown, and served
          at a counter that would otherwise have been doing nothing. The
          counter time recovered figure above is the more direct measure of the
          efficiency gained.
        </p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php page_foot(); ?>

<?php
/**
 * Client feedback.
 *
 * Collects the satisfaction and perceived-accuracy measures that Section
 * 3.3.4 of the report commits to gathering during evaluation. Offered to a
 * client once their service is complete, and reachable from their ticket.
 *
 * Kept deliberately short: two taps and an optional comment. A long form
 * after a queue is a form nobody fills in.
 */
define('QMS_ROOT', './');
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/ratelimit.php';
require_once __DIR__ . '/modules/dispatch.php';

$entryId = (int)($_GET['id'] ?? $_POST['entry_id'] ?? 0);
$entry = $entryId ? ticket_view($entryId) : null;

$done = false;
$error = null;

$already = $entryId
    ? q_one('SELECT feedback_id FROM feedback WHERE queue_entry_id = :id',
            [':id' => $entryId])
    : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rating = (int)($_POST['rating'] ?? 0);
    $accurate = $_POST['wait_accurate'] ?? '';
    $comment = trim((string)($_POST['comment'] ?? ''));

    $limit = rate_limit_hit('feedback:' . client_ip(), 10, 3600);

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (!$limit['allowed']) {
        $error = 'Too many submissions from this device. Please try later.';
    } elseif ($rating < 1 || $rating > 5) {
        $error = 'Please choose a rating from 1 to 5.';
    } elseif ($already) {
        $error = 'Feedback has already been recorded for this ticket. Thank you.';
    } else {
        $accurateValue = $accurate === '' ? null : (int)$accurate;
        q('INSERT INTO feedback (queue_entry_id, rating, wait_accurate, comment)
           VALUES (:id, :r, :a, :c)',
          [':id' => $entryId ?: null, ':r' => $rating,
           ':a' => $accurateValue,
           ':c' => $comment !== '' ? mb_substr($comment, 0, 1000) : null]);
        $done = true;
    }
}

page_head('Feedback', '', ['narrow' => true]);
?>
<div style="max-width:520px; margin:18px auto;">
  <?php if ($done): ?>
    <div class="panel" style="text-align:center; padding:34px 24px;">
      <div style="font-size:42px; line-height:1;">&#10003;</div>
      <h2 style="border:none; font-size:19px; text-transform:none; letter-spacing:0;
                 color:var(--ink); margin:12px 0 6px;">Thank you</h2>
      <p class="muted" style="font-size:14px;">
        Your feedback helps us measure how well the waiting time estimates work.
      </p>
      <div class="btn-row" style="justify-content:center; margin-top:18px;">
        <a class="btn ghost" href="index.php">Back to the home page</a>
      </div>
    </div>
  <?php else: ?>
    <div class="page-title">How was your visit?</div>
    <div class="page-sub">
      <?= $entry ? 'Ticket ' . e($entry['queue_number']) . ' &middot; '
                   . e($entry['service_type'])
                 : 'Two quick questions — it takes about fifteen seconds.' ?>
    </div>

    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

    <?php if ($entry && $entry['predicted_wait_time'] !== null
              && $entry['service_start_time']): ?>
      <?php
        $actual = (strtotime($entry['service_start_time'])
                   - strtotime($entry['join_time'])) / 60;
      ?>
      <div class="panel">
        <h2>Your visit</h2>
        <div class="grid g2">
          <div class="stat"><div class="k">We predicted</div>
            <div class="v" style="font-size:24px;">
              <?= e(fmt_minutes($entry['predicted_wait_time'])) ?></div></div>
          <div class="stat"><div class="k">You actually waited</div>
            <div class="v" style="font-size:24px;">
              <?= e(fmt_minutes($actual)) ?></div></div>
        </div>
      </div>
    <?php endif; ?>

    <div class="panel">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="entry_id" value="<?= (int)$entryId ?>">

        <label>Overall, how satisfied were you with the service?</label>
        <div class="rating">
          <?php
            $labels = [1 => 'Very poor', 2 => 'Poor', 3 => 'Fair',
                       4 => 'Good', 5 => 'Very good'];
            foreach ($labels as $value => $label):
          ?>
            <label class="rate-opt">
              <input type="radio" name="rating" value="<?= $value ?>" required>
              <span class="num"><?= $value ?></span>
              <span class="lbl"><?= e($label) ?></span>
            </label>
          <?php endforeach; ?>
        </div>

        <label style="margin-top:20px;">
          Was the waiting time we showed you about right?
        </label>
        <div class="rating three">
          <label class="rate-opt">
            <input type="radio" name="wait_accurate" value="1">
            <span class="lbl">Yes, about right</span></label>
          <label class="rate-opt">
            <input type="radio" name="wait_accurate" value="0">
            <span class="lbl">No, it was off</span></label>
          <label class="rate-opt">
            <input type="radio" name="wait_accurate" value="">
            <span class="lbl">Did not notice</span></label>
        </div>

        <label for="comment" style="margin-top:20px;">
          Anything else? <span class="hint">optional</span></label>
        <textarea id="comment" name="comment" rows="3"
                  placeholder="What worked well, or what would you change?"></textarea>

        <button class="btn block" type="submit">Submit feedback</button>
        <p class="tiny muted mt" style="text-align:center;">
          Your feedback is anonymous. We record only your answers, not your name
          or phone number.
        </p>
      </form>
    </div>
  <?php endif; ?>
</div>
<?php page_foot(); ?>

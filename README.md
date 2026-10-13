# Web-Based Queue Management System with Predictive Waiting Time

Prototype implementation of the system specified in Chapter 4 of the project
report. Built on the stack justified in Section 3.5: HTML5, CSS3, JavaScript
with the AJAX Fetch API on the client; PHP on an Apache server; MySQL for
storage; and a Random Forest Regression model trained in Python with
scikit-learn.

---

## 1. Installation on XAMPP

1. Copy the `qms` folder into `C:\xampp\htdocs\` (Windows) or
   `/opt/lampp/htdocs/` (Linux).
2. Start **Apache** and **MySQL** from the XAMPP control panel.
3. Create the database. Either import `database/schema.sql` through
   phpMyAdmin, or from a terminal:

   ```bash
   mysql -u root -p < database/schema.sql
   ```

   This creates the `qms` database, the five tables, and seed records for the
   staff accounts and service counters.
4. If your MySQL root account has a password, set it in `config/config.php`
   (`DB_PASS`). The defaults match a stock XAMPP install.
5. Open <http://localhost/qms/>.

### Training the prediction model

The system runs without the model by falling back to the deterministic
queueing-theory estimate, but the Random Forest model is the point of the
project. To train it:

```bash
cd qms/ml
pip install -r requirements.txt
python generate_dataset.py     # writes ml/data/queue_dataset.csv (1,000 records)
python train_model.py          # writes ml/model/rf_model.joblib + metrics
```

`train_model.py` prints the held-out test performance and writes
`ml/model/training_metrics.json`, which the administrator dashboard reads and
displays. Re-run both scripts whenever you want to retrain.

If `python` is not on the system path, set `QMS_PYTHON` in
`config/config.php` (`PYTHON_BIN`) to the full interpreter path.

---

## 2. Prototype accounts

| Role          | Email                          | Password    |
|---------------|--------------------------------|-------------|
| Administrator | admin@hudumacentre.go.ke       | Password123 |
| Counter staff | a.mwangi@hudumacentre.go.ke    | Password123 |
| Counter staff | b.chebet@hudumacentre.go.ke    | Password123 |
| Counter staff | j.wanjiru@hudumacentre.go.ke   | Password123 |

Clients do not sign in. Requiring account creation before joining a queue
would be a barrier for exactly the users the system is meant to serve, so a
client is identified by their phone number or ticket number.

**Remove these seed accounts before any real deployment.**

---

## 3. Demonstration data

To populate the dashboard with several days of plausible activity and a live
queue:

```bash
php tests/seed_demo.php
```

This clears all transactional data first, so do not run it against records
you want to keep.

---

## 4. Testing

```bash
php tests/smoke_test.php
```

The smoke test exercises each process of the level 1 data flow diagram in
sequence — registration, prediction, dispatch, service delivery, reporting —
and asserts the functional requirements individually, along with the SMS
drivers, the delivery-report parser, settings, pagination, rate limiting,
authentication and feedback capture. It currently runs 92 assertions. It prints a pass and
fail count and exits non-zero on failure, so it can be wired into a CI check.
It also clears transactional data, so run `seed_demo.php` afterwards to
restore the demonstration state.

---

## 5. Structure

```
qms/
├── config/config.php             Database, centre, queue, model and SMS settings
├── database/schema.sql           Schema and seed data (Chapter 4, Table 4.6)
├── includes/
│   ├── db.php                    PDO connection; every query is prepared
│   ├── auth.php                  Sessions, roles, CSRF (FR-20, NFR-07)
│   ├── helpers.php               Formatting, phone normalisation, validation
│   ├── layout.php                Shared page chrome
│   ├── pagination.php            Shared pager for administrative tables
│   └── ratelimit.php             Join-form flood protection
├── modules/                      One file per level 1 DFD process
│   ├── queue_registration.php    Process 1.0  (FR-01, FR-02, FR-03, FR-11)
│   ├── prediction.php            Process 2.0  (FR-04, FR-05, FR-06)
│   ├── dispatch.php              Process 3.0  (FR-07 to FR-10)
│   ├── service_delivery.php      Process 4.0  (FR-12, FR-14, FR-15, FR-16)
│   └── reporting.php             Process 6.0  (FR-18, FR-19)
├── api/                          AJAX endpoints for asynchronous refresh
│   ├── queue_status.php          Live ticket state (FR-13)
│   └── counter_status.php        Live counter state
├── ml/
│   ├── generate_dataset.py       Simulated 1,000-record training dataset
│   ├── train_model.py            Random Forest training and evaluation
│   ├── predict.py                stdin/stdout bridge called by PHP
│   └── model/                    Serialised model, exported forest, metrics
├── staff/counter.php             Counter console (Wireframe 7)
├── admin/
│   ├── dashboard.php             Administrator dashboard (Wireframe 8)
│   ├── counters.php              Counter configuration (Wireframe 9)
│   ├── reports.php               Reports and analytics (Wireframe 10)
│   ├── notifications.php         Dispatch audit log
│   └── users.php                 Staff and administrator accounts
├── tasks/run_maintenance.php     Scheduled expiry, reminders and refresh
├── index.php  join.php  ticket.php  track.php  login.php  logout.php
├── account.php                   Change my password
├── assets/css/style.css          Implements the wireframes
├── assets/js/app.js              AJAX polling
└── tests/
    ├── smoke_test.php            Requirement-level integration test
    └── seed_demo.php             Demonstration data generator
```

Each module file maps to exactly one process in the level 1 data flow
diagram, so the code structure and the design documentation can be read
against each other.

---

## 6. How prediction works

On every queue event — an arrival, a service completion, a ticket expiry, or a
counter being opened or closed — `recalculate_predictions()` runs for the
affected service type:

1. Five predictor features are assembled from live queue state and the
   trailing service history: `queue_length` (entries ahead of this one),
   `arrival_rate` (joins in the last 60 minutes), `service_rate`,
   `avg_service_time` (from `service_log` over 14 days) and `time_of_day`.
2. One feature row is built per outstanding entry and the whole batch is
   passed to `ml/predict.py` in a single call.
3. The returned predictions are written to `queue_entry.predicted_wait_time`,
   and `prediction_source` records whether the value came from the model or
   the fallback.
4. If the model file is missing, Python is unavailable, or the call times out,
   the deterministic estimate `(entries ahead x mean service time) / open
   counters` is used instead. Entries produced this way are flagged so they can
   be excluded from accuracy reporting.

Accuracy is measured on the dashboard by comparing `predicted_wait_time`
against the derived actual waiting time, which is the interval between
`join_time` and `service_start_time`.

---

## 7. SMS gateway

The gateway ships in **simulation mode**: messages are composed and written to
the `notification` table with a status of `simulated`, but nothing is
transmitted. Connecting a real provider needs no code changes — configure it
from the administrator screen at **Admin → SMS gateway**.

### 7.1 Drivers

| Driver | Use |
|---|---|
| `simulate` | Default. Compose and log only. |
| `africastalking` | Africa's Talking (Kenya). |
| `twilio` | Twilio. |
| `generic` | Any gateway accepting an HTTP POST; field names configurable. |

Everything provider-specific lives in one driver function in
`modules/sms_gateway.php`. Adding a provider means one function and one line
in `sms_send()`.

### 7.2 Connecting Africa's Talking

1. Create an account at africastalking.com.
2. Start in the **Sandbox** application — free, but messages go to the
   simulator and only to registered sandbox test numbers.
3. Copy the API key from Settings. The username is `sandbox` for sandbox, or
   your own username for a live application.
4. For live sending, top up and request an alphanumeric **sender ID**. In
   Kenya this needs approval and takes a few working days — request it early.
5. In the system: **Admin → SMS gateway**, choose the provider, enter the
   username, API key and sender ID, save, then use the test panel to send
   yourself a message.

The API key is stored and never redisplayed. Leaving the field blank on a
later save keeps the stored key.

### 7.3 Delivery reports (important)

A send request only confirms the gateway *accepted* the message. Whether it
reached the handset is reported back minutes later to a callback URL. Without
this, every SMS stays recorded as `sent` and is never confirmed delivered.

1. **Admin → SMS gateway → Generate callback token.** The page then shows the
   full callback URL.
2. Register it with the provider:
   - Africa's Talking: Dashboard → SMS → SMS Callbacks → Delivery Reports
   - Twilio: set it as the `StatusCallback` on the messaging service
3. Send a test ticket and watch the notifications log — the status should move
   from `sent` to `delivered`.

The endpoint is `api/sms_callback.php`. It authenticates on the token,
normalises Africa's Talking, Twilio and generic payloads into one status, and
refuses to move a message backwards from a final state.

**The URL must be reachable from the internet.** `localhost` is not, so during
development expose it with a tunnel such as ngrok and register the tunnel
address. Because the token travels in the URL, run the system over HTTPS in
production.

---

## 8. Scheduled maintenance

Ticket expiry (FR-12) and reminder dispatch (FR-10) must run whether or not
anyone is using the system. `tasks/run_maintenance.php` does that work and is
safe to run every minute.

**Windows (Task Scheduler)**

1. Open Task Scheduler and choose **Create Task**.
2. General tab: name it `QMS maintenance`, select **Run whether user is logged
   on or not**.
3. Triggers tab: **New**, Daily, and under Advanced settings tick **Repeat
   task every 1 minute** for a duration of **Indefinitely**.
4. Actions tab: **New**, Action **Start a program**
   - Program: `C:\xampp\php\php.exe`
   - Arguments: `C:\xampp\htdocs\qms\tasks\run_maintenance.php --quiet`
5. Save.

**Linux (cron)**

```
* * * * * /usr/bin/php /opt/lampp/htdocs/qms/tasks/run_maintenance.php --quiet
```

Each run appends a line to `logs/maintenance.log`, which is trimmed
automatically so it cannot grow without bound. Run it once by hand first to
confirm it works:

```
C:\xampp\php\php.exe C:\xampp\htdocs\qms\tasks\run_maintenance.php
```

It prints, for example:
`[2026-09-21 23:36:26] expired=0 reminders=4 predictions=9 (122ms)`

Without this task the system still works, because expiry and reminders are
also triggered opportunistically whenever a page is loaded. The scheduled task
is what makes them reliable when the system is idle.

---

## 9. Performance note on the prediction path

Importing scikit-learn costs roughly 0.75 seconds of Python start-up. Because
predictions are recalculated on every queue event, loading scikit-learn each
time put the round trip at about 1.1 seconds, which breached NFR-02.

`train_model.py` therefore also exports the fitted forest to
`ml/model/forest.npz`, a compact array form that `ml/predict.py` evaluates
with numpy alone. This brings the same call down to roughly 120 milliseconds.

The two paths are not allowed to drift: after every training run,
`train_model.py` evaluates the entire held-out test partition through both the
scikit-learn model and the exported forest and aborts if they differ by more
than 1e-6 minutes. The current export matches to within 7e-15 minutes.

`rf_model.joblib` remains the reference model and is used automatically if the
exported forest is missing.

---

## 10. Current state of the build

Implemented and tested:

- Complete database schema with referential integrity
- Client registration and queue joining, with validation, duplicate-ticket
  prevention and rate limiting
- Sequential ticket numbering and counter assignment by lightest load
- Random Forest prediction with the deterministic fallback, served from the
  exported forest for speed
- Dual-channel ticket dispatch and notification logging
- Reminder dispatch at the five-minute threshold
- E-ticket with QR code, live tracking and asynchronous refresh
- Check-in, cancellation, grace-period expiry and no-show handling
- Scheduled maintenance task for expiry, reminders and prediction refresh
- Staff authentication with role-based routing, CSRF protection and
  self-service password change
- Counter console: call next, start, complete, no-show, open and close
- Administrator dashboard with live KPIs, hourly volume, model metrics,
  counter performance and predicted-against-actual reporting
- Counter configuration
- Reports and analytics with filters, charts, heat map, paginated extract and
  CSV and print/PDF export
- Notifications log with channel and delivery-status filters
- Staff and administrator account management

Also implemented since:

- SMS gateway driver layer (Africa's Talking, Twilio, generic, simulation)
  with an administrator configuration and test-send screen
- Delivery-report callback endpoint with token authentication and
  provider-payload normalisation
- Live queue monitor and prediction model screens
- Public queue display board for a waiting-area screen
- Client feedback capture for the evaluation measures in Section 3.3.4
- Schema migration runner (`database/migrate.php`)

Outstanding:

- Transmission over a funded gateway account. The integration is complete and
  tested against documented provider payloads, but no message has been sent to
  a real handset, so deliverability over Kenyan networks is unverified.
- User Acceptance Testing with participants. The instruments are ready in the
  UAT pack; the results tables in Chapter 5 are awaiting real responses.

# Setting the system up on Windows (XAMPP)

Follow these in order. Steps 1–5 get the website running. Step 6 switches on
the Random Forest prediction. Steps 7–9 load demonstration data, schedule the
background task and run the tests.

---

## What you need installed

| Software | Why | Have it? |
|---|---|---|
| XAMPP (Apache + MySQL + PHP) | Runs the website and the database | You have this |
| VS Code | Editing the code | You have this |
| Python 3.10 or newer | Trains and runs the Random Forest model | **Need to install** |

Nothing else. No Node, no Composer, no web server other than XAMPP.

---

## Step 1 — Put the project in htdocs

1. Unzip `qms_system.zip`.
2. Copy the **`qms`** folder into `C:\xampp\htdocs\`.

You should end up with `C:\xampp\htdocs\qms\index.php` existing. If you have
`C:\xampp\htdocs\qms\qms\index.php` you have gone one folder too deep — move
the inner folder up.

---

## Step 2 — Start Apache and MySQL

1. Open the **XAMPP Control Panel** (search "XAMPP" in the Start menu).
2. Click **Start** next to **Apache**.
3. Click **Start** next to **MySQL**.

Both should turn green. If Apache refuses to start, see Troubleshooting below.

---

## Step 3 — Create the database

1. In the XAMPP Control Panel, click **Admin** next to MySQL. phpMyAdmin
   opens in your browser at `http://localhost/phpmyadmin`.
2. Click the **Import** tab at the top.
3. Click **Choose File** and select
   `C:\xampp\htdocs\qms\database\schema.sql`.
4. Scroll down and click **Import** (or **Go**).

You should see a green success message. A database called **qms** now appears
in the left sidebar with five tables: `user`, `service_counter`,
`queue_entry`, `service_log`, `notification`.

The script drops and recreates the database each time it runs, so re-importing
it later wipes everything and starts fresh. That is useful if you break
something, but do not do it after you have data you want to keep.

---

## Step 4 — Check the database settings

Open `C:\xampp\htdocs\qms\config\config.php` in VS Code.

On a stock XAMPP install the defaults are already correct:

```php
define('DB_USER', 'root');
define('DB_PASS', '');     // empty password
```

Only change `DB_PASS` if you set a MySQL root password yourself. Most people
have not.

---

## Step 5 — Open the site

Go to **http://localhost/qms/**

You should see the landing page with live queue figures. Try it:

- Click **Join queue now**, fill in a name, phone number and service, submit.
- You get a ticket with a predicted waiting time.

At this point the website and backend are fully working. The waiting time is
currently coming from the **fallback calculation**, not the machine learning
model — the ticket page tells you which one produced it. Step 6 fixes that.

**Staff and admin logins** (from the login page at
`http://localhost/qms/login.php`):

| Role | Email | Password |
|---|---|---|
| Administrator | admin@hudumacentre.go.ke | Password123 |
| Counter staff | b.chebet@hudumacentre.go.ke | Password123 |

---

## Step 6 — Install Python and train the model

This is the part that makes it *your* project rather than a generic queue
system, so do not skip it.

### 6a. Install Python

1. Go to <https://www.python.org/downloads/>
2. Download the latest Python 3 for Windows and run the installer.
3. **On the first screen, tick "Add python.exe to PATH".** This matters — if
   you miss it, PHP will not be able to find Python and the model will not
   run.
4. Click **Install Now**.

Verify it worked. Open a **new** terminal in VS Code
(**Terminal → New Terminal**) and run:

```
python --version
```

You should see something like `Python 3.12.4`. If you instead get
`'python' is not recognized`, Python is not on your PATH — reinstall and tick
the PATH box, or see Troubleshooting.

### 6b. Install the Python libraries

In the same terminal:

```
cd C:\xampp\htdocs\qms\ml
pip install -r requirements.txt
```

This downloads scikit-learn, pandas, numpy and joblib. It takes a couple of
minutes and needs internet.

### 6c. Generate the dataset and train

**The zip already contains a dataset and a trained model**, so you may wonder
why this step exists. Two reasons:

1. The supplied `rf_model.joblib` was trained with a specific version of
   scikit-learn. A model saved by joblib is tied to the library version that
   created it — if `pip` installs a different version on your laptop, loading
   it may warn or fail outright, and the system will silently fall back.
2. Training it yourself is the evidence your examiner wants to see.

It takes about ten seconds. Still in `C:\xampp\htdocs\qms\ml`:

```
python generate_dataset.py
python train_model.py
```

`generate_dataset.py` writes the 1,000-record simulated dataset described in
Chapter 3, overwriting the supplied one. It uses a fixed random seed, so you
get the identical dataset every time — your results are reproducible, which
matters for the report.

`train_model.py` trains the Random Forest and prints mean absolute error,
RMSE, R-squared, cross-validated error and feature importance. **Screenshot
this output**, it is evidence for Chapter 5.

Two files are rewritten: `ml/model/rf_model.joblib` (the trained model) and
`ml/model/training_metrics.json` (the numbers the admin dashboard displays).

### 6d. Confirm the model is being used

1. Reload `http://localhost/qms/` and join the queue again.
2. On the ticket page, look at the small grey line under the status. It should
   now read **"Estimate produced by the Random Forest model"** rather than
   "queueing-theory fallback".
3. Sign in as admin — the dashboard now shows a **Prediction model** panel with
   the training metrics in it.

If it still says fallback, see Troubleshooting.

---

## Step 7 — Load demonstration data (optional but recommended)

An empty system is hard to demonstrate. To fill it with several days of
plausible activity plus a live queue:

```
cd C:\xampp\htdocs\qms
C:\xampp\php\php.exe tests\seed_demo.php
```

The dashboard now shows real hourly volumes, counter performance and
predicted-against-actual accuracy figures.

**This clears all existing queue data first**, so do not run it after you have
records you care about.

---

## Step 8 — Schedule the maintenance task

Ticket expiry and reminder dispatch need to run even when nobody is using the
system. Without this they only fire when someone happens to load a page.

Test it by hand first:

```
C:\xampp\php\php.exe C:\xampp\htdocs\qms\tasks\run_maintenance.php
```

You should see a line like
`[2026-09-21 23:36:26] expired=0 reminders=4 predictions=9 (122ms)`

Then schedule it:

1. Search the Start menu for **Task Scheduler** and open it.
2. Click **Create Task** in the right-hand panel.
3. **General** tab: name it `QMS maintenance`. Select **Run whether user is
   logged on or not**.
4. **Triggers** tab: **New** → Begin the task **On a schedule** → **Daily**.
   Under *Advanced settings*, tick **Repeat task every** and type `1 minute`
   into the box (it is editable even though 1 minute is not in the dropdown).
   Set *for a duration of* **Indefinitely**. Click OK.
5. **Actions** tab: **New** → Action **Start a program**.
   - Program/script: `C:\xampp\php\php.exe`
   - Add arguments: `C:\xampp\htdocs\qms\tasks\run_maintenance.php --quiet`
   Click OK.
6. **Settings** tab: tick **Run task as soon as possible after a scheduled
   start is missed**. Click OK and enter your Windows password if prompted.

Each run appends to `qms\logs\maintenance.log`, so you can confirm it is
firing. The log is trimmed automatically.

---

## Step 9 — Run the tests (for your report)

```
cd C:\xampp\htdocs\qms
C:\xampp\php\php.exe tests\smoke_test.php
```

This runs 67 assertions against the live database, checking each functional
requirement in turn, plus reporting, pagination, rate limiting and
authentication. It prints a pass/fail count. Screenshot the output — it is
your testing evidence for Chapter 5.

Note it clears queue data too, so run `seed_demo.php` again afterwards if you
want the demonstration state back.

---

## Troubleshooting

**Apache will not start — "port 80 in use"**
Something else is using port 80, usually Skype, IIS, or VMware. Either close
that program, or in the XAMPP Control Panel click **Config** next to Apache →
`httpd.conf`, change `Listen 80` to `Listen 8080` and `ServerName
localhost:80` to `localhost:8080`, save, restart Apache, then use
`http://localhost:8080/qms/`.

**MySQL will not start**
Usually another MySQL is already running as a Windows service. Open Services
(Win+R, `services.msc`), find MySQL, stop it, then start MySQL from XAMPP.

**"Database connection failed"**
MySQL is not running in the XAMPP panel, or you have set a root password that
is not in `config/config.php`, or the import in Step 3 did not complete.

**Ticket says "queueing-theory fallback" after training the model**
PHP cannot run Python. In order:
1. Confirm `python --version` works in a terminal.
2. Confirm `ml\model\rf_model.joblib` exists.
3. Test the bridge directly:
   ```
   cd C:\xampp\htdocs\qms\ml
   echo {"rows":[{"queue_length":5,"arrival_rate":24,"service_rate":10,"avg_service_time":6,"time_of_day":10}]} | python predict.py
   ```
   It should print `{"predictions": [something]}`. If it prints an error, the
   message tells you what is wrong — usually a missing library, so re-run
   `pip install -r requirements.txt`.
4. If the bridge works in the terminal but the website still falls back, PHP
   cannot see Python on its PATH. Fix it by setting the full path in
   `config/config.php`:
   ```php
   define('PYTHON_BIN', 'C:\\Users\\YourName\\AppData\\Local\\Programs\\Python\\Python312\\python.exe');
   ```
   Find the exact path by running `where python` in a terminal. Note the
   **doubled backslashes** — that is required in PHP.

**Page is blank or shows a PHP error**
`APP_DEBUG` is set to `true` in `config/config.php`, so errors print to the
screen. Read the message — it names the file and line.

**Nothing changes when I edit a file**
Hard-refresh the browser with **Ctrl+F5**. The CSS in particular is cached.

---

## What runs where

Worth understanding for your defence, because examiners ask:

- **Apache** serves the pages and runs the **PHP** code. This is the backend —
  registration, prediction triggering, ticket dispatch, service management.
- **MySQL** stores everything: users, queue entries, counters, service logs,
  notifications.
- **Python** is not a server. It is invoked by PHP on demand, as a short-lived
  process, whenever waiting times need recalculating. PHP passes it the
  features, it returns predictions, it exits.
- **The browser** runs the JavaScript that polls the server every 15 seconds
  so queue positions update without a reload.

So there is no separate "backend server" to start beyond Apache and MySQL in
the XAMPP panel. That is the whole stack.

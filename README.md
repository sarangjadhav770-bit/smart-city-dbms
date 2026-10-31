# CivicPulse SCDMS

CivicPulse is a PHP/MySQL app for reporting and managing city service complaints. It includes a public landing page, citizen reporting and tracking, department work queues, and an administrator overview.

## Run locally with XAMPP

1. Copy or clone the project into `C:\xampp\htdocs\CivicPulse-SCDMS`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. If this is a **new install**, import `database.sql` in phpMyAdmin. If `smart_city_db` already contains CivicPulse data, **do not re-import it**; back up the database, then open `http://localhost/CivicPulse-SCDMS/upgrade.php` from this computer and choose **Upgrade database**. The upgrade adds the complaint workflow fields/tables and preserves existing accounts, reports, and other module tables.
4. Copy `config/database.example.php` to `config/database.php`, then set your local MySQL credentials in `config/database.php`. The local configuration file is ignored by Git.
5. For a new empty database only, open `http://localhost/CivicPulse-SCDMS/setup.php` locally and choose **Create demo data**. Existing users are never overwritten.
6. Delete `setup.php` after demo setup.
7. Optional: to add 500 fictional citizen accounts and 500 linked sample complaints to a local test database, run `C:\xampp\php\php.exe C:\xampp\htdocs\CivicPulse-SCDMS\seed_test_data_once.php` in PowerShell. The script runs only from the PHP CLI, preserves existing records, and safely reports if its test rows are already present.
8. Open `http://localhost/CivicPulse-SCDMS/`.

The site requires PHP with PDO MySQL enabled. Bootstrap, Font Awesome, Leaflet, Three.js, GSAP, and map tiles load from CDNs, so those interface resources require an internet connection.

## Demo accounts

| Account | Email | Password |
|---|---|---|
| Citizen | `demo@civicpulse.test` | `Citizen@123` |
| Administrator | `admin@civicpulse.test` | `Admin@123` |
| Department officers (new demo database) | `municipal`, `road`, `water`, `sanitation`, `electricity`, `health`, or `police` + `@civicpulse.test` | `Dept@123` |

These are local demo credentials created by `setup.php`. Use them only for local testing; do not expose a demo installation publicly.

### Synthetic load-test accounts

The optional local seed script creates fictional accounts named from Indian-style first and last names. Emails range from `cp.test.citizen.0001@civicpulse.test` to `cp.test.citizen.0500@civicpulse.test`; the shared local test password is `TestCitizen@123`. It also creates 500 linked complaints marked with `[TEST]` titles and `is_sample = 1`, including status history. These are generated test records, not Kaggle data or real citizens. Never use the shared password or synthetic records on a public/production site.

### Download the Kaggle competition data

The optional `download_kaggle_competition.py` helper downloads the Mumbai Nagar Seva BMC complaint-resolution competition files using KaggleHub. First sign in to Kaggle, accept the competition rules, configure Kaggle API credentials as described by Kaggle, and install KaggleHub:

```powershell
py -m pip install kagglehub
py download_kaggle_competition.py
```

The script prints the local Kaggle cache path. It downloads files only; it does not import rows into CivicPulse. Review the competition license/rules and remove or anonymize personal identifiers before using any records. Do not commit the downloaded files, Kaggle credentials, or personal data to GitHub.

## Main workflows

- **Citizen:** create an account, sign in, submit a report with a service category (including streetlights and water leaks), location, optional map pin and photo, then track updates from the dashboard or **My reports**. The report form includes a live Three.js city with procedural districts streamed around the camera, animated traffic, metro and drones, orbit/zoom/travel controls, interactive buildings, category highlighting, Leaflet location pinning projected into the city in meters, and a browser-local **Save draft** action. Drag to orbit, scroll to zoom, and hold **Shift** while dragging (or right-drag) to travel into new city districts; on touchscreens, use two fingers to pan and pinch to zoom. Page scrolling remains available outside the interactive city canvas. Focus the city canvas and use the arrow keys or WASD as an alternative. Search **My reports** by the complaint tracking ID; a resolved report can be reopened.
- **Department officer:** sign in with the account for the assigned department, review its searchable, filterable, paginated queue, assign field staff, post public status updates and optional evidence, and add internal notes.
- **Administrator:** review reports across departments, filter the queue, update a report’s status, inspect its timeline, manage departments and report routing, review account totals, and activate or deactivate user accounts. Complaint pins appear on the map when reports include coordinates. The last active administrator and the current administrator account cannot be disabled.
- **Sign-in:** select Citizen, Officer or Admin. The selected role is checked against the stored account; it does not grant access by itself. Landing-page report and tracking links return citizens to the requested screen after sign-in.
- Existing installs retain their previous users; sign in with those accounts after running `upgrade.php`. Demo accounts are only created on an empty database.

New citizen passwords must be at least eight characters. Uploaded images are limited to JPG, PNG, or WEBP and 2 MB.

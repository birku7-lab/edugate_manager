# 🛡️ SoftBuilt Developer Fleet Cloud Dashboard

A secure, zero-knowledge central management dashboard for monitoring client school installations, deploying custom feature updates per school, remotely locking/unlocking systems, and reviewing live crash diagnostics without ever accessing private school database records.

---

## 🚀 Key Features

1. **School Fleet Overview**:
   - Real-time online/offline heartbeat status.
   - Device/Machine ID tracking and version inventory.
2. **Remote Kill-Switch / Lock System**:
   - 1-Click lock or unlock for any specific school.
   - Custom display message shown to locked clients (e.g. *Subscription Renewal Required*).
3. **Per-School Feature Updates (.zip)**:
   - Upload new application `.zip` archives.
   - Choose target: **ALL Schools** (global update) or **Specific School** (custom modules).
   - SoftBuilt Shield automatically detects, downloads, stages, and deploys the update.
4. **Zero-Knowledge Privacy Guarantee**:
   - Strictly handles technical telemetry and system exceptions.
   - **Zero access to private school databases** (no student records, marks, attendance, or financial records are ever transmitted or stored).
5. **Live Crash & Error Diagnostics**:
   - View unhandled exceptions and stack traces reported by client installations so you can diagnose and fix bugs remotely.

---

## ☁️ How to Host for FREE (Step-by-Step)

The free hosting company you were thinking of is most likely **Render.com** (or **PythonAnywhere** / **Railway**). Here is how to host it 100% free with free automatic SSL (HTTPS):

### Option 1: Render.com (Recommended — 100% Free with HTTPS)

1. **Create a Free Account** at [render.com](https://render.com).
2. **Push this folder to a GitHub repository** (e.g. `softbuilt-dashboard`).
3. In Render dashboard:
   - Click **New +** → **Web Service**.
   - Connect your GitHub repository.
   - Set:
     - **Name**: `softbuilt-shield-fleet` (or your chosen name)
     - **Runtime**: `Python 3`
     - **Build Command**: `pip install -r requirements.txt`
     - **Start Command**: `gunicorn app:app`
     - **Instance Type**: `Free`
4. Under **Environment Variables**, add:
   - `DEV_DASHBOARD_PASSWORD`: `your_secure_password`
   - `SECRET_KEY`: `random_long_secret_string`
5. Click **Create Web Service**.
6. Render will build and deploy your dashboard with a live HTTPS address:
   `https://softbuilt-shield-fleet.onrender.com`

---

### Option 2: PythonAnywhere (Free Forever)

1. Create a free beginner account at [pythonanywhere.com](https://www.pythonanywhere.com).
2. Open a Bash console and upload or git clone the files.
3. In the **Web** tab:
   - Create a **Manual configuration** Python app (Python 3.10+).
   - In the WSGI configuration file, point to `app`:
     ```python
     import sys
     path = '/home/yourusername/developer-cloud-dashboard'
     if path not in sys.path:
         sys.path.append(path)
     from app import app as application
     ```
4. Click **Reload**. Your dashboard will be live at `https://yourusername.pythonanywhere.com`.

---

## 💻 How to Run Locally on Your PC

Simply double-click `run_dashboard.bat` or run:
```bash
python app.py
```
Open your browser to: `http://127.0.0.1:5000`
- **Default Master Passcode**: `softbuilt2026`

---

## 🔌 API Endpoints (Used by SoftBuilt Shield Client)

- `POST /api/heartbeat`
  - Sends machine telemetry ping.
  - Returns lock status (`active` or `locked`) and any pending updates.
- `GET /api/download/<package_id>`
  - Downloads authorized update zip archive.
- `POST /api/report_error`
  - Submits sanitized system stack traces for debugging.

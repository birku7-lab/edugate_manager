import os
import sqlite3
import uuid
import datetime
from functools import wraps
from flask import (
    Flask, render_template, request, redirect,
    url_for, flash, jsonify, session, send_from_directory
)
from werkzeug.utils import secure_filename

app = Flask(__name__)
app.secret_key = os.environ.get("SECRET_KEY", "softbuilt_shield_dev_secret_key_2026")

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
DATA_DIR = os.path.join(BASE_DIR, "data")
UPLOADS_DIR = os.path.join(BASE_DIR, "uploads", "updates")
DB_PATH = os.path.join(DATA_DIR, "shield_fleet.db")

DEV_PASSWORD = os.environ.get("DEV_DASHBOARD_PASSWORD", "softbuilt2026")

os.makedirs(DATA_DIR, exist_ok=True)
os.makedirs(UPLOADS_DIR, exist_ok=True)

# ================= DATABASE INITIALIZATION =================
def get_db():
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    return conn

def init_db():
    conn = get_db()
    cursor = conn.cursor()

    cursor.execute("""
    CREATE TABLE IF NOT EXISTS schools (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        school_id TEXT UNIQUE NOT NULL,
        school_name TEXT NOT NULL,
        contact_name TEXT,
        contact_phone TEXT,
        status TEXT NOT NULL DEFAULT 'active', -- 'active' or 'locked'
        lock_reason TEXT DEFAULT '',
        machine_id TEXT,
        shield_version TEXT DEFAULT '1.0.0',
        current_app_version TEXT DEFAULT '1.0.0',
        ip_address TEXT,
        last_heartbeat DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    """)

    cursor.execute("""
    CREATE TABLE IF NOT EXISTS updates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        package_id TEXT UNIQUE NOT NULL,
        title TEXT NOT NULL,
        version TEXT NOT NULL,
        filename TEXT NOT NULL,
        filesize INTEGER DEFAULT 0,
        target_school_id TEXT NOT NULL DEFAULT 'ALL', -- 'ALL' or specific school_id
        notes TEXT DEFAULT '',
        download_count INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    """)

    cursor.execute("""
    CREATE TABLE IF NOT EXISTS error_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        school_id TEXT NOT NULL,
        school_name TEXT,
        error_type TEXT NOT NULL,
        message TEXT NOT NULL,
        stack_trace TEXT,
        system_info TEXT,
        status TEXT DEFAULT 'open', -- 'open' or 'resolved'
        reported_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    """)

    # Seed sample school if empty
    cursor.execute("SELECT COUNT(*) FROM schools")
    if cursor.fetchone()[0] == 0:
        cursor.execute("""
        INSERT INTO schools (school_id, school_name, contact_name, contact_phone, status, shield_version, last_heartbeat)
        VALUES 
            ('SCH-EDUGATE-01', 'Main Demonstration School', 'Head Teacher', '+256 700 000000', 'active', '1.0.0', datetime('now')),
            ('SCH-STJUDE-02', 'St. Jude Academy (Live Client)', 'Admin Dept', '+256 711 111111', 'active', '1.0.0', datetime('now', '-2 hours'));
        """)

    conn.commit()
    conn.close()

init_db()

# ================= AUTHENTICATION DECORATOR =================
def login_required(f):
    @wraps(f)
    def decorated_function(*args, **kwargs):
        if not session.get("is_authenticated"):
            return redirect(url_for("login"))
        return f(*args, **kwargs)
    return decorated_function

# ================= DEVELOPER WEB UI ROUTES =================
@app.route("/login", methods=["GET", "POST"])
def login():
    if session.get("is_authenticated"):
        return redirect(url_for("dashboard"))

    if request.method == "POST":
        password = request.form.get("password", "")
        if password == DEV_PASSWORD:
            session["is_authenticated"] = True
            flash("Welcome back, Developer! Access granted.", "success")
            return redirect(url_for("dashboard"))
        else:
            flash("Invalid Master Passcode. Access denied.", "danger")

    return render_template("login.html")

@app.route("/logout")
def logout():
    session.clear()
    flash("You have been signed out.", "info")
    return redirect(url_for("login"))

@app.route("/")
@login_required
def dashboard():
    conn = get_db()
    cursor = conn.cursor()

    cursor.execute("SELECT COUNT(*) FROM schools")
    total_schools = cursor.fetchone()[0]

    cursor.execute("SELECT COUNT(*) FROM schools WHERE status = 'active'")
    active_schools = cursor.fetchone()[0]

    cursor.execute("SELECT COUNT(*) FROM schools WHERE status = 'locked'")
    locked_schools = cursor.fetchone()[0]

    # Online in last 15 minutes
    fifteen_mins_ago = (datetime.datetime.utcnow() - datetime.timedelta(minutes=15)).strftime("%Y-%m-%d %H:%M:%S")
    cursor.execute("SELECT COUNT(*) FROM schools WHERE last_heartbeat >= ?", (fifteen_mins_ago,))
    online_schools = cursor.fetchone()[0]

    cursor.execute("SELECT COUNT(*) FROM error_logs WHERE status = 'open'")
    open_errors = cursor.fetchone()[0]

    cursor.execute("SELECT COUNT(*) FROM updates")
    total_updates = cursor.fetchone()[0]

    # Recent heartbeats
    cursor.execute("SELECT * FROM schools ORDER BY last_heartbeat DESC LIMIT 5")
    recent_schools = cursor.fetchall()

    # Recent error logs
    cursor.execute("SELECT * FROM error_logs ORDER BY reported_at DESC LIMIT 5")
    recent_errors = cursor.fetchall()

    conn.close()

    return render_template("index.html",
        total_schools=total_schools,
        active_schools=active_schools,
        locked_schools=locked_schools,
        online_schools=online_schools,
        open_errors=open_errors,
        total_updates=total_updates,
        recent_schools=recent_schools,
        recent_errors=recent_errors
    )

@app.route("/schools")
@login_required
def schools_list():
    conn = get_db()
    cursor = conn.cursor()
    cursor.execute("SELECT * FROM schools ORDER BY id ASC")
    schools = cursor.fetchall()
    conn.close()
    return render_template("schools.html", schools=schools)

@app.route("/schools/add", methods=["POST"])
@login_required
def school_add():
    school_id = request.form.get("school_id", "").strip().upper()
    school_name = request.form.get("school_name", "").strip()
    contact_name = request.form.get("contact_name", "").strip()
    contact_phone = request.form.get("contact_phone", "").strip()

    if not school_id or not school_name:
        flash("School ID and Name are required.", "danger")
        return redirect(url_for("schools_list"))

    conn = get_db()
    cursor = conn.cursor()
    try:
        cursor.execute("""
            INSERT INTO schools (school_id, school_name, contact_name, contact_phone, status)
            VALUES (?, ?, ?, ?, 'active')
        """, (school_id, school_name, contact_name, contact_phone))
        conn.commit()
        flash(f"School '{school_name}' ({school_id}) added successfully.", "success")
    except sqlite3.IntegrityError:
        flash(f"School ID '{school_id}' already exists.", "danger")
    finally:
        conn.close()

    return redirect(url_for("schools_list"))

@app.route("/schools/<school_id>/lock", methods=["POST"])
@login_required
def school_toggle_lock(school_id):
    action = request.form.get("action", "lock")
    reason = request.form.get("reason", "Administrative hold or subscription update required.")

    new_status = "locked" if action == "lock" else "active"
    lock_reason = reason if action == "lock" else ""

    conn = get_db()
    cursor = conn.cursor()
    cursor.execute("""
        UPDATE schools SET status = ?, lock_reason = ? WHERE school_id = ?
    """, (new_status, lock_reason, school_id))
    conn.commit()
    conn.close()

    flash(f"School {school_id} has been {new_status.upper()}.", "warning" if new_status == "locked" else "success")
    return redirect(url_for("schools_list"))

@app.route("/schools/<school_id>/delete", methods=["POST"])
@login_required
def school_delete(school_id):
    conn = get_db()
    cursor = conn.cursor()
    cursor.execute("DELETE FROM schools WHERE school_id = ?", (school_id,))
    conn.commit()
    conn.close()
    flash(f"School {school_id} removed from dashboard.", "info")
    return redirect(url_for("schools_list"))

# ================= UPDATES & PACKAGES =================
@app.route("/updates", methods=["GET", "POST"])
@login_required
def updates_list():
    conn = get_db()
    cursor = conn.cursor()

    if request.method == "POST":
        title = request.form.get("title", "").strip()
        version = request.form.get("version", "").strip()
        target_school = request.form.get("target_school_id", "ALL").strip()
        notes = request.form.get("notes", "").strip()
        file = request.files.get("package_file")

        if not title or not version or not file or not file.filename.endswith(".zip"):
            flash("Please provide Title, Version, and a valid .zip file.", "danger")
            return redirect(url_for("updates_list"))

        package_id = f"pkg_{uuid.uuid4().hex[:10]}"
        safe_name = f"{package_id}_{secure_filename(file.filename)}"
        save_path = os.path.join(UPLOADS_DIR, safe_name)
        file.save(save_path)
        filesize = os.path.getsize(save_path)

        cursor.execute("""
            INSERT INTO updates (package_id, title, version, filename, filesize, target_school_id, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        """, (package_id, title, version, safe_name, filesize, target_school, notes))
        conn.commit()
        flash(f"Update package '{title}' (v{version}) uploaded for {target_school}.", "success")
        return redirect(url_for("updates_list"))

    cursor.execute("SELECT * FROM updates ORDER BY created_at DESC")
    updates = cursor.fetchall()

    cursor.execute("SELECT school_id, school_name FROM schools ORDER BY school_name ASC")
    schools = cursor.fetchall()

    conn.close()
    return render_template("updates.html", updates=updates, schools=schools)

@app.route("/updates/<package_id>/delete", methods=["POST"])
@login_required
def update_delete(package_id):
    conn = get_db()
    cursor = conn.cursor()
    cursor.execute("SELECT filename FROM updates WHERE package_id = ?", (package_id,))
    row = cursor.fetchone()
    if row:
        file_path = os.path.join(UPLOADS_DIR, row["filename"])
        if os.path.exists(file_path):
            try:
                os.remove(file_path)
            except:
                pass
        cursor.execute("DELETE FROM updates WHERE package_id = ?", (package_id,))
        conn.commit()
        flash(f"Package {package_id} deleted.", "info")
    conn.close()
    return redirect(url_for("updates_list"))

# ================= ERROR & DIAGNOSTIC LOGS =================
@app.route("/errors")
@login_required
def errors_list():
    conn = get_db()
    cursor = conn.cursor()
    cursor.execute("SELECT * FROM error_logs ORDER BY reported_at DESC LIMIT 100")
    errors = cursor.fetchall()
    conn.close()
    return render_template("errors.html", errors=errors)

@app.route("/errors/<int:error_id>/resolve", methods=["POST"])
@login_required
def error_resolve(error_id):
    conn = get_db()
    cursor = conn.cursor()
    cursor.execute("UPDATE error_logs SET status = 'resolved' WHERE id = ?", (error_id,))
    conn.commit()
    conn.close()
    flash(f"Error #{error_id} marked as resolved.", "success")
    return redirect(url_for("errors_list"))

@app.route("/errors/clear_resolved", methods=["POST"])
@login_required
def errors_clear_resolved():
    conn = get_db()
    cursor = conn.cursor()
    cursor.execute("DELETE FROM error_logs WHERE status = 'resolved'")
    conn.commit()
    conn.close()
    flash("Resolved errors cleared.", "info")
    return redirect(url_for("errors_list"))

# ================= REST API FOR CLIENTS (SoftBuilt Shield) =================
@app.route("/api/heartbeat", methods=["POST"])
def api_heartbeat():
    """
    Heartbeat ping from SoftBuilt Shield client on school machine.
    Zero-knowledge: NEVER receives or handles any school/student data!
    """
    data = request.get_json(force=True, silent=True) or {}
    school_id = data.get("school_id", "").strip().upper()
    machine_id = data.get("machine_id", "UNKNOWN")
    shield_version = data.get("shield_version", "1.0.0")
    current_app_version = data.get("current_app_version", "1.0.0")
    client_ip = request.headers.get("X-Forwarded-For", request.remote_addr)

    if not school_id:
        return jsonify({"error": "Missing school_id"}), 400

    conn = get_db()
    cursor = conn.cursor()

    cursor.execute("SELECT * FROM schools WHERE school_id = ?", (school_id,))
    school = cursor.fetchone()

    # Auto-register new school if unseen
    if not school:
        school_name = data.get("school_name", f"School ({school_id})")
        cursor.execute("""
            INSERT INTO schools (school_id, school_name, machine_id, shield_version, current_app_version, ip_address, last_heartbeat, status)
            VALUES (?, ?, ?, ?, ?, ?, datetime('now'), 'active')
        """, (school_id, school_name, machine_id, shield_version, current_app_version, client_ip))
        conn.commit()
        status = "active"
        lock_reason = ""
    else:
        cursor.execute("""
            UPDATE schools 
            SET machine_id = ?, shield_version = ?, current_app_version = ?, ip_address = ?, last_heartbeat = datetime('now')
            WHERE school_id = ?
        """, (machine_id, shield_version, current_app_version, client_ip, school_id))
        conn.commit()
        status = school["status"]
        lock_reason = school["lock_reason"]

    # Check for pending update packages targeted to this school or to ALL
    cursor.execute("""
        SELECT * FROM updates 
        WHERE (target_school_id = ? OR target_school_id = 'ALL')
        ORDER BY id DESC LIMIT 1
    """, (school_id,))
    update_row = cursor.fetchone()

    update_payload = None
    if update_row and update_row["version"] != current_app_version:
        base_url = request.host_url.rstrip("/")
        download_url = f"{base_url}/api/download/{update_row['package_id']}"
        update_payload = {
            "available": True,
            "package_id": update_row["package_id"],
            "title": update_row["title"],
            "version": update_row["version"],
            "download_url": download_url,
            "filesize": update_row["filesize"],
            "notes": update_row["notes"]
        }
    else:
        update_payload = {"available": False}

    conn.close()

    return jsonify({
        "school_id": school_id,
        "status": status,                  # "active" or "locked"
        "lock_reason": lock_reason,        # displayed on school screen if locked
        "update": update_payload,
        "server_time": datetime.datetime.utcnow().isoformat()
    })

@app.route("/api/download/<package_id>", methods=["GET"])
def api_download_update(package_id):
    conn = get_db()
    cursor = conn.cursor()
    cursor.execute("SELECT filename FROM updates WHERE package_id = ?", (package_id,))
    row = cursor.fetchone()
    if not row:
        conn.close()
        return jsonify({"error": "Update package not found"}), 404

    cursor.execute("UPDATE updates SET download_count = download_count + 1 WHERE package_id = ?", (package_id,))
    conn.commit()
    conn.close()

    return send_from_directory(UPLOADS_DIR, row["filename"], as_attachment=True)

@app.route("/api/report_error", methods=["POST"])
def api_report_error():
    """
    Sanitized system telemetry & bug reporting.
    Zero-knowledge architecture: Strictly receives system runtime/stack errors, no personal or database data.
    """
    data = request.get_json(force=True, silent=True) or {}
    school_id = data.get("school_id", "UNKNOWN").strip().upper()
    error_type = data.get("error_type", "GeneralException")
    message = data.get("message", "No message provided")
    stack_trace = data.get("stack_trace", "")
    system_info = data.get("system_info", "")

    conn = get_db()
    cursor = conn.cursor()

    cursor.execute("SELECT school_name FROM schools WHERE school_id = ?", (school_id,))
    school_row = cursor.fetchone()
    school_name = school_row["school_name"] if school_row else "Unknown School"

    cursor.execute("""
        INSERT INTO error_logs (school_id, school_name, error_type, message, stack_trace, system_info, status)
        VALUES (?, ?, ?, ?, ?, ?, 'open')
    """, (school_id, school_name, error_type, message, stack_trace, system_info))
    conn.commit()
    conn.close()

    return jsonify({"status": "received", "message": "Diagnostic log logged for developer review."})

if __name__ == "__main__":
    port = int(os.environ.get("PORT", 5000))
    app.run(host="0.0.0.0", port=port, debug=True)

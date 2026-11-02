import os
import sqlite3
import uuid
import datetime
import zipfile
import shutil
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
WORKSPACES_DIR = os.path.join(DATA_DIR, "workspaces")
DB_PATH = os.path.join(DATA_DIR, "shield_fleet.db")

DEV_PASSWORD = os.environ.get("DEV_DASHBOARD_PASSWORD", "softbuilt2026")

os.makedirs(DATA_DIR, exist_ok=True)
os.makedirs(UPLOADS_DIR, exist_ok=True)
os.makedirs(WORKSPACES_DIR, exist_ok=True)

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
        license_key TEXT,
        school_name TEXT NOT NULL,
        contact_name TEXT,
        contact_phone TEXT,
        status TEXT NOT NULL DEFAULT 'active', -- 'active' or 'locked'
        lock_reason TEXT DEFAULT '',
        machine_id TEXT,
        shield_version TEXT DEFAULT '1.0.0',
        current_app_version TEXT DEFAULT '1.0.0',
        last_sync_status TEXT DEFAULT 'Ready',
        http_health_status TEXT DEFAULT 'Healthy (200 OK)',
        last_sync_message TEXT DEFAULT '',
        last_sync_time DATETIME,
        deployed_package_id TEXT DEFAULT '',
        ip_address TEXT,
        last_heartbeat DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    """)

    # Run backward-compatible schema migrations for schools table
    cursor.execute("PRAGMA table_info(schools)")
    existing_cols = [row[1] for row in cursor.fetchall()]

    new_columns = [
        ("license_key", "TEXT"),
        ("contact_phone", "TEXT"),
        ("last_sync_status", "TEXT DEFAULT 'Ready'"),
        ("http_health_status", "TEXT DEFAULT 'Healthy (200 OK)'"),
        ("last_sync_message", "TEXT DEFAULT ''"),
        ("last_sync_time", "DATETIME"),
        ("deployed_package_id", "TEXT DEFAULT ''")
    ]
    for col_name, col_type in new_columns:
        if col_name not in existing_cols:
            try:
                cursor.execute(f"ALTER TABLE schools ADD COLUMN {col_name} {col_type};")
            except:
                pass

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

    cursor.execute("""
    CREATE TABLE IF NOT EXISTS sync_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        school_id TEXT NOT NULL,
        license_key TEXT,
        package_id TEXT,
        version TEXT,
        status TEXT,
        health_status TEXT,
        message TEXT,
        details TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    """)

    # Seed sample school if empty
    cursor.execute("SELECT COUNT(*) FROM schools")
    if cursor.fetchone()[0] == 0:
        cursor.execute("""
        INSERT INTO schools (school_id, license_key, school_name, contact_name, contact_phone, status, shield_version, last_heartbeat, last_sync_status, http_health_status)
        VALUES 
            ('SCH-EDUGATE-01', 'EDUGATE-7F3A-8D92-4B1C', 'Main Demonstration School', 'Head Teacher', '+256 700 000000', 'active', '1.0.0', datetime('now'), 'Success', '200 OK - Healthy'),
            ('SCH-STJUDE-02', 'EDUGATE-9A2E-14CF-803D', 'St. Jude Academy (Live Client)', 'Admin Dept', '+256 711 111111', 'active', '1.0.0', datetime('now', '-2 hours'), 'Success', '200 OK - Healthy');
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
    license_key = request.form.get("license_key", "").strip().upper()
    school_name = request.form.get("school_name", "").strip()
    contact_name = request.form.get("contact_name", "").strip()
    contact_phone = request.form.get("contact_phone", "").strip()
    status = request.form.get("status", "active")

    # If developer didn't provide a school ID or license key, auto-generate one
    if not school_id:
        school_id = f"SCH-{uuid.uuid4().hex[:6].upper()}"
    if not license_key:
        license_key = f"EDUGATE-{uuid.uuid4().hex[:4].upper()}-{uuid.uuid4().hex[:4].upper()}-{uuid.uuid4().hex[:4].upper()}"

    if not school_name:
        flash("School Name is required.", "danger")
        return redirect(url_for("schools_list"))

    conn = get_db()
    cursor = conn.cursor()
    try:
        cursor.execute("""
            INSERT INTO schools (school_id, license_key, school_name, contact_name, contact_phone, status, last_sync_status, http_health_status)
            VALUES (?, ?, ?, ?, ?, ?, 'Ready', 'Healthy (200 OK)')
        """, (school_id, license_key, school_name, contact_name, contact_phone, status))
        conn.commit()
        flash(f"School '{school_name}' registered successfully! Key: {license_key} • Phone: {contact_phone or 'None'}", "success")
    except sqlite3.IntegrityError:
        flash(f"School ID or Key '{school_id}' already exists in system.", "danger")
    finally:
        conn.close()

    return redirect(url_for("schools_list"))

@app.route("/schools/edit", methods=["POST"])
@login_required
def school_edit():
    school_id = request.form.get("school_id", "").strip().upper()
    license_key = request.form.get("license_key", "").strip().upper()
    school_name = request.form.get("school_name", "").strip()
    contact_name = request.form.get("contact_name", "").strip()
    contact_phone = request.form.get("contact_phone", "").strip()
    status = request.form.get("status", "active")

    if not school_id or not school_name:
        flash("School ID and Name are required.", "danger")
        return redirect(url_for("schools_list"))

    conn = get_db()
    cursor = conn.cursor()
    cursor.execute("""
        UPDATE schools 
        SET school_name = ?, contact_name = ?, contact_phone = ?, license_key = ?, status = ?
        WHERE school_id = ?
    """, (school_name, contact_name, contact_phone, license_key, status, school_id))
    conn.commit()
    conn.close()

    flash(f"Updated client details for {school_name} ({school_id}). Phone: {contact_phone}", "success")
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

# ================= WORKSPACE & IN-BROWSER CODE EDITOR =================
def ensure_workspace(package_id):
    """
    Extracts package ZIP into a persistent editable workspace directory in data/workspaces/<package_id>
    """
    workspace_path = os.path.join(WORKSPACES_DIR, package_id)
    if os.path.exists(workspace_path):
        return workspace_path

    conn = get_db()
    cursor = conn.cursor()
    cursor.execute("SELECT filename FROM updates WHERE package_id = ?", (package_id,))
    row = cursor.fetchone()
    conn.close()

    if not row:
        return None

    zip_path = os.path.join(UPLOADS_DIR, row["filename"])
    if not os.path.exists(zip_path):
        return None

    os.makedirs(workspace_path, exist_ok=True)
    try:
        with zipfile.ZipFile(zip_path, 'r') as zf:
            zf.extractall(workspace_path)
    except Exception as ex:
        return None

    return workspace_path

def repackage_workspace(package_id, new_notes=None):
    """
    Zips the workspace back into the package's zip file and updates the database record.
    Increments version patch number (e.g. 1.0.0 -> 1.0.1) so clients detect the update automatically!
    """
    workspace_path = os.path.join(WORKSPACES_DIR, package_id)
    if not os.path.exists(workspace_path):
        return False, "Workspace directory does not exist."

    conn = get_db()
    cursor = conn.cursor()
    cursor.execute("SELECT * FROM updates WHERE package_id = ?", (package_id,))
    row = cursor.fetchone()
    if not row:
        conn.close()
        return False, "Package not found."

    filename = row["filename"]
    zip_path = os.path.join(UPLOADS_DIR, filename)

    temp_zip = zip_path + ".tmp"
    with zipfile.ZipFile(temp_zip, 'w', zipfile.ZIP_DEFLATED) as zf:
        for root, dirs, files in os.walk(workspace_path):
            for file in files:
                full_path = os.path.join(root, file)
                rel_path = os.path.relpath(full_path, workspace_path)
                zf.write(full_path, rel_path)

    if os.path.exists(zip_path):
        os.remove(zip_path)
    os.rename(temp_zip, zip_path)

    filesize = os.path.getsize(zip_path)

    # Bump version patch or revision so clients know there is a newer build
    curr_version = row["version"]
    parts = curr_version.split(".")
    if len(parts) == 3 and parts[2].isdigit():
        new_version = f"{parts[0]}.{parts[1]}.{int(parts[2]) + 1}"
    elif len(parts) >= 1 and parts[-1].isdigit():
        parts[-1] = str(int(parts[-1]) + 1)
        new_version = ".".join(parts)
    else:
        new_version = f"{curr_version}.1"

    notes = new_notes if new_notes else f"Edited & Silent-Sync pushed via Web Code Editor at {datetime.datetime.utcnow().strftime('%H:%M:%S UTC')}"

    cursor.execute("""
        UPDATE updates 
        SET version = ?, filesize = ?, notes = ?, created_at = datetime('now')
        WHERE package_id = ?
    """, (new_version, filesize, notes, package_id))
    conn.commit()
    conn.close()

    return True, new_version

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

        # Pre-extract workspace so code is immediately viewable and editable
        ensure_workspace(package_id)

        flash(f"Update package '{title}' (v{version}) uploaded for {target_school}.", "success")
        return redirect(url_for("updates_list"))

    cursor.execute("SELECT * FROM updates ORDER BY created_at DESC")
    updates = cursor.fetchall()

    cursor.execute("SELECT school_id, school_name, contact_phone, license_key FROM schools ORDER BY school_name ASC")
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
        ws_dir = os.path.join(WORKSPACES_DIR, package_id)
        if os.path.exists(ws_dir):
            try:
                shutil.rmtree(ws_dir, ignore_errors=True)
            except:
                pass
        cursor.execute("DELETE FROM updates WHERE package_id = ?", (package_id,))
        conn.commit()
        flash(f"Package {package_id} deleted.", "info")
    conn.close()
    return redirect(url_for("updates_list"))

@app.route("/updates/<package_id>/editor")
@login_required
def update_editor(package_id):
    conn = get_db()
    cursor = conn.cursor()
    cursor.execute("SELECT * FROM updates WHERE package_id = ?", (package_id,))
    package = cursor.fetchone()

    if not package:
        conn.close()
        flash("Update package not found.", "danger")
        return redirect(url_for("updates_list"))

    school = None
    if package["target_school_id"] != "ALL":
        cursor.execute("SELECT * FROM schools WHERE school_id = ? OR license_key = ?", (package["target_school_id"], package["target_school_id"]))
        school = cursor.fetchone()

    conn.close()

    ws_path = ensure_workspace(package_id)
    if not ws_path:
        flash("Could not open package files for editing.", "danger")
        return redirect(url_for("updates_list"))

    return render_template("editor.html", package=package, school=school)

@app.route("/api/editor/<package_id>/tree")
@login_required
def api_editor_tree(package_id):
    ws_path = ensure_workspace(package_id)
    if not ws_path or not os.path.exists(ws_path):
        return jsonify({"error": "Workspace not found"}), 404

    def build_tree(dir_path):
        items = []
        try:
            entries = sorted(os.listdir(dir_path), key=lambda s: (not os.path.isdir(os.path.join(dir_path, s)), s.lower()))
            for entry in entries:
                full_path = os.path.join(dir_path, entry)
                rel_path = os.path.relpath(full_path, ws_path).replace("\\", "/")
                is_dir = os.path.isdir(full_path)
                item = {
                    "name": entry,
                    "path": rel_path,
                    "is_dir": is_dir
                }
                if is_dir:
                    item["children"] = build_tree(full_path)
                else:
                    item["size"] = os.path.getsize(full_path)
                    ext = os.path.splitext(entry)[1].lower()
                    item["ext"] = ext
                items.append(item)
        except:
            pass
        return items

    tree = build_tree(ws_path)
    return jsonify({"root": tree})

@app.route("/api/editor/<package_id>/file", methods=["GET"])
@login_required
def api_editor_file(package_id):
    ws_path = ensure_workspace(package_id)
    if not ws_path:
        return jsonify({"error": "Workspace not found"}), 404

    rel_path = request.args.get("path", "").strip().lstrip("/").lstrip("\\")
    if not rel_path or ".." in rel_path:
        return jsonify({"error": "Invalid file path"}), 400

    full_path = os.path.abspath(os.path.join(ws_path, rel_path))
    if not full_path.startswith(os.path.abspath(ws_path)) or not os.path.isfile(full_path):
        return jsonify({"error": "File does not exist"}), 404

    try:
        with open(full_path, "r", encoding="utf-8", errors="replace") as f:
            content = f.read()
        return jsonify({
            "path": rel_path,
            "filename": os.path.basename(full_path),
            "content": content
        })
    except Exception as ex:
        return jsonify({"error": str(ex)}), 500

@app.route("/api/editor/<package_id>/save_file", methods=["POST"])
@login_required
def api_editor_save_file(package_id):
    ws_path = ensure_workspace(package_id)
    if not ws_path:
        return jsonify({"error": "Workspace not found"}), 404

    data = request.get_json(force=True, silent=True) or {}
    rel_path = data.get("path", "").strip().lstrip("/").lstrip("\\")
    content = data.get("content", "")

    if not rel_path or ".." in rel_path:
        return jsonify({"error": "Invalid file path"}), 400

    full_path = os.path.abspath(os.path.join(ws_path, rel_path))
    if not full_path.startswith(os.path.abspath(ws_path)):
        return jsonify({"error": "Access denied"}), 403

    try:
        os.makedirs(os.path.dirname(full_path), exist_ok=True)
        with open(full_path, "w", encoding="utf-8") as f:
            f.write(content)
        return jsonify({"status": "success", "message": f"Saved {os.path.basename(full_path)}"})
    except Exception as ex:
        return jsonify({"error": str(ex)}), 500

@app.route("/api/editor/<package_id>/push_sync", methods=["POST"])
@login_required
def api_editor_push_sync(package_id):
    data = request.get_json(force=True, silent=True) or {}
    notes = data.get("notes")
    ok, result = repackage_workspace(package_id, notes)
    if ok:
        return jsonify({
            "status": "success",
            "message": f"Changes repackaged! Version updated to v{result}. Connected clients will silently sync and deploy this fix.",
            "version": result
        })
    else:
        return jsonify({"error": result}), 500

@app.route("/api/school_health/<school_id>", methods=["GET"])
@login_required
def api_school_health(school_id):
    conn = get_db()
    cursor = conn.cursor()
    cursor.execute("SELECT * FROM schools WHERE school_id = ? OR license_key = ?", (school_id, school_id))
    school = cursor.fetchone()
    
    cursor.execute("SELECT * FROM sync_logs WHERE school_id = ? ORDER BY id DESC LIMIT 5", (school_id,))
    recent_syncs = cursor.fetchall()
    conn.close()

    if not school:
        return jsonify({"found": False})

    is_online = False
    if school["last_heartbeat"]:
        try:
            last_hb = datetime.datetime.strptime(school["last_heartbeat"], "%Y-%m-%d %H:%M:%S")
            is_online = (datetime.datetime.utcnow() - last_hb).total_seconds() < 300
        except:
            is_online = True

    return jsonify({
        "found": True,
        "school_id": school["school_id"],
        "school_name": school["school_name"],
        "license_key": school["license_key"],
        "contact_phone": school["contact_phone"] or "",
        "status": school["status"],
        "is_online": is_online,
        "last_heartbeat": school["last_heartbeat"],
        "current_app_version": school["current_app_version"],
        "last_sync_status": school["last_sync_status"] or "Ready",
        "http_health_status": school["http_health_status"] or "Healthy (200 OK)",
        "last_sync_message": school["last_sync_message"] or "",
        "last_sync_time": school["last_sync_time"] or "",
        "recent_syncs": [dict(r) for r in recent_syncs]
    })

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
    license_key = data.get("license_key", school_id).strip().upper()
    school_name = data.get("school_name", "").strip()
    contact_phone = data.get("contact_phone", "").strip()
    machine_id = data.get("machine_id", "UNKNOWN")
    shield_version = data.get("shield_version", "1.0.0")
    current_app_version = data.get("current_app_version", "1.0.0")
    last_sync_status = data.get("last_sync_status", "Ready")
    http_health_status = data.get("http_health_status", "Healthy (200 OK)")
    last_sync_time = data.get("last_sync_time", "")
    client_ip = request.headers.get("X-Forwarded-For", request.remote_addr)

    target_id = school_id or license_key
    if not target_id:
        return jsonify({"error": "Missing school_id or license_key"}), 400

    conn = get_db()
    cursor = conn.cursor()

    cursor.execute("SELECT * FROM schools WHERE school_id = ? OR license_key = ?", (target_id, target_id))
    school = cursor.fetchone()

    # Auto-register new school if unseen
    if not school:
        name = school_name or f"School ({target_id})"
        cursor.execute("""
            INSERT INTO schools (school_id, license_key, school_name, contact_phone, machine_id, shield_version, current_app_version, last_sync_status, http_health_status, last_sync_time, ip_address, last_heartbeat, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), 'active')
        """, (target_id, license_key or target_id, name, contact_phone, machine_id, shield_version, current_app_version, last_sync_status, http_health_status, last_sync_time, client_ip))
        conn.commit()
        status = "active"
        lock_reason = ""
    else:
        phone_to_save = contact_phone if contact_phone else school["contact_phone"]
        name_to_save = school_name if school_name and school_name != "EduGate Client" else school["school_name"]
        key_to_save = license_key if license_key else school["license_key"]

        cursor.execute("""
            UPDATE schools 
            SET machine_id = ?, shield_version = ?, current_app_version = ?,
                contact_phone = ?, school_name = ?, license_key = ?,
                last_sync_status = ?, http_health_status = ?,
                ip_address = ?, last_heartbeat = datetime('now')
            WHERE school_id = ?
        """, (machine_id, shield_version, current_app_version, phone_to_save, name_to_save, key_to_save, last_sync_status, http_health_status, client_ip, school["school_id"]))
        conn.commit()
        status = school["status"]
        lock_reason = school["lock_reason"]

    # Check for pending update packages targeted to this school or to ALL
    cursor.execute("""
        SELECT * FROM updates 
        WHERE (target_school_id = ? OR target_school_id = 'ALL' OR target_school_id = ?)
        ORDER BY id DESC LIMIT 1
    """, (target_id, license_key))
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
        "school_id": target_id,
        "status": status,                  # "active" or "locked"
        "lock_reason": lock_reason,        # displayed on school screen if locked
        "update": update_payload,
        "server_time": datetime.datetime.utcnow().isoformat()
    })

@app.route("/api/sync_report", methods=["POST"])
def api_sync_report():
    """
    Receives silent deployment & health verification telemetry from client machines.
    Zero-knowledge architecture.
    """
    data = request.get_json(force=True, silent=True) or {}
    school_id = data.get("school_id", "").strip().upper()
    license_key = data.get("license_key", "").strip().upper()
    package_id = data.get("package_id", "")
    version = data.get("version", "")
    status = data.get("status", "unknown")
    health_status = data.get("health_status", "")
    message = data.get("message", "")
    details = data.get("details", "")

    target_id = school_id or license_key
    if not target_id:
        return jsonify({"error": "Missing school_id"}), 400

    conn = get_db()
    cursor = conn.cursor()

    cursor.execute("""
        UPDATE schools 
        SET last_sync_status = ?, http_health_status = ?, last_sync_message = ?, 
            last_sync_time = datetime('now'), deployed_package_id = ?, current_app_version = ?
        WHERE school_id = ? OR license_key = ?
    """, (status, health_status, message, package_id, version, target_id, target_id))

    cursor.execute("""
        INSERT INTO sync_logs (school_id, license_key, package_id, version, status, health_status, message, details)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    """, (target_id, license_key, package_id, version, status, health_status, message, details))

    conn.commit()
    conn.close()

    return jsonify({"status": "received", "message": "Telemetry updated successfully."})

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

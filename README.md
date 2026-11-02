# SoftBuilt PHP Shield (v1.0)

A standalone Windows desktop application designed to import, protect, and safely deploy PHP projects (starting with **EduGate**, part of the SoftlyBuilt product family) onto an existing, independently installed XAMPP environment.

---

## 🛡️ Key Features & PRD Compliance

| Feature Area | PRD ID | Implementation Details |
|---|---|---|
| **Standalone Executable** | Section 1, 9 | Built with .NET Framework / WPF. Runs natively on Windows 10/11 with zero Python or external runtime installation required. |
| **Project Import & Validation** | FR-1.1 - FR-1.6 | Strict ZIP validation blocking **Zip Slip** (path traversal `../`), absolute paths, symlinks, and **Zip Bombs** (ratio > 100:1, > 500MB, > 50k files). Staged in isolated AppData directory. |
| **Automated Code Analysis** | FR-2.1 - FR-2.6 | Inspects file counts, PHP syntax, entry points, public folders, framework detection, min/max PHP version, required extensions (`mysqli`, `pdo_mysql`, `mbstring`, `openssl`), and flags plaintext `.env` / credentials / `phpinfo()` risks. Exportable HTML report. |
| **AES-256 Encrypted Vault** | FR-3.1 - FR-3.6 | Authenticated encryption (AES-256 with HMAC-SHA256 Encrypt-then-MAC), PBKDF2 key derivation (100k rounds), and **Windows DPAPI binding** to the local machine/user. Checked for tampering on every open. |
| **Hardened Production Build** | FR-4.1 - FR-4.7 | Strips development files (`tests/`, `docs/`, `.git/`, `.env.example`, `.sql`). Minifies and strips comments. Obfuscates local variables. Injects runtime configuration. Generates signed SHA-256 build manifest. |
| **XAMPP Discovery & Integration** | FR-5.1 - FR-5.8 | Auto-detects XAMPP via Windows Registry (`HKLM`/`HKCU`), common drive paths (`C:\xampp`, `D:\xampp`), and active processes (`httpd`, `mysqld`). Validates binaries, queries `php.exe -v` and `php.exe -m`. |
| **Isolated Apache Configuration** | FR-6.1 - FR-6.9 | Minimal isolated include: `conf/extra/softbuilt-shield.conf` included in `httpd.conf`. Full timestamped backups before modifications. Syntax validated with `httpd.exe -t` before activation. Denies access to dotfiles, `.env`, `.sql`, and backups. |
| **Atomic Deployment & Verification** | FR-7.1 - FR-7.8 | Deploys to dedicated directory `<xampp>\softbuilt-apps\edugate\releases\<id>\`. Switches `current` pointer. Built-in HTTP verifier checks 200 OK and negative paths (403/404). Automatic rollback upon verification failure. |
| **Runtime Service Control** | FR-8.1 - FR-8.7 | Live monitoring every 3s. Starts/stops managed services. Respects XAMPP Control Panel. "Stop runtime when Shield closes" setting (default OFF). |
| **Security Audit & Logs** | FR-9.1 - FR-10.3 | 6-point hardening checklist with letter grade (A+). Cryptographic tamper checks against manifest. Local audit log with automatic secret redaction. |

---

## 🚀 Getting Started

### 1. Launching the Executable
The compiled standalone executable is located at:
```text
bin\Release\SoftBuiltShield.exe
```
Double-click `SoftBuiltShield.exe` to run.

### 2. Instant Demo with EduGate Starter Package
1. Open SoftBuilt PHP Shield.
2. Click **"Load EduGate Demo"** in the top navigation bar or on the Dashboard.
3. This generates and stages an authentic EduGate School Portal package.
4. Click **"Quick Run: Import, Encrypt & Deploy EduGate Demo"** on the Dashboard.
5. Shield will encrypt the source in the vault, generate the obfuscated production build, configure XAMPP Apache, and run verification health checks!

---

## 🔨 Rebuilding from Source

You can rebuild the executable anytime using the included script:
```powershell
.\build.bat
```
Or directly with MSBuild:
```powershell
& 'C:\Windows\Microsoft.NET\Framework64\v4.0.30319\MSBuild.exe' SoftBuiltShield.csproj /p:Configuration=Release
```

---

## 🔒 Architecture & Security Principles (PRD Section 2.2)

1. **Integrate, Never Replace**: Shield never ships or replaces Apache, PHP, MySQL, or phpMyAdmin. XAMPP remains owned by XAMPP.
2. **Backup First**: Before touching any configuration file, a verified timestamped backup is made in `%ProgramData%\SoftBuilt\Shield\Backups`.
3. **Source Stays in Vault**: Original PHP source is stored encrypted with AES-256 + Windows DPAPI outside the web root. Only obfuscated, stripped production artifacts are placed in XAMPP.
4. **Honest Limitation**: Deployed PHP code is hardened and isolated from public access; original code is never exposed.

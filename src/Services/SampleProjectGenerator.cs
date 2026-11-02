using System;
using System.IO;
using System.IO.Compression;
using System.Text;

namespace SoftBuilt.PHPShield.Services
{
    public class SampleProjectGenerator
    {
        public static string GenerateEduGateStarterZip()
        {
            string sampleDir = StorageManager.SamplesDirectory;

            string zipPath = Path.Combine(sampleDir, "EduGate_School_Portal_v1.0.zip");
            if (File.Exists(zipPath))
            {
                try { return zipPath; } catch { }
            }

            string tempStaging = StorageManager.CreateTempDirectory("edugate_pack");

            try
            {
                // Create directories
                Directory.CreateDirectory(Path.Combine(tempStaging, "public"));
                Directory.CreateDirectory(Path.Combine(tempStaging, "public", "css"));
                Directory.CreateDirectory(Path.Combine(tempStaging, "public", "js"));
                Directory.CreateDirectory(Path.Combine(tempStaging, "app"));
                Directory.CreateDirectory(Path.Combine(tempStaging, "app", "Controllers"));
                Directory.CreateDirectory(Path.Combine(tempStaging, "app", "Models"));
                Directory.CreateDirectory(Path.Combine(tempStaging, "app", "config"));
                Directory.CreateDirectory(Path.Combine(tempStaging, "tests"));

                // 1. public/index.php
                string indexPhp = @"<?php
/**
 * EduGate School Portal - Production Entry Point
 * Part of the SoftlyBuilt Product Family
 */
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/Controllers/AuthController.php';

$auth = new AuthController();
$serverTime = date('Y-m-d H:i:s');
?>
<!DOCTYPE html>
<html lang=""en"">
<head>
    <meta charset=""UTF-8"">
    <meta name=""viewport"" content=""width=device-width, initial-scale=1.0"">
    <title>EduGate - School Management Portal</title>
    <link rel=""stylesheet"" href=""css/portal.css"">
</head>
<body>
    <div class=""container"">
        <header class=""hero"">
            <div class=""logo"">🎓 EduGate</div>
            <div class=""status-badge"">Active & Shielded</div>
        </header>

        <main class=""card"">
            <h2>Welcome to EduGate Portal</h2>
            <p class=""lead"">Integrated School Information & Administration Management System.</p>

            <div class=""info-grid"">
                <div class=""info-item"">
                    <span class=""label"">Runtime Engine:</span>
                    <span class=""val""><?php echo 'PHP ' . PHP_VERSION; ?></span>
                </div>
                <div class=""info-item"">
                    <span class=""label"">Deployment Status:</span>
                    <span class=""val status-online"">● Online & Verified</span>
                </div>
                <div class=""info-item"">
                    <span class=""label"">Database Connection:</span>
                    <span class=""val""><?php echo DB_HOST . ' / ' . DB_NAME; ?></span>
                </div>
                <div class=""info-item"">
                    <span class=""label"">Server Timestamp:</span>
                    <span class=""val""><?php echo $serverTime; ?></span>
                </div>
            </div>

            <div class=""action-box"">
                <button class=""btn btn-primary"">Sign In to Dashboard</button>
                <button class=""btn btn-outline"">Student Roster</button>
            </div>
        </main>

        <footer>
            <p>&copy; <?php echo date('Y'); ?> SoftlyBuilt. Protected by SoftBuilt PHP Shield.</p>
        </footer>
    </div>
</body>
</html>";
                File.WriteAllText(Path.Combine(tempStaging, "public", "index.php"), indexPhp, Encoding.UTF8);

                // 2. public/css/portal.css
                string portalCss = @"body {
    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    background: #0f172a;
    color: #f8fafc;
    display: flex;
    justify-content: center;
    padding: 40px 20px;
}
.container { width: 100%; max-width: 720px; }
.hero { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
.logo { font-size: 28px; font-weight: 800; color: #38bdf8; }
.status-badge { background: #065f46; color: #34d399; padding: 6px 14px; border-radius: 999px; font-size: 13px; font-weight: 600; }
.card { background: #1e293b; border-radius: 12px; border: 1px solid #334155; padding: 32px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); }
h2 { margin-top: 0; color: #f1f5f9; }
.lead { color: #94a3b8; font-size: 16px; margin-bottom: 24px; }
.info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; background: #0f172a; padding: 20px; border-radius: 8px; margin-bottom: 28px; border: 1px solid #334155; }
.info-item .label { display: block; font-size: 12px; color: #64748b; text-transform: uppercase; margin-bottom: 4px; }
.info-item .val { font-size: 14px; font-weight: 600; color: #e2e8f0; }
.status-online { color: #10b981 !important; }
.btn { padding: 12px 24px; font-weight: 600; border-radius: 8px; cursor: pointer; border: none; font-size: 14px; margin-right: 12px; }
.btn-primary { background: #0284c7; color: white; }
.btn-primary:hover { background: #0369a1; }
.btn-outline { background: transparent; color: #94a3b8; border: 1px solid #475569; }
footer { text-align: center; margin-top: 32px; color: #64748b; font-size: 13px; }";
                File.WriteAllText(Path.Combine(tempStaging, "public", "css", "portal.css"), portalCss, Encoding.UTF8);

                // 3. app/config/database.php
                string configDb = @"<?php
// Load Shield Injected Config if available
if (file_exists(__DIR__ . '/../softbuilt_config.php')) {
    require_once __DIR__ . '/../softbuilt_config.php';
} else {
    if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
    if (!defined('DB_NAME')) define('DB_NAME', 'edugate_db');
    if (!defined('DB_USER')) define('DB_USER', 'root');
    if (!defined('DB_PASS')) define('DB_PASS', '');
}

class Database {
    public static function getConnection() {
        return ['host' => DB_HOST, 'database' => DB_NAME, 'user' => DB_USER];
    }
}";
                File.WriteAllText(Path.Combine(tempStaging, "app", "config", "database.php"), configDb, Encoding.UTF8);

                // 4. app/Controllers/AuthController.php
                string authCtrl = @"<?php
class AuthController {
    private $sessionName = 'edugate_auth_token';

    public function __construct() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function checkAccess() {
        return isset($_SESSION[$this->sessionName]);
    }

    public function authenticateUser($username, $passwordHash) {
        // Authenticate student or staff member
        $validToken = hash('sha256', $username . 'edugate_salt_2026');
        $_SESSION[$this->sessionName] = $validToken;
        return true;
    }
}";
                File.WriteAllText(Path.Combine(tempStaging, "app", "Controllers", "AuthController.php"), authCtrl, Encoding.UTF8);

                // 5. app/Models/Student.php
                string studentModel = @"<?php
class Student {
    public $id;
    public $name;
    public $grade;
    public $enrolledAt;

    public function __construct($id, $name, $grade) {
        $this->id = $id;
        $this->name = $name;
        $this->grade = $grade;
        $this->enrolledAt = date('Y-m-d');
    }
}";
                File.WriteAllText(Path.Combine(tempStaging, "app", "Models", "Student.php"), studentModel, Encoding.UTF8);

                // 6. composer.json
                string composerJson = @"{
    ""name"": ""softlybuilt/edugate"",
    ""description"": ""EduGate School Information System"",
    ""type"": ""project"",
    ""require"": {
        ""php"": ""^8.0 || ^8.1 || ^8.2 || ^8.3"",
        ""ext-mysqli"": ""*"",
        ""ext-pdo_mysql"": ""*"",
        ""ext-mbstring"": ""*"",
        ""ext-openssl"": ""*""
    }
}";
                File.WriteAllText(Path.Combine(tempStaging, "composer.json"), composerJson, Encoding.UTF8);

                // 7. database.sql
                string dbSql = @"-- EduGate Database Schema
CREATE TABLE IF NOT EXISTS `students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(120) NOT NULL,
  `grade_level` varchar(20) NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
                File.WriteAllText(Path.Combine(tempStaging, "database.sql"), dbSql, Encoding.UTF8);

                // 8. tests/StudentTest.php (development test to be stripped in production)
                string testPhp = @"<?php
// Unit Test file for Student Model
class StudentTest {
    public function testCreation() {
        assert(true);
    }
}";
                File.WriteAllText(Path.Combine(tempStaging, "tests", "StudentTest.php"), testPhp, Encoding.UTF8);

                // Create the zip archive
                ZipFile.CreateFromDirectory(tempStaging, zipPath, CompressionLevel.Optimal, false);
            }
            finally
            {
                try { Directory.Delete(tempStaging, true); } catch { }
            }

            return zipPath;
        }
    }
}

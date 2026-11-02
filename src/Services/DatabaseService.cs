using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Text;

namespace SoftBuilt.PHPShield.Services
{
    public class DatabaseService
    {
        private static DatabaseService _instance;
        public static DatabaseService Instance
        {
            get
            {
                if (_instance == null) _instance = new DatabaseService();
                return _instance;
            }
        }

        public string GetMysqlExePath(string xamppPath)
        {
            if (string.IsNullOrEmpty(xamppPath)) return null;
            string path = Path.Combine(xamppPath, @"mysql\bin\mysql.exe");
            return File.Exists(path) ? path : null;
        }

        public string GetMysqldumpExePath(string xamppPath)
        {
            if (string.IsNullOrEmpty(xamppPath)) return null;
            string path = Path.Combine(xamppPath, @"mysql\bin\mysqldump.exe");
            return File.Exists(path) ? path : null;
        }

        public string GetMysqladminExePath(string xamppPath)
        {
            if (string.IsNullOrEmpty(xamppPath)) return null;
            string path = Path.Combine(xamppPath, @"mysql\bin\mysqladmin.exe");
            return File.Exists(path) ? path : null;
        }

        private static string FormatPassArg(string pass)
        {
            if (string.IsNullOrEmpty(pass)) return "";
            string safe = pass.Replace("\\", "\\\\").Replace("\"", "\\\"");
            return string.Format("-p\"{0}\"", safe);
        }

        public bool TestConnection(string xamppPath, string dbHost, string dbUser, string dbPass, out string message)
        {
            message = string.Empty;
            string mysqlExe = GetMysqlExePath(xamppPath);
            if (string.IsNullOrEmpty(mysqlExe))
            {
                message = "mysql.exe not found in XAMPP directory: " + Path.Combine(xamppPath ?? "", @"mysql\bin");
                return false;
            }

            try
            {
                string host = string.IsNullOrEmpty(dbHost) ? "127.0.0.1" : dbHost;
                string user = string.IsNullOrEmpty(dbUser) ? "root" : dbUser;
                string passArg = FormatPassArg(dbPass);

                string args = string.Format("-h {0} -u {1} {2} -e \"SELECT 1;\"", host, user, passArg);

                var psi = new ProcessStartInfo
                {
                    FileName = mysqlExe,
                    Arguments = args,
                    UseShellExecute = false,
                    RedirectStandardOutput = true,
                    RedirectStandardError = true,
                    CreateNoWindow = true
                };

                using (var p = Process.Start(psi))
                {
                    // Read streams async to prevent deadlock
                    var stdoutTask = p.StandardOutput.ReadToEndAsync();
                    var stderrTask = p.StandardError.ReadToEndAsync();
                    p.WaitForExit(8000);
                    if (p.ExitCode == 0)
                    {
                        message = "Database connection successful! MySQL accepted credentials.";
                        return true;
                    }
                    else
                    {
                        string err = stderrTask.Result;
                        message = "Connection failed: " + (string.IsNullOrEmpty(err) ? "Exit code " + p.ExitCode : err.Trim());
                        return false;
                    }
                }
            }
            catch (Exception ex)
            {
                message = "Connection error: " + ex.Message;
                return false;
            }
        }

        public bool ChangeMysqlRootPassword(string xamppPath, string currentPassword, string newPassword, bool updatePhpMyAdmin, Action<string> log, out string errorMessage)
        {
            return ConfigureAdminUser(xamppPath, "root", currentPassword, "root", newPassword, updatePhpMyAdmin, true, log, out errorMessage);
        }

        public bool ChangeMysqlRootPassword(string xamppPath, string currentPassword, string newPassword, bool updatePhpMyAdmin, bool requireLoginAuth, Action<string> log, out string errorMessage)
        {
            return ConfigureAdminUser(xamppPath, "root", currentPassword, "root", newPassword, updatePhpMyAdmin, requireLoginAuth, log, out errorMessage);
        }

        public bool ConfigureAdminUser(string xamppPath, string currentAdminUser, string currentPassword, string newAdminUser, string newPassword, bool updatePhpMyAdmin, bool requireLoginAuth, Action<string> log, out string errorMessage)
        {
            errorMessage = string.Empty;
            Action<string> logger = log ?? (s => { });

            string mysqlExe = GetMysqlExePath(xamppPath);
            string mysqladminExe = GetMysqladminExePath(xamppPath);

            if (string.IsNullOrEmpty(mysqlExe))
            {
                errorMessage = "mysql.exe not found in XAMPP installation.";
                logger("FAIL: " + errorMessage);
                return false;
            }

            try
            {
                string targetUser = string.IsNullOrEmpty(newAdminUser) ? "root" : newAdminUser.Trim();
                string connectingUser = string.IsNullOrEmpty(currentAdminUser) ? "root" : currentAdminUser.Trim();
                string safeNew = newPassword ?? "";
                string safeCurr = currentPassword ?? "";

                logger(string.Format("Attempting to configure MySQL admin user '{0}'...", targetUser));

                // Smart auto-fallback: verify connection with provided current credentials
                string testMsg;
                if (!TestConnection(xamppPath, "127.0.0.1", connectingUser, safeCurr, out testMsg))
                {
                    // If connectingUser with safeCurr failed, probe if root with safeCurr or root with blank connects
                    if (TestConnection(xamppPath, "127.0.0.1", "root", safeCurr, out testMsg))
                    {
                        connectingUser = "root";
                    }
                    else if (TestConnection(xamppPath, "127.0.0.1", "root", "", out testMsg))
                    {
                        logger("ℹ️ Connected to MySQL via default blank root. Applying credentials.");
                        connectingUser = "root";
                        safeCurr = "";
                    }
                    else if (!string.IsNullOrEmpty(targetUser) && TestConnection(xamppPath, "127.0.0.1", targetUser, safeCurr, out testMsg))
                    {
                        connectingUser = targetUser;
                    }
                }

                // Construct SQL statements:
                string escapedPass = safeNew.Replace("'", "''");
                string escapedUser = targetUser.Replace("'", "''");
                string sqlCommands;

                if (string.Equals(targetUser, "root", StringComparison.OrdinalIgnoreCase))
                {
                    sqlCommands = string.Format(
                        "ALTER USER 'root'@'localhost' IDENTIFIED BY '{0}'; " +
                        "ALTER USER 'root'@'127.0.0.1' IDENTIFIED BY '{0}'; " +
                        "ALTER USER 'root'@'::1' IDENTIFIED BY '{0}'; " +
                        "CREATE USER IF NOT EXISTS 'pma'@'localhost'; " +
                        "GRANT ALL PRIVILEGES ON phpmyadmin.* TO 'pma'@'localhost'; " +
                        "GRANT ALL PRIVILEGES ON phpmyadmin.* TO 'pma'@'127.0.0.1'; " +
                        "FLUSH PRIVILEGES;", escapedPass);
                }
                else
                {
                    // Create/update custom admin user with full grant privileges AND ensure root also has the password
                    sqlCommands = string.Format(
                        "CREATE USER IF NOT EXISTS '{1}'@'localhost' IDENTIFIED BY '{0}'; " +
                        "ALTER USER '{1}'@'localhost' IDENTIFIED BY '{0}'; " +
                        "GRANT ALL PRIVILEGES ON *.* TO '{1}'@'localhost' WITH GRANT OPTION; " +
                        "CREATE USER IF NOT EXISTS '{1}'@'127.0.0.1' IDENTIFIED BY '{0}'; " +
                        "ALTER USER '{1}'@'127.0.0.1' IDENTIFIED BY '{0}'; " +
                        "GRANT ALL PRIVILEGES ON *.* TO '{1}'@'127.0.0.1' WITH GRANT OPTION; " +
                        "CREATE USER IF NOT EXISTS '{1}'@'::1' IDENTIFIED BY '{0}'; " +
                        "ALTER USER '{1}'@'::1' IDENTIFIED BY '{0}'; " +
                        "GRANT ALL PRIVILEGES ON *.* TO '{1}'@'::1' WITH GRANT OPTION; " +
                        "ALTER USER 'root'@'localhost' IDENTIFIED BY '{0}'; " +
                        "ALTER USER 'root'@'127.0.0.1' IDENTIFIED BY '{0}'; " +
                        "CREATE USER IF NOT EXISTS 'pma'@'localhost'; " +
                        "GRANT ALL PRIVILEGES ON phpmyadmin.* TO 'pma'@'localhost'; " +
                        "GRANT ALL PRIVILEGES ON phpmyadmin.* TO 'pma'@'127.0.0.1'; " +
                        "FLUSH PRIVILEGES;", escapedPass, escapedUser);
                }

                string currPassArg = string.IsNullOrEmpty(safeCurr) ? "" : ("-p\"" + safeCurr.Replace("\"", "\\\"") + "\"");
                string args = string.Format("-h 127.0.0.1 -u {0} {1} -e \"{2}\"", connectingUser, currPassArg, sqlCommands.Replace("\"", "\\\""));

                var psi = new ProcessStartInfo
                {
                    FileName = mysqlExe,
                    Arguments = args,
                    UseShellExecute = false,
                    RedirectStandardOutput = true,
                    RedirectStandardError = true,
                    CreateNoWindow = true
                };

                bool stepSuccess = false;
                using (var p = Process.Start(psi))
                {
                    p.WaitForExit(10000);
                    if (p.ExitCode == 0)
                    {
                        stepSuccess = true;
                    }
                }

                // Attempt 2: If SQL failed (e.g. older MariaDB syntax or ALTER USER unsupported)
                if (!stepSuccess)
                {
                    string sqlLegacy = string.Format(
                        "SET PASSWORD FOR 'root'@'localhost' = PASSWORD('{0}'); " +
                        "SET PASSWORD FOR 'root'@'127.0.0.1' = PASSWORD('{0}'); " +
                        "FLUSH PRIVILEGES;", escapedPass);
                    string argsLegacy = string.Format("-h 127.0.0.1 -u root {0} -e \"{1}\"", currPassArg, sqlLegacy.Replace("\"", "\\\""));
                    var psiLegacy = new ProcessStartInfo
                    {
                        FileName = mysqlExe,
                        Arguments = argsLegacy,
                        UseShellExecute = false,
                        RedirectStandardOutput = true,
                        RedirectStandardError = true,
                        CreateNoWindow = true
                    };
                    using (var p = Process.Start(psiLegacy))
                    {
                        p.WaitForExit(10000);
                        if (p.ExitCode == 0)
                        {
                            stepSuccess = true;
                        }
                    }
                }

                // Attempt 3: Try mysqladmin if mysql.exe commands failed
                if (!stepSuccess && !string.IsNullOrEmpty(mysqladminExe))
                {
                    string adminArgs = string.Format("-u root -h 127.0.0.1 {0} password \"{1}\"",
                        currPassArg, safeNew.Replace("\"", "\\\""));
                    var psiAdmin = new ProcessStartInfo
                    {
                        FileName = mysqladminExe,
                        Arguments = adminArgs,
                        UseShellExecute = false,
                        RedirectStandardOutput = true,
                        RedirectStandardError = true,
                        CreateNoWindow = true
                    };
                    using (var p = Process.Start(psiAdmin))
                    {
                        p.WaitForExit(10000);
                        if (p.ExitCode == 0)
                        {
                            stepSuccess = true;
                        }
                    }
                }

                // Verification check with new username and password
                string verifyMsg;
                bool verified = TestConnection(xamppPath, "127.0.0.1", targetUser, safeNew, out verifyMsg);
                if (!verified)
                {
                    // Also try localhost
                    verified = TestConnection(xamppPath, "localhost", targetUser, safeNew, out verifyMsg);
                }

                if (!verified && !string.Equals(targetUser, "root", StringComparison.OrdinalIgnoreCase))
                {
                    // Also check root as backup
                    verified = TestConnection(xamppPath, "127.0.0.1", "root", safeNew, out verifyMsg);
                }

                if (!verified)
                {
                    errorMessage = "Failed to verify connection with new credentials: " + verifyMsg;
                    logger("FAIL: " + errorMessage);
                    AuditLogService.Instance.Log("DATABASE", "Credentials Update Failed", errorMessage, false);
                    return false;
                }

                logger(string.Format("✅ MySQL admin credentials for user '{0}' verified successfully.", targetUser));

                // Synchronize phpMyAdmin config.inc.php if requested
                if (updatePhpMyAdmin)
                {
                    string pmaErr;
                    if (SyncPhpMyAdminPassword(xamppPath, targetUser, safeNew, requireLoginAuth, out pmaErr))
                    {
                        logger(requireLoginAuth
                            ? string.Format("✅ phpMyAdmin configured for Cookie Auth (User: {0}). Login prompt active.", targetUser)
                            : string.Format("✅ phpMyAdmin configuration updated (User: {0}).", targetUser));
                    }
                    else
                    {
                        logger("WARN: phpMyAdmin sync note: " + pmaErr);
                    }

                    // Auto-heal: Ensure phpMyAdmin configuration storage & pma user privileges on this PC
                    EnsurePhpMyAdminConfigStorage(xamppPath, targetUser, safeNew, logger);
                }

                AuditLogService.Instance.Log("DATABASE", "Credentials Updated", string.Format("MySQL user '{0}' configured and verified successfully.", targetUser), true);
                return true;
            }
            catch (Exception ex)
            {
                errorMessage = "Exception updating database password: " + ex.Message;
                logger("FAIL: " + errorMessage);
                AuditLogService.Instance.Log("DATABASE", "Password Exception", ex.Message, false);
                return false;
            }
        }

        public void EnsurePhpMyAdminConfigStorage(string xamppPath, string adminUser, string adminPass, Action<string> log)
        {
            Action<string> logger = log ?? (s => { });
            string mysqlExe = GetMysqlExePath(xamppPath);
            if (string.IsNullOrEmpty(mysqlExe)) return;

            try
            {
                string u = string.IsNullOrEmpty(adminUser) ? "root" : adminUser.Trim();
                string passArg = FormatPassArg(adminPass);

                // 1. Proactively repair mysql.db in case MariaDB Aria table index had a crash
                RunMySqlDirect(mysqlExe, string.Format("-h 127.0.0.1 -u {0} {1} -e \"REPAIR TABLE mysql.db USE_FRM;\"", u, passArg));

                // 2. Check if phpmyadmin database tables exist; if missing, auto-import create_tables.sql
                string testTables = RunMySqlDirectWithOutput(mysqlExe, string.Format("-h 127.0.0.1 -u {0} {1} -e \"SHOW TABLES FROM phpmyadmin;\"", u, passArg));
                if (string.IsNullOrEmpty(testTables) || !testTables.Contains("pma__"))
                {
                    string createTablesSql = Path.Combine(xamppPath, @"phpMyAdmin\sql\create_tables.sql");
                    if (File.Exists(createTablesSql))
                    {
                        logger("Initializing default phpMyAdmin configuration storage tables...");
                        RunMySqlDirect(mysqlExe, string.Format("-h 127.0.0.1 -u {0} {1} -e \"CREATE DATABASE IF NOT EXISTS phpmyadmin; source {2};\"",
                            u, passArg, createTablesSql.Replace("\\", "/")));
                    }
                }

                // 3. Ensure pma user exists and has all permissions on phpmyadmin.*
                string pmaSql = "CREATE USER IF NOT EXISTS 'pma'@'localhost'; " +
                                "CREATE USER IF NOT EXISTS 'pma'@'127.0.0.1'; " +
                                "GRANT ALL PRIVILEGES ON phpmyadmin.* TO 'pma'@'localhost'; " +
                                "GRANT ALL PRIVILEGES ON phpmyadmin.* TO 'pma'@'127.0.0.1'; " +
                                "FLUSH PRIVILEGES;";
                RunMySqlDirect(mysqlExe, string.Format("-h 127.0.0.1 -u {0} {1} -e \"{2}\"", u, passArg, pmaSql.Replace("\"", "\\\"")));
            }
            catch (Exception ex)
            {
                logger("Note on phpMyAdmin storage setup: " + ex.Message);
            }
        }

        private bool RunMySqlDirect(string mysqlExe, string args)
        {
            try
            {
                var psi = new ProcessStartInfo
                {
                    FileName = mysqlExe,
                    Arguments = args,
                    UseShellExecute = false,
                    RedirectStandardOutput = true,
                    RedirectStandardError = true,
                    CreateNoWindow = true
                };
                using (var p = Process.Start(psi))
                {
                    p.WaitForExit(8000);
                    return p.ExitCode == 0;
                }
            }
            catch
            {
                return false;
            }
        }

        private string RunMySqlDirectWithOutput(string mysqlExe, string args)
        {
            try
            {
                var psi = new ProcessStartInfo
                {
                    FileName = mysqlExe,
                    Arguments = args,
                    UseShellExecute = false,
                    RedirectStandardOutput = true,
                    RedirectStandardError = true,
                    CreateNoWindow = true
                };
                using (var p = Process.Start(psi))
                {
                    string stdout = p.StandardOutput.ReadToEnd();
                    p.WaitForExit(8000);
                    return stdout;
                }
            }
            catch
            {
                return string.Empty;
            }
        }

        public bool SyncPhpMyAdminPassword(string xamppPath, string newPassword, out string errorMessage)
        {
            return SyncPhpMyAdminPassword(xamppPath, "root", newPassword, true, out errorMessage);
        }

        public bool SyncPhpMyAdminPassword(string xamppPath, string newPassword, bool requireLoginAuth, out string errorMessage)
        {
            return SyncPhpMyAdminPassword(xamppPath, "root", newPassword, requireLoginAuth, out errorMessage);
        }

        public bool SyncPhpMyAdminPassword(string xamppPath, string dbUser, string newPassword, bool requireLoginAuth, out string errorMessage)
        {
            errorMessage = string.Empty;
            try
            {
                if (string.IsNullOrEmpty(xamppPath))
                {
                    errorMessage = "XAMPP path is empty.";
                    return false;
                }

                string configFile = Path.Combine(xamppPath, @"phpMyAdmin\config.inc.php");
                if (!File.Exists(configFile))
                {
                    errorMessage = "phpMyAdmin config.inc.php not found at " + configFile;
                    return false;
                }

                // L2: Always create a timestamped backup (not just on first run)
                string bakFile = configFile + "." + DateTime.Now.ToString("yyyyMMdd_HHmmss") + ".shield_bak";
                File.Copy(configFile, bakFile, true);

                string text = File.ReadAllText(configFile);
                string escaped = (newPassword ?? "").Replace("'", "\\'");

                string safeUser = (string.IsNullOrEmpty(dbUser) ? "root" : dbUser.Trim()).Replace("'", "\\'");
                if (System.Text.RegularExpressions.Regex.IsMatch(text, @"\$cfg\['Servers'\]\[\$i\]\['user'\]\s*=\s*['""][^'""]*['""];"))
                {
                    text = System.Text.RegularExpressions.Regex.Replace(text, @"\$cfg\['Servers'\]\[\$i\]\['user'\]\s*=\s*['""][^'""]*['""];",
                        string.Format("$cfg['Servers'][$i]['user'] = '{0}';", safeUser));
                }

                if (requireLoginAuth)
                {
                    // 1. Switch auth_type to 'cookie' so every user must enter the password to log in.
                    // This blocks any unauthorized user on the LAN who visits /phpmyadmin/ from auto-entering!
                    if (System.Text.RegularExpressions.Regex.IsMatch(text, @"\$cfg\['Servers'\]\[\$i\]\['auth_type'\]\s*=\s*['""][^'""]*['""];"))
                    {
                        text = System.Text.RegularExpressions.Regex.Replace(text, @"\$cfg\['Servers'\]\[\$i\]\['auth_type'\]\s*=\s*['""][^'""]*['""];",
                            "$cfg['Servers'][$i]['auth_type'] = 'cookie';");
                    }

                    // 2. Clear hardcoded password in config so plaintext credentials aren't saved on disk
                    if (System.Text.RegularExpressions.Regex.IsMatch(text, @"\$cfg\['Servers'\]\[\$i\]\['password'\]\s*=\s*['""][^'""]*['""];"))
                    {
                        text = System.Text.RegularExpressions.Regex.Replace(text, @"\$cfg\['Servers'\]\[\$i\]\['password'\]\s*=\s*['""][^'""]*['""];",
                            "$cfg['Servers'][$i]['password'] = '';");
                    }

                    // 3. Set AllowNoPassword = false so empty passwords are rejected
                    if (System.Text.RegularExpressions.Regex.IsMatch(text, @"\$cfg\['Servers'\]\[\$i\]\['AllowNoPassword'\]\s*=\s*(true|false);", System.Text.RegularExpressions.RegexOptions.IgnoreCase))
                    {
                        text = System.Text.RegularExpressions.Regex.Replace(text, @"\$cfg\['Servers'\]\[\$i\]\['AllowNoPassword'\]\s*=\s*(true|false);",
                            "$cfg['Servers'][$i]['AllowNoPassword'] = false;", System.Text.RegularExpressions.RegexOptions.IgnoreCase);
                    }
                    else if (text.Contains("$cfg['Servers'][$i]['auth_type']"))
                    {
                        text = System.Text.RegularExpressions.Regex.Replace(text, @"(\$cfg\['Servers'\]\[\$i\]\['auth_type'\]\s*=[^;]+;)",
                            "$1\r\n$cfg['Servers'][$i]['AllowNoPassword'] = false;");
                    }

                    // 4. Ensure blowfish_secret is exactly 32 bytes (required by phpMyAdmin for cookie auth)
                    var blowfishMatch = System.Text.RegularExpressions.Regex.Match(text, @"\$cfg\['blowfish_secret'\]\s*=\s*['""]([^'""]*)['""];");
                    if (blowfishMatch.Success)
                    {
                        string currentSecret = blowfishMatch.Groups[1].Value;
                        if (string.IsNullOrEmpty(currentSecret) || currentSecret == "xampp" || currentSecret.Length != 32)
                        {
                            string newSecret = Guid.NewGuid().ToString("N"); // Exactly 32 hex chars (32 bytes)
                            text = System.Text.RegularExpressions.Regex.Replace(text, @"\$cfg\['blowfish_secret'\]\s*=\s*['""][^'""]*['""];",
                                string.Format("$cfg['blowfish_secret'] = '{0}';", newSecret));
                        }
                    }

                    // 5. Disable version check notification in school environments
                    if (!text.Contains("$cfg['VersionCheck']"))
                    {
                        text = text.Replace("$cfg['blowfish_secret']", "$cfg['VersionCheck'] = false;\r\n$cfg['blowfish_secret']");
                    }
                }
                else
                {
                    // Config-based auto-login mode
                    if (System.Text.RegularExpressions.Regex.IsMatch(text, @"\$cfg\['Servers'\]\[\$i\]\['auth_type'\]\s*=\s*['""][^'""]*['""];"))
                    {
                        text = System.Text.RegularExpressions.Regex.Replace(text, @"\$cfg\['Servers'\]\[\$i\]\['auth_type'\]\s*=\s*['""][^'""]*['""];",
                            "$cfg['Servers'][$i]['auth_type'] = 'config';");
                    }

                    bool replaced = false;
                    if (System.Text.RegularExpressions.Regex.IsMatch(text, @"\$cfg\['Servers'\]\[\$i\]\['password'\]\s*=\s*'[^']*';"))
                    {
                        text = System.Text.RegularExpressions.Regex.Replace(text, @"\$cfg\['Servers'\]\[\$i\]\['password'\]\s*=\s*'[^']*';",
                            string.Format("$cfg['Servers'][$i]['password'] = '{0}';", escaped));
                        replaced = true;
                    }
                    else if (System.Text.RegularExpressions.Regex.IsMatch(text, @"\$cfg\['Servers'\]\[\$i\]\['password'\]\s*=\s*""[^""]*"";"))
                    {
                        text = System.Text.RegularExpressions.Regex.Replace(text, @"\$cfg\['Servers'\]\[\$i\]\['password'\]\s*=\s*""[^""]*"";",
                            string.Format("$cfg['Servers'][$i]['password'] = '{0}';", escaped));
                        replaced = true;
                    }

                    if (!replaced && text.Contains("$cfg['Servers'][$i]['auth_type']"))
                    {
                        text = System.Text.RegularExpressions.Regex.Replace(text, @"(\$cfg\['Servers'\]\[\$i\]\['auth_type'\]\s*=[^;]+;)",
                            string.Format("$1\r\n$cfg['Servers'][$i]['password'] = '{0}';", escaped));
                    }
                }

                File.WriteAllText(configFile, text);
                return true;
            }
            catch (Exception ex)
            {
                errorMessage = ex.Message;
                return false;
            }
        }

        public List<string> FindSqlFilesInDirectory(string dir)
        {
            var list = new List<string>();
            if (string.IsNullOrEmpty(dir) || !Directory.Exists(dir)) return list;

            try
            {
                var files = Directory.GetFiles(dir, "*.sql", SearchOption.AllDirectories);
                list.AddRange(files);
            }
            catch { }
            return list;
        }

        public bool ImportSqlFile(string xamppPath, string dbHost, string dbUser, string dbPass, string dbName, string sqlFilePath, Action<string> log, out string errorMessage)
        {
            errorMessage = string.Empty;
            Action<string> logger = log ?? (s => { });

            if (!File.Exists(sqlFilePath))
            {
                errorMessage = "SQL file not found at " + sqlFilePath;
                logger("FAIL: " + errorMessage);
                return false;
            }

            string mysqlExe = GetMysqlExePath(xamppPath);
            if (string.IsNullOrEmpty(mysqlExe))
            {
                errorMessage = "mysql.exe not found in XAMPP directory: " + Path.Combine(xamppPath ?? "", @"mysql\bin");
                logger("FAIL: " + errorMessage);
                return false;
            }

            try
            {
                logger(string.Format("Ensuring target database '{0}' exists in MySQL...", dbName));
                
                // 1. Create DB if not exists
                string passArg = FormatPassArg(dbPass);
                string createDbArgs = string.Format("-h {0} -u {1} {2} -e \"CREATE DATABASE IF NOT EXISTS `{3}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\"",
                    string.IsNullOrEmpty(dbHost) ? "127.0.0.1" : dbHost,
                    string.IsNullOrEmpty(dbUser) ? "root" : dbUser,
                    passArg,
                    dbName);

                var psiCreate = new ProcessStartInfo
                {
                    FileName = mysqlExe,
                    Arguments = createDbArgs,
                    UseShellExecute = false,
                    RedirectStandardOutput = true,
                    RedirectStandardError = true,
                    CreateNoWindow = true
                };

                using (var p = Process.Start(psiCreate))
                {
                    p.WaitForExit(10000);
                    if (p.ExitCode != 0)
                    {
                        string err = p.StandardError.ReadToEnd();
                        logger("WARN: Create database returned code " + p.ExitCode + ": " + err);
                    }
                }

                // 2. Import SQL script
                logger(string.Format("Importing SQL script '{0}' into '{1}'...", Path.GetFileName(sqlFilePath), dbName));
                string importArgs = string.Format("-h {0} -u {1} {2} {3}",
                    string.IsNullOrEmpty(dbHost) ? "127.0.0.1" : dbHost,
                    string.IsNullOrEmpty(dbUser) ? "root" : dbUser,
                    passArg,
                    dbName);

                var psiImport = new ProcessStartInfo
                {
                    FileName = mysqlExe,
                    Arguments = importArgs,
                    UseShellExecute = false,
                    RedirectStandardInput = true,
                    RedirectStandardOutput = true,
                    RedirectStandardError = true,
                    CreateNoWindow = true
                };

                using (var p = Process.Start(psiImport))
                {
                    using (var sr = new StreamReader(sqlFilePath, Encoding.UTF8))
                    {
                        string line;
                        while ((line = sr.ReadLine()) != null)
                        {
                            p.StandardInput.WriteLine(line);
                        }
                    }
                    p.StandardInput.Flush();
                    p.StandardInput.Close();

                    // C4: Large school DBs can have hundreds of tables — 5 minute timeout
                    // Also read streams async to prevent deadlock on large output
                    var stderrTask = p.StandardError.ReadToEndAsync();
                    bool finished = p.WaitForExit(300000); // 5 minutes
                    string stdErr = stderrTask.Result;

                    if (!finished)
                    {
                        try { p.Kill(); } catch { }
                        errorMessage = "MySQL import timed out after 5 minutes. The SQL file may be too large or MySQL is unresponsive.";
                        logger("FAIL: " + errorMessage);
                        AuditLogService.Instance.Log("DATABASE", "Schema Import Timeout", errorMessage, false);
                        return false;
                    }

                    if (p.ExitCode != 0 && !string.IsNullOrEmpty(stdErr))
                    {
                        errorMessage = stdErr;
                        logger("FAIL: MySQL import error: " + stdErr);
                        AuditLogService.Instance.Log("DATABASE", "Schema Import Failed", stdErr, false);
                        return false;
                    }
                }

                logger(string.Format("✅ Successfully imported database schema into '{0}'.", dbName));
                AuditLogService.Instance.Log("DATABASE", "Schema Import Succeeded", string.Format("Imported {0} into {1}", Path.GetFileName(sqlFilePath), dbName), true);
                return true;
            }
            catch (Exception ex)
            {
                errorMessage = ex.Message;
                logger("FAIL: Database import exception: " + ex.Message);
                AuditLogService.Instance.Log("DATABASE", "Schema Import Error", ex.Message, false);
                return false;
            }
        }

        public string BackupDatabase(string xamppPath, string dbHost, string dbUser, string dbPass, string dbName, string targetFolder, Action<string> log, out string errorMessage)
        {
            errorMessage = string.Empty;
            Action<string> logger = log ?? (s => { });

            string dumpExe = GetMysqldumpExePath(xamppPath);
            if (string.IsNullOrEmpty(dumpExe))
            {
                errorMessage = "mysqldump.exe not found in XAMPP directory: " + Path.Combine(xamppPath ?? "", @"mysql\bin");
                logger("FAIL: " + errorMessage);
                return null;
            }

            if (string.IsNullOrEmpty(targetFolder))
            {
                targetFolder = Path.Combine(xamppPath, "backups", "database");
            }

            if (!Directory.Exists(targetFolder)) Directory.CreateDirectory(targetFolder);

            string timestamp = DateTime.Now.ToString("yyyyMMdd_HHmmss");
            string backupFileName = string.Format("{0}_backup_{1}.sql", dbName, timestamp);
            string backupFilePath = Path.Combine(targetFolder, backupFileName);

            try
            {
                logger(string.Format("Creating timestamped database backup for '{0}'...", dbName));

                string dumpArgs = string.Format("-h {0} -u {1} {2} --databases {3} --routines --triggers",
                    string.IsNullOrEmpty(dbHost) ? "127.0.0.1" : dbHost,
                    string.IsNullOrEmpty(dbUser) ? "root" : dbUser,
                    FormatPassArg(dbPass),
                    dbName);

                var psi = new ProcessStartInfo
                {
                    FileName = dumpExe,
                    Arguments = dumpArgs,
                    UseShellExecute = false,
                    RedirectStandardOutput = true,
                    RedirectStandardError = true,
                    CreateNoWindow = true
                };

                // H6: Fix stdout deadlock — write output directly to file while reading,
                // then wait for process. Old pattern (ReadLine loop then WaitForExit) deadlocks
                // when stdout buffer fills on large databases.
                using (var p = Process.Start(psi))
                using (var fs = new FileStream(backupFilePath, FileMode.Create, FileAccess.Write))
                using (var sw = new StreamWriter(fs, Encoding.UTF8))
                {
                    var stderrTask = p.StandardError.ReadToEndAsync();

                    // Read stdout line by line feeding to file (prevents buffer overflow)
                    string dumpLine;
                    while ((dumpLine = p.StandardOutput.ReadLine()) != null)
                    {
                        sw.WriteLine(dumpLine);
                    }
                    sw.Flush();

                    bool finished = p.WaitForExit(300000); // 5 min for large DBs
                    string stdErr = stderrTask.Result;

                    if (!finished)
                    {
                        try { p.Kill(); } catch { }
                        errorMessage = "mysqldump timed out after 5 minutes.";
                        logger("FAIL: " + errorMessage);
                        try { if (File.Exists(backupFilePath)) File.Delete(backupFilePath); } catch { }
                        AuditLogService.Instance.Log("DATABASE", "Backup Timeout", errorMessage, false);
                        return null;
                    }

                    if (p.ExitCode != 0)
                    {
                        errorMessage = stdErr;
                        logger("FAIL: mysqldump error: " + stdErr);
                        try { if (File.Exists(backupFilePath)) File.Delete(backupFilePath); } catch { }
                        AuditLogService.Instance.Log("DATABASE", "Backup Failed", stdErr, false);
                        return null;
                    }
                }

                var fi = new FileInfo(backupFilePath);
                logger(string.Format("✅ Database backup created: {0} ({1:F1} KB)", backupFileName, fi.Length / 1024.0));
                AuditLogService.Instance.Log("DATABASE", "Backup Created", string.Format("Saved {0} ({1} bytes)", backupFileName, fi.Length), true);
                return backupFilePath;
            }
            catch (Exception ex)
            {
                errorMessage = ex.Message;
                logger("FAIL: Database backup exception: " + ex.Message);
                AuditLogService.Instance.Log("DATABASE", "Backup Exception", ex.Message, false);
                return null;
            }
        }
    }
}

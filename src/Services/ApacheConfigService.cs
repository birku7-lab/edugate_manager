using System;
using System.Diagnostics;
using System.IO;
using System.Text;
using SoftBuilt.PHPShield.Models;

namespace SoftBuilt.PHPShield.Services
{
    public class ApacheConfigService
    {
        private static ApacheConfigService _instance;
        public static ApacheConfigService Instance
        {
            get
            {
                if (_instance == null) _instance = new ApacheConfigService();
                return _instance;
            }
        }

        private const string INCLUDE_MARKER = "# SoftBuilt PHP Shield Managed Include";

        public string CreateBackup(string xamppPath, out string backupFolder)
        {
            string backupsBase = SettingsService.Instance.GetBackupsDirectory();
            string timestamp = DateTime.Now.ToString("yyyyMMdd_HHmmss");
            backupFolder = Path.Combine(backupsBase, "xampp_backup_" + timestamp);
            Directory.CreateDirectory(backupFolder);

            string httpdConf = Path.Combine(xamppPath, @"apache\conf\httpd.conf");
            string phpIni = Path.Combine(xamppPath, @"php\php.ini");
            string vhostsConf = Path.Combine(xamppPath, @"apache\conf\extra\httpd-vhosts.conf");

            if (File.Exists(httpdConf))
            {
                File.Copy(httpdConf, Path.Combine(backupFolder, "httpd.conf"), true);
            }

            if (File.Exists(phpIni))
            {
                File.Copy(phpIni, Path.Combine(backupFolder, "php.ini"), true);
            }

            if (File.Exists(vhostsConf))
            {
                File.Copy(vhostsConf, Path.Combine(backupFolder, "httpd-vhosts.conf"), true);
            }

            AuditLogService.Instance.Log("CONFIG", "XAMPP Backup Created", "Backed up Apache and PHP configs to " + backupFolder);
            return backupFolder;
        }

        public void RestoreBackup(string xamppPath, string backupFolder)
        {
            if (!Directory.Exists(backupFolder)) throw new DirectoryNotFoundException("Backup folder not found: " + backupFolder);

            string httpdBackup = Path.Combine(backupFolder, "httpd.conf");
            if (File.Exists(httpdBackup))
            {
                string httpdDest = Path.Combine(xamppPath, @"apache\conf\httpd.conf");
                string content = File.ReadAllText(httpdBackup);
                content = content.Replace("Include \"conf/extra/softbuilt-shield.conf\"", "")
                                 .Replace("IncludeOptional \"conf/extra/softbuilt-shield.conf\"", "")
                                 .Replace(INCLUDE_MARKER, "");
                File.WriteAllText(httpdDest, content.TrimEnd() + Environment.NewLine, Encoding.UTF8);
            }

            string phpBackup = Path.Combine(backupFolder, "php.ini");
            if (File.Exists(phpBackup))
            {
                File.Copy(phpBackup, Path.Combine(xamppPath, @"php\php.ini"), true);
            }

            string vhostsBackup = Path.Combine(backupFolder, "httpd-vhosts.conf");
            if (File.Exists(vhostsBackup))
            {
                File.Copy(vhostsBackup, Path.Combine(xamppPath, @"apache\conf\extra\httpd-vhosts.conf"), true);
            }

            // Remove shield config file if present
            string shieldConf = Path.Combine(xamppPath, @"apache\conf\extra\softbuilt-shield.conf");
            if (File.Exists(shieldConf))
            {
                try { File.Delete(shieldConf); } catch { }
            }

            AuditLogService.Instance.Log("CONFIG", "XAMPP Config Rolled Back", "Restored previous Apache/PHP configuration from " + backupFolder);
        }

        public string GenerateShieldApacheConfig(string publicServedPath, bool enableLan, string lanSubnet, string appAlias = "edugate", bool blockPhpMyAdminFromLan = true)
        {
            var sb = new StringBuilder();
            sb.AppendLine("# ======================================================================");
            sb.AppendLine("# SoftBuilt PHP Shield - Dedicated Isolated Application Configuration");
            sb.AppendLine("# Generated automatically - DO NOT EDIT MANUALLY");
            sb.AppendLine("# ======================================================================");
            sb.AppendLine();

            string normalizedPublic = publicServedPath.Replace('\\', '/').TrimEnd('/');

            // Map Alias /edugate to public directory only (both with and without trailing slash)
            sb.AppendLine(string.Format("Alias /{0}/ \"{1}/\"", appAlias, normalizedPublic));
            sb.AppendLine(string.Format("Alias /{0} \"{1}\"", appAlias, normalizedPublic));
            sb.AppendLine();

            sb.AppendLine(string.Format("<Directory \"{0}\">", normalizedPublic));
            sb.AppendLine("    Options -Indexes +FollowSymLinks");
            sb.AppendLine("    AllowOverride All");
            sb.AppendLine("    DirectoryIndex index.php index.html");
            sb.AppendLine();

            if (enableLan && !string.IsNullOrEmpty(lanSubnet))
            {
                sb.AppendLine("    # LAN Access Enabled");
                sb.AppendLine("    <RequireAny>");
                sb.AppendLine("        Require all granted");
                sb.AppendLine(string.Format("        Require ip {0}", lanSubnet));
                sb.AppendLine("    </RequireAny>");
            }
            else
            {
                sb.AppendLine("    # Localhost + LAN Access Granted");
                sb.AppendLine("    Require all granted");
            }
            sb.AppendLine("</Directory>");
            sb.AppendLine();

            // Hardening rules: Block access to dotfiles, .env, backups, storage
            sb.AppendLine("# Hardening: Block sensitive files and private folders");
            sb.AppendLine("<LocationMatch \"/\\.(?!well-known)\">");
            sb.AppendLine("    Require all denied");
            sb.AppendLine("</LocationMatch>");
            sb.AppendLine();

            sb.AppendLine("<FilesMatch \"(?i)\\.(env|sql|bak|backup|log|md|git.*|yml|yaml|ini|lock)$\">");
            sb.AppendLine("    Require all denied");
            sb.AppendLine("</FilesMatch>");
            sb.AppendLine();

            sb.AppendLine("# Block direct access to app/ private folder or composer internals");
            sb.AppendLine("<LocationMatch \"^/(?i)(app|vendor|storage|database)(\\b|/|$)\">");
            sb.AppendLine("    Require all denied");
            sb.AppendLine("</LocationMatch>");

            try
            {
                string parentApp = Path.GetFullPath(Path.Combine(normalizedPublic, "..", "app")).Replace('\\', '/');
                if (Directory.Exists(parentApp))
                {
                    sb.AppendLine(string.Format("<Directory \"{0}\">", parentApp));
                    sb.AppendLine("    Require all denied");
                    sb.AppendLine("</Directory>");
                }
            }
            catch { }

            // Block phpMyAdmin from remote LAN access to protect database from unauthorized users on the same network
            if (blockPhpMyAdminFromLan)
            {
                sb.AppendLine();
                sb.AppendLine("# Hardening: Restrict phpMyAdmin to localhost only");
                sb.AppendLine("# Blocks any unauthorized person on the LAN/Wi-Fi from opening http://<server-ip>/phpmyadmin/");
                sb.AppendLine("<Location \"/phpmyadmin\">");
                sb.AppendLine("    Require local");
                sb.AppendLine("</Location>");
            }

            return sb.ToString();
        }

        public bool ValidateApacheConfig(string xamppPath, out string validationOutput)
        {
            validationOutput = string.Empty;
            string httpdExe = Path.Combine(xamppPath, @"apache\bin\httpd.exe");

            if (!File.Exists(httpdExe))
            {
                validationOutput = "httpd.exe not found at " + httpdExe;
                return false;
            }

            try
            {
                var psi = new ProcessStartInfo
                {
                    FileName = httpdExe,
                    Arguments = "-t",
                    WorkingDirectory = Path.GetDirectoryName(httpdExe),
                    UseShellExecute = false,
                    RedirectStandardOutput = true,
                    RedirectStandardError = true,
                    CreateNoWindow = true
                };

                using (var proc = Process.Start(psi))
                {
                    var stdoutTask = proc.StandardOutput.ReadToEndAsync();
                    var stderrTask = proc.StandardError.ReadToEndAsync();

                    if (proc.WaitForExit(7000))
                    {
                        string stdout = stdoutTask.Result;
                        string stderr = stderrTask.Result;
                        validationOutput = (stdout + " " + stderr).Trim();

                        return validationOutput.Contains("Syntax OK");
                    }
                    else
                    {
                        try { proc.Kill(); } catch { }
                        validationOutput = "Apache syntax check timed out.";
                        return false;
                    }
                }
            }
            catch (Exception ex)
            {
                validationOutput = "Exception during httpd -t validation: " + ex.Message;
                return false;
            }
        }

        public void ApplyShieldConfiguration(string xamppPath, string publicServedPath, bool enableLan, string lanSubnet, string appAlias = "edugate")
        {
            string backupFolder;
            CreateBackup(xamppPath, out backupFolder);

            try
            {
                // 1. Write softbuilt-shield.conf
                string extraDir = Path.Combine(xamppPath, @"apache\conf\extra");
                if (!Directory.Exists(extraDir)) Directory.CreateDirectory(extraDir);

                string shieldConfPath = Path.Combine(extraDir, "softbuilt-shield.conf");
                bool blockPma = SettingsService.Instance.Settings.BlockPhpMyAdminFromLan;
                string confContent = GenerateShieldApacheConfig(publicServedPath, enableLan, lanSubnet, appAlias, blockPma);
                File.WriteAllText(shieldConfPath, confContent, Encoding.UTF8);

                // 2. Add minimal include in httpd.conf if not already present
                string httpdConfPath = Path.Combine(xamppPath, @"apache\conf\httpd.conf");
                string httpdContent = File.ReadAllText(httpdConfPath);

                if (!httpdContent.Contains("conf/extra/softbuilt-shield.conf"))
                {
                    var sb = new StringBuilder(httpdContent);
                    sb.AppendLine();
                    sb.AppendLine(INCLUDE_MARKER);
                    sb.AppendLine("IncludeOptional \"conf/extra/softbuilt-shield.conf\"");
                    File.WriteAllText(httpdConfPath, sb.ToString(), Encoding.UTF8);
                }

                // 3. Validate with httpd.exe -t
                string validationResult;
                bool isValid = ValidateApacheConfig(xamppPath, out validationResult);

                if (!isValid)
                {
                    // Roll back immediately
                    RestoreBackup(xamppPath, backupFolder);
                    AuditLogService.Instance.Log("CONFIG", "Config Validation Failed - Auto Rolled Back", "Syntax error in Apache config: " + validationResult, false);
                    throw new InvalidOperationException("Apache configuration syntax test failed: " + validationResult + ". Reverted all changes.");
                }

                AuditLogService.Instance.Log("CONFIG", "Apache Configuration Applied", "Shield configuration applied cleanly and verified with 'httpd -t' (Syntax OK).");
            }
            catch
            {
                RestoreBackup(xamppPath, backupFolder);
                throw;
            }
        }

        public bool ApplyPhpMyAdminLanProtection(string xamppPath, bool blockFromLan, out string error)
        {
            error = string.Empty;
            try
            {
                if (string.IsNullOrEmpty(xamppPath) || !Directory.Exists(xamppPath))
                {
                    error = "XAMPP path is not valid.";
                    return false;
                }

                string extraDir = Path.Combine(xamppPath, @"apache\conf\extra");
                if (!Directory.Exists(extraDir)) Directory.CreateDirectory(extraDir);

                string shieldConfPath = Path.Combine(extraDir, "softbuilt-shield.conf");
                string confContent = File.Exists(shieldConfPath) ? File.ReadAllText(shieldConfPath) : "";

                string marker = "# SoftBuilt Shield: phpMyAdmin LAN Protection";
                string blockDirective = string.Format(
                    "{0}\r\n<Location \"/phpmyadmin\">\r\n    Require {1}\r\n</Location>\r\n",
                    marker,
                    blockFromLan ? "local" : "all granted");

                if (confContent.Contains(marker))
                {
                    confContent = System.Text.RegularExpressions.Regex.Replace(
                        confContent,
                        @"# SoftBuilt Shield: phpMyAdmin LAN Protection[\s\S]*?</Location>\r?\n?",
                        blockDirective);
                }
                else
                {
                    confContent = confContent.TrimEnd() + "\r\n\r\n" + blockDirective;
                }

                File.WriteAllText(shieldConfPath, confContent, Encoding.UTF8);

                // Ensure IncludeOptional in httpd.conf
                string httpdConfPath = Path.Combine(xamppPath, @"apache\conf\httpd.conf");
                if (File.Exists(httpdConfPath))
                {
                    string httpdContent = File.ReadAllText(httpdConfPath);
                    if (!httpdContent.Contains("conf/extra/softbuilt-shield.conf"))
                    {
                        var sb = new StringBuilder(httpdContent);
                        sb.AppendLine();
                        sb.AppendLine(INCLUDE_MARKER);
                        sb.AppendLine("IncludeOptional \"conf/extra/softbuilt-shield.conf\"");
                        File.WriteAllText(httpdConfPath, sb.ToString(), Encoding.UTF8);
                    }
                }

                string validationResult;
                bool ok = ValidateApacheConfig(xamppPath, out validationResult);
                if (!ok)
                {
                    error = "Apache config syntax error: " + validationResult;
                    return false;
                }

                var xampp = XamppDetectorService.Instance.LastStatus;
                if (xampp != null && xampp.ApacheRunning)
                {
                    DeploymentService.Instance.ReloadApache(xampp);
                }

                AuditLogService.Instance.Log("CONFIG", "phpMyAdmin Network Shield",
                    blockFromLan ? "phpMyAdmin locked to localhost. Unauthorized network access blocked." : "phpMyAdmin LAN restriction removed.", true);
                return true;
            }
            catch (Exception ex)
            {
                error = ex.Message;
                return false;
            }
        }
    }
}

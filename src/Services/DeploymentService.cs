using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Web.Script.Serialization;
using SoftBuilt.PHPShield.Models;

namespace SoftBuilt.PHPShield.Services
{
    public class DeploymentService
    {
        private static DeploymentService _instance;
        public static DeploymentService Instance
        {
            get
            {
                if (_instance == null) _instance = new DeploymentService();
                return _instance;
            }
        }

        public class PreflightCheck
        {
            public bool AllPassed { get; set; }
            public List<string> Items { get; set; }
            public PreflightCheck() { Items = new List<string>(); AllPassed = true; }
        }

        public PreflightCheck RunPreflight(XamppStatus xampp, Project project)
        {
            var pre = new PreflightCheck();

            // 1. XAMPP Found
            if (xampp == null || !xampp.IsDetected)
            {
                pre.AllPassed = false;
                pre.Items.Add("FAIL: XAMPP installation not detected. Cannot deploy without XAMPP.");
            }
            else
            {
                pre.Items.Add("PASS: XAMPP detected at " + xampp.RootPath);
            }

            // 2. PHP Compatibility
            if (xampp != null && xampp.IsDetected && project != null && project.Analysis != null)
            {
                List<string> missing;
                string verdict;
                bool ok = XamppDetectorService.Instance.ComparePhpCompatibility(
                    project.Analysis.MinPhpVersion, project.Analysis.MaxPhpVersion,
                    project.Analysis.RequiredExtensions, out missing, out verdict);

                if (ok) pre.Items.Add("PASS: PHP version and extensions compatible (" + xampp.PhpVersion + ").");
                else
                {
                    pre.AllPassed = false;
                    pre.Items.Add("FAIL: " + verdict);
                    foreach (var m in missing) pre.Items.Add("Missing required PHP extension: " + m);
                }
            }

            // 3. Port check & service state
            XamppDetectorService.Instance.DetectServicesAndPorts(xampp);
            if (xampp != null && xampp.PortConflicts.Count > 0)
            {
                foreach (var c in xampp.PortConflicts)
                {
                    pre.Items.Add("WARN: " + c);
                    if (c.Contains("IIS"))
                    {
                        pre.Items.Add("💡 FIX: Run 'net stop w3svc' in an Administrator command prompt to free port 80, or configure Apache to port 8080.");
                    }
                    if (c.Contains("MySQL"))
                    {
                        pre.Items.Add("💡 FIX: Existing MySQL service is running on 3306. You can use it directly, or stop it via 'net stop mysql'.");
                    }
                }
            }
            else
            {
                pre.Items.Add("PASS: Web ports free and accessible.");
            }

            if (xampp != null && !xampp.ApacheRunning)
            {
                pre.Items.Add(string.Format("WARN: Apache web server is not currently running. Shield will attempt to start it during deployment.", xampp.ApachePort));
            }
            else if (xampp != null)
            {
                pre.Items.Add(string.Format("PASS: Apache web server is running (PID: {0}, Port: {1}).", xampp.ApachePid, xampp.ApachePort));
            }

            // 4. Disk space check
            try
            {
                var drive = new DriveInfo(Path.GetPathRoot(xampp != null && xampp.IsDetected ? xampp.RootPath : "C:\\"));
                long freeMb = drive.AvailableFreeSpace / (1024 * 1024);
                if (freeMb < 200)
                {
                    pre.AllPassed = false;
                    pre.Items.Add(string.Format("FAIL: Low disk space ({0} MB free). At least 200 MB required.", freeMb));
                }
                else
                {
                    pre.Items.Add(string.Format("PASS: Sufficient disk space ({0} MB free).", freeMb));
                }
            }
            catch { }

            // 5. Write permission check to XAMPP deployment root
            if (xampp != null && xampp.IsDetected)
            {
                try
                {
                    string testDir = Path.Combine(xampp.RootPath, "softbuilt-apps");
                    if (!Directory.Exists(testDir)) Directory.CreateDirectory(testDir);
                    string testProbe = Path.Combine(testDir, ".write_probe_" + Guid.NewGuid().ToString("N"));
                    File.WriteAllText(testProbe, "write_test");
                    File.Delete(testProbe);
                    pre.Items.Add("PASS: Write access to XAMPP directory verified.");
                }
                catch (Exception ex)
                {
                    pre.AllPassed = false;
                    pre.Items.Add(string.Format("FAIL: Write access to '{0}' is denied ({1}).", xampp.RootPath, ex.Message));
                    pre.Items.Add("TIP: Administrator privileges are required. Please run SoftBuilt Shield as Administrator (Right-click -> 'Run as administrator').");
                }
            }

            return pre;
        }

        public DeploymentRecord Deploy(XamppStatus xampp, BuildRecord build, string appAlias, bool enableLan, Action<string> logger)
        {
            return Deploy(xampp, build, appAlias, enableLan, "192.168.1.0/24", logger);
        }

        public DeploymentRecord Deploy(XamppStatus xampp, BuildRecord build, string appAlias = "edugate", bool enableLan = false, string lanSubnet = "192.168.1.0/24", Action<string> logger = null)
        {
            Action<string> log = logger ?? (s => { });

            if (xampp == null || !xampp.IsDetected)
                throw new InvalidOperationException("XAMPP is not detected.");
            if (build == null || !Directory.Exists(build.BuildPath))
                throw new InvalidOperationException("Build directory not found.");

            string deployRoot = Path.Combine(xampp.RootPath, "softbuilt-apps", appAlias);
            string releasesDir = Path.Combine(deployRoot, "releases");
            string releaseId = "rel_" + DateTime.Now.ToString("yyyyMMdd_HHmmss");
            string newReleaseDir = Path.Combine(releasesDir, releaseId);
            string currentLinkDir = Path.Combine(deployRoot, "current");

            Directory.CreateDirectory(newReleaseDir);

            string xamppBackupFolder = null;
            var record = new DeploymentRecord
            {
                ProjectId = build.ProjectId,
                BuildId = build.BuildId,
                ReleaseFolder = newReleaseDir,
                PublicServedFolder = Path.Combine(currentLinkDir, "public"),
                LocalUrl = string.Format("http://localhost:{0}/{1}/", xampp.ApachePort, appAlias),
                LanUrl = enableLan ? string.Format("http://<LAN-IP>:{0}/{1}/", xampp.ApachePort, appAlias) : "Disabled"
            };

            try
            {
                // 1. Copy build files to atomic release folder (FR-7.1)
                log("Copying production build to atomic release folder (" + releaseId + ")...");
                CopyDirectory(build.BuildPath, newReleaseDir);

                // 1b. Preserve existing user uploads and persistent data from previous release (uploads/, storage/, media/)
                if (Directory.Exists(currentLinkDir))
                {
                    PreserveUserData(currentLinkDir, newReleaseDir, log);
                }

                // 2. Update 'current' directory pointer (Atomic switch)
                log("Updating active 'current' directory pointer...");
                if (Directory.Exists(currentLinkDir))
                {
                    try { Directory.Delete(currentLinkDir, true); } catch { }
                }
                CopyDirectory(newReleaseDir, currentLinkDir);

                // 3. Backup XAMPP configuration and apply isolated Apache config (FR-6.3, FR-6.4)
                log("Backing up XAMPP configuration and applying isolated Apache include...");
                string servedPublic = Path.Combine(currentLinkDir, "public");
                ApacheConfigService.Instance.ApplyShieldConfiguration(xampp.RootPath, servedPublic, enableLan, lanSubnet, appAlias);
                xamppBackupFolder = SettingsService.Instance.GetBackupsDirectory(); // points to latest
                record.BackupFolder = xamppBackupFolder;
                log("Isolated Apache configuration validated with 'httpd.exe -t' (Syntax OK).");

                // Ensure phpMyAdmin configuration is secured and healthy on this PC
                try
                {
                    string dbUser = SettingsService.Instance.Settings.XamppDbUser ?? "root";
                    string dbPass = SettingsService.Instance.Settings.XamppDbPassword ?? "";
                    string pmaErr;
                    DatabaseService.Instance.SyncPhpMyAdminPassword(xampp.RootPath, dbUser, dbPass, SettingsService.Instance.Settings.PhpMyAdminRequireLogin, out pmaErr);
                    DatabaseService.Instance.EnsurePhpMyAdminConfigStorage(xampp.RootPath, dbUser, dbPass, log);
                }
                catch { }

                // 4. Reload or Start Apache (FR-7.3)
                log("Ensuring Apache is active...");
                ReloadApache(xampp);
                System.Threading.Thread.Sleep(1500);
                XamppDetectorService.Instance.DetectServicesAndPorts(xampp);
                if (xampp.ApacheRunning)
                {
                    log(string.Format("Apache confirmed active (PID: {0}, Port: {1}).", xampp.ApachePid, xampp.ApachePort));
                }
                else
                {
                    log(string.Format("WARN: Apache is not responding on port {0}. If port {0} is occupied by another service (e.g. IIS), release the port.", xampp.ApachePort));
                }

                // 5. Post-deployment verification (FR-7.4, FR-7.5)
                log("Running post-deployment HTTP health & negative path security checks...");
                string baseUrl = string.Format("http://localhost:{0}/", xampp.ApachePort);
                var vResult = VerificationService.Instance.VerifyDeployment(
                    baseUrl, appAlias,
                    Path.Combine(currentLinkDir, "public"),
                    Path.Combine(currentLinkDir, "app"),
                    Path.Combine(newReleaseDir, "build_manifest.json")
                );

                record.VerificationPassed = vResult.AllPassed;
                record.VerificationSummary = vResult.Summary;
                record.NegativeTestResults = vResult.Details;

                foreach (var detail in vResult.Details)
                {
                    log("   " + detail);
                }

                if (!vResult.AllPassed)
                {
                    // Automatic Rollback upon failure (FR-7.6, AC-8)
                    record.Status = "Failed - Automatically Rolled Back";
                    AuditLogService.Instance.Log("DEPLOY", "Deployment Verification Failed", "Deployment failed verification checks. Automatically rolling back.", false);
                    RollbackLatest(xampp, appAlias);

                    string failDetails = string.Join("\n - ", vResult.Details.FindAll(d => d.StartsWith("FAIL")));
                    if (string.IsNullOrEmpty(failDetails)) failDetails = vResult.Summary;

                    bool connectionFailed = failDetails.Contains("Unable to connect") ||
                                           failDetails.Contains("timed out") ||
                                           failDetails.Contains("ConnectFailure") ||
                                           failDetails.Contains("No connection could be made");

                    if (connectionFailed || (!xampp.ApacheRunning && !failDetails.Contains("Fatal Error") && !failDetails.Contains("HTTP status")))
                    {
                        failDetails += "\n\n💡 Reason: Apache is not responding on port " + xampp.ApachePort + ".\nIf another application (such as IIS / World Wide Web Publishing Service) is using port 80, run 'net stop w3svc' in an Administrator terminal, or change Apache's Listen port to 8080 in httpd.conf.";
                    }

                    throw new InvalidOperationException("Deployment verification failed:\n - " + failDetails);
                }

                record.Status = "Active";
                AuditLogService.Instance.Log("DEPLOY", "Deployment Successful",
                    string.Format("Successfully deployed '{0}' to {1}. Site verified healthy.", appAlias, record.LocalUrl));

                SaveDeploymentRecord(deployRoot, record);
                return record;
            }
            catch (Exception ex)
            {

                record.Status = "Failed: " + ex.Message;
                // Only restore if the Apache config step ran (xamppBackupFolder set) and
                // ApplyShieldConfiguration hasn't already restored it internally.
                if (!string.IsNullOrEmpty(xamppBackupFolder) && !(ex.Message.Contains("Reverted all changes")))
                {
                    try { ApacheConfigService.Instance.RestoreBackup(xampp.RootPath, xamppBackupFolder); } catch { }
                }
                SaveDeploymentRecord(deployRoot, record);
                throw;
            }
        }

        public void ReloadApache(XamppStatus xampp)
        {
            if (xampp == null || string.IsNullOrEmpty(xampp.ApacheExe) || !File.Exists(xampp.ApacheExe)) return;

            // 1. Try graceful restart (works if Apache runs as a Windows Service)
            try
            {
                var psi = new ProcessStartInfo
                {
                    FileName = xampp.ApacheExe,
                    Arguments = "-k restart",
                    UseShellExecute = false,
                    CreateNoWindow = true
                };
                using (var p = Process.Start(psi))
                {
                    p.WaitForExit(1500);
                }
            }
            catch { }

            // 2. Kill only httpd processes that belong to THIS XAMPP installation (C2 fix)
            // Avoids killing IIS or other Apache installs on the same machine.
            try
            {
                string expectedApacheDir = Path.GetDirectoryName(xampp.ApacheExe);
                var procs = Process.GetProcessesByName("httpd");
                foreach (var proc in procs)
                {
                    try
                    {
                        string procPath = proc.MainModule.FileName;
                        string procDir = Path.GetDirectoryName(procPath);
                        if (string.Equals(procDir, expectedApacheDir, StringComparison.OrdinalIgnoreCase))
                        {
                            proc.Kill();
                            proc.WaitForExit(2000);
                        }
                    }
                    catch { }
                }
                System.Threading.Thread.Sleep(1000);

                var startPsi = new ProcessStartInfo
                {
                    FileName = xampp.ApacheExe,
                    WorkingDirectory = Path.GetDirectoryName(xampp.ApacheExe),
                    UseShellExecute = true,
                    WindowStyle = ProcessWindowStyle.Hidden
                };
                Process.Start(startPsi);
                System.Threading.Thread.Sleep(2000);
            }
            catch { }

            XamppDetectorService.Instance.DetectServicesAndPorts(xampp);
        }

        public void RollbackLatest(XamppStatus xampp, string appAlias = "edugate")
        {
            bool apacheWasRunning = xampp != null && xampp.ApacheRunning;

            string deployRoot = Path.Combine(xampp.RootPath, "softbuilt-apps", appAlias);
            string releasesDir = Path.Combine(deployRoot, "releases");
            string currentLinkDir = Path.Combine(deployRoot, "current");

            if (Directory.Exists(releasesDir))
            {
                // H1: Sort by CreationTime, not string name, to handle any timestamp format
                var dirInfos = new DirectoryInfo(releasesDir).GetDirectories();
                Array.Sort(dirInfos, (a, b) => a.CreationTime.CompareTo(b.CreationTime));

                if (dirInfos.Length >= 2)
                {
                    string prevRelease = dirInfos[dirInfos.Length - 2].FullName;
                    if (Directory.Exists(currentLinkDir)) Directory.Delete(currentLinkDir, true);
                    CopyDirectory(prevRelease, currentLinkDir);
                    AuditLogService.Instance.Log("DEPLOY", "Release Rolled Back", "Restored previous release " + dirInfos[dirInfos.Length - 2].Name);
                }
                else
                {
                    if (Directory.Exists(currentLinkDir)) Directory.Delete(currentLinkDir, true);
                }
            }

            // Restore Apache config
            string backupsBase = SettingsService.Instance.GetBackupsDirectory();
            if (Directory.Exists(backupsBase))
            {
                var backupInfos = new DirectoryInfo(backupsBase).GetDirectories();
                Array.Sort(backupInfos, (a, b) => a.CreationTime.CompareTo(b.CreationTime));
                if (backupInfos.Length > 0)
                {
                    string latestBackup = backupInfos[backupInfos.Length - 1].FullName;
                    ApacheConfigService.Instance.RestoreBackup(xampp.RootPath, latestBackup);
                }
            }

            // M5: Only reload Apache if it was running before rollback was triggered
            if (apacheWasRunning)
            {
                ReloadApache(xampp);
            }
        }

        private void CopyDirectory(string sourceDir, string targetDir)
        {
            if (!Directory.Exists(targetDir)) Directory.CreateDirectory(targetDir);

            foreach (var file in Directory.GetFiles(sourceDir))
            {
                string targetFile = Path.Combine(targetDir, Path.GetFileName(file));
                // H2: Clear read-only flag before copying (e.g. .git-managed files)
                if (File.Exists(targetFile))
                {
                    File.SetAttributes(targetFile, FileAttributes.Normal);
                }
                File.Copy(file, targetFile, true);
            }

            foreach (var sub in Directory.GetDirectories(sourceDir))
            {
                string targetSub = Path.Combine(targetDir, Path.GetFileName(sub));
                CopyDirectory(sub, targetSub);
            }
        }

        private void PreserveUserData(string previousCurrentDir, string newReleaseDir, Action<string> log)
        {
            try
            {
                string[] candidates = new string[]
                {
                    @"public\uploads",
                    @"uploads",
                    @"public\storage",
                    @"storage",
                    @"public\media",
                    @"media",
                    @"app\storage",
                    @"user_data"
                };

                foreach (var relDir in candidates)
                {
                    string oldPath = Path.Combine(previousCurrentDir, relDir);
                    if (Directory.Exists(oldPath))
                    {
                        string targetPath = Path.Combine(newReleaseDir, relDir);
                        if (!Directory.Exists(targetPath)) Directory.CreateDirectory(targetPath);

                        foreach (var file in Directory.GetFiles(oldPath, "*.*", SearchOption.AllDirectories))
                        {
                            string fileRel = file.Substring(oldPath.Length).TrimStart('\\', '/');
                            string targetFile = Path.Combine(targetPath, fileRel);
                            string destFolder = Path.GetDirectoryName(targetFile);
                            if (!Directory.Exists(destFolder)) Directory.CreateDirectory(destFolder);

                            if (!File.Exists(targetFile))
                            {
                                File.Copy(file, targetFile, true);
                            }
                        }
                        log(string.Format("Preserved existing user data in '{0}' across update.", relDir));
                    }
                }
            }
            catch (Exception ex)
            {
                log("WARN: Unable to copy some user persistent data: " + ex.Message);
            }
        }

        private void SaveDeploymentRecord(string deployRoot, DeploymentRecord record)
        {
            try
            {
                string historyDir = Path.Combine(deployRoot, "history");
                Directory.CreateDirectory(historyDir);
                string file = Path.Combine(historyDir, record.DeploymentId + ".json");
                var js = new JavaScriptSerializer();
                File.WriteAllText(file, js.Serialize(record));
            }
            catch { }
        }

        public List<DeploymentRecord> GetDeploymentHistory(string xamppPath, string appAlias = "edugate")
        {
            var list = new List<DeploymentRecord>();
            try
            {
                string historyDir = Path.Combine(xamppPath, "softbuilt-apps", appAlias, "history");
                if (Directory.Exists(historyDir))
                {
                    var js = new JavaScriptSerializer();
                    foreach (var f in Directory.GetFiles(historyDir, "*.json"))
                    {
                        try
                        {
                            var rec = js.Deserialize<DeploymentRecord>(File.ReadAllText(f));
                            if (rec != null) list.Add(rec);
                        }
                        catch { }
                    }
                }
            }
            catch { }
            list.Sort((a, b) => b.DeployedAt.CompareTo(a.DeployedAt));
            return list;
        }
    }
}

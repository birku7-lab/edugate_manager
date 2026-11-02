using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Net.NetworkInformation;
using System.Text.RegularExpressions;
using Microsoft.Win32;
using SoftBuilt.PHPShield.Models;

namespace SoftBuilt.PHPShield.Services
{
    public class XamppDetectorService
    {
        private static XamppDetectorService _instance;
        public static XamppDetectorService Instance
        {
            get
            {
                if (_instance == null) _instance = new XamppDetectorService();
                return _instance;
            }
        }

        public XamppStatus LastStatus { get; private set; }

        public XamppDetectorService()
        {
            LastStatus = new XamppStatus { IsDetected = false };
        }

        public XamppStatus Detect(string preferredPath = null)
        {
            var status = new XamppStatus();
            string root = null;
            string method = "None";

            // 1. Try preferred or configured path
            if (!string.IsNullOrEmpty(preferredPath) && Directory.Exists(preferredPath))
            {
                if (IsValidXamppDirectory(preferredPath))
                {
                    root = preferredPath;
                    method = "User Configured / Manual Path";
                }
            }

            // 2. Try registry check
            if (string.IsNullOrEmpty(root))
            {
                root = CheckRegistryForXampp();
                if (!string.IsNullOrEmpty(root)) method = "Windows Registry";
            }

            // 3. Try running processes
            if (string.IsNullOrEmpty(root))
            {
                root = CheckProcessesForXampp();
                if (!string.IsNullOrEmpty(root)) method = "Active Process Inspection";
            }

            // 4. Try common drive locations (comprehensive list for school PCs)
            if (string.IsNullOrEmpty(root))
            {
                string userProfile = Environment.GetFolderPath(Environment.SpecialFolder.UserProfile);
                string userName = Environment.UserName;

                string[] candidates = new string[]
                {
                    // Standard root installs
                    @"C:\xampp",
                    @"D:\xampp",
                    @"E:\xampp",
                    @"F:\xampp",
                    // Program Files variants
                    @"C:\Program Files\xampp",
                    @"C:\Program Files (x86)\xampp",
                    @"D:\Program Files\xampp",
                    @"D:\Program Files (x86)\xampp",
                    // User profile installs (portable / non-admin installs)
                    Path.Combine(userProfile, "xampp"),
                    Path.Combine(userProfile, "Downloads", "xampp"),
                    Path.Combine(@"C:\Users", userName, "xampp"),
                    // WAMP / LAMP misidentification safety
                    @"C:\wamp64",
                    @"C:\wamp",
                    // Alternate drive letters frequently used in labs
                    @"G:\xampp",
                    @"H:\xampp",
                };

                foreach (var cand in candidates)
                {
                    if (!string.IsNullOrEmpty(cand) && Directory.Exists(cand) && IsValidXamppDirectory(cand))
                    {
                        root = cand;
                        method = "Standard Filesystem Discovery (" + cand + ")";
                        break;
                    }
                }
            }

            if (!string.IsNullOrEmpty(root) && Directory.Exists(root))
            {
                status.IsDetected = true;
                status.RootPath = Path.GetFullPath(root);
                status.DetectionMethod = method;

                status.ApacheExe = Path.Combine(status.RootPath, @"apache\bin\httpd.exe");
                status.PhpExe = Path.Combine(status.RootPath, @"php\php.exe");
                status.MysqlExe = Path.Combine(status.RootPath, @"mysql\bin\mysqld.exe");
                status.ControlExe = Path.Combine(status.RootPath, "xampp-control.exe");
                status.PhpMyAdminPath = Path.Combine(status.RootPath, "phpMyAdmin");

                // Query PHP version & modules
                DetectPhpDetails(status);

                // Detect configured ports from config files if present
                try
                {
                    string confPath = Path.Combine(status.RootPath, @"apache\conf\httpd.conf");
                    if (File.Exists(confPath))
                    {
                        string conf = File.ReadAllText(confPath);
                        var match = Regex.Match(conf, @"(?m)^\s*Listen\s+(?:.*:)?([0-9]+)");
                        if (match.Success)
                        {
                            int p;
                            if (int.TryParse(match.Groups[1].Value, out p) && p > 0) status.ApachePort = p;
                        }
                    }
                }
                catch { }

                try
                {
                    string myIni = Path.Combine(status.RootPath, @"mysql\bin\my.ini");
                    if (File.Exists(myIni))
                    {
                        string ini = File.ReadAllText(myIni);
                        var match = Regex.Match(ini, @"(?m)^\s*port\s*=\s*([0-9]+)");
                        if (match.Success)
                        {
                            int p;
                            if (int.TryParse(match.Groups[1].Value, out p) && p > 0) status.MysqlPort = p;
                        }
                    }
                }
                catch { }

                // Query runtime services & ports
                DetectServicesAndPorts(status);
            }
            else
            {
                status.IsDetected = false;
                status.DetectionMethod = "XAMPP not found. Please install XAMPP independently from https://www.apachefriends.org";
            }

            LastStatus = status;
            return status;
        }

        private bool IsValidXamppDirectory(string path)
        {
            if (string.IsNullOrEmpty(path) || !Directory.Exists(path)) return false;

            bool hasApache = File.Exists(Path.Combine(path, @"apache\bin\httpd.exe"));
            bool hasPhp = File.Exists(Path.Combine(path, @"php\php.exe"));
            return hasApache || hasPhp;
        }

        /// <summary>
        /// Called on startup: scrubs any orphaned softbuilt-shield Include lines from httpd.conf
        /// that would crash Apache if the shield conf file doesn't exist.
        /// Safe to run even if Apache is already running.
        /// </summary>
        public void SafeCleanOrphanedIncludes(string xamppRoot)
        {
            if (string.IsNullOrEmpty(xamppRoot)) return;
            try
            {
                string httpdConf = Path.Combine(xamppRoot, @"apache\conf\httpd.conf");
                if (!File.Exists(httpdConf)) return;

                string original = File.ReadAllText(httpdConf);
                // Remove any Include/IncludeOptional lines pointing to softbuilt-shield.conf
                string cleaned = System.Text.RegularExpressions.Regex.Replace(
                    original,
                    @"(?m)^\s*Include(?:Optional)?\s+[""']?[^""'\r\n]*softbuilt-shield\.conf[""']?\s*(?:\r?\n|$)",
                    "",
                    System.Text.RegularExpressions.RegexOptions.IgnoreCase);

                if (cleaned != original)
                {
                    File.WriteAllText(httpdConf, cleaned, System.Text.Encoding.UTF8);
                    AuditLogService.Instance.Log("STARTUP", "httpd.conf Cleaned",
                        "Removed orphaned softbuilt-shield.conf Include directive from httpd.conf to prevent Apache startup crash.");
                }
            }
            catch { }
        }

        /// <summary>
        /// Returns true if the current process is running with Administrator privileges.
        /// </summary>
        public static bool IsRunningAsAdmin()
        {
            try
            {
                var identity = System.Security.Principal.WindowsIdentity.GetCurrent();
                var principal = new System.Security.Principal.WindowsPrincipal(identity);
                return principal.IsInRole(System.Security.Principal.WindowsBuiltInRole.Administrator);
            }
            catch { return false; }
        }

        private string CheckRegistryForXampp()
        {
            string[] keys = new string[]
            {
                @"SOFTWARE\xampp",
                @"SOFTWARE\WOW6432Node\xampp"
            };

            foreach (var keyPath in keys)
            {
                try
                {
                    using (var key = Registry.LocalMachine.OpenSubKey(keyPath))
                    {
                        if (key != null)
                        {
                            object val = key.GetValue("Install_Dir") ?? key.GetValue("Location");
                            if (val != null && Directory.Exists(val.ToString()))
                                return val.ToString();
                        }
                    }

                    using (var key = Registry.CurrentUser.OpenSubKey(keyPath))
                    {
                        if (key != null)
                        {
                            object val = key.GetValue("Install_Dir") ?? key.GetValue("Location");
                            if (val != null && Directory.Exists(val.ToString()))
                                return val.ToString();
                        }
                    }
                }
                catch { }
            }
            return null;
        }

        private string CheckProcessesForXampp()
        {
            try
            {
                Process[] procs = Process.GetProcessesByName("httpd");
                if (procs.Length == 0) procs = Process.GetProcessesByName("mysqld");

                foreach (var p in procs)
                {
                    try
                    {
                        // M2: Handle cross-architecture (32-bit vs 64-bit) process module queries gracefully
                        string exePath = null;
                        try
                        {
                            exePath = p.MainModule != null ? p.MainModule.FileName : null;
                        }
                        catch
                        {
                            // If MainModule throws due to 32/64-bit permission boundaries, ignore and continue
                        }

                        if (!string.IsNullOrEmpty(exePath))
                        {
                            string dir = Path.GetDirectoryName(exePath); // ...\apache\bin
                            string parent = Path.GetDirectoryName(dir);   // ...\apache
                            string root = Path.GetDirectoryName(parent);  // ...\xampp
                            if (IsValidXamppDirectory(root)) return root;
                        }
                    }
                    catch { }
                }
            }
            catch { }
            return null;
        }

        private void DetectPhpDetails(XamppStatus status)
        {
            if (!File.Exists(status.PhpExe))
            {
                status.PhpVersion = "Not found";
                return;
            }

            try
            {
                var psi = new ProcessStartInfo
                {
                    FileName = status.PhpExe,
                    Arguments = "-v",
                    WorkingDirectory = Path.GetDirectoryName(status.PhpExe),
                    UseShellExecute = false,
                    RedirectStandardOutput = true,
                    RedirectStandardError = true,
                    CreateNoWindow = true
                };

                // H3: Read stdout asynchronously before WaitForExit to eliminate buffer deadlocks
                using (var proc = Process.Start(psi))
                {
                    var stdoutTask = proc.StandardOutput.ReadToEndAsync();
                    if (proc.WaitForExit(4000))
                    {
                        string output = stdoutTask.Result;
                        var match = Regex.Match(output, @"PHP\s+([0-9]+\.[0-9]+\.[0-9]+)");
                        if (match.Success) status.PhpVersion = match.Groups[1].Value;
                        else status.PhpVersion = output.Split('\n')[0].Trim();
                    }
                    else
                    {
                        try { proc.Kill(); } catch { }
                    }
                }

                // Query modules
                psi.Arguments = "-m";
                using (var proc = Process.Start(psi))
                {
                    var stdoutTask = proc.StandardOutput.ReadToEndAsync();
                    if (proc.WaitForExit(4000))
                    {
                        string output = stdoutTask.Result;
                        string[] lines = output.Split(new char[] { '\r', '\n' }, StringSplitOptions.RemoveEmptyEntries);
                        foreach (var line in lines)
                        {
                            string trimmed = line.Trim();
                            if (!string.IsNullOrEmpty(trimmed) && !trimmed.StartsWith("[") && !status.LoadedExtensions.Contains(trimmed))
                            {
                                status.LoadedExtensions.Add(trimmed);
                            }
                        }
                    }
                    else
                    {
                        try { proc.Kill(); } catch { }
                    }
                }
            }
            catch (Exception ex)
            {
                status.PhpVersion = "Query error: " + ex.Message;
            }
        }

        public void DetectServicesAndPorts(XamppStatus status)
        {
            // Apache process check: Apache is only running if httpd.exe process exists
            try
            {
                Process[] apacheProcs = Process.GetProcessesByName("httpd");
                status.ApacheRunning = apacheProcs.Length > 0;
                status.ApachePid = status.ApacheRunning ? apacheProcs[0].Id : 0;
            }
            catch { status.ApacheRunning = false; status.ApachePid = 0; }

            // MySQL process check: MySQL is only running if mysqld.exe process exists
            try
            {
                Process[] mysqlProcs = Process.GetProcessesByName("mysqld");
                status.MysqlRunning = mysqlProcs.Length > 0;
                status.MysqlPid = status.MysqlRunning ? mysqlProcs[0].Id : 0;
            }
            catch { status.MysqlRunning = false; status.MysqlPid = 0; }

            // Port conflict detection
            CheckPortConflicts(status);
        }

        private void CheckPortConflicts(XamppStatus status)
        {
            status.PortConflicts.Clear();
            try
            {
                bool port80Taken = IsPortResponding(status.ApachePort);
                bool port3306Taken = IsPortResponding(status.MysqlPort);

                if (port80Taken && !status.ApacheRunning)
                {
                    status.PortConflicts.Add(string.Format("Port {0} is in use by another application (e.g. IIS / World Wide Web Publishing Service), which blocks Apache.", status.ApachePort));
                }

                if (port3306Taken && !status.MysqlRunning)
                {
                    status.PortConflicts.Add(string.Format("Port {0} is in use by an existing MySQL Windows Service, which blocks XAMPP MySQL.", status.MysqlPort));
                }
            }
            catch { }
        }

        private bool IsPortResponding(int port)
        {
            if (port <= 0) return false;
            try
            {
                using (var client = new System.Net.Sockets.TcpClient())
                {
                    var ar = client.BeginConnect("127.0.0.1", port, null, null);
                    bool ok = ar.AsyncWaitHandle.WaitOne(200);
                    if (ok && client.Connected)
                    {
                        client.EndConnect(ar);
                        return true;
                    }
                }
            }
            catch { }
            return false;
        }

        public bool ComparePhpCompatibility(string minVersion, string maxVersion, List<string> requiredExtensions, out List<string> missingExtensions, out string versionVerdict)
        {
            missingExtensions = new List<string>();
            versionVerdict = "Pass";

            if (string.IsNullOrEmpty(LastStatus.PhpVersion) || LastStatus.PhpVersion.Contains("Not found"))
            {
                versionVerdict = "Fail: PHP is not detected in XAMPP.";
                return false;
            }

            // Compare versions
            try
            {
                var verMatch = Regex.Match(LastStatus.PhpVersion, @"([0-9]+)\.([0-9]+)");
                if (verMatch.Success)
                {
                    int installedMajor = int.Parse(verMatch.Groups[1].Value);
                    int installedMinor = int.Parse(verMatch.Groups[2].Value);

                    var minMatch = Regex.Match(minVersion ?? "7.4", @"([0-9]+)\.([0-9]+)");
                    if (minMatch.Success)
                    {
                        int minMajor = int.Parse(minMatch.Groups[1].Value);
                        int minMinor = int.Parse(minMatch.Groups[2].Value);
                        if (installedMajor < minMajor || (installedMajor == minMajor && installedMinor < minMinor))
                        {
                            versionVerdict = string.Format("Fail: Project requires PHP >={0}, but XAMPP has {1}", minVersion, LastStatus.PhpVersion);
                        }
                    }
                }
            }
            catch { }

            // Check extensions
            if (requiredExtensions != null)
            {
                var loadedLower = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
                foreach (var ext in LastStatus.LoadedExtensions) loadedLower.Add(ext);

                foreach (var req in requiredExtensions)
                {
                    if (!loadedLower.Contains(req) && !loadedLower.Contains("pdo_" + req))
                    {
                        missingExtensions.Add(req);
                    }
                }
            }

            return versionVerdict.StartsWith("Pass") && missingExtensions.Count == 0;
        }
    }
}

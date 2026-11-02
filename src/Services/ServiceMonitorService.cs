using System;
using System.Diagnostics;
using System.IO;
using System.Threading;
using SoftBuilt.PHPShield.Models;

namespace SoftBuilt.PHPShield.Services
{
    public class ServiceMonitorService : IDisposable
    {
        private static ServiceMonitorService _instance;
        public static ServiceMonitorService Instance
        {
            get
            {
                if (_instance == null) _instance = new ServiceMonitorService();
                return _instance;
            }
        }

        private Timer _timer;
        private bool _isDisposed;

        // Tracks processes started explicitly by Shield
        private Process _shieldStartedApache;
        private Process _shieldStartedMysql;

        public event Action<XamppStatus> StatusUpdated;
        public event Action<string> ServiceCrashAlert;

        public ServiceMonitorService()
        {
            _timer = new Timer(OnTimerTick, null, 1000, 3000);
        }

        private void OnTimerTick(object state)
        {
            try
            {
                var xampp = XamppDetectorService.Instance.LastStatus;
                if (xampp != null && xampp.IsDetected)
                {
                    bool wasApacheRunning = xampp.ApacheRunning;
                    bool wasMysqlRunning = xampp.MysqlRunning;

                    XamppDetectorService.Instance.DetectServicesAndPorts(xampp);

                    // Check if a process Shield started has died unexpectedly
                    if (_shieldStartedApache != null && _shieldStartedApache.HasExited && wasApacheRunning)
                    {
                        AuditLogService.Instance.Log("SERVICE", "Apache Crash Alert", "Shield-managed Apache process terminated unexpectedly.", false);
                        if (ServiceCrashAlert != null) ServiceCrashAlert("Apache stopped unexpectedly. Check logs at apache/logs/error.log");
                        _shieldStartedApache = null;
                    }

                    if (_shieldStartedMysql != null && _shieldStartedMysql.HasExited && wasMysqlRunning)
                    {
                        AuditLogService.Instance.Log("SERVICE", "MySQL Crash Alert", "Shield-managed MySQL process terminated unexpectedly.", false);
                        if (ServiceCrashAlert != null) ServiceCrashAlert("MySQL stopped unexpectedly. Check logs at mysql/data/mysqld.err");
                        _shieldStartedMysql = null;
                    }

                    if (StatusUpdated != null)
                    {
                        StatusUpdated(xampp);
                    }
                }
            }
            catch { }
        }

        public void StartApache(XamppStatus xampp)
        {
            if (xampp == null || !xampp.IsDetected) return;
            if (xampp.ApacheRunning) return;

            string apacheExe = xampp.ApacheExe;
            if (File.Exists(apacheExe))
            {
                var psi = new ProcessStartInfo
                {
                    FileName = apacheExe,
                    WorkingDirectory = Path.GetDirectoryName(apacheExe),
                    UseShellExecute = false,
                    CreateNoWindow = true
                };

                _shieldStartedApache = Process.Start(psi);
                System.Threading.Thread.Sleep(1200);
                if (_shieldStartedApache != null && _shieldStartedApache.HasExited)
                {
                    string logHint = Path.Combine(xampp.RootPath ?? "", @"apache\logs\error.log");
                    AuditLogService.Instance.Log("SERVICE", "Apache Start Failed",
                        "Apache exited immediately after launch (Exit code: " + _shieldStartedApache.ExitCode + "). Check for port 80/443 conflict (e.g. IIS / w3svc) or missing VC++ Runtime. Log: " + logHint, false);
                    _shieldStartedApache = null;
                }
                else if (_shieldStartedApache != null)
                {
                    AuditLogService.Instance.Log("SERVICE", "Apache Started", "Apache process started by Shield (PID: " + _shieldStartedApache.Id + ")");
                }
                XamppDetectorService.Instance.DetectServicesAndPorts(xampp);
            }
        }

        public void StopApache(XamppStatus xampp)
        {
            if (xampp == null || !xampp.IsDetected) return;

            if (_shieldStartedApache != null && !_shieldStartedApache.HasExited)
            {
                try
                {
                    _shieldStartedApache.Kill();
                    _shieldStartedApache = null;
                    AuditLogService.Instance.Log("SERVICE", "Apache Stopped", "Stopped Shield-managed Apache process.");
                }
                catch { }
            }
            else
            {
                // Attempt standard graceful stop
                try
                {
                    var psi = new ProcessStartInfo
                    {
                        FileName = xampp.ApacheExe,
                        Arguments = "-k stop",
                        UseShellExecute = false,
                        CreateNoWindow = true
                    };
                    using (var p = Process.Start(psi)) { p.WaitForExit(3000); }
                    AuditLogService.Instance.Log("SERVICE", "Apache Stopped", "Apache signaled to stop.");
                }
                catch { }
            }

            XamppDetectorService.Instance.DetectServicesAndPorts(xampp);
        }

        public void StartMysql(XamppStatus xampp)
        {
            if (xampp == null || !xampp.IsDetected) return;
            if (xampp.MysqlRunning) return;

            string mysqlExe = xampp.MysqlExe;
            if (File.Exists(mysqlExe))
            {
                var psi = new ProcessStartInfo
                {
                    FileName = mysqlExe,
                    WorkingDirectory = Path.GetDirectoryName(mysqlExe),
                    UseShellExecute = false,
                    CreateNoWindow = true
                };

                _shieldStartedMysql = Process.Start(psi);
                System.Threading.Thread.Sleep(1200);
                if (_shieldStartedMysql != null && _shieldStartedMysql.HasExited)
                {
                    AuditLogService.Instance.Log("SERVICE", "MySQL Start Failed",
                        "MySQL exited immediately after launch (Exit code: " + _shieldStartedMysql.ExitCode + "). Check for port 3306 conflict.", false);
                    _shieldStartedMysql = null;
                }
                else if (_shieldStartedMysql != null)
                {
                    AuditLogService.Instance.Log("SERVICE", "MySQL Started", "MySQL process started by Shield (PID: " + _shieldStartedMysql.Id + ")");
                }
                XamppDetectorService.Instance.DetectServicesAndPorts(xampp);
            }
        }

        public void StopMysql(XamppStatus xampp)
        {
            if (xampp == null || !xampp.IsDetected) return;

            if (_shieldStartedMysql != null && !_shieldStartedMysql.HasExited)
            {
                try
                {
                    _shieldStartedMysql.Kill();
                    _shieldStartedMysql = null;
                    AuditLogService.Instance.Log("SERVICE", "MySQL Stopped", "Stopped Shield-managed MySQL process.");
                }
                catch { }
            }

            XamppDetectorService.Instance.DetectServicesAndPorts(xampp);
        }

        public void HandleApplicationExit()
        {
            // FR-8.4: 'Stop EduGate runtime when Shield closes' (default off)
            // When off: closing Shield leaves services running.
            // When on: stop ONLY what Shield started. XAMPP is never uninstalled or altered.
            bool stopOnExit = SettingsService.Instance.Settings.StopOnExit;
            if (stopOnExit)
            {
                if (_shieldStartedApache != null && !_shieldStartedApache.HasExited)
                {
                    try { _shieldStartedApache.Kill(); } catch { }
                }

                if (_shieldStartedMysql != null && !_shieldStartedMysql.HasExited)
                {
                    try { _shieldStartedMysql.Kill(); } catch { }
                }
            }

            // Lock vault on exit (FR-3.6)
            VaultService.Instance.Lock();
        }

        public void OpenUrlInBrowser(string url)
        {
            // Try common browsers explicitly, then fall back to OS shell
            string[] browserCandidates = new string[]
            {
                Environment.ExpandEnvironmentVariables(@"%ProgramFiles%\Google\Chrome\Application\chrome.exe"),
                Environment.ExpandEnvironmentVariables(@"%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe"),
                Environment.ExpandEnvironmentVariables(@"%LocalAppData%\Google\Chrome\Application\chrome.exe"),
                Environment.ExpandEnvironmentVariables(@"%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe"),
                Environment.ExpandEnvironmentVariables(@"%ProgramFiles%\Microsoft\Edge\Application\msedge.exe"),
                Environment.ExpandEnvironmentVariables(@"%ProgramFiles%\Mozilla Firefox\firefox.exe"),
            };

            foreach (var browser in browserCandidates)
            {
                if (File.Exists(browser))
                {
                    try
                    {
                        Process.Start(new ProcessStartInfo
                        {
                            FileName = browser,
                            Arguments = "\"" + url + "\"",
                            UseShellExecute = false
                        });
                        return;
                    }
                    catch { }
                }
            }

            // Fallback 1: cmd /c start (most reliable on Windows for any default browser)
            try
            {
                Process.Start(new ProcessStartInfo
                {
                    FileName = "cmd.exe",
                    Arguments = "/c start \"\" \"" + url + "\"",
                    UseShellExecute = false,
                    CreateNoWindow = true
                });
                return;
            }
            catch { }

            // Fallback 2: ShellExecute
            try
            {
                Process.Start(new ProcessStartInfo
                {
                    FileName = url,
                    UseShellExecute = true
                });
            }
            catch { }
        }

        public void OpenXamppControl(XamppStatus xampp)
        {
            if (xampp != null && !string.IsNullOrEmpty(xampp.ControlExe) && File.Exists(xampp.ControlExe))
            {
                try
                {
                    Process.Start(new ProcessStartInfo
                    {
                        FileName = xampp.ControlExe,
                        WorkingDirectory = xampp.RootPath,
                        UseShellExecute = true
                    });
                }
                catch { }
            }
        }

        public void Dispose()
        {
            if (!_isDisposed)
            {
                _isDisposed = true;
                if (_timer != null) _timer.Dispose();
            }
        }
    }
}

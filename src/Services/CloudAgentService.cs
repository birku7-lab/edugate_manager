using System;
using System.IO;
using System.Net;
using System.Text;
using System.Threading;
using System.Web.Script.Serialization;
using SoftBuilt.PHPShield.Models;

namespace SoftBuilt.PHPShield.Services
{
    public class CloudHeartbeatResponse
    {
        public string school_id { get; set; }
        public string status { get; set; }           // "active" or "locked"
        public string lock_reason { get; set; }
        public CloudUpdatePayload update { get; set; }
        public string server_time { get; set; }
    }

    public class CloudUpdatePayload
    {
        public bool available { get; set; }
        public string package_id { get; set; }
        public string title { get; set; }
        public string version { get; set; }
        public string download_url { get; set; }
        public long filesize { get; set; }
        public string notes { get; set; }
    }

    public class CloudAgentService
    {
        private static CloudAgentService _instance;
        public static CloudAgentService Instance
        {
            get
            {
                if (_instance == null) _instance = new CloudAgentService();
                return _instance;
            }
        }

        private readonly JavaScriptSerializer _json = new JavaScriptSerializer();
        private Timer _heartbeatTimer;
        private bool _isHeartbeatRunning = false;

        public event Action<bool, string> LockStatusChanged;
        public event Action<CloudUpdatePayload> UpdateAvailable;

        static CloudAgentService()
        {
            // Ensure TLS 1.2 is enabled for modern HTTPS endpoints like Render
            try
            {
                ServicePointManager.SecurityProtocol |= SecurityProtocolType.Tls12 | SecurityProtocolType.Tls11 | SecurityProtocolType.Tls;
            }
            catch { }
        }

        public void StartHeartbeatLoop(int intervalSeconds = 60)
        {
            if (_heartbeatTimer != null) return;

            // Run first check after 2 seconds, then every intervalSeconds (default 60s)
            _heartbeatTimer = new Timer(delegate
            {
                if (_isHeartbeatRunning) return;
                _isHeartbeatRunning = true;
                try
                {
                    string err;
                    SendHeartbeat(out err);
                }
                finally
                {
                    _isHeartbeatRunning = false;
                }
            }, null, 2000, intervalSeconds * 1000);
        }

        public string GetMachineFingerprint()
        {
            try
            {
                string raw = Environment.MachineName + "_" + Environment.UserName + "_" + Environment.ProcessorCount;
                using (var sha = System.Security.Cryptography.SHA256.Create())
                {
                    byte[] bytes = sha.ComputeHash(Encoding.UTF8.GetBytes(raw));
                    return BitConverter.ToString(bytes).Replace("-", "").Substring(0, 16);
                }
            }
            catch
            {
                return "HWID_" + Environment.MachineName;
            }
        }

        public CloudHeartbeatResponse SendHeartbeat(out string errorMessage)
        {
            errorMessage = string.Empty;
            var settings = SettingsService.Instance.Settings;
            string cloudUrl = settings.CloudDashboardUrl;

            if (string.IsNullOrEmpty(cloudUrl))
            {
                errorMessage = "Cloud Dashboard URL not configured.";
                return null;
            }

            try
            {
                string endpoint = cloudUrl.TrimEnd('/') + "/api/heartbeat";
                var payload = new
                {
                    school_id = string.IsNullOrEmpty(settings.SchoolId) ? "SCH-EDUGATE-01" : settings.SchoolId.Trim(),
                    license_key = settings.LicenseKey ?? "",
                    school_name = string.IsNullOrEmpty(settings.SchoolName) ? "EduGate Client" : settings.SchoolName.Trim(),
                    contact_phone = settings.ContactPhone ?? "",
                    machine_id = GetMachineFingerprint(),
                    shield_version = "1.0.0",
                    current_app_version = settings.CurrentAppVersion ?? "1.0.0",
                    last_sync_status = settings.LastSyncStatus ?? "Ready",
                    http_health_status = settings.LastSyncHealth ?? "Healthy (200 OK)",
                    last_sync_time = settings.LastSyncTime ?? ""
                };

                string jsonStr = _json.Serialize(payload);
                byte[] data = Encoding.UTF8.GetBytes(jsonStr);

                var req = (HttpWebRequest)WebRequest.Create(endpoint);
                req.Method = "POST";
                req.ContentType = "application/json";
                req.Timeout = 15000; // Allow 15s in case Render instance is spinning up
                req.ContentLength = data.Length;

                using (var stream = req.GetRequestStream())
                {
                    stream.Write(data, 0, data.Length);
                }

                using (var resp = (HttpWebResponse)req.GetResponse())
                using (var reader = new StreamReader(resp.GetResponseStream()))
                {
                    string respJson = reader.ReadToEnd();
                    var result = _json.Deserialize<CloudHeartbeatResponse>(respJson);

                    if (result != null)
                    {
                        bool isLocked = string.Equals(result.status, "locked", StringComparison.OrdinalIgnoreCase);
                        string reason = result.lock_reason ?? "";

                        bool lockStateChanged = (settings.RemoteLockActive != isLocked) || (settings.RemoteLockReason != reason);
                        settings.RemoteLockActive = isLocked;
                        settings.RemoteLockReason = reason;

                        if (lockStateChanged)
                        {
                            SettingsService.Instance.Save();
                            AuditLogService.Instance.Log("CLOUD", "Remote Status", 
                                isLocked ? "System locked remotely by developer: " + reason : "System marked active by developer.", isLocked);
                            
                            if (LockStatusChanged != null)
                            {
                                LockStatusChanged(isLocked, reason);
                            }
                        }

                        if (result.update != null && result.update.available)
                        {
                            if (UpdateAvailable != null)
                            {
                                UpdateAvailable(result.update);
                            }

                            // Automatically & silently sync update in background if new version or package
                            if (result.update.package_id != settings.DeployedPackageId || result.update.version != settings.CurrentAppVersion)
                            {
                                TriggerSilentSyncAsync(result.update);
                            }
                        }
                    }

                    return result;
                }
            }
            catch (Exception ex)
            {
                errorMessage = ex.Message;
                return null;
            }
        }

        private readonly object _syncLock = new object();
        private bool _isSyncing = false;

        public void TriggerSilentSyncAsync(CloudUpdatePayload update)
        {
            if (update == null || string.IsNullOrEmpty(update.download_url)) return;

            ThreadPool.QueueUserWorkItem(_ =>
            {
                lock (_syncLock)
                {
                    if (_isSyncing) return;
                    _isSyncing = true;
                }

                try
                {
                    PerformSilentSync(update);
                }
                finally
                {
                    lock (_syncLock)
                    {
                        _isSyncing = false;
                    }
                }
            });
        }

        private void PerformSilentSync(CloudUpdatePayload update)
        {
            var settings = SettingsService.Instance.Settings;
            AuditLogService.Instance.Log("SYNC", "Silent Sync Starting", string.Format("Auto-downloading and applying update '{0}' (v{1}) from cloud...", update.title, update.version), true);

            SendSyncReport(settings.SchoolId, settings.LicenseKey, update.package_id, update.version, "syncing", "Downloading update package...", "Silent sync in progress");

            string tempZipPath = Path.Combine(StorageManager.StagingDirectory, "update_" + update.package_id + ".zip");
            string dlErr;
            if (!DownloadUpdateZip(update.download_url, tempZipPath, out dlErr))
            {
                settings.LastSyncStatus = "Failed";
                settings.LastSyncHealth = "Download Failed";
                settings.LastSyncMessage = dlErr;
                settings.LastSyncTime = DateTime.UtcNow.ToString("yyyy-MM-dd HH:mm:ss");
                SettingsService.Instance.Save();

                SendSyncReport(settings.SchoolId, settings.LicenseKey, update.package_id, update.version, "failed", "Download Failed", dlErr);
                AuditLogService.Instance.Log("SYNC", "Silent Sync Download Error", dlErr, false);
                return;
            }

            try
            {
                SendSyncReport(settings.SchoolId, settings.LicenseKey, update.package_id, update.version, "syncing", "Staging & Obfuscating...", "Unpacking and hardening package");

                // 1. Unpack & validate ZIP safely
                var project = new ProjectImportService().ImportZip(tempZipPath, "EduGate_" + update.version, update.version);

                // 2. Build production package
                var buildProfile = new BuildProfile();
                var buildRecord = BuildService.Instance.CreateProductionBuild(project, buildProfile);

                // 3. Detect XAMPP
                var xampp = XamppDetectorService.Instance.Detect(settings.XamppPath);
                if (xampp == null || !xampp.IsDetected)
                {
                    throw new InvalidOperationException("XAMPP installation not detected on client machine at: " + settings.XamppPath);
                }

                SendSyncReport(settings.SchoolId, settings.LicenseKey, update.package_id, update.version, "syncing", "Deploying & Testing HTTP...", "Activating Apache release");

                // 4. Deploy release atomically
                var deployRecord = DeploymentService.Instance.Deploy(xampp, buildRecord, "edugate", settings.EnableLan, settings.LanSubnet, msg =>
                {
                    // silent logging
                });

                if (deployRecord != null && deployRecord.VerificationPassed)
                {
                    settings.CurrentAppVersion = update.version;
                    settings.DeployedPackageId = update.package_id;
                    settings.LastSyncStatus = "Success";
                    settings.LastSyncHealth = "200 OK - Verified Healthy";
                    settings.LastSyncTime = DateTime.UtcNow.ToString("yyyy-MM-dd HH:mm:ss");
                    settings.LastSyncMessage = string.Format("Package '{0}' v{1} deployed silently & verified healthy.", update.title, update.version);
                    SettingsService.Instance.Save();

                    SendSyncReport(settings.SchoolId, settings.LicenseKey, update.package_id, update.version, "success", "200 OK - Healthy", settings.LastSyncMessage);
                    AuditLogService.Instance.Log("SYNC", "Silent Sync Complete", settings.LastSyncMessage, true);
                }
                else
                {
                    string vSummary = deployRecord != null ? deployRecord.VerificationSummary : "Verification checks did not pass.";
                    throw new InvalidOperationException("Deployment verification failed: " + vSummary);
                }
            }
            catch (Exception ex)
            {
                settings.LastSyncStatus = "Failed";
                settings.LastSyncHealth = "Deploy Failed: " + ex.Message;
                settings.LastSyncMessage = ex.ToString();
                settings.LastSyncTime = DateTime.UtcNow.ToString("yyyy-MM-dd HH:mm:ss");
                SettingsService.Instance.Save();

                SendSyncReport(settings.SchoolId, settings.LicenseKey, update.package_id, update.version, "failed", "Deploy Error: " + ex.Message, ex.ToString());
                AuditLogService.Instance.Log("SYNC", "Silent Sync Failed", ex.Message, false);
            }
            finally
            {
                try { if (File.Exists(tempZipPath)) File.Delete(tempZipPath); } catch { }
            }
        }

        public bool SendSyncReport(string schoolId, string licenseKey, string packageId, string version, string status, string healthStatus, string message, string details = "")
        {
            try
            {
                var settings = SettingsService.Instance.Settings;
                string cloudUrl = settings.CloudDashboardUrl;
                if (string.IsNullOrEmpty(cloudUrl)) return false;

                string endpoint = cloudUrl.TrimEnd('/') + "/api/sync_report";
                var payload = new
                {
                    school_id = schoolId ?? "",
                    license_key = licenseKey ?? "",
                    package_id = packageId ?? "",
                    version = version ?? "",
                    status = status ?? "unknown",
                    health_status = healthStatus ?? "",
                    message = message ?? "",
                    details = details ?? "",
                    machine_id = GetMachineFingerprint()
                };

                string jsonStr = _json.Serialize(payload);
                byte[] data = Encoding.UTF8.GetBytes(jsonStr);

                var req = (HttpWebRequest)WebRequest.Create(endpoint);
                req.Method = "POST";
                req.ContentType = "application/json";
                req.Timeout = 12000;
                req.ContentLength = data.Length;

                using (var stream = req.GetRequestStream())
                {
                    stream.Write(data, 0, data.Length);
                }

                using (var resp = (HttpWebResponse)req.GetResponse())
                {
                    return resp.StatusCode == HttpStatusCode.OK;
                }
            }
            catch
            {
                return false;
            }
        }

        public bool ReportError(string errorType, string message, string stackTrace)
        {
            try
            {
                var settings = SettingsService.Instance.Settings;
                string cloudUrl = settings.CloudDashboardUrl;
                if (string.IsNullOrEmpty(cloudUrl)) return false;

                string endpoint = cloudUrl.TrimEnd('/') + "/api/report_error";
                var payload = new
                {
                    school_id = string.IsNullOrEmpty(settings.SchoolId) ? "SCH-UNKNOWN" : settings.SchoolId.Trim(),
                    license_key = settings.LicenseKey ?? "",
                    error_type = errorType ?? "Exception",
                    message = message ?? "Unknown error",
                    stack_trace = stackTrace ?? "",
                    system_info = string.Format("OS: {0}, Arch: {1}, Machine: {2}",
                        Environment.OSVersion, Environment.Is64BitOperatingSystem ? "x64" : "x86", Environment.MachineName)
                };

                string jsonStr = _json.Serialize(payload);
                byte[] data = Encoding.UTF8.GetBytes(jsonStr);

                var req = (HttpWebRequest)WebRequest.Create(endpoint);
                req.Method = "POST";
                req.ContentType = "application/json";
                req.Timeout = 8000;
                req.ContentLength = data.Length;

                using (var stream = req.GetRequestStream())
                {
                    stream.Write(data, 0, data.Length);
                }

                using (var resp = (HttpWebResponse)req.GetResponse())
                {
                    return resp.StatusCode == HttpStatusCode.OK;
                }
            }
            catch
            {
                return false;
            }
        }

        public bool DownloadUpdateZip(string downloadUrl, string targetZipPath, out string errorMessage)
        {
            errorMessage = string.Empty;
            try
            {
                if (File.Exists(targetZipPath)) File.Delete(targetZipPath);

                using (var client = new WebClient())
                {
                    client.DownloadFile(downloadUrl, targetZipPath);
                }

                return File.Exists(targetZipPath);
            }
            catch (Exception ex)
            {
                errorMessage = "Failed to download update package: " + ex.Message;
                return false;
            }
        }
    }
}

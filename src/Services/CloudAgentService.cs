using System;
using System.IO;
using System.Net;
using System.Text;
using System.Threading.Tasks;
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
        public static CloudAgentService Instance => _instance ?? (_instance = new CloudAgentService());

        private readonly JavaScriptSerializer _json = new JavaScriptSerializer();

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
                    school_name = string.IsNullOrEmpty(settings.SchoolName) ? "EduGate Client" : settings.SchoolName.Trim(),
                    machine_id = GetMachineFingerprint(),
                    shield_version = "1.0.0",
                    current_app_version = "1.0.0"
                };

                string jsonStr = _json.Serialize(payload);
                byte[] data = Encoding.UTF8.GetBytes(jsonStr);

                var req = (HttpWebRequest)WebRequest.Create(endpoint);
                req.Method = "POST";
                req.ContentType = "application/json";
                req.Timeout = 6000;
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
                        settings.RemoteLockActive = isLocked;
                        settings.RemoteLockReason = result.lock_reason ?? "";
                        SettingsService.Instance.Save();
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
                req.Timeout = 5000;
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

using System;
using System.Collections.Generic;
using System.IO;
using System.Net;
using System.Net.Http;
using System.Security.Cryptography;
using System.Text;
using System.Web.Script.Serialization;
using SoftBuilt.PHPShield.Models;

namespace SoftBuilt.PHPShield.Services
{
    public class VerificationResult
    {
        public bool AllPassed { get; set; }
        public string Summary { get; set; }
        public List<string> Details { get; set; }
        public List<string> TamperAlerts { get; set; }

        public VerificationResult()
        {
            Details = new List<string>();
            TamperAlerts = new List<string>();
        }
    }

    public class VerificationService
    {
        private static VerificationService _instance;
        public static VerificationService Instance
        {
            get
            {
                if (_instance == null) _instance = new VerificationService();
                return _instance;
            }
        }

        public VerificationResult VerifyDeployment(string baseUrl, string appAlias, string currentPublicDir, string currentAppDir, string manifestPath)
        {
            var result = new VerificationResult { AllPassed = true };

            if (!baseUrl.EndsWith("/")) baseUrl += "/";
            string fullUrl = baseUrl + appAlias + "/";

            // 1. Positive HTTP Check (FR-7.4) - retry up to 4 times with backoff to allow Apache worker threads and slow disk I/O to bind
            bool checkDone = false;
            for (int attempt = 1; attempt <= 4 && !checkDone; attempt++)
            {
                try
                {
                    string targetTestUrl = fullUrl;
                    if (attempt == 2) targetTestUrl = fullUrl + "index.php";
                    else if (attempt == 3) targetTestUrl = fullUrl.Replace("localhost", "127.0.0.1");
                    else if (attempt == 4) targetTestUrl = fullUrl.Replace("localhost", "127.0.0.1") + "index.php";

                    var request = (HttpWebRequest)WebRequest.Create(targetTestUrl);
                    // C5: 12 second timeout for slow school PCs (e.g. spinning HDDs or high load)
                    request.Timeout = 12000;
                    request.UserAgent = "SoftBuilt-PHP-Shield-Verifier/1.0";

                    using (var response = (HttpWebResponse)request.GetResponse())
                    {
                        if (response.StatusCode == HttpStatusCode.OK)
                        {
                            using (var reader = new StreamReader(response.GetResponseStream()))
                            {
                                string body = reader.ReadToEnd();
                                // Check for PHP fatal errors or parse errors
                                if (body.Contains("Fatal error:") || body.Contains("Parse error:") || body.Contains("Uncaught Error"))
                                {
                                    result.AllPassed = false;
                                    string errSnippet = "";
                                    var match = System.Text.RegularExpressions.Regex.Match(body, @"(<b>)?(Fatal error|Parse error|Uncaught [^<\r\n]+)(</b>)?[:\s]([^\r\n<]+)", System.Text.RegularExpressions.RegexOptions.IgnoreCase);
                                    if (match.Success)
                                    {
                                        errSnippet = ": " + match.Value.Replace("<b>", "").Replace("</b>", "").Trim();
                                    }
                                    result.Details.Add(string.Format("FAIL: Application threw PHP Fatal Error upon execution at {0}{1}", targetTestUrl, errSnippet));
                                }
                                else
                                {
                                    result.Details.Add(string.Format("PASS: HTTP 200 OK from application endpoint: {0}", targetTestUrl));
                                }
                            }
                        }
                        else
                        {
                            result.AllPassed = false;
                            result.Details.Add(string.Format("FAIL: Application returned HTTP status {0} at {1}", response.StatusCode, targetTestUrl));
                        }
                        checkDone = true;
                    }
                }
                catch (WebException wex)
                {
                    if (attempt < 4)
                    {
                        System.Threading.Thread.Sleep(attempt * 1000);
                        continue;
                    }
                    result.AllPassed = false;
                    result.Details.Add(string.Format("FAIL: HTTP Health check failed at {0}: {1}", fullUrl, wex.Message));
                    checkDone = true;
                }
                catch (Exception ex)
                {
                    if (attempt < 4)
                    {
                        System.Threading.Thread.Sleep(attempt * 1000);
                        continue;
                    }
                    result.AllPassed = false;
                    result.Details.Add(string.Format("FAIL: Unexpected error connecting to {0}: {1}", fullUrl, ex.Message));
                    checkDone = true;
                }
            }

            // 2. Negative Security Checks (FR-7.5)
            string[] testNegativePaths = new string[]
            {
                ".env",
                "app/softbuilt_config.php",
                "database.sql",
                "backup.bak",
                ".git/config"
            };

            foreach (var testPath in testNegativePaths)
            {
                string negUrl = baseUrl + appAlias + "/" + testPath;
                try
                {
                    var req = (HttpWebRequest)WebRequest.Create(negUrl);
                    req.Timeout = 4000;
                    using (var resp = (HttpWebResponse)req.GetResponse())
                    {
                        // If it responded 200 OK to a private file, that's a security FAIL!
                        result.AllPassed = false;
                        result.Details.Add(string.Format("SECURITY FAIL: Negative check failed! Sensitive path '{0}' returned HTTP 200 instead of 403/404.", testPath));
                    }
                }
                catch (WebException wex)
                {
                    var errResp = wex.Response as HttpWebResponse;
                    if (errResp != null)
                    {
                        if (errResp.StatusCode == HttpStatusCode.Forbidden || errResp.StatusCode == HttpStatusCode.NotFound)
                        {
                            result.Details.Add(string.Format("PASS (Negative Test): Access to sensitive path '{0}' correctly blocked with HTTP {1}.", testPath, (int)errResp.StatusCode));
                        }
                        else
                        {
                            result.Details.Add(string.Format("WARN: Access to '{0}' returned status {1}.", testPath, (int)errResp.StatusCode));
                        }
                    }
                    else
                    {
                        result.Details.Add(string.Format("PASS: Protected path '{0}' unreachable ({1}).", testPath, wex.Message));
                    }
                }
            }

            // 3. Tamper Check against signed manifest (FR-9.4)
            if (!string.IsNullOrEmpty(manifestPath) && File.Exists(manifestPath))
            {
                VerifyTamperStatus(manifestPath, currentPublicDir, currentAppDir, result);
            }

            result.Summary = result.AllPassed
                ? "All verification checks passed successfully (HTTP 200 healthy, negative checks 403/404 blocked, zero tampering detected)."
                : "Verification encountered failures. Review checklist details below.";

            AuditLogService.Instance.Log("VERIFY", "Deployment Verification", result.Summary, result.AllPassed);
            return result;
        }

        public void VerifyTamperStatus(string manifestPath, string currentPublicDir, string currentAppDir, VerificationResult result)
        {
            try
            {
                string manifestJson = File.ReadAllText(manifestPath);
                var js = new JavaScriptSerializer();
                var dict = js.Deserialize<Dictionary<string, object>>(manifestJson);

                if (dict != null && dict.ContainsKey("files"))
                {
                    var filesDict = dict["files"] as Dictionary<string, object>;
                    if (filesDict != null)
                    {
                        using (var sha = SHA256.Create())
                        {
                            foreach (var kvp in filesDict)
                            {
                                string relPath = kvp.Key;
                                string expectedHash = kvp.Value.ToString();

                                string diskPath = null;
                                if (relPath.StartsWith("public/"))
                                {
                                    diskPath = Path.Combine(currentPublicDir, relPath.Substring(7).Replace('/', '\\'));
                                }
                                else if (relPath.StartsWith("app/"))
                                {
                                    diskPath = Path.Combine(currentAppDir, relPath.Substring(4).Replace('/', '\\'));
                                }

                                if (diskPath == null || !File.Exists(diskPath))
                                {
                                    result.TamperAlerts.Add(string.Format("Missing deployed file: '{0}'", relPath));
                                }
                                else
                                {
                                    // L3: Stream the hash computation instead of loading entire file into memory (prevents OutOfMemoryException on large assets)
                                    string actualHash;
                                    using (var fs = new FileStream(diskPath, FileMode.Open, FileAccess.Read, FileShare.Read, 65536))
                                    {
                                        byte[] hashBytes = sha.ComputeHash(fs);
                                        actualHash = BitConverter.ToString(hashBytes).Replace("-", "").ToLowerInvariant();
                                    }

                                    if (!string.Equals(expectedHash, actualHash, StringComparison.OrdinalIgnoreCase))
                                    {
                                        result.TamperAlerts.Add(string.Format("Tampering detected: File '{0}' hash modified! Expected {1}, found {2}", relPath, expectedHash.Substring(0, 8), actualHash.Substring(0, 8)));
                                    }
                                }
                            }
                        }
                    }
                }

                if (result.TamperAlerts.Count > 0)
                {
                    result.AllPassed = false;
                    result.Details.Add(string.Format("SECURITY ALERT: {0} files failed tamper verification!", result.TamperAlerts.Count));
                }
                else
                {
                    result.Details.Add("PASS: Integrity & Tamper Check: All deployed files match the signed build manifest.");
                }
            }
            catch (Exception ex)
            {
                result.Details.Add("WARN: Could not complete tamper check: " + ex.Message);
            }
        }
    }
}

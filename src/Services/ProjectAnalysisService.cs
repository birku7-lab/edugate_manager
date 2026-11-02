using System;
using System.Collections.Generic;
using System.IO;
using System.Text.RegularExpressions;
using SoftBuilt.PHPShield.Models;

namespace SoftBuilt.PHPShield.Services
{
    public class ProjectAnalysisService
    {
        public AnalysisReport Analyze(Project project)
        {
            var report = new AnalysisReport();
            string root = project.RootDirectory ?? project.StagingPath;

            if (!Directory.Exists(root))
            {
                report.Warnings.Add("Project staging directory not found.");
                return report;
            }

            var allFiles = Directory.GetFiles(root, "*.*", SearchOption.AllDirectories);
            report.FileCount = allFiles.Length;

            long totalBytes = 0;
            var phpFiles = new List<string>();
            var extensionsFound = new HashSet<string>(StringComparer.OrdinalIgnoreCase);

            bool hasComposer = false;
            string composerPath = Path.Combine(root, "composer.json");

            if (File.Exists(composerPath))
            {
                hasComposer = true;
                ParseComposer(composerPath, report, extensionsFound);
            }

            // Framework detection patterns
            if (File.Exists(Path.Combine(root, "artisan"))) report.DetectedFramework = "Laravel";
            else if (Directory.Exists(Path.Combine(root, "app")) && Directory.Exists(Path.Combine(root, "public"))) report.DetectedFramework = "EduGate / Modern MVC";
            else if (File.Exists(Path.Combine(root, "wp-config.php")) || File.Exists(Path.Combine(root, "wp-load.php"))) report.DetectedFramework = "WordPress";
            else if (Directory.Exists(Path.Combine(root, "application")) && Directory.Exists(Path.Combine(root, "system"))) report.DetectedFramework = "CodeIgniter";
            else if (hasComposer) report.DetectedFramework = "Composer / PHP App";
            else report.DetectedFramework = "Custom PHP Project";

            // Scan files
            foreach (var file in allFiles)
            {
                var fi = new FileInfo(file);
                totalBytes += fi.Length;

                string relPath = file.Substring(root.Length).TrimStart('\\', '/');
                string ext = fi.Extension.ToLowerInvariant();

                // Check for entry points
                string fileNameLower = fi.Name.ToLowerInvariant();
                if (fileNameLower == "index.php" || fileNameLower == "app.php" || fileNameLower == "main.php")
                {
                    report.EntryPoints.Add(relPath);
                }

                // Check risky backup / debug files
                if (ext == ".sql" || ext == ".bak" || ext == ".backup" || ext == ".old" || ext == ".dump")
                {
                    report.RiskyItems.Add(string.Format("Exposed database/backup file: '{0}' ({1:F1} KB)", relPath, fi.Length / 1024.0));
                }
                if (relPath.StartsWith(".git") || relPath.Contains("\\.git\\"))
                {
                    report.RiskyItems.Add("Exposed .git repository folder in release package.");
                }

                // Check configuration files
                if (fileNameLower == ".env" || fileNameLower == ".env.local" || fileNameLower == "config.php" || fileNameLower == "database.php")
                {
                    report.DetectedConfigs.Add(relPath);
                    if (fileNameLower.StartsWith(".env"))
                    {
                        report.Warnings.Add("Plaintext environment file found: '" + relPath + "'. Ensure sensitive keys are injected at deployment time.");
                    }
                }

                if (ext == ".php" || ext == ".phtml" || ext == ".php5" || ext == ".inc")
                {
                    phpFiles.Add(file);
                    InspectPhpFile(file, relPath, report, extensionsFound);
                }
            }

            report.PhpFileCount = phpFiles.Count;
            report.TotalSizeBytes = totalBytes;

            foreach (var reqExt in extensionsFound)
            {
                if (!report.RequiredExtensions.Contains(reqExt))
                {
                    report.RequiredExtensions.Add(reqExt);
                }
            }

            // Always ensure standard database extensions are listed if relevant
            if (report.DetectedConfigs.Count > 0 && !report.RequiredExtensions.Contains("mysqli") && !report.RequiredExtensions.Contains("pdo_mysql"))
            {
                report.RequiredExtensions.Add("mysqli");
                report.RequiredExtensions.Add("pdo_mysql");
            }

            // Check public folders
            string[] possiblePublic = new string[] { "public", "public_html", "www", "web" };
            foreach (var p in possiblePublic)
            {
                if (Directory.Exists(Path.Combine(root, p)))
                {
                    report.PublicDirectoriesFound.Add(p);
                }
            }

            project.Analysis = report;
            AuditLogService.Instance.Log("ANALYSIS", "Analysis Complete",
                string.Format("Analyzed '{0}': {1} files ({2} PHP), framework: {3}, {4} warnings, {5} risky items.",
                project.Name, report.FileCount, report.PhpFileCount, report.DetectedFramework, report.Warnings.Count, report.RiskyItems.Count));

            return report;
        }

        private void ParseComposer(string composerPath, AnalysisReport report, HashSet<string> extensions)
        {
            try
            {
                string content = File.ReadAllText(composerPath);

                // PHP Version pattern in composer: "php": "^8.0" or ">=7.4"
                var phpVerMatch = Regex.Match(content, @"""php""\s*:\s*""([^""]+)""");
                if (phpVerMatch.Success)
                {
                    string verStr = phpVerMatch.Groups[1].Value;
                    if (verStr.Contains("8.2") || verStr.Contains("8.3")) report.MinPhpVersion = "8.2";
                    else if (verStr.Contains("8.1")) report.MinPhpVersion = "8.1";
                    else if (verStr.Contains("8.0") || verStr.Contains("^8")) report.MinPhpVersion = "8.0";
                    else if (verStr.Contains("7.4") || verStr.Contains("^7")) report.MinPhpVersion = "7.4";
                }

                // Extension requirements in composer: "ext-mysqli", "ext-pdo_mysql", etc.
                var extMatches = Regex.Matches(content, @"""ext-([a-zA-Z0-9_]+)""");
                foreach (Match m in extMatches)
                {
                    extensions.Add(m.Groups[1].Value);
                }
            }
            catch { }
        }

        private void InspectPhpFile(string fullPath, string relPath, AnalysisReport report, HashSet<string> extensions)
        {
            try
            {
                // Read up to 256KB of the file for inspection to stay blazing fast
                string snippet;
                using (var reader = new StreamReader(fullPath))
                {
                    char[] buffer = new char[262144];
                    int read = reader.Read(buffer, 0, buffer.Length);
                    snippet = new string(buffer, 0, read);
                }

                // Extension detection heuristics
                if (snippet.Contains("mysqli_") || snippet.Contains("new mysqli")) extensions.Add("mysqli");
                if (snippet.Contains("new PDO") || snippet.Contains("PDO::"))
                {
                    extensions.Add("pdo");
                    if (snippet.Contains("mysql:")) extensions.Add("pdo_mysql");
                }
                if (snippet.Contains("mb_") || snippet.Contains("mbstring")) extensions.Add("mbstring");
                if (snippet.Contains("curl_init") || snippet.Contains("curl_exec")) extensions.Add("curl");
                if (snippet.Contains("openssl_") || snippet.Contains("OPENSSL_")) extensions.Add("openssl");
                if (snippet.Contains("imagecreatetruecolor") || snippet.Contains("imagepng") || snippet.Contains("imagejpeg")) extensions.Add("gd");
                if (snippet.Contains("new ZipArchive") || snippet.Contains("ZipArchive::")) extensions.Add("zip");
                if (snippet.Contains("finfo_open") || snippet.Contains("mime_content_type")) extensions.Add("fileinfo");
                if (snippet.Contains("NumberFormatter") || snippet.Contains("IntlDateFormatter")) extensions.Add("intl");
                if (snippet.Contains("sodium_")) extensions.Add("sodium");

                // Risky items check
                if (snippet.Contains("phpinfo()"))
                {
                    report.RiskyItems.Add(string.Format("Dangerous call 'phpinfo()' found in '{0}' (leaks server internals).", relPath));
                }

                if (snippet.Contains("eval(") || snippet.Contains("shell_exec(") || snippet.Contains("system("))
                {
                    report.RiskyItems.Add(string.Format("Dynamic code execution call (eval/exec) detected in '{0}'.", relPath));
                }

                // Hardcoded credentials check
                if (Regex.IsMatch(snippet, @"(?i)(password|pwd|secret)\s*=\s*['""][a-zA-Z0-9_\-!@#$%^&*()]{4,}['""]"))
                {
                    if (!relPath.Contains("test") && !relPath.Contains("vendor"))
                    {
                        report.Warnings.Add(string.Format("Potential hardcoded credential or secret detected in '{0}'.", relPath));
                    }
                }
            }
            catch { }
        }

        public string GenerateHtmlReport(Project project)
        {
            var r = project.Analysis ?? new AnalysisReport();
            var sb = new System.Text.StringBuilder();

            sb.AppendLine("<!DOCTYPE html><html><head><meta charset='utf-8'/><title>Shield Analysis: " + project.Name + "</title>");
            sb.AppendLine("<style>");
            sb.AppendLine("body { font-family: 'Segoe UI', sans-serif; background: #0d1117; color: #c9d1d9; padding: 24px; }");
            sb.AppendLine(".card { background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 18px; margin-bottom: 18px; }");
            sb.AppendLine("h1, h2, h3 { color: #58a6ff; }");
            sb.AppendLine(".badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-weight: bold; font-size: 12px; }");
            sb.AppendLine(".badge-pass { background: #238636; color: white; }");
            sb.AppendLine(".badge-warn { background: #d29922; color: black; }");
            sb.AppendLine(".badge-fail { background: #da3633; color: white; }");
            sb.AppendLine("ul { line-height: 1.6; }");
            sb.AppendLine("</style></head><body>");

            sb.AppendLine("<h1>SoftBuilt PHP Shield - Code Analysis Report</h1>");
            sb.AppendLine("<div class='card'>");
            sb.AppendLine("<h2>Project: " + project.Name + " (" + project.Version + ")</h2>");
            sb.AppendLine("<p>Total Files: <strong>" + r.FileCount + "</strong> (" + r.PhpFileCount + " PHP files, " + (r.TotalSizeBytes / (1024 * 1024.0)).ToString("F2") + " MB)</p>");
            sb.AppendLine("<p>Detected Framework: <strong>" + r.DetectedFramework + "</strong></p>");
            sb.AppendLine("<p>PHP Support: <strong>" + r.MinPhpVersion + " - " + r.MaxPhpVersion + "</strong></p>");
            sb.AppendLine("<p>Public Directory: <strong>" + (string.IsNullOrEmpty(project.PublicDirectory) ? "(Project Root)" : "/" + project.PublicDirectory) + "</strong></p>");
            sb.AppendLine("</div>");

            sb.AppendLine("<div class='card'>");
            sb.AppendLine("<h3>Required PHP Extensions</h3><ul>");
            foreach (var ext in r.RequiredExtensions) sb.AppendLine("<li><code>" + ext + "</code></li>");
            sb.AppendLine("</ul></div>");

            if (r.Warnings.Count > 0)
            {
                sb.AppendLine("<div class='card'><h3>Configuration Warnings (" + r.Warnings.Count + ")</h3><ul>");
                foreach (var w in r.Warnings) sb.AppendLine("<li style='color:#e3b341;'>" + w + "</li>");
                sb.AppendLine("</ul></div>");
            }

            if (r.RiskyItems.Count > 0)
            {
                sb.AppendLine("<div class='card'><h3>Risky Items / Hardening Needed (" + r.RiskyItems.Count + ")</h3><ul>");
                foreach (var risk in r.RiskyItems) sb.AppendLine("<li style='color:#f85149;'>" + risk + "</li>");
                sb.AppendLine("</ul></div>");
            }

            sb.AppendLine("<footer><p style='color:#8b949e; font-size:12px;'>Generated by SoftBuilt PHP Shield on " + DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + "</p></footer>");
            sb.AppendLine("</body></html>");

            return sb.ToString();
        }
    }
}

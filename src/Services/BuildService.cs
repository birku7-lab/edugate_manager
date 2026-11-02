using System;
using System.Collections.Generic;
using System.IO;
using System.Security.Cryptography;
using System.Text;
using System.Text.RegularExpressions;
using System.Web.Script.Serialization;
using SoftBuilt.PHPShield.Models;

namespace SoftBuilt.PHPShield.Services
{
    public class BuildService
    {
        private static BuildService _instance;
        public static BuildService Instance
        {
            get
            {
                if (_instance == null) _instance = new BuildService();
                return _instance;
            }
        }

        private static readonly byte[] SIGN_KEY = Encoding.UTF8.GetBytes("SoftBuilt_PHP_Shield_BuildSignerKey_2026_x86_x64");

        public BuildRecord CreateProductionBuild(Project project, BuildProfile profile)
        {
            if (profile == null) profile = new BuildProfile();

            string buildsDir = SettingsService.Instance.GetBuildsDirectory();
            string buildId = "build_" + DateTime.Now.ToString("yyyyMMdd_HHmmss");
            string targetDir = Path.Combine(buildsDir, buildId);
            Directory.CreateDirectory(targetDir);

            string appDir = Path.Combine(targetDir, "app");
            string publicDir = Path.Combine(targetDir, "public");
            Directory.CreateDirectory(appDir);
            Directory.CreateDirectory(publicDir);

            string sourceRoot = project.RootDirectory ?? project.StagingPath;
            if (!Directory.Exists(sourceRoot))
                throw new DirectoryNotFoundException("Source project staging directory not found.");

            string declaredPublic = (project.PublicDirectory ?? "").Trim('\\', '/');

            // Auto-correct: if declared public folder exists AND has an entry point, use it.
            // If declared public folder does not exist or has NO index, but root has index.php/index.html,
            // then treat the root itself as public (clear declaredPublic).
            if (!string.IsNullOrEmpty(declaredPublic))
            {
                string publicDirFull = Path.Combine(sourceRoot, declaredPublic);
                bool publicFolderExists = Directory.Exists(publicDirFull);
                bool publicHasIndex = publicFolderExists && (File.Exists(Path.Combine(publicDirFull, "index.php")) || File.Exists(Path.Combine(publicDirFull, "index.html")));
                bool rootHasIndex = File.Exists(Path.Combine(sourceRoot, "index.php")) || File.Exists(Path.Combine(sourceRoot, "index.html"));

                if (!publicFolderExists || (!publicHasIndex && rootHasIndex))
                {
                    // Root-level project: treat root as public
                    declaredPublic = "";
                }
            }

            var allSourceFiles = Directory.GetFiles(sourceRoot, "*.*", SearchOption.AllDirectories);
            var manifestEntries = new Dictionary<string, string>(); // relPath -> sha256

            int totalCopied = 0;
            long totalBytes = 0;

            foreach (var filePath in allSourceFiles)
            {
                string relPath = filePath.Substring(sourceRoot.Length).TrimStart('\\', '/');
                string relLower = relPath.ToLowerInvariant();

                // 1. Exclude non-runtime files (FR-4.2)
                if (profile.ExcludeDevFiles)
                {
                    if (relLower.StartsWith(".git") || relLower.Contains("\\.git\\")) continue;
                    if (relLower.StartsWith(".github") || relLower.Contains("\\.github\\")) continue;
                    if (relLower.StartsWith("tests") || relLower.Contains("\\tests\\")) continue;
                    if (relLower.StartsWith("test") || relLower.Contains("\\test\\")) continue;
                    if (relLower.StartsWith("docs") || relLower.Contains("\\docs\\")) continue;
                    if (relLower.EndsWith("test.php") || relLower.EndsWith("testcase.php")) continue;
                    if (relLower.EndsWith(".md") || relLower.EndsWith(".txt") && (relLower.Contains("readme") || relLower.Contains("changelog") || relLower.Contains("license"))) continue;
                    if (relLower.EndsWith(".env.example") || relLower.EndsWith(".env.local")) continue;
                    if (relLower == "package.json" || relLower == "package-lock.json" || relLower == "webpack.config.js" || relLower == "gulpfile.js") continue;
                    if (relLower.EndsWith(".sql") || relLower.EndsWith(".bak") || relLower.EndsWith(".dump")) continue;
                }

                // 2. Determine target placement (Public vs Private Application) (FR-4.4)
                bool isPublic = false;
                string targetSubPath;

                if (!string.IsNullOrEmpty(declaredPublic) && (relLower.StartsWith(declaredPublic.ToLowerInvariant() + "\\") || relLower.StartsWith(declaredPublic.ToLowerInvariant() + "/")))
                {
                    isPublic = true;
                    targetSubPath = relPath.Substring(declaredPublic.Length).TrimStart('\\', '/');
                }
                else if (string.IsNullOrEmpty(declaredPublic))
                {
                    // Root-level flat project: all runtime files (PHP entry points/api, HTML, assets, vendor) go to public
                    isPublic = true;
                    targetSubPath = relPath;
                }
                else
                {
                    // Has a declared public folder but file is outside it - keep in app (private)
                    if (relLower.StartsWith("app\\") || relLower.StartsWith("app/"))
                    {
                        targetSubPath = relPath.Substring(3).TrimStart('\\', '/');
                    }
                    else
                    {
                        targetSubPath = relPath;
                    }
                }

                string destinationFile = isPublic ? Path.Combine(publicDir, targetSubPath) : Path.Combine(appDir, targetSubPath);
                string destDir = Path.GetDirectoryName(destinationFile);
                if (!Directory.Exists(destDir)) Directory.CreateDirectory(destDir);

                string extLower = Path.GetExtension(filePath).ToLowerInvariant();
                if (extLower == ".php" || extLower == ".phtml" || extLower == ".inc")
                {
                    // Apply protection profile (FR-4.3)
                    string phpCode = File.ReadAllText(filePath);
                    string protectedCode = ProcessPhpFile(phpCode, profile, isPublic);
                    File.WriteAllText(destinationFile, protectedCode, new UTF8Encoding(false));
                }
                else
                {
                    // Copy non-php asset verbatim
                    File.Copy(filePath, destinationFile, true);
                }

                // Compute SHA-256 for manifest
                byte[] fileBytes = File.ReadAllBytes(destinationFile);
                string sha256;
                using (var sha = SHA256.Create())
                {
                    sha256 = BitConverter.ToString(sha.ComputeHash(fileBytes)).Replace("-", "").ToLowerInvariant();
                }

                // L1: Ensure manifest key uses forward slashes and no leading slash
                string cleanSub = targetSubPath.Replace('\\', '/').TrimStart('/');
                string manifestKey = (isPublic ? "public/" : "app/") + cleanSub;
                manifestEntries[manifestKey] = sha256;

                totalCopied++;
                totalBytes += fileBytes.Length;
            }

            // 3. Inject deployment runtime configuration file (FR-4.5)
            if (profile.InjectRuntimeConfig)
            {
                string configPhp = GenerateRuntimeConfigPhp(profile);
                string configPath = Path.Combine(appDir, "softbuilt_config.php");
                File.WriteAllText(configPath, configPhp, new UTF8Encoding(false));

                byte[] cfgBytes = File.ReadAllBytes(configPath);
                using (var sha = SHA256.Create())
                {
                    manifestEntries["app/softbuilt_config.php"] = BitConverter.ToString(sha.ComputeHash(cfgBytes)).Replace("-", "").ToLowerInvariant();
                }
                totalCopied++;
                totalBytes += cfgBytes.Length;
            }

            // 4. Create and Sign Build Manifest (FR-4.6)
            var js = new JavaScriptSerializer { MaxJsonLength = int.MaxValue };
            string manifestJson = js.Serialize(new
            {
                buildId = buildId,
                projectId = project.Id,
                projectName = project.Name,
                createdAt = DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss"),
                files = manifestEntries
            });

            string manifestPath = Path.Combine(targetDir, "build_manifest.json");
            File.WriteAllText(manifestPath, manifestJson, new UTF8Encoding(false));

            string manifestSha;
            string signature;
            using (var sha = SHA256.Create())
            {
                byte[] mBytes = Encoding.UTF8.GetBytes(manifestJson);
                manifestSha = BitConverter.ToString(sha.ComputeHash(mBytes)).Replace("-", "").ToLowerInvariant();

                using (var hmac = new HMACSHA256(SIGN_KEY))
                {
                    signature = BitConverter.ToString(hmac.ComputeHash(mBytes)).Replace("-", "").ToLowerInvariant();
                }
            }

            File.WriteAllText(Path.Combine(targetDir, "build_signature.sig"), signature, new UTF8Encoding(false));

            var record = new BuildRecord
            {
                BuildId = buildId,
                ProjectId = project.Id,
                ProjectName = project.Name,
                Timestamp = DateTime.Now,
                BuildPath = targetDir,
                ManifestPath = manifestPath,
                FileCount = totalCopied,
                TotalSizeBytes = totalBytes,
                ManifestSha256 = manifestSha,
                Signature = signature,
                ProfileUsed = profile
            };

            // Save record
            File.WriteAllText(Path.Combine(targetDir, "build_record.json"), js.Serialize(record), new UTF8Encoding(false));

            AuditLogService.Instance.Log("BUILD", "Production Build Created",
                string.Format("Generated signed build '{0}' for project '{1}' ({2} files, {3:F2} MB). Manifest SHA: {4}",
                buildId, project.Name, totalCopied, totalBytes / (1024.0 * 1024.0), manifestSha.Substring(0, 12)));

            return record;
        }

        private string ProcessPhpFile(string code, BuildProfile profile, bool isPublic)
        {
            if (string.IsNullOrEmpty(code)) return code;

            try
            {
                string processed = code;

                // 1. Minify & Strip Comments (FR-4.3a)
                if (profile.Minify)
                {
                    // Remove multi-line comments /* ... */
                    processed = Regex.Replace(processed, @"/\*[\s\S]*?\*/", "");
                    // Remove standalone line comments // and # (C7 fix: only strip # when followed by whitespace/comments, avoiding CSS IDs or hex colors)
                    processed = Regex.Replace(processed, @"(?m)^\s*//.*$", "");
                    processed = Regex.Replace(processed, @"(?m)^\s*#\s+.*$", "");
                    // Collapse excessive blank lines
                    processed = Regex.Replace(processed, @"(\r?\n){3,}", "\n\n");
                }

                // 2. Identifier Obfuscation for local variables (FR-4.3b)
                if (profile.ObfuscateIdentifiers)
                {
                    var reserved = new HashSet<string>(StringComparer.OrdinalIgnoreCase)
                    {
                        "_GET", "_POST", "_SESSION", "_SERVER", "_REQUEST", "_ENV", "_COOKIE", "_FILES",
                        "this", "GLOBALS", "argc", "argv", "http_response_header", "php_errormsg",
                        "pdo", "stmt", "db", "host", "user", "pass", "password", "conn", "connection",
                        "sql", "query", "result", "results", "row", "rows", "e", "ex", "action",
                        "data", "response", "auth", "token", "userId", "user_id", "admin", "school",
                        "columns", "added", "skipped", "stmt_check", "errors", "success", "default_settings",
                        "key", "value", "table", "tables", "res", "count", "params", "fields", "input",
                        "ip", "rateLimits", "rateLimitFile", "user_role", "permissions", "ip_address",
                        "server_name", "server_host", "clean_host", "name", "username", "role", "output",
                        "path", "file", "status", "msg", "message", "id", "insert", "update", "delete"
                    };

                    var varMap = new Dictionary<string, string>();
                    int idCounter = 1;

                    // Collect local variables
                    var varMatches = Regex.Matches(processed, @"\$([a-zA-Z_][a-zA-Z0-9_]*)");
                    foreach (Match m in varMatches)
                    {
                        string varName = m.Groups[1].Value;
                        if (!reserved.Contains(varName) && !varMap.ContainsKey(varName) && varName.Length > 2)
                        {
                            varMap[varName] = string.Format("x_{0:x4}", idCounter++);
                        }
                    }

                    // Single-pass replacement using MatchEvaluator (avoids regex substitution tokens like $_)
                    if (varMap.Count > 0)
                    {
                        processed = Regex.Replace(processed, @"\$([a-zA-Z_][a-zA-Z0-9_]*)", new MatchEvaluator(m =>
                        {
                            string varName = m.Groups[1].Value;
                            string obfuscated;
                            if (varMap.TryGetValue(varName, out obfuscated))
                            {
                                return "$" + obfuscated;
                            }
                            return m.Value;
                        }));
                    }
                }

                // Add SoftBuilt Shield Protection Header
                string header = "<?php\n/* Protected by SoftBuilt PHP Shield v1.0 - Unauthorised duplication or alteration is strictly prohibited */\n";
                if (processed.StartsWith("<?php", StringComparison.OrdinalIgnoreCase))
                {
                    processed = header + processed.Substring(5).TrimStart();
                }

                return processed;
            }
            catch
            {
                // Fallback to original code if processing encounters any anomaly
                return code;
            }
        }

        private string GenerateRuntimeConfigPhp(BuildProfile profile)
        {
            var sb = new StringBuilder();
            sb.AppendLine("<?php");
            sb.AppendLine("/**");
            sb.AppendLine(" * SoftBuilt Shield Injected Runtime Configuration");
            sb.AppendLine(" * Generated at deployment time - DO NOT COMMIT OR EXPOSE");
            sb.AppendLine(" */");
            sb.AppendLine("if (!defined('SOFTBUILT_SHIELD_CONFIG')) {");
            sb.AppendLine("    define('SOFTBUILT_SHIELD_CONFIG', true);");
            sb.AppendLine(string.Format("    define('DB_HOST', '{0}');", profile.DbHost));
            sb.AppendLine(string.Format("    define('DB_NAME', '{0}');", profile.DbName));
            sb.AppendLine(string.Format("    define('DB_USER', '{0}');", profile.DbUser));
            sb.AppendLine(string.Format("    define('DB_PASS', '{0}');", profile.DbPass));
            sb.AppendLine("}");
            return sb.ToString();
        }

        public List<BuildRecord> GetBuildHistory()
        {
            var list = new List<BuildRecord>();
            try
            {
                string buildsDir = SettingsService.Instance.GetBuildsDirectory();
                if (Directory.Exists(buildsDir))
                {
                    var js = new JavaScriptSerializer();
                    foreach (var dir in Directory.GetDirectories(buildsDir))
                    {
                        string recordPath = Path.Combine(dir, "build_record.json");
                        if (File.Exists(recordPath))
                        {
                            try
                            {
                                var rec = js.Deserialize<BuildRecord>(File.ReadAllText(recordPath));
                                if (rec != null) list.Add(rec);
                            }
                            catch { }
                        }
                    }
                }
            }
            catch { }

            list.Sort((a, b) => b.Timestamp.CompareTo(a.Timestamp));
            return list;
        }
    }
}

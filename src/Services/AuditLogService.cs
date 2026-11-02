using System;
using System.Collections.Generic;
using System.IO;
using System.Text.RegularExpressions;
using SoftBuilt.PHPShield.Models;

namespace SoftBuilt.PHPShield.Services
{
    public class AuditLogService
    {
        private static AuditLogService _instance;
        public static AuditLogService Instance
        {
            get
            {
                if (_instance == null) _instance = new AuditLogService();
                return _instance;
            }
        }

        private readonly string _logFilePath;
        private readonly List<AuditLogEntry> _recentEntries = new List<AuditLogEntry>();
        private readonly object _lock = new object();

        public event Action<AuditLogEntry> EntryAdded;

        public AuditLogService()
        {
            string logDir = StorageManager.LogsDirectory;
            _logFilePath = Path.Combine(logDir, "audit.log");
        }

        public void Log(string category, string action, string details, bool isSuccess = true)
        {
            string redacted = RedactSecrets(details);
            var entry = new AuditLogEntry
            {
                Timestamp = DateTime.Now,
                Category = category,
                Action = action,
                Details = redacted,
                IsSuccess = isSuccess
            };

            lock (_lock)
            {
                _recentEntries.Insert(0, entry);
                if (_recentEntries.Count > 500) _recentEntries.RemoveAt(_recentEntries.Count - 1);

                try
                {
                    string line = string.Format("[{0:yyyy-MM-dd HH:mm:ss}] [{1}] [{2}] [{3}] {4}{5}",
                        entry.Timestamp,
                        entry.IsSuccess ? "SUCCESS" : "FAILED",
                        entry.Category.ToUpperInvariant(),
                        entry.Action,
                        entry.Details,
                        Environment.NewLine);
                    File.AppendAllText(_logFilePath, line);
                }
                catch { }
            }

            if (EntryAdded != null)
            {
                try { EntryAdded(entry); } catch { }
            }
        }

        public List<AuditLogEntry> GetRecentEntries()
        {
            lock (_lock)
            {
                return new List<AuditLogEntry>(_recentEntries);
            }
        }

        public string RedactSecrets(string input)
        {
            if (string.IsNullOrEmpty(input)) return string.Empty;

            // Redact DB passwords, secret keys, tokens
            string result = Regex.Replace(input, @"(?i)(password|passwd|pwd|secret|token|auth_key|master_key)\s*[:=]\s*[""']?([^""'\s,;&]+)[""']?", "$1=***REDACTED***");
            result = Regex.Replace(result, @"(?i)(DB_PASS|DB_PASSWORD)\s*=\s*([^\r\n]+)", "$1=***REDACTED***");
            return result;
        }

        public void ExportLogs(string destinationPath)
        {
            lock (_lock)
            {
                if (File.Exists(_logFilePath))
                {
                    string allText = File.ReadAllText(_logFilePath);
                    string safeText = RedactSecrets(allText);
                    File.WriteAllText(destinationPath, safeText);
                }
                else
                {
                    File.WriteAllText(destinationPath, "# SoftBuilt PHP Shield Audit Log (Empty)");
                }
            }
        }
    }
}

using System;
using System.IO;

namespace SoftBuilt.PHPShield.Services
{
    public static class StorageManager
    {
        private static string _baseDir;

        public static string BaseDirectory
        {
            get
            {
                if (string.IsNullOrEmpty(_baseDir))
                {
                    _baseDir = ResolveSafeDirectory();
                }
                return _baseDir;
            }
        }

        private static string ResolveSafeDirectory()
        {
            string[] candidates = new string[]
            {
                // 1. Portable data folder next to the exe
                Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "data"),
                // 2. LocalApplicationData (always writable for the current user)
                Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "SoftBuilt", "Shield"),
                // 3. User Temp path (universal fallback)
                Path.Combine(Path.GetTempPath(), "SoftBuilt", "Shield")
            };

            foreach (var candidate in candidates)
            {
                try
                {
                    Directory.CreateDirectory(candidate);
                    string testFile = Path.Combine(candidate, "probe_" + Guid.NewGuid().ToString("N") + ".tmp");
                    File.WriteAllText(testFile, "probe");
                    File.Delete(testFile);
                    return candidate;
                }
                catch
                {
                    // Continue to next candidate
                }
            }

            // Extreme fallback: current directory
            return AppDomain.CurrentDomain.BaseDirectory;
        }

        public static string GetSubdirectory(string name)
        {
            string path = Path.Combine(BaseDirectory, name);
            try
            {
                if (!Directory.Exists(path)) Directory.CreateDirectory(path);
            }
            catch { }
            return path;
        }

        public static string SettingsFilePath
        {
            get { return Path.Combine(BaseDirectory, "settings.json"); }
        }

        public static string LogsDirectory
        {
            get { return GetSubdirectory("Logs"); }
        }

        public static string VaultDirectory
        {
            get { return GetSubdirectory("Vault"); }
        }

        public static string BuildsDirectory
        {
            get { return GetSubdirectory("Builds"); }
        }

        public static string BackupsDirectory
        {
            get { return GetSubdirectory("Backups"); }
        }

        public static string StagingDirectory
        {
            get { return GetSubdirectory("Staging"); }
        }

        public static string SamplesDirectory
        {
            get { return GetSubdirectory("Samples"); }
        }

        public static string TempDirectory
        {
            get { return GetSubdirectory("Temp"); }
        }

        public static string GetTempFilePath(string extension = ".tmp")
        {
            if (!extension.StartsWith(".")) extension = "." + extension;
            return Path.Combine(TempDirectory, "tmp_" + Guid.NewGuid().ToString("N") + extension);
        }

        public static string CreateTempDirectory(string prefix = "temp")
        {
            string path = Path.Combine(TempDirectory, prefix + "_" + Guid.NewGuid().ToString("N"));
            Directory.CreateDirectory(path);
            return path;
        }

        public static bool IsDirectoryWritable(string path)
        {
            if (string.IsNullOrEmpty(path)) return false;
            try
            {
                if (!Directory.Exists(path)) Directory.CreateDirectory(path);
                string testFile = Path.Combine(path, "probe_" + Guid.NewGuid().ToString("N") + ".tmp");
                File.WriteAllText(testFile, "probe");
                File.Delete(testFile);
                return true;
            }
            catch
            {
                return false;
            }
        }
    }
}

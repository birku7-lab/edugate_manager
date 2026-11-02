using System;
using System.IO;
using System.Web.Script.Serialization;
using SoftBuilt.PHPShield.Models;

namespace SoftBuilt.PHPShield.Services
{
    public class SettingsService
    {
        private static SettingsService _instance;
        public static SettingsService Instance
        {
            get
            {
                if (_instance == null) _instance = new SettingsService();
                return _instance;
            }
        }

        public AppSettings Settings { get; private set; }
        private readonly string _settingsFilePath;

        public SettingsService()
        {
            _settingsFilePath = StorageManager.SettingsFilePath;
            Load();
            EnsureDirectories();
        }

        public void Load()
        {
            try
            {
                if (File.Exists(_settingsFilePath))
                {
                    string json = File.ReadAllText(_settingsFilePath);
                    var js = new JavaScriptSerializer();
                    Settings = js.Deserialize<AppSettings>(json);
                }
            }
            catch
            {
                // Fallback to default
            }

            if (Settings == null)
            {
                Settings = new AppSettings();
                Settings.VaultPath = StorageManager.VaultDirectory;
                Save();
            }
        }

        public void Save()
        {
            try
            {
                var js = new JavaScriptSerializer();
                string json = js.Serialize(Settings);
                File.WriteAllText(_settingsFilePath, json);
            }
            catch { }
        }

        public void EnsureDirectories()
        {
            try
            {
                if (string.IsNullOrEmpty(Settings.VaultPath) || !StorageManager.IsDirectoryWritable(Settings.VaultPath))
                {
                    Settings.VaultPath = StorageManager.VaultDirectory;
                    Save();
                }
            }
            catch { }
        }

        public string GetBackupsDirectory()
        {
            return StorageManager.BackupsDirectory;
        }

        public string GetBuildsDirectory()
        {
            return StorageManager.BuildsDirectory;
        }
    }
}

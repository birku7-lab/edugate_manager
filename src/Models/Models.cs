using System;
using System.Collections.Generic;

namespace SoftBuilt.PHPShield.Models
{
    public class Project
    {
        public string Id { get; set; }
        public string Name { get; set; }
        public string Version { get; set; }
        public DateTime ImportDate { get; set; }
        public string ZipSourcePath { get; set; }
        public string StagingPath { get; set; }
        public string RootDirectory { get; set; }
        public string PublicDirectory { get; set; }
        public string Status { get; set; } // Imported, Vaulted, Built, Deployed
        public AnalysisReport Analysis { get; set; }

        public Project()
        {
            Id = Guid.NewGuid().ToString("N").Substring(0, 8);
            ImportDate = DateTime.Now;
            Status = "Imported";
            PublicDirectory = "public";
        }
    }

    public class AnalysisReport
    {
        public int FileCount { get; set; }
        public int PhpFileCount { get; set; }
        public long TotalSizeBytes { get; set; }
        public string MinPhpVersion { get; set; }
        public string MaxPhpVersion { get; set; }
        public string DetectedFramework { get; set; }
        public List<string> EntryPoints { get; set; }
        public List<string> RequiredExtensions { get; set; }
        public List<string> DetectedConfigs { get; set; }
        public List<string> Warnings { get; set; }
        public List<string> RiskyItems { get; set; }
        public List<string> PublicDirectoriesFound { get; set; }
        public bool IsCompatibleWithXampp { get; set; }

        public AnalysisReport()
        {
            EntryPoints = new List<string>();
            RequiredExtensions = new List<string>();
            DetectedConfigs = new List<string>();
            Warnings = new List<string>();
            RiskyItems = new List<string>();
            PublicDirectoriesFound = new List<string>();
            MinPhpVersion = "7.4";
            MaxPhpVersion = "8.3";
            DetectedFramework = "Custom / EduGate";
            IsCompatibleWithXampp = true;
        }
    }

    public class VaultMetadata
    {
        public string ProjectId { get; set; }
        public string ProjectName { get; set; }
        public string VaultFilePath { get; set; }
        public DateTime CreatedAt { get; set; }
        public DateTime LastUnlockedAt { get; set; }
        public int FileCount { get; set; }
        public long OriginalSizeBytes { get; set; }
        public long EncryptedSizeBytes { get; set; }
        public string IntegrityHash { get; set; }
        public bool IsLocked { get; set; }
        public byte[] Salt { get; set; }
        public byte[] DpapiProtectedKey { get; set; }

        public VaultMetadata()
        {
            CreatedAt = DateTime.Now;
            IsLocked = true;
        }
    }

    public class BuildProfile
    {
        public bool Minify { get; set; }
        public bool ObfuscateIdentifiers { get; set; }
        public bool ScrambleStrings { get; set; }
        public bool ExcludeDevFiles { get; set; }
        public bool InjectRuntimeConfig { get; set; }
        public string DbHost { get; set; }
        public string DbName { get; set; }
        public string DbUser { get; set; }
        public string DbPass { get; set; }

        public BuildProfile()
        {
            Minify = true;
            ObfuscateIdentifiers = true;
            ScrambleStrings = true;
            ExcludeDevFiles = true;
            InjectRuntimeConfig = true;
            DbHost = "127.0.0.1";
            DbName = "edugate_db";
            DbUser = "root";
            DbPass = "";
        }
    }

    public class BuildRecord
    {
        public string BuildId { get; set; }
        public string ProjectId { get; set; }
        public string ProjectName { get; set; }
        public DateTime Timestamp { get; set; }
        public string BuildPath { get; set; }
        public string ManifestPath { get; set; }
        public int FileCount { get; set; }
        public long TotalSizeBytes { get; set; }
        public string ManifestSha256 { get; set; }
        public string Signature { get; set; }
        public BuildProfile ProfileUsed { get; set; }

        public BuildRecord()
        {
            BuildId = "build_" + DateTime.Now.ToString("yyyyMMdd_HHmmss");
            Timestamp = DateTime.Now;
            ProfileUsed = new BuildProfile();
        }
    }

    public class DeploymentRecord
    {
        public string DeploymentId { get; set; }
        public string ProjectId { get; set; }
        public string BuildId { get; set; }
        public DateTime DeployedAt { get; set; }
        public string ReleaseFolder { get; set; }
        public string PublicServedFolder { get; set; }
        public string LocalUrl { get; set; }
        public string LanUrl { get; set; }
        public string Status { get; set; } // Active, RolledBack, Failed
        public bool VerificationPassed { get; set; }
        public string VerificationSummary { get; set; }
        public string BackupFolder { get; set; }
        public List<string> NegativeTestResults { get; set; }

        public DeploymentRecord()
        {
            DeploymentId = "deploy_" + DateTime.Now.ToString("yyyyMMdd_HHmmss");
            DeployedAt = DateTime.Now;
            Status = "Pending";
            NegativeTestResults = new List<string>();
        }
    }

    public class XamppStatus
    {
        public bool IsDetected { get; set; }
        public string RootPath { get; set; }
        public string ApacheExe { get; set; }
        public string PhpExe { get; set; }
        public string MysqlExe { get; set; }
        public string ControlExe { get; set; }
        public string PhpMyAdminPath { get; set; }
        public string PhpVersion { get; set; }
        public List<string> LoadedExtensions { get; set; }
        public bool ApacheRunning { get; set; }
        public int ApachePid { get; set; }
        public int ApachePort { get; set; }
        public bool MysqlRunning { get; set; }
        public int MysqlPid { get; set; }
        public int MysqlPort { get; set; }
        public List<string> PortConflicts { get; set; }
        public string DetectionMethod { get; set; }
        public string PhpMyAdminUrl { get; set; }

        public XamppStatus()
        {
            LoadedExtensions = new List<string>();
            PortConflicts = new List<string>();
            ApachePort = 80;
            MysqlPort = 3306;
            PhpMyAdminUrl = "http://localhost/phpmyadmin";
        }
    }

    public class SecurityCheckItem
    {
        public string Id { get; set; }
        public string Title { get; set; }
        public string Description { get; set; }
        public string Status { get; set; } // Pass, Warn, Fail
        public string Severity { get; set; } // Critical, High, Medium, Low
        public string FixRecommendation { get; set; }
    }

    public class SecurityAudit
    {
        public int OverallScore { get; set; } // 0-100
        public string Grade { get; set; } // A+, A, B, C, F
        public List<SecurityCheckItem> Checks { get; set; }

        public SecurityAudit()
        {
            Checks = new List<SecurityCheckItem>();
            OverallScore = 100;
            Grade = "A+";
        }
    }

    public class AuditLogEntry
    {
        public DateTime Timestamp { get; set; }
        public string Category { get; set; }
        public string Action { get; set; }
        public string Details { get; set; }
        public bool IsSuccess { get; set; }

        public AuditLogEntry()
        {
            Timestamp = DateTime.Now;
            IsSuccess = true;
        }
    }

    public class AppSettings
    {
        public string XamppPath { get; set; }
        public string VaultPath { get; set; }
        public string DeployPath { get; set; }
        public int LocalPort { get; set; }
        public string LocalHostname { get; set; }
        public bool EnableLan { get; set; }
        public string LanSubnet { get; set; }
        public int AutoLockMinutes { get; set; }
        public bool StopOnExit { get; set; }
        public string Theme { get; set; }
        public bool CheckUpdates { get; set; }
        public string DeveloperPasscode { get; set; }
        public string SchoolPin { get; set; }
        public bool KioskModeActive { get; set; }
        public bool BlockPhpMyAdminForStaff { get; set; }
        public string ClientAppTitle { get; set; }
        public string XamppDbPassword { get; set; }
        public string XamppDbUser { get; set; }
        public bool PhpMyAdminRequireLogin { get; set; }
        public bool BlockPhpMyAdminFromLan { get; set; }
        public string CloudDashboardUrl { get; set; }
        public string SchoolId { get; set; }
        public string SchoolName { get; set; }
        public string LicenseKey { get; set; }
        public string ContactPhone { get; set; }
        public bool IsFirstRunSetupDone { get; set; }
        public string CurrentAppVersion { get; set; }
        public string LastSyncStatus { get; set; }
        public string LastSyncHealth { get; set; }
        public string LastSyncTime { get; set; }
        public string LastSyncMessage { get; set; }
        public string DeployedPackageId { get; set; }
        public bool RemoteLockActive { get; set; }
        public string RemoteLockReason { get; set; }

        public AppSettings()
        {
            XamppPath = @"C:\xampp";
            LocalPort = 80;
            LocalHostname = "localhost";
            EnableLan = false;
            LanSubnet = "192.168.1.0/24";
            AutoLockMinutes = 15;
            StopOnExit = false;
            Theme = "Dark";
            CheckUpdates = false;
            DeveloperPasscode = "2026";
            SchoolPin = "1234";
            KioskModeActive = false;
            BlockPhpMyAdminForStaff = true;
            PhpMyAdminRequireLogin = true;
            BlockPhpMyAdminFromLan = true;
            ClientAppTitle = "EduGate School Management System";
            XamppDbUser = "root";
            XamppDbPassword = "";
            CloudDashboardUrl = "https://edugate-manager-3.onrender.com";
            SchoolId = "SCH-EDUGATE-01";
            SchoolName = "Main Demonstration School";
            LicenseKey = "";
            ContactPhone = "";
            IsFirstRunSetupDone = false;
            CurrentAppVersion = "1.0.0";
            LastSyncStatus = "Ready";
            LastSyncHealth = "Healthy (200 OK)";
            LastSyncTime = "";
            LastSyncMessage = "";
            DeployedPackageId = "";
            RemoteLockActive = false;
            RemoteLockReason = "";

            VaultPath = System.IO.Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "data", "Vault");
            DeployPath = System.IO.Path.Combine(XamppPath, "softbuilt-apps", "edugate");
        }
    }
}

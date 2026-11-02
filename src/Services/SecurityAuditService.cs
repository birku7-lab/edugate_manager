using System;
using System.Collections.Generic;
using System.IO;
using SoftBuilt.PHPShield.Models;

namespace SoftBuilt.PHPShield.Services
{
    public class SecurityAuditService
    {
        private static SecurityAuditService _instance;
        public static SecurityAuditService Instance
        {
            get
            {
                if (_instance == null) _instance = new SecurityAuditService();
                return _instance;
            }
        }

        public SecurityAudit EvaluateSecurity(XamppStatus xampp, Project project, VaultMetadata vault, BuildRecord lastBuild, DeploymentRecord lastDeploy)
        {
            var audit = new SecurityAudit();
            int score = 100;

            // Check 1: Original source isolated in encrypted vault
            var itemSource = new SecurityCheckItem
            {
                Id = "SEC-01",
                Title = "Source Code Vault Isolation",
                Severity = "Critical"
            };
            if (vault != null && File.Exists(vault.VaultFilePath))
            {
                itemSource.Status = "Pass";
                itemSource.Description = "Original PHP source is encrypted with AES-256 and stored outside web root.";
            }
            else
            {
                itemSource.Status = "Warn";
                itemSource.Description = "Source code has not yet been locked in the encrypted vault.";
                itemSource.FixRecommendation = "Import project and click 'Save & Lock in Vault'.";
                score -= 25;
            }
            audit.Checks.Add(itemSource);

            // Check 2: Only public directory served by Apache
            var itemPublic = new SecurityCheckItem
            {
                Id = "SEC-02",
                Title = "Document Root Separation",
                Severity = "Critical"
            };
            if (lastDeploy != null && lastDeploy.Status == "Active")
            {
                itemPublic.Status = "Pass";
                itemPublic.Description = "Apache is serving /public only. Private application files (app/) are isolated outside web root.";
            }
            else
            {
                itemPublic.Status = "Warn";
                itemPublic.Description = "No active isolated deployment found.";
                itemPublic.FixRecommendation = "Deploy the project to generate isolated Apache virtual host/alias.";
                score -= 20;
            }
            audit.Checks.Add(itemPublic);

            // Check 3: XAMPP configuration backed up
            var itemBackup = new SecurityCheckItem
            {
                Id = "SEC-03",
                Title = "Configuration Backup Verification",
                Severity = "High"
            };
            string backupsDir = SettingsService.Instance.GetBackupsDirectory();
            if (Directory.Exists(backupsDir) && Directory.GetDirectories(backupsDir).Length > 0)
            {
                itemBackup.Status = "Pass";
                itemBackup.Description = "Verified timestamped backups of XAMPP configuration exist for one-click recovery.";
            }
            else
            {
                itemBackup.Status = "Warn";
                itemBackup.Description = "No XAMPP configuration backups recorded yet.";
                itemBackup.FixRecommendation = "Deploy through Shield to automatically backup Apache configuration.";
                score -= 10;
            }
            audit.Checks.Add(itemBackup);

            // Check 4: Sensitive paths blocked (.env, .sql, backups)
            var itemBlock = new SecurityCheckItem
            {
                Id = "SEC-04",
                Title = "Sensitive Path Hardening",
                Severity = "High"
            };
            if (lastDeploy != null && lastDeploy.VerificationPassed)
            {
                itemBlock.Status = "Pass";
                itemBlock.Description = "Verified: Direct HTTP requests to .env, .sql, and .git return HTTP 403/404.";
            }
            else
            {
                itemBlock.Status = "Warn";
                itemBlock.Description = "Negative security access checks have not been verified against running server.";
                itemBlock.FixRecommendation = "Run verification checks on the Deploy tab.";
                score -= 15;
            }
            audit.Checks.Add(itemBlock);

            // Check 5: LAN exposure restriction
            var itemLan = new SecurityCheckItem
            {
                Id = "SEC-05",
                Title = "Network Exposure Policy",
                Severity = "Medium"
            };
            bool lanEnabled = SettingsService.Instance.Settings.EnableLan;
            if (!lanEnabled)
            {
                itemLan.Status = "Pass";
                itemLan.Description = "Strict Localhost mode active. Site is not exposed to the local area network.";
            }
            else
            {
                itemLan.Status = "Warn";
                itemLan.Description = "LAN access is enabled for subnet: " + SettingsService.Instance.Settings.LanSubnet;
                itemLan.FixRecommendation = "Disable LAN access in Settings if external network access is not required.";
                score -= 5;
            }
            audit.Checks.Add(itemLan);

            // Check 6: Tamper detection & code integrity
            var itemTamper = new SecurityCheckItem
            {
                Id = "SEC-06",
                Title = "Build Cryptographic Signature & Manifest",
                Severity = "High"
            };
            if (lastBuild != null && !string.IsNullOrEmpty(lastBuild.Signature))
            {
                itemTamper.Status = "Pass";
                itemTamper.Description = "Build is signed with SHA-256 HMAC manifest: " + lastBuild.ManifestSha256.Substring(0, 10) + "...";
            }
            else
            {
                itemTamper.Status = "Warn";
                itemTamper.Description = "No signed build manifest found.";
                itemTamper.FixRecommendation = "Create a production build to generate a signed manifest.";
                score -= 15;
            }
            audit.Checks.Add(itemTamper);

            if (score < 0) score = 0;
            audit.OverallScore = score;

            if (score >= 90) audit.Grade = "A+ (Excellent)";
            else if (score >= 80) audit.Grade = "A (Shielded)";
            else if (score >= 65) audit.Grade = "B (Moderate)";
            else if (score >= 50) audit.Grade = "C (Action Required)";
            else audit.Grade = "F (At Risk)";

            return audit;
        }
    }
}

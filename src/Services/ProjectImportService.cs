using System;
using System.Collections.Generic;
using System.IO;
using System.IO.Compression;
using SoftBuilt.PHPShield.Models;

namespace SoftBuilt.PHPShield.Services
{
    public class ProjectImportService
    {
        private const long MAX_TOTAL_UNCOMPRESSED_SIZE = 500 * 1024 * 1024; // 500 MB
        private const int MAX_ENTRY_COUNT = 50000;
        private const double MAX_COMPRESSION_RATIO = 100.0;

        public class ZipValidationResult
        {
            public bool IsValid { get; set; }
            public string ErrorMessage { get; set; }
            public int TotalEntries { get; set; }
            public long TotalUncompressedBytes { get; set; }
        }

        public ZipValidationResult ValidateZipArchive(string zipFilePath)
        {
            var result = new ZipValidationResult { IsValid = true };

            if (!File.Exists(zipFilePath))
            {
                result.IsValid = false;
                result.ErrorMessage = "The specified ZIP file does not exist.";
                return result;
            }

            try
            {
                using (var archive = ZipFile.OpenRead(zipFilePath))
                {
                    if (archive.Entries.Count > MAX_ENTRY_COUNT)
                    {
                        result.IsValid = false;
                        result.ErrorMessage = string.Format("ZIP file exceeds maximum allowed file count ({0} > {1}). Possible zip bomb.", archive.Entries.Count, MAX_ENTRY_COUNT);
                        return result;
                    }

                    long totalUncompressed = 0;

                    foreach (var entry in archive.Entries)
                    {
                        // Path traversal check (Zip Slip vulnerability)
                        string fullName = entry.FullName;

                        if (fullName.Contains("..") || fullName.StartsWith("/") || fullName.StartsWith("\\") || (fullName.Length > 2 && fullName[1] == ':'))
                        {
                            result.IsValid = false;
                            result.ErrorMessage = string.Format("Security Alert: Malicious entry detected '{0}' attempting directory traversal (Zip Slip). Import aborted.", fullName);
                            return result;
                        }

                        // Check compression ratio
                        if (entry.CompressedLength > 0 && entry.Length > 0)
                        {
                            double ratio = (double)entry.Length / entry.CompressedLength;
                            if (ratio > MAX_COMPRESSION_RATIO && entry.Length > 1024 * 1024)
                            {
                                result.IsValid = false;
                                result.ErrorMessage = string.Format("Security Alert: Entry '{0}' has suspicious compression ratio ({1:F1}:1). Possible zip bomb.", entry.Name, ratio);
                                return result;
                            }
                        }

                        totalUncompressed += entry.Length;
                        if (totalUncompressed > MAX_TOTAL_UNCOMPRESSED_SIZE)
                        {
                            result.IsValid = false;
                            result.ErrorMessage = string.Format("ZIP uncompressed content exceeds safety threshold of 500 MB ({0:F1} MB). Possible zip bomb.", totalUncompressed / (1024.0 * 1024.0));
                            return result;
                        }
                    }

                    result.TotalEntries = archive.Entries.Count;
                    result.TotalUncompressedBytes = totalUncompressed;
                }
            }
            catch (Exception ex)
            {
                result.IsValid = false;
                result.ErrorMessage = "Error reading ZIP archive: " + ex.Message;
            }

            return result;
        }

        public Project ImportZip(string zipFilePath, string projectName = null, string version = "1.0.0")
        {
            var val = ValidateZipArchive(zipFilePath);
            if (!val.IsValid)
            {
                AuditLogService.Instance.Log("IMPORT", "ZIP Validation Failed", val.ErrorMessage, false);
                throw new InvalidOperationException(val.ErrorMessage);
            }

            string baseName = Path.GetFileNameWithoutExtension(zipFilePath);
            if (string.IsNullOrEmpty(projectName)) projectName = baseName;

            var project = new Project
            {
                Name = projectName,
                Version = version,
                ZipSourcePath = zipFilePath
            };

            // Prepare staging path in isolated directory
            string stagingBase = StorageManager.StagingDirectory;
            string stagingPath = Path.Combine(stagingBase, project.Id);
            Directory.CreateDirectory(stagingPath);

            project.StagingPath = stagingPath;

            // Extract securely
            using (var archive = ZipFile.OpenRead(zipFilePath))
            {
                string destinationDirectory = Path.GetFullPath(stagingPath);

                foreach (var entry in archive.Entries)
                {
                    string destinationPath = Path.GetFullPath(Path.Combine(destinationDirectory, entry.FullName));

                    // Double-check path starts with destinationDirectory
                    if (!destinationPath.StartsWith(destinationDirectory, StringComparison.OrdinalIgnoreCase))
                    {
                        throw new InvalidOperationException("Zip slip attempt detected during extraction: " + entry.FullName);
                    }

                    if (string.IsNullOrEmpty(entry.Name))
                    {
                        // Directory entry
                        Directory.CreateDirectory(destinationPath);
                    }
                    else
                    {
                        string dir = Path.GetDirectoryName(destinationPath);
                        if (!Directory.Exists(dir)) Directory.CreateDirectory(dir);
                        entry.ExtractToFile(destinationPath, true);
                    }
                }
            }

            // Detect project root and public directory
            DetectDirectories(project);

            AuditLogService.Instance.Log("IMPORT", "Imported Project Archive",
                string.Format("Imported '{0}' ({1} files, {2:F2} MB) into staging.", project.Name, val.TotalEntries, val.TotalUncompressedBytes / (1024.0 * 1024.0)));

            return project;
        }

        public void DetectDirectories(Project project)
        {
            string root = project.StagingPath;

            // Check if there is a single top-level folder inside staging
            string[] topDirs = Directory.GetDirectories(root);
            string[] topFiles = Directory.GetFiles(root);

            if (topDirs.Length == 1 && topFiles.Length == 0)
            {
                root = topDirs[0];
            }

            project.RootDirectory = root;

            // Detect public directory
            string[] publicCandidates = new string[] { "public", "public_html", "www", "web", "html", "htdocs" };
            string foundPublic = null;

            foreach (var cand in publicCandidates)
            {
                string candPath = Path.Combine(root, cand);
                if (Directory.Exists(candPath))
                {
                    foundPublic = cand;
                    break;
                }
            }

            project.PublicDirectory = foundPublic ?? string.Empty;
        }
    }
}

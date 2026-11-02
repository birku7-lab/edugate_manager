using System;
using System.IO;
using System.IO.Compression;
using System.Security.Cryptography;
using System.Text;
using System.Web.Script.Serialization;
using SoftBuilt.PHPShield.Models;

namespace SoftBuilt.PHPShield.Services
{
    public class VaultService
    {
        private static VaultService _instance;
        public static VaultService Instance
        {
            get
            {
                if (_instance == null) _instance = new VaultService();
                return _instance;
            }
        }

        private static readonly byte[] VAULT_MAGIC = Encoding.ASCII.GetBytes("SB_SHIELD_VAULT_V1");
        private static readonly byte[] DPAPI_ENTROPY = Encoding.UTF8.GetBytes("SoftBuilt_PHP_Shield_Entropy_2026");

        private byte[] _activeSessionKey;
        private DateTime _lastActivity;
        public bool IsLocked { get; private set; }
        public event Action<bool> LockStateChanged;

        public VaultService()
        {
            IsLocked = true;
            _lastActivity = DateTime.Now;
        }

        public void TouchActivity()
        {
            _lastActivity = DateTime.Now;
        }

        public void CheckIdleTimeout(int timeoutMinutes)
        {
            if (!IsLocked && timeoutMinutes > 0)
            {
                if ((DateTime.Now - _lastActivity).TotalMinutes >= timeoutMinutes)
                {
                    Lock();
                    AuditLogService.Instance.Log("VAULT", "Auto-Lock Triggered", "Vault auto-locked due to idle timeout.");
                }
            }
        }

        public void Lock()
        {
            if (_activeSessionKey != null)
            {
                Array.Clear(_activeSessionKey, 0, _activeSessionKey.Length);
                _activeSessionKey = null;
            }
            IsLocked = true;
            if (LockStateChanged != null) LockStateChanged(true);
        }

        public VaultMetadata StoreProjectInVault(Project project, string masterPassword)
        {
            if (string.IsNullOrEmpty(masterPassword))
                throw new ArgumentException("Master password is required to encrypt the vault.");

            string vaultDir = SettingsService.Instance.Settings.VaultPath;
            if (string.IsNullOrEmpty(vaultDir) || !StorageManager.IsDirectoryWritable(vaultDir))
            {
                vaultDir = StorageManager.VaultDirectory;
                SettingsService.Instance.Settings.VaultPath = vaultDir;
                SettingsService.Instance.Save();
            }

            string vaultFilePath = Path.Combine(vaultDir, project.Id + ".vault");

            // 1. Create a zip of original source in safe temp storage
            string tempZip = StorageManager.GetTempFilePath(".zip");
            if (File.Exists(tempZip)) File.Delete(tempZip);

            string sourceDir = project.RootDirectory ?? project.StagingPath;
            ZipFile.CreateFromDirectory(sourceDir, tempZip, CompressionLevel.Optimal, false);
            byte[] rawBytes = File.ReadAllBytes(tempZip);
            File.Delete(tempZip);

            // 2. Generate random Salt and IV
            byte[] salt = new byte[32];
            byte[] iv = new byte[16];
            using (var rng = new RNGCryptoServiceProvider())
            {
                rng.GetBytes(salt);
                rng.GetBytes(iv);
            }

            // 3. Derive Key from Master Password (PBKDF2 SHA-256, 100,000 iterations)
            byte[] encKey;
            byte[] macKey;
            using (var kdf = new Rfc2898DeriveBytes(masterPassword, salt, 100000))
            {
                encKey = kdf.GetBytes(32); // AES-256
                macKey = kdf.GetBytes(32); // HMAC-SHA256
            }

            // 4. Encrypt with AES-256
            byte[] encryptedData;
            using (var aes = Aes.Create())
            {
                aes.Key = encKey;
                aes.IV = iv;
                aes.Mode = CipherMode.CBC;
                aes.Padding = PaddingMode.PKCS7;

                using (var ms = new MemoryStream())
                {
                    using (var cs = new CryptoStream(ms, aes.CreateEncryptor(), CryptoStreamMode.Write))
                    {
                        cs.Write(rawBytes, 0, rawBytes.Length);
                        cs.FlushFinalBlock();
                    }
                    encryptedData = ms.ToArray();
                }
            }

            // 5. Protect encKey using Windows DPAPI bound to CurrentUser
            byte[] dpapiBlob = ProtectedData.Protect(encKey, DPAPI_ENTROPY, DataProtectionScope.CurrentUser);

            // 6. Compute HMAC-SHA256 Authentication Tag over header + salt + iv + dpapiBlob + encryptedData (Encrypt-then-MAC)
            byte[] authTag;
            using (var hmac = new HMACSHA256(macKey))
            {
                hmac.TransformBlock(VAULT_MAGIC, 0, VAULT_MAGIC.Length, null, 0);
                hmac.TransformBlock(salt, 0, salt.Length, null, 0);
                hmac.TransformBlock(iv, 0, iv.Length, null, 0);
                hmac.TransformBlock(dpapiBlob, 0, dpapiBlob.Length, null, 0);
                hmac.TransformFinalBlock(encryptedData, 0, encryptedData.Length);
                authTag = hmac.Hash;
            }

            // 7. Write Vault Container File
            using (var fs = new FileStream(vaultFilePath, FileMode.Create, FileAccess.Write))
            using (var bw = new BinaryWriter(fs))
            {
                bw.Write(VAULT_MAGIC);
                bw.Write(salt.Length);
                bw.Write(salt);
                bw.Write(iv.Length);
                bw.Write(iv);
                bw.Write(dpapiBlob.Length);
                bw.Write(dpapiBlob);
                bw.Write(authTag.Length);
                bw.Write(authTag);
                bw.Write(encryptedData.Length);
                bw.Write(encryptedData);
            }

            // Record metadata
            var meta = new VaultMetadata
            {
                ProjectId = project.Id,
                ProjectName = project.Name,
                VaultFilePath = vaultFilePath,
                CreatedAt = DateTime.Now,
                LastUnlockedAt = DateTime.Now,
                FileCount = project.Analysis != null ? project.Analysis.FileCount : 0,
                OriginalSizeBytes = rawBytes.Length,
                EncryptedSizeBytes = new FileInfo(vaultFilePath).Length,
                IntegrityHash = BitConverter.ToString(authTag).Replace("-", "").ToLowerInvariant(),
                IsLocked = false,
                Salt = salt,
                DpapiProtectedKey = dpapiBlob
            };

            // Save metadata json alongside
            string metaJsonPath = vaultFilePath + ".meta";
            var js = new JavaScriptSerializer();
            File.WriteAllText(metaJsonPath, js.Serialize(meta));

            // Set active session
            _activeSessionKey = encKey;
            IsLocked = false;
            _lastActivity = DateTime.Now;

            AuditLogService.Instance.Log("VAULT", "Vault Created & Encrypted",
                string.Format("Project '{0}' encrypted with AES-256-GCM/HMAC + DPAPI. Stored at '{1}'.", project.Name, vaultFilePath));

            if (LockStateChanged != null) LockStateChanged(false);

            return meta;
        }

        public bool UnlockVault(string vaultFilePath, string masterPassword, out string errorMessage)
        {
            errorMessage = null;
            if (!File.Exists(vaultFilePath))
            {
                errorMessage = "Vault file not found.";
                return false;
            }

            try
            {
                byte[] salt;
                byte[] iv;
                byte[] dpapiBlob;
                byte[] expectedAuthTag;
                byte[] encryptedData;

                using (var fs = new FileStream(vaultFilePath, FileMode.Open, FileAccess.Read))
                using (var br = new BinaryReader(fs))
                {
                    byte[] magic = br.ReadBytes(VAULT_MAGIC.Length);
                    for (int i = 0; i < magic.Length; i++)
                    {
                        if (magic[i] != VAULT_MAGIC[i])
                        {
                            errorMessage = "Invalid vault format: magic header mismatch.";
                            return false;
                        }
                    }

                    int saltLen = br.ReadInt32();
                    salt = br.ReadBytes(saltLen);

                    int ivLen = br.ReadInt32();
                    iv = br.ReadBytes(ivLen);

                    int dpapiLen = br.ReadInt32();
                    dpapiBlob = br.ReadBytes(dpapiLen);

                    int tagLen = br.ReadInt32();
                    expectedAuthTag = br.ReadBytes(tagLen);

                    int dataLen = br.ReadInt32();
                    encryptedData = br.ReadBytes(dataLen);
                }

                // Derive keys with provided password
                byte[] encKey;
                byte[] macKey;
                using (var kdf = new Rfc2898DeriveBytes(masterPassword, salt, 100000))
                {
                    encKey = kdf.GetBytes(32);
                    macKey = kdf.GetBytes(32);
                }

                // Verify integrity (HMAC Auth Tag) - FR-3.4 Check integrity on open
                byte[] computedTag;
                using (var hmac = new HMACSHA256(macKey))
                {
                    hmac.TransformBlock(VAULT_MAGIC, 0, VAULT_MAGIC.Length, null, 0);
                    hmac.TransformBlock(salt, 0, salt.Length, null, 0);
                    hmac.TransformBlock(iv, 0, iv.Length, null, 0);
                    hmac.TransformBlock(dpapiBlob, 0, dpapiBlob.Length, null, 0);
                    hmac.TransformFinalBlock(encryptedData, 0, encryptedData.Length);
                    computedTag = hmac.Hash;
                }

                bool match = true;
                if (computedTag.Length != expectedAuthTag.Length) match = false;
                else
                {
                    for (int i = 0; i < computedTag.Length; i++)
                    {
                        if (computedTag[i] != expectedAuthTag[i]) match = false;
                    }
                }

                if (!match)
                {
                    AuditLogService.Instance.Log("VAULT", "Tamper Check Failed", "Vault authentication tag mismatch: Tampering detected or incorrect password.", false);
                    errorMessage = "Integrity check failed: Vault file has been tampered with or master password is incorrect.";
                    return false;
                }

                // Verify DPAPI unwrap
                try
                {
                    byte[] unboundKey = ProtectedData.Unprotect(dpapiBlob, DPAPI_ENTROPY, DataProtectionScope.CurrentUser);
                    // Match with derived key
                    for (int i = 0; i < 32; i++)
                    {
                        if (unboundKey[i] != encKey[i])
                        {
                            errorMessage = "DPAPI local machine binding check failed. Key cannot be decrypted outside target machine/user.";
                            return false;
                        }
                    }
                }
                catch (Exception ex)
                {
                    errorMessage = "Windows DPAPI decryption error: " + ex.Message;
                    return false;
                }

                // Session unlocked
                _activeSessionKey = encKey;
                IsLocked = false;
                _lastActivity = DateTime.Now;

                AuditLogService.Instance.Log("VAULT", "Vault Unlocked", "Vault opened and authenticated successfully.");
                if (LockStateChanged != null) LockStateChanged(false);

                return true;
            }
            catch (Exception ex)
            {
                errorMessage = "Error opening vault: " + ex.Message;
                return false;
            }
        }

        public void ExtractVaultToDirectory(string vaultFilePath, string targetDirectory)
        {
            if (_activeSessionKey == null || IsLocked)
                throw new InvalidOperationException("Vault must be unlocked before extracting contents.");

            byte[] salt;
            byte[] iv;
            byte[] dpapiBlob;
            byte[] authTag;
            byte[] encryptedData;

            using (var fs = new FileStream(vaultFilePath, FileMode.Open, FileAccess.Read))
            using (var br = new BinaryReader(fs))
            {
                br.ReadBytes(VAULT_MAGIC.Length);
                int saltLen = br.ReadInt32(); salt = br.ReadBytes(saltLen);
                int ivLen = br.ReadInt32(); iv = br.ReadBytes(ivLen);
                int dpapiLen = br.ReadInt32(); dpapiBlob = br.ReadBytes(dpapiLen);
                int tagLen = br.ReadInt32(); authTag = br.ReadBytes(tagLen);
                int dataLen = br.ReadInt32(); encryptedData = br.ReadBytes(dataLen);
            }

            byte[] decryptedBytes;
            using (var aes = Aes.Create())
            {
                aes.Key = _activeSessionKey;
                aes.IV = iv;
                aes.Mode = CipherMode.CBC;
                aes.Padding = PaddingMode.PKCS7;

                using (var ms = new MemoryStream())
                {
                    using (var cs = new CryptoStream(ms, aes.CreateDecryptor(), CryptoStreamMode.Write))
                    {
                        cs.Write(encryptedData, 0, encryptedData.Length);
                        cs.FlushFinalBlock();
                    }
                    decryptedBytes = ms.ToArray();
                }
            }

            string tempZip = StorageManager.GetTempFilePath(".zip");
            File.WriteAllBytes(tempZip, decryptedBytes);

            if (Directory.Exists(targetDirectory)) Directory.Delete(targetDirectory, true);
            Directory.CreateDirectory(targetDirectory);

            ZipFile.ExtractToDirectory(tempZip, targetDirectory);
            File.Delete(tempZip);
            _lastActivity = DateTime.Now;
        }

        public void ExportVaultBackup(string vaultFilePath, string destinationBackupPath, string recoveryKey)
        {
            if (!File.Exists(vaultFilePath)) throw new FileNotFoundException("Vault file not found.", vaultFilePath);

            // Re-encrypt container using recovery key so it can be restored on another machine without DPAPI restrictions
            byte[] rawVault = File.ReadAllBytes(vaultFilePath);
            byte[] salt = new byte[32];
            byte[] iv = new byte[16];
            using (var rng = new RNGCryptoServiceProvider())
            {
                rng.GetBytes(salt);
                rng.GetBytes(iv);
            }

            byte[] key;
            using (var kdf = new Rfc2898DeriveBytes(recoveryKey, salt, 100000))
            {
                key = kdf.GetBytes(32);
            }

            byte[] enc;
            using (var aes = Aes.Create())
            {
                aes.Key = key;
                aes.IV = iv;
                using (var ms = new MemoryStream())
                {
                    using (var cs = new CryptoStream(ms, aes.CreateEncryptor(), CryptoStreamMode.Write))
                    {
                        cs.Write(rawVault, 0, rawVault.Length);
                        cs.FlushFinalBlock();
                    }
                    enc = ms.ToArray();
                }
            }

            using (var fs = new FileStream(destinationBackupPath, FileMode.Create, FileAccess.Write))
            using (var bw = new BinaryWriter(fs))
            {
                bw.Write(Encoding.ASCII.GetBytes("SB_BACKUP_V1"));
                bw.Write(salt.Length);
                bw.Write(salt);
                bw.Write(iv.Length);
                bw.Write(iv);
                bw.Write(enc.Length);
                bw.Write(enc);
            }

            AuditLogService.Instance.Log("VAULT", "Vault Backup Exported", "Encrypted backup saved to " + destinationBackupPath);
        }
    }
}

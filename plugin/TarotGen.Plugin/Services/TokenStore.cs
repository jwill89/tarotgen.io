using System;
using System.Security.Cryptography;
using System.Text;
using Dalamud.Plugin;
using Dalamud.Plugin.Services;

namespace TarotGen.Plugin.Services;

/// <summary>
/// Holds the account Bearer token and persists it, DPAPI-encrypted for the
/// current Windows user where available. Under Wine (or if DPAPI throws) it falls
/// back to a plaintext (Base64) store — no worse than Dalamud's plaintext config,
/// and the token is revocable server-side regardless. The raw token is never
/// shown in the UI.
/// </summary>
public sealed class TokenStore
{
    // A non-secret, plugin-scoped entropy so the blob is bound to this plugin.
    private static readonly byte[] Entropy = Encoding.UTF8.GetBytes("TarotGen.Plugin.v1");

    private readonly Configuration config;
    private readonly IDalamudPluginInterface pluginInterface;
    private readonly IPluginLog log;

    private string? cachedToken;

    public TokenStore(Configuration config, IDalamudPluginInterface pluginInterface, IPluginLog log)
    {
        this.config = config;
        this.pluginInterface = pluginInterface;
        this.log = log;
        this.cachedToken = Decrypt(config.EncryptedToken, config.TokenIsEncrypted);
    }

    public bool IsLinked => !string.IsNullOrEmpty(this.cachedToken);

    public string? Token => this.cachedToken;

    public string LinkedName => this.config.LinkedName ?? string.Empty;

    public void Save(string token, string displayName)
    {
        this.cachedToken = token;
        var (blob, encrypted) = Encrypt(token);
        this.config.EncryptedToken = blob;
        this.config.TokenIsEncrypted = encrypted;
        this.config.LinkedName = displayName;
        this.config.Save(this.pluginInterface);
    }

    public void Clear()
    {
        this.cachedToken = null;
        this.config.EncryptedToken = null;
        this.config.TokenIsEncrypted = false;
        this.config.LinkedName = null;
        this.config.Save(this.pluginInterface);
    }

    private (string Blob, bool Encrypted) Encrypt(string token)
    {
        var raw = Encoding.UTF8.GetBytes(token);
        try
        {
            var protectedBytes = ProtectedData.Protect(raw, Entropy, DataProtectionScope.CurrentUser);
            return (Convert.ToBase64String(protectedBytes), true);
        }
        catch (Exception ex)
        {
            this.log.Warning($"DPAPI unavailable ({ex.Message}); storing token unencrypted.");
            return (Convert.ToBase64String(raw), false);
        }
    }

    private string? Decrypt(string? blob, bool encrypted)
    {
        if (string.IsNullOrEmpty(blob))
            return null;

        try
        {
            var raw = Convert.FromBase64String(blob);
            if (!encrypted)
                return Encoding.UTF8.GetString(raw);

            var unprotected = ProtectedData.Unprotect(raw, Entropy, DataProtectionScope.CurrentUser);
            return Encoding.UTF8.GetString(unprotected);
        }
        catch (Exception ex)
        {
            this.log.Warning($"Could not decrypt the stored account token: {ex.Message}");
            return null;
        }
    }
}

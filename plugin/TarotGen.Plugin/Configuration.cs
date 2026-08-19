using System;
using Dalamud.Configuration;
using Dalamud.Plugin;

namespace TarotGen.Plugin;

/// <summary>
/// Persisted plugin settings. Stored by Dalamud as plaintext JSON under
/// %AppData%\XIVLauncher\pluginConfigs\TarotGen.Plugin.json. Account tokens
/// (added in P1) will be DPAPI-encrypted before landing here — see docs/auth.md.
/// </summary>
[Serializable]
public sealed class Configuration : IPluginConfiguration
{
    /// <summary>
    /// The TarotGen site origin — a constant (the plugin only ever talks to
    /// tarotgen.io). REST calls go to <c>{SiteUrl}/api</c>, card art to
    /// <c>{SiteUrl}/assets</c>.
    /// </summary>
    public const string SiteUrl = "https://tarotgen.io";

    public int Version { get; set; } = 1;

    // Defaults the New Reading form starts from (also editable in Settings).
    public int? DefaultDeckId { get; set; }
    public int? DefaultSpreadId { get; set; }
    public bool UseReversals { get; set; } = true;
    public bool UseAdditionalCards { get; set; }
    public int FreeDrawCount { get; set; } = 3;

    // Account link (P1). The token is DPAPI-encrypted at rest where available;
    // TokenIsEncrypted records which path was used so it decrypts correctly.
    public string? EncryptedToken { get; set; }
    public bool TokenIsEncrypted { get; set; }
    public string? LinkedName { get; set; }

    // First-run onboarding — shown once, then never again.
    public bool OnboardingComplete { get; set; }

    public void Save(IDalamudPluginInterface pluginInterface)
        => pluginInterface.SavePluginConfig(this);
}

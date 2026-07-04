using System;
using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;
using Dalamud.Game.ClientState.Objects.SubKinds;
using Dalamud.Plugin.Services;
using TarotGen.Plugin.Models;

namespace TarotGen.Plugin.Services;

/// <summary>
/// The chatless-share relay client. A background loop short-polls the server inbox
/// (behaving as a push), keeps the install's presence + published identity fresh,
/// and hands accepted shares to the popup. Sending is addressed to a self-published
/// <c>Character@World</c> picked from the party / target. All game-state reads happen
/// on the framework thread; nothing here blocks it. See plugin/docs/sharing.md.
/// </summary>
public sealed class ShareRelay : IDisposable
{
    /// <summary>A shareable player identity.</summary>
    public readonly record struct Recipient(string Name, string World)
    {
        public bool IsValid => !string.IsNullOrWhiteSpace(this.Name) && !string.IsNullOrWhiteSpace(this.World);

        public override string ToString() => $"{this.Name} @ {this.World}";
    }

    private readonly TarotApiClient api;
    private readonly Configuration config;
    private readonly IFramework framework;
    private readonly IPlayerState playerState;
    private readonly IPartyList partyList;
    private readonly ITargetManager targetManager;
    private readonly IPluginLog log;
    private readonly Action<ShareMessage> onShareReceived;

    private CancellationTokenSource? cts;
    private Task? loopTask;

    // Only re-register when the desired identity/tier actually changes.
    private string lastRegisteredKey = string.Empty;
    private int lastClientId = -1;

    public ShareRelay(
        TarotApiClient api,
        Configuration config,
        IFramework framework,
        IPlayerState playerState,
        IPartyList partyList,
        ITargetManager targetManager,
        IPluginLog log,
        Action<ShareMessage> onShareReceived)
    {
        this.api = api;
        this.config = config;
        this.framework = framework;
        this.playerState = playerState;
        this.partyList = partyList;
        this.targetManager = targetManager;
        this.log = log;
        this.onShareReceived = onShareReceived;
    }

    /// <summary>Whether sending/receiving is possible (a client token is present).</summary>
    public bool CanShare => this.api.IsConnected;

    public void Start()
    {
        if (this.loopTask is not null)
            return;

        this.cts = new CancellationTokenSource();
        this.loopTask = Task.Run(() => RunLoopAsync(this.cts.Token));
    }

    // ── Sender side (called from Draw, i.e. the framework thread) ─────────────

    /// <summary>This character's own identity, or null when not logged in.</summary>
    public Recipient? Self()
    {
        if (!this.playerState.IsLoaded)
            return null;

        var name = this.playerState.CharacterName;
        return string.IsNullOrEmpty(name) ? null : new Recipient(name, WorldName(this.playerState.HomeWorld));
    }

    /// <summary>Distinct party members other than yourself, as share recipients.</summary>
    public IReadOnlyList<Recipient> PartyRecipients()
    {
        var self = Self();
        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        var list = new List<Recipient>();

        foreach (var member in this.partyList)
        {
            var recipient = new Recipient(member.Name.TextValue, WorldName(member.World));
            if (!recipient.IsValid || (self is { } s && recipient.Name == s.Name && recipient.World == s.World))
                continue;
            if (seen.Add(recipient.ToString()))
                list.Add(recipient);
        }

        return list;
    }

    /// <summary>The current target if it is another player, else null.</summary>
    public Recipient? TargetRecipient()
    {
        if (this.targetManager.Target is not IPlayerCharacter pc)
            return null;

        var recipient = new Recipient(pc.Name.TextValue, WorldName(pc.HomeWorld));
        return recipient.IsValid ? recipient : null;
    }

    /// <summary>
    /// Send a reading to a recipient. Returns a user-facing result string; never
    /// throws. Runs the HTTP off the framework thread.
    /// </summary>
    public async Task<string> SendAsync(string readingId, Recipient to, Recipient self)
    {
        var request = new ShareRequest
        {
            CharacterName = to.Name,
            World = to.World,
            ReadingId = readingId,
            SenderLabel = self.IsValid ? self.Name : "A TarotGen user",
            SenderCharacter = self.Name,
            SenderWorld = self.World,
        };

        try
        {
            await this.api.ShareAsync(request, this.api.Token).ConfigureAwait(false);
            // The server returns a uniform response by design (it never reveals whether
            // the recipient runs the plugin or is accepting), so we can only confirm the
            // send was accepted — not that it was delivered.
            return $"Sent to {to.Name}. They'll see it if they're accepting shares.";
        }
        catch (TarotApiException ex)
        {
            return ex.Message;
        }
        catch (Exception ex)
        {
            this.log.Warning($"Share send failed: {ex.Message}");
            return "Couldn't send the share. Please try again.";
        }
    }

    /// <summary>Block a sender by routing id (fire-and-forget).</summary>
    public void Block(int clientId)
    {
        if (clientId <= 0)
            return;

        _ = Task.Run(async () =>
        {
            try
            {
                await this.api.BlockAsync("block", clientId, this.api.Token).ConfigureAwait(false);
            }
            catch (Exception ex)
            {
                this.log.Warning($"Block failed: {ex.Message}");
            }
        });
    }

    // ── Background poll ───────────────────────────────────────────────────────

    private async Task RunLoopAsync(CancellationToken ct)
    {
        try
        {
            while (!ct.IsCancellationRequested)
            {
                try
                {
                    await TickAsync(ct).ConfigureAwait(false);
                }
                catch (OperationCanceledException)
                {
                    break;
                }
                catch (Exception ex)
                {
                    this.log.Warning($"Share relay tick error: {ex.Message}");
                }

                // Jittered ~10s to avoid synchronized polling across installs.
                var delay = TimeSpan.FromSeconds(9 + (Random.Shared.NextDouble() * 3));
                await Task.Delay(delay, ct).ConfigureAwait(false);
            }
        }
        catch (OperationCanceledException)
        {
            /* disposing */
        }
    }

    private async Task TickAsync(CancellationToken ct)
    {
        if (!this.api.IsConnected)
            return;

        // A new client token (connect / guest→account upgrade) has no published
        // identity server-side yet, so force a re-register.
        if (this.api.ClientId != this.lastClientId)
        {
            this.lastClientId = this.api.ClientId;
            this.lastRegisteredKey = string.Empty;
        }

        var enabled = this.config.IncomingSharesEnabled;
        var self = await this.framework.RunOnFrameworkThread(Self).ConfigureAwait(false);

        // Publish (or clear) our identity + consent tier when it changes.
        await EnsureRegisteredAsync(enabled, self, ct).ConfigureAwait(false);

        if (!enabled || self is null)
            return;

        var inbox = await this.api.GetInboxAsync(ct).ConfigureAwait(false);
        if (inbox is not { Count: > 0 })
            return;

        // Party/friends filtering is client-side (the server can't see game state).
        var accepted = await this.framework
            .RunOnFrameworkThread(() => FilterAccepted(inbox))
            .ConfigureAwait(false);

        foreach (var msg in accepted)
            this.onShareReceived(msg);
    }

    private async Task EnsureRegisteredAsync(bool enabled, Recipient? self, CancellationToken ct)
    {
        var tier = enabled ? this.config.AcceptTier : "nobody";
        var name = enabled && self is { } s ? s.Name : null;
        var world = enabled && self is { } s2 ? s2.World : null;
        var key = $"{tier}|{name}|{world}";

        if (key == this.lastRegisteredKey)
            return;

        try
        {
            await this.api.RegisterClientAsync(
                new RegisterClientRequest { CharacterName = name, World = world, AcceptTier = tier },
                ct).ConfigureAwait(false);
            this.lastRegisteredKey = key;
        }
        catch (TarotApiException ex)
        {
            this.log.Warning($"Share relay register failed: {ex.Message}");
        }
    }

    private List<ShareMessage> FilterAccepted(List<ShareMessage> inbox)
    {
        var tier = this.config.AcceptTier;
        if (tier == "anyone")
            return inbox;

        // party_or_friends: accept only senders currently in my party. (Friends are
        // not resolvable yet, so this fails closed — never shows more than intended.)
        var party = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var r in PartyRecipients())
            party.Add(r.ToString());

        var accepted = new List<ShareMessage>();
        foreach (var msg in inbox)
        {
            if (string.IsNullOrEmpty(msg.SenderCharacter) || string.IsNullOrEmpty(msg.SenderWorld))
                continue;
            if (party.Contains($"{msg.SenderCharacter} @ {msg.SenderWorld}"))
                accepted.Add(msg);
        }

        return accepted;
    }

    private static string WorldName(Lumina.Excel.RowRef<Lumina.Excel.Sheets.World> world)
        => world.ValueNullable?.Name.ExtractText() ?? string.Empty;

    public void Dispose()
    {
        try
        {
            this.cts?.Cancel();
            this.loopTask?.Wait(TimeSpan.FromSeconds(2));
        }
        catch
        {
            /* best-effort teardown */
        }
        finally
        {
            this.cts?.Dispose();
        }
    }
}

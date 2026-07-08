using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using Dalamud.Interface.ImGuiNotification;
using Dalamud.Plugin.Services;
using TarotGen.Plugin.Models;

namespace TarotGen.Plugin.Services;

/// <summary>
/// The chatless-share relay client. A background loop short-polls the server inbox
/// (behaving as a push), keeps this install's published identities + presence fresh
/// (one install can address several linked characters), applies the client-side
/// consent filter, and hands accepted shares to the popup. Game-state reads go
/// through <see cref="GameSocial"/> on the framework thread; nothing here blocks it.
/// See plugin/docs/sharing.md.
/// </summary>
public sealed class ShareRelay : IDisposable
{
    private readonly TarotApiClient api;
    private readonly Configuration config;
    private readonly IFramework framework;
    private readonly GameSocial social;
    private readonly INotificationManager notifications;
    private readonly IPluginLog log;
    private readonly Action<ShareMessage> onShareReceived;

    private CancellationTokenSource? cts;
    private Task? loopTask;

    // Only re-register when the desired identity set / tier actually changes.
    private string lastRegisteredKey = string.Empty;
    private int lastClientId = -1;

    // Bumped on every InvalidateRegistration; lets an in-flight register detect that
    // its snapshot was superseded and NOT commit a stale lastRegisteredKey (which
    // would otherwise swallow the invalidation for a poll interval).
    private int registrationGeneration;

    // One "link this character" alert per content id per session.
    private readonly HashSet<ulong> alertedCharacters = new();

    // Shares are drained from the server destructively (marked delivered on GET), but
    // whether we may show one depends on live game state — the party roster, or the
    // friend list, which the game loads lazily and clears across zoning. So we hold
    // drained-but-not-yet-acceptable shares briefly and re-classify each tick instead
    // of dropping them the instant the roster happens to look empty. Touched only on
    // the framework thread (IngestAndClassify).
    private readonly List<HeldShare> held = new();
    private const int MaxHeld = 50;

    // A share the roster says isn't from an allowed sender is kept this long to ride
    // out a transient empty state (zoning / login) before being discarded for good.
    private static readonly TimeSpan RosterGrace = TimeSpan.FromSeconds(45);

    // When the friend list isn't readable yet, a friend's share can't be confirmed —
    // hold much longer so the user has time to open their Friend List and receive it.
    private static readonly TimeSpan FriendsUnknownGrace = TimeSpan.FromMinutes(10);

    // One "open your Friend List" toast per unknown-friends episode.
    private bool friendsUnknownAlerted;

    private readonly record struct HeldShare(ShareMessage Message, DateTime FirstSeen);

    public ShareRelay(
        TarotApiClient api,
        Configuration config,
        IFramework framework,
        GameSocial social,
        INotificationManager notifications,
        IPluginLog log,
        Action<ShareMessage> onShareReceived)
    {
        this.api = api;
        this.config = config;
        this.framework = framework;
        this.social = social;
        this.notifications = notifications;
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

    /// <summary>Force the next tick to re-sync identities/tier (after a settings change).</summary>
    public void InvalidateRegistration()
    {
        Interlocked.Increment(ref this.registrationGeneration);
        this.lastRegisteredKey = string.Empty;
    }

    // ── Sender side ───────────────────────────────────────────────────────────

    /// <summary>
    /// Send a reading to a recipient. Returns a user-facing result string; never
    /// throws. Runs the HTTP off the framework thread.
    /// </summary>
    public async Task<string> SendAsync(string readingId, GameSocial.Recipient to, GameSocial.Recipient self)
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
        // identities server-side yet, so force a re-sync.
        if (this.api.ClientId != this.lastClientId)
        {
            this.lastClientId = this.api.ClientId;
            this.lastRegisteredKey = string.Empty;
        }

        var enabled = this.config.IncomingSharesEnabled;

        // Sync our linked-character identity set + consent tier when it changes.
        await EnsureRegisteredAsync(enabled, ct).ConfigureAwait(false);

        // Framework thread: nudge on an unlinked character; if sharing is off, drop
        // any shares still held so they don't leak through after opting out.
        await this.framework.RunOnFrameworkThread(() => FrameworkPreTick(enabled)).ConfigureAwait(false);

        if (!enabled)
            return;

        var inbox = await this.api.GetInboxAsync(ct).ConfigureAwait(false);

        // Fold newly drained shares into the hold buffer and re-classify everything
        // held against fresh game state (party roster + friend list). Done on the
        // framework thread — game reads and the buffer must not race the UI. A share
        // that can't be classified yet is held, not dropped.
        var accepted = await this.framework
            .RunOnFrameworkThread(() => IngestAndClassify(inbox))
            .ConfigureAwait(false);

        foreach (var msg in accepted)
            this.onShareReceived(msg);
    }

    private async Task EnsureRegisteredAsync(bool enabled, CancellationToken ct)
    {
        var tier = enabled ? this.config.AcceptTier : "nobody";

        // Snapshot the linked-character list AND the invalidation generation together
        // on the framework thread. The UI mutates the list and calls
        // InvalidateRegistration there, so capturing both atomically means `gen`
        // matches exactly the list state in `chars`, and lets us detect an
        // invalidation that races our HTTP register below.
        List<LinkedCharacter> chars;
        int gen;
        if (enabled)
        {
            (chars, gen) = await this.framework.RunOnFrameworkThread(
                    () => (this.config.LinkedCharacters.ToList(), Volatile.Read(ref this.registrationGeneration)))
                .ConfigureAwait(false);
        }
        else
        {
            chars = new List<LinkedCharacter>();
            gen = Volatile.Read(ref this.registrationGeneration);
        }

        var key = tier + "|" + string.Join(
            ";",
            chars.Select(c => $"{c.Name}@{c.World}").OrderBy(x => x, StringComparer.OrdinalIgnoreCase));

        if (key == this.lastRegisteredKey)
            return;

        try
        {
            await this.api.RegisterClientAsync(
                new RegisterClientRequest
                {
                    AcceptTier = tier,
                    Characters = chars
                        .Select(c => new CharacterIdentity { CharacterName = c.Name, World = c.World })
                        .ToList(),
                },
                ct).ConfigureAwait(false);

            // Only record this key as synced if no invalidation raced us since the
            // snapshot; otherwise leave lastRegisteredKey empty so the next tick
            // re-syncs with the newer state (never swallow an invalidation).
            if (Volatile.Read(ref this.registrationGeneration) == gen)
                this.lastRegisteredKey = key;
        }
        catch (TarotApiException ex)
        {
            this.log.Warning($"Share relay register failed: {ex.Message}");
        }
    }

    /// <summary>Framework-thread per-tick housekeeping before the inbox is drained.</summary>
    private void FrameworkPreTick(bool enabled)
    {
        if (!enabled)
        {
            // Opted out: don't keep (or later show) anything we were holding.
            this.held.Clear();
            this.friendsUnknownAlerted = false;
        }

        CheckNewCharacterAlert();
    }

    /// <summary>Framework-thread: toast once when on a character not yet linked for sharing.</summary>
    private void CheckNewCharacterAlert()
    {
        if (!this.config.IncomingSharesEnabled)
            return;

        if (this.social.Current() is not { } cur)
            return;

        if (this.config.LinkedCharacters.Any(c => c.ContentId == cur.ContentId))
            return;

        if (!this.alertedCharacters.Add(cur.ContentId))
            return;

        this.notifications.AddNotification(new Notification
        {
            Title = "TarotGen",
            Content = $"{cur.Name} isn't linked for shared readings yet. "
                + "Open TarotGen → Settings → Sharing to link this character.",
            Type = NotificationType.Info,
            InitialDuration = TimeSpan.FromSeconds(10),
        });
    }

    /// <summary>
    /// Framework-thread: fold newly drained shares into the hold buffer, then decide
    /// which held shares may be shown now. A share whose sender isn't currently in the
    /// allowed set is <b>held</b> (up to a grace window), not dropped — so one that
    /// arrives during a zone transition, or before the friend list is readable, isn't
    /// lost forever (the inbox drain is destructive server-side, so we can't re-fetch).
    /// </summary>
    private List<ShareMessage> IngestAndClassify(List<ShareMessage>? inbox)
    {
        var accepted = new List<ShareMessage>();

        if (inbox != null)
        {
            foreach (var m in inbox)
            {
                if (string.IsNullOrEmpty(m.SenderCharacter) || string.IsNullOrEmpty(m.SenderWorld))
                    continue;
                if (this.held.Count >= MaxHeld)
                {
                    this.held.RemoveAt(0); // buffer full (pathological/spam): shed the oldest
                    this.log.Warning($"Share hold buffer full ({MaxHeld}); dropping the oldest unclassified share.");
                }

                this.held.Add(new HeldShare(m, DateTime.UtcNow));
            }
        }

        if (this.held.Count == 0)
        {
            this.friendsUnknownAlerted = false;
            return accepted;
        }

        var tier = this.config.AcceptTier;
        bool needsFriends = tier is "friends" or "party_or_friends";
        bool friendsUnknown = needsFriends && !this.social.FriendsListLoaded;

        var allow = new HashSet<string>();
        if (tier != "anyone")
        {
            if (tier is "party" or "party_or_friends")
            {
                foreach (var r in this.social.AllPartyMembers())
                    allow.Add(r.Key);
            }

            if (tier is "friends" or "party_or_friends")
            {
                foreach (var r in this.social.Friends())
                    allow.Add(r.Key);
            }
        }

        var now = DateTime.UtcNow;
        bool deferredForFriends = false;

        for (int i = this.held.Count - 1; i >= 0; i--)
        {
            var h = this.held[i];
            var msg = h.Message;

            if (string.IsNullOrEmpty(msg.SenderCharacter) || string.IsNullOrEmpty(msg.SenderWorld))
            {
                this.held.RemoveAt(i);
                continue;
            }

            bool ok = tier == "anyone"
                || allow.Contains(GameSocial.IdentityKey(msg.SenderCharacter, msg.SenderWorld));
            if (ok)
            {
                accepted.Add(msg);
                this.held.RemoveAt(i);
                continue;
            }

            // Not currently allowed. Hold longer while the friend list is unreadable
            // (the miss may be a friend we simply can't see yet); otherwise only ride
            // out a brief transient (zoning/login) before discarding.
            var grace = friendsUnknown ? FriendsUnknownGrace : RosterGrace;
            if (now - h.FirstSeen > grace)
                this.held.RemoveAt(i);
            else if (friendsUnknown)
                deferredForFriends = true;
        }

        // Surface why a share is waiting instead of silently dropping it: the friend
        // list isn't loaded, so open it once to confirm friend shares.
        if (deferredForFriends && !this.friendsUnknownAlerted)
        {
            this.friendsUnknownAlerted = true;
            this.notifications.AddNotification(new Notification
            {
                Title = "TarotGen",
                Content = "Someone shared a reading with you. Open your in-game Friend List "
                    + "once so the plugin can confirm shares from friends.",
                Type = NotificationType.Info,
                InitialDuration = TimeSpan.FromSeconds(12),
            });
        }
        else if (!friendsUnknown)
        {
            this.friendsUnknownAlerted = false;
        }

        accepted.Reverse(); // restore chronological (oldest-first) order
        return accepted;
    }

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

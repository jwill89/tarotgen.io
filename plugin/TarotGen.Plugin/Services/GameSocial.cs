using System;
using System.Collections.Generic;
using Dalamud.Game.ClientState.Objects.SubKinds;
using Dalamud.Plugin.Services;
using FFXIVClientStructs.FFXIV.Client.UI.Info;

namespace TarotGen.Plugin.Services;

/// <summary>
/// Reads FFXIV social state for the share relay: the local character, all party
/// members (normal party <b>and</b> cross-world / Party-Finder / cross-realm
/// alliance, which Dalamud's <see cref="IPartyList"/> alone misses), the current
/// target, and the friends list.
///
/// Everything here touches live game memory, so call ONLY from the framework
/// thread. The FFXIVClientStructs member names were verified against the bundled
/// assembly (Dalamud API 15); re-verify after a game/ClientStructs bump.
/// </summary>
public sealed class GameSocial
{
    /// <summary>A shareable player identity (Name + home World name).</summary>
    public readonly record struct Recipient(string Name, string World)
    {
        public bool IsValid => !string.IsNullOrWhiteSpace(this.Name) && !string.IsNullOrWhiteSpace(this.World);

        /// <summary>Case-insensitive key for de-duping / membership tests.</summary>
        public string Key => IdentityKey(this.Name, this.World);

        public override string ToString() => $"{this.Name} @ {this.World}";
    }

    /// <summary>The current character with its stable content id.</summary>
    public readonly record struct CurrentChar(ulong ContentId, string Name, string World);

    /// <summary>
    /// Case-insensitive Name@World key, used both to de-dupe recipients and to test
    /// whether an incoming share's sender is in the party/friends allow-list. The
    /// pipe separator can't appear in a character or world name.
    /// </summary>
    public static string IdentityKey(string name, string world) => $"{name}|{world}".ToLowerInvariant();

    private readonly IPlayerState playerState;
    private readonly IPartyList partyList;
    private readonly ITargetManager targetManager;
    private readonly IDataManager dataManager;

    public GameSocial(
        IPlayerState playerState,
        IPartyList partyList,
        ITargetManager targetManager,
        IDataManager dataManager)
    {
        this.playerState = playerState;
        this.partyList = partyList;
        this.targetManager = targetManager;
        this.dataManager = dataManager;
    }

    /// <summary>The logged-in character (with content id), or null.</summary>
    public CurrentChar? Current()
    {
        if (!this.playerState.IsLoaded)
            return null;

        var name = this.playerState.CharacterName;
        return string.IsNullOrEmpty(name)
            ? null
            : new CurrentChar(this.playerState.ContentId, name, WorldName(this.playerState.HomeWorld));
    }

    /// <summary>The logged-in character as a share sender identity, or null.</summary>
    public Recipient? Self()
        => Current() is { } c ? new Recipient(c.Name, c.World) : null;

    /// <summary>The current target if it is another player, else null.</summary>
    public Recipient? Target()
    {
        if (this.targetManager.Target is not IPlayerCharacter pc)
            return null;

        var r = new Recipient(pc.Name.TextValue, WorldName(pc.HomeWorld));
        return r.IsValid ? r : null;
    }

    /// <summary>
    /// Every current party member — the normal same-world party AND cross-world
    /// (Party-Finder / cross-realm alliance) members — minus yourself, de-duped.
    /// </summary>
    public IReadOnlyList<Recipient> AllPartyMembers()
    {
        var self = Self();
        var seen = new HashSet<string>();
        var list = new List<Recipient>();

        void Add(Recipient r)
        {
            if (!r.IsValid)
                return;
            if (self is { } s && r.Key == s.Key)
                return;
            if (seen.Add(r.Key))
                list.Add(r);
        }

        foreach (var member in this.partyList)
            Add(new Recipient(member.Name.TextValue, WorldName(member.World)));

        foreach (var (name, worldId) in CrossRealmMembers())
            Add(new Recipient(name, WorldNameById(worldId)));

        return list;
    }

    /// <summary>The player's friends as recipients (see <see cref="FriendsListLoaded"/>).</summary>
    public IReadOnlyList<Recipient> Friends()
    {
        var seen = new HashSet<string>();
        var list = new List<Recipient>();

        foreach (var (name, worldId) in FriendEntries())
        {
            var r = new Recipient(name, WorldNameById(worldId));
            if (r.IsValid && seen.Add(r.Key))
                list.Add(r);
        }

        return list;
    }

    /// <summary>
    /// Whether the friend-list proxy has been populated this session. The game only
    /// sends friend data after the in-game Friend List window is opened once, so
    /// when this is false, <see cref="Friends"/> being empty means "unknown", not
    /// "no friends" — the UI prompts the user to open their Friend List.
    /// </summary>
    public bool FriendsListLoaded => FriendCount() > 0;

    // ── unsafe game-memory reads (FFXIVClientStructs) ──────────────────────────

    private static unsafe IReadOnlyList<(string Name, uint HomeWorldId)> CrossRealmMembers()
    {
        var results = new List<(string, uint)>();

        var proxy = InfoProxyCrossRealm.Instance();
        if (proxy == null)
            return results;

        byte groupCount = proxy->GroupCount; // 0 = not in a cross-realm party
        for (int g = 0; g < groupCount; g++)
        {
            byte memberCount = InfoProxyCrossRealm.GetGroupMemberCount(g);
            for (uint m = 0; m < memberCount; m++)
            {
                var member = InfoProxyCrossRealm.GetGroupMember(m, g);
                if (member == null)
                    continue;

                var name = member->NameString;
                if (string.IsNullOrEmpty(name))
                    continue;

                results.Add((name, (uint)(ushort)member->HomeWorld));
            }
        }

        return results;
    }

    private static unsafe uint FriendCount()
    {
        var info = InfoModule.Instance();
        if (info == null)
            return 0;

        var proxy = info->GetInfoProxyFriendList();
        return proxy == null ? 0 : proxy->EntryCount;
    }

    private static unsafe IReadOnlyList<(string Name, uint HomeWorldId)> FriendEntries()
    {
        var results = new List<(string, uint)>();

        var info = InfoModule.Instance();
        if (info == null)
            return results;

        var proxy = info->GetInfoProxyFriendList();
        if (proxy == null)
            return results;

        uint count = proxy->EntryCount; // 0 until the Friend List UI has been opened this session
        for (uint i = 0; i < count; i++)
        {
            var entry = proxy->GetEntry(i);
            if (entry == null)
                continue;

            var name = entry->NameString;
            if (string.IsNullOrEmpty(name))
                continue;

            results.Add((name, entry->HomeWorld));
        }

        return results;
    }

    // ── world name resolution ──────────────────────────────────────────────────

    private static string WorldName(Lumina.Excel.RowRef<Lumina.Excel.Sheets.World> world)
        => world.ValueNullable?.Name.ExtractText() ?? string.Empty;

    private string WorldNameById(uint id)
    {
        var row = this.dataManager.GetExcelSheet<Lumina.Excel.Sheets.World>()?.GetRowOrDefault(id);
        return row?.Name.ExtractText() ?? string.Empty;
    }
}

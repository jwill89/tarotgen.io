using System;
using System.Collections.Generic;
using System.Linq;
using System.Numerics;
using System.Threading.Tasks;
using Dalamud.Bindings.ImGui;
using Dalamud.Interface.Utility;
using Dalamud.Interface.Utility.Raii;
using Dalamud.Interface.Windowing;
using Dalamud.Plugin;
using Dalamud.Utility;
using TarotGen.Plugin.Models;
using TarotGen.Plugin.Services;

namespace TarotGen.Plugin.Windows;

/// <summary>
/// The single plugin window. Everything lives here as tabs — New Reading, Reading
/// (hosted by <see cref="ReadingPanel"/>), My Readings, and Account.
/// </summary>
public sealed class MainWindow : Window, IDisposable
{
    private static readonly Vector4 ErrorColor = new(1f, 0.45f, 0.45f, 1f);
    private static readonly Vector4 LinkedColor = new(0.4f, 0.85f, 0.4f, 1f);
    private static readonly Vector4 SectionColor = new(0.85f, 0.75f, 0.5f, 1f);

    private readonly TarotApiClient api;
    private readonly ReadingPanel readingPanel;
    private readonly LinkService linkService;
    private readonly ShareRelay shareRelay;
    private readonly Configuration config;
    private readonly IDalamudPluginInterface pluginInterface;

    private IReadOnlyList<Spread> spreads = Array.Empty<Spread>();
    private List<DeckGroup> deckGroups = new();
    private bool catalogRequested;
    private bool catalogLoading;
    private string? catalogError;

    private Deck? selectedDeck;
    private Spread? selectedSpread; // null = free draw
    private int freeDrawCount;

    private string newReadingTitle = string.Empty;
    private bool hideUser;
    private string newReadingPassword = string.Empty;

    private volatile bool generating;
    private string? generateError;

    private IReadOnlyList<Reading> myReadings = Array.Empty<Reading>();
    private bool myReadingsRequested;
    private volatile bool myReadingsLoading;
    private string? myReadingsError;
    private string myReadingsFilter = string.Empty;

    private bool switchToReadingTab;
    private bool switchToSettingsTab;

    public MainWindow(
        TarotApiClient api,
        ReadingPanel readingPanel,
        LinkService linkService,
        ShareRelay shareRelay,
        Configuration config,
        IDalamudPluginInterface pluginInterface)
        : base("TarotGen###TarotGenMain")
    {
        this.api = api;
        this.readingPanel = readingPanel;
        this.linkService = linkService;
        this.shareRelay = shareRelay;
        this.config = config;
        this.pluginInterface = pluginInterface;
        this.freeDrawCount = Math.Max(1, config.FreeDrawCount);
        this.Size = new Vector2(680, 780);
        this.SizeCondition = ImGuiCond.FirstUseEver;
        this.SizeConstraints = new WindowSizeConstraints
        {
            MinimumSize = new Vector2(460, 420),
            MaximumSize = new Vector2(1400, 2000),
        };
    }

    /// <summary>Open a reading by code and switch to the Reading tab.</summary>
    public void ShowReading(string idOrUrl)
    {
        this.readingPanel.OpenReading(idOrUrl);
        this.switchToReadingTab = true;
        this.IsOpen = true;
    }

    /// <summary>Open the window on the Settings tab (the config/gear button).</summary>
    public void ShowSettings()
    {
        this.switchToSettingsTab = true;
        this.IsOpen = true;
    }

    public override void Draw()
    {
        if (!this.catalogRequested)
            StartCatalogLoad();

        using var tabs = ImRaii.TabBar("##tarotgen-tabs");
        if (!tabs)
            return;

        var readingFlags = this.switchToReadingTab ? ImGuiTabItemFlags.SetSelected : ImGuiTabItemFlags.None;
        this.switchToReadingTab = false;
        using (var t = ImRaii.TabItem("Reading", readingFlags))
        {
            if (t)
                this.readingPanel.Draw();
        }

        using (var t = ImRaii.TabItem("New Reading"))
        {
            if (t)
                DrawNewReadingTab();
        }

        if (this.api.IsLinked)
        {
            using var t = ImRaii.TabItem("My Readings");
            if (t)
                DrawMyReadingsTab();
        }

        var settingsFlags = this.switchToSettingsTab ? ImGuiTabItemFlags.SetSelected : ImGuiTabItemFlags.None;
        this.switchToSettingsTab = false;
        using (var t = ImRaii.TabItem("Settings", settingsFlags))
        {
            if (t)
                DrawSettingsTab();
        }
    }

    // ── New Reading tab ───────────────────────────────────────────────────────

    private void DrawNewReadingTab()
    {
        if (this.catalogLoading)
        {
            ImGui.TextUnformatted("Loading decks and spreads…");
            return;
        }

        if (this.catalogError != null)
        {
            ImGui.TextColored(ErrorColor, this.catalogError);
            if (ImGui.Button("Retry"))
                StartCatalogLoad();
            return;
        }

        Section("Deck");
        DrawDeckCombo();
        if (this.selectedDeck is { AdditionalCards: > 0 })
        {
            bool additional = this.config.UseAdditionalCards;
            if (ImGui.Checkbox($"Include this deck's {this.selectedDeck.AdditionalCards} extra card(s)", ref additional))
                this.config.UseAdditionalCards = additional;
        }

        Section("Spread");
        DrawSpreadCombo();
        if (this.selectedSpread != null)
            DrawSpreadDetailsColumns(this.selectedSpread);
        else
            DrawFreeDrawCount();

        Section("Options");
        bool reversals = this.config.UseReversals;
        if (ImGui.Checkbox("Reversed cards", ref reversals))
            this.config.UseReversals = reversals;

        if (this.api.IsLinked)
            DrawOwnerOptions();
        else
            ImGui.TextDisabled("Link your account (Account tab) to set a title, hide your name, or add a password.");

        ImGui.Spacing();
        DrawGenerateButton();
    }

    private void DrawOwnerOptions()
    {
        float scale = ImGuiHelpers.GlobalScale;
        ImGui.SetNextItemWidth(-1);
        ImGui.InputTextWithHint("##rtitle", "Reading title (optional)", ref this.newReadingTitle, 100);
        ImGui.Checkbox("Hide my name", ref this.hideUser);
        ImGui.SetNextItemWidth(240 * scale);
        ImGui.InputTextWithHint("##rpw", "Password (optional)", ref this.newReadingPassword, 128, ImGuiInputTextFlags.Password);
    }

    private void DrawGenerateButton()
    {
        float scale = ImGuiHelpers.GlobalScale;
        using (ImRaii.PushColor(ImGuiCol.Button, new Vector4(0.46f, 0.33f, 0.66f, 1f)))
        using (ImRaii.PushColor(ImGuiCol.ButtonHovered, new Vector4(0.56f, 0.43f, 0.76f, 1f)))
        using (ImRaii.PushColor(ImGuiCol.ButtonActive, new Vector4(0.38f, 0.26f, 0.56f, 1f)))
        using (ImRaii.Disabled(this.generating || this.selectedDeck == null))
        {
            if (ImGui.Button("Generate Reading", new Vector2(-1, 36 * scale)))
                StartGenerate();
        }

        if (this.generating)
            ImGui.TextUnformatted("Drawing…");
        if (this.generateError != null)
            ImGui.TextColored(ErrorColor, this.generateError);
    }

    private void DrawDeckCombo()
    {
        var label = this.selectedDeck?.Name ?? "Select a deck…";
        ImGui.SetNextItemWidth(-1);
        using var combo = ImRaii.Combo("##deck", label);
        if (!combo)
            return;

        bool first = true;
        foreach (var group in this.deckGroups)
        {
            if (!first)
                ImGui.Separator();
            first = false;

            ImGui.TextDisabled(group.Header);
            foreach (var d in group.Decks)
            {
                var extra = d.AdditionalCards > 0 ? $"  (+{d.AdditionalCards})" : string.Empty;
                var artist = string.IsNullOrWhiteSpace(d.Artist) ? string.Empty : $"   ·  {d.Artist}";
                if (ImGui.Selectable($"{d.Name}{artist}{extra}##deck{d.DeckId}", d == this.selectedDeck))
                    SelectDeck(d);
            }
        }
    }

    private void SelectDeck(Deck deck)
    {
        this.selectedDeck = deck;
        this.config.DefaultDeckId = deck.DeckId;
        this.config.UseAdditionalCards = false;
        this.config.Save(this.pluginInterface);
    }

    private void SelectSpread(Spread? spread)
    {
        this.selectedSpread = spread;
        this.config.DefaultSpreadId = spread?.SpreadId;
        this.config.Save(this.pluginInterface);
    }

    private void DrawSpreadCombo()
    {
        var label = this.selectedSpread?.Name ?? "Free draw";
        ImGui.SetNextItemWidth(-1);
        using var combo = ImRaii.Combo("##spread", label);
        if (!combo)
            return;

        if (ImGui.Selectable("Free draw", this.selectedSpread == null))
            SelectSpread(null);

        foreach (var s in this.spreads)
        {
            var cards = s.CardCount == 1 ? "1 card" : $"{s.CardCount} cards";
            if (ImGui.Selectable($"{s.Name}   ({cards})##spread{s.SpreadId}", s == this.selectedSpread))
                SelectSpread(s);
        }
    }

    private void DrawFreeDrawCount()
    {
        int max = MaxCardsForDeck();
        if (this.freeDrawCount > max)
            this.freeDrawCount = max;
        ImGui.SetNextItemWidth(200 * ImGuiHelpers.GlobalScale);
        ImGui.SliderInt("Number of cards", ref this.freeDrawCount, 1, max);
        SaveFreeDrawCountOnEdit();
    }

    private void SaveFreeDrawCountOnEdit()
    {
        if (ImGui.IsItemDeactivatedAfterEdit())
        {
            this.config.FreeDrawCount = this.freeDrawCount;
            this.config.Save(this.pluginInterface);
        }
    }

    private void DrawSpreadDetailsColumns(Spread spread)
    {
        var positions = spread.Positions.OrderBy(p => p.Order).ToList();

        using var table = ImRaii.Table("##spreaddetail", 2, ImGuiTableFlags.BordersInnerV);
        if (!table)
            return;

        ImGui.TableSetupColumn("Preview", ImGuiTableColumnFlags.WidthStretch, 0.55f);
        ImGui.TableSetupColumn("Details", ImGuiTableColumnFlags.WidthStretch, 0.45f);
        ImGui.TableNextRow();

        ImGui.TableNextColumn();
        DrawSpreadPreview(positions);

        ImGui.TableNextColumn();
        ImGui.TextDisabled(spread.CardCount == 1 ? "1 card" : $"{spread.CardCount} cards");
        if (!string.IsNullOrWhiteSpace(spread.Description))
            ImGui.TextWrapped(spread.Description);
        ImGui.Spacing();
        foreach (var p in positions)
        {
            var title = string.IsNullOrWhiteSpace(p.Title) ? $"Position {p.Order}" : p.Title;
            ImGui.TextWrapped($"{p.Order}. {title}");
        }
    }

    private static void DrawSpreadPreview(List<SpreadPosition> positions)
    {
        if (positions.Count == 0)
            return;

        var fit = SpreadLayout.ComputeFit(positions);
        var sizePct = SpreadLayout.CardSizePct(fit);
        float scale = ImGuiHelpers.GlobalScale;
        float canvas = Math.Clamp(ImGui.GetContentRegionAvail().X, 120f, 440f * scale);

        var origin = ImGui.GetCursorScreenPos();
        ImGui.Dummy(new Vector2(canvas, canvas));
        var dl = ImGui.GetWindowDrawList();
        dl.AddRect(origin, origin + new Vector2(canvas, canvas), 0x22FFFFFFu, 4f);
        float cardWpx = sizePct.X / 100f * canvas;
        float cardHpx = sizePct.Y / 100f * canvas;

        foreach (var p in positions)
        {
            var proj = SpreadLayout.Project(fit, p.X, p.Y);
            var center = origin + new Vector2(proj.X / 100f * canvas, proj.Y / 100f * canvas);
            SpreadDraw.RotatedRect(dl, center, cardWpx, cardHpx, p.Rotation, 0x66FFFFFFu);
            SpreadDraw.OrderNumber(dl, center, p.Order);
        }
    }

    // ── My Readings tab ───────────────────────────────────────────────────────

    private void DrawMyReadingsTab()
    {
        if (!this.api.IsLinked)
        {
            ImGui.TextWrapped("Link your TarotGen account (Account tab) to see the readings saved to your account.");
            return;
        }

        if (!this.myReadingsRequested)
            StartLoadMyReadings();

        if (ImGui.Button("Refresh"))
            StartLoadMyReadings();
        ImGui.SameLine();
        ImGui.SetNextItemWidth(-1);
        ImGui.InputTextWithHint("##filter", "Filter by title, deck, or ID…", ref this.myReadingsFilter, 100);

        if (this.myReadingsLoading)
        {
            ImGui.TextUnformatted("Loading…");
            return;
        }

        if (this.myReadingsError != null)
        {
            ImGui.TextColored(ErrorColor, this.myReadingsError);
            return;
        }

        var q = this.myReadingsFilter.Trim();
        var rows = this.myReadings.Where(r => Matches(r, q)).ToList();
        if (rows.Count == 0)
        {
            ImGui.TextDisabled(q.Length > 0 ? "No readings match your filter." : "No saved readings yet.");
            return;
        }

        using var table = ImRaii.Table("##myreadings", 4,
            ImGuiTableFlags.RowBg | ImGuiTableFlags.Borders | ImGuiTableFlags.Resizable | ImGuiTableFlags.SizingStretchProp);
        if (!table)
            return;

        ImGui.TableSetupColumn("Title");
        ImGui.TableSetupColumn("Reading ID", ImGuiTableColumnFlags.WidthFixed, 110 * ImGuiHelpers.GlobalScale);
        ImGui.TableSetupColumn("Deck");
        ImGui.TableSetupColumn("Date", ImGuiTableColumnFlags.WidthFixed, 150 * ImGuiHelpers.GlobalScale);
        ImGui.TableHeadersRow();

        foreach (var r in rows)
        {
            ImGui.TableNextRow();
            ImGui.TableNextColumn();
            if (ImGui.Selectable($"{ReadingFormat.Title(r)}##row{r.ReadingId}", false, ImGuiSelectableFlags.SpanAllColumns))
                ShowReading(r.ReadingId);
            ImGui.TableNextColumn();
            ImGui.TextUnformatted(r.ReadingId);
            ImGui.TableNextColumn();
            ImGui.TextUnformatted(DeckName(r));
            ImGui.TableNextColumn();
            ImGui.TextUnformatted(r.ReadingTime ?? string.Empty);
        }
    }

    private bool Matches(Reading r, string query)
    {
        if (query.Length == 0)
            return true;
        query = query.ToLowerInvariant();
        return ReadingFormat.Title(r).ToLowerInvariant().Contains(query)
            || r.ReadingId.ToLowerInvariant().Contains(query)
            || DeckName(r).ToLowerInvariant().Contains(query);
    }

    private string DeckName(Reading r)
    {
        var id = r.ReadingInfo?.DeckId ?? 0;
        return this.api.DeckById(id)?.Name ?? "—";
    }

    // ── Account tab ───────────────────────────────────────────────────────────

    private void DrawSettingsTab()
    {
        Section("Defaults");
        if (this.catalogLoading)
        {
            ImGui.TextDisabled("Loading decks and spreads…");
        }
        else if (this.catalogError != null)
        {
            ImGui.TextColored(ErrorColor, this.catalogError);
        }
        else
        {
            ImGui.TextDisabled("Default deck");
            DrawDeckCombo();
            ImGui.TextDisabled("Default spread");
            DrawSpreadCombo();
            ImGui.SetNextItemWidth(200 * ImGuiHelpers.GlobalScale);
            ImGui.SliderInt("Default cards on free draw", ref this.freeDrawCount, 1, Math.Max(1, MaxCardsForDeck()));
            SaveFreeDrawCountOnEdit();
        }

        Section("Connection");
        if (this.api.IsConnected)
        {
            if (this.api.IsLinked)
            {
                ImGui.TextColored(LinkedColor, $"Linked as {this.api.LinkedName}");
                ImGui.TextDisabled("Account features (locking, favorites, My Readings) are available.");
            }
            else
            {
                ImGui.TextColored(LinkedColor, "Connected as guest");
                ImGui.TextDisabled("You can send and receive shared readings — no account needed.");
                ImGui.Spacing();
                using (ImRaii.Disabled(this.linkService.IsBusy))
                {
                    if (ImGui.Button("Log in to link an account…"))
                        this.linkService.StartLink();
                }
            }

            ImGui.Spacing();
            using (ImRaii.Disabled(this.linkService.IsBusy))
            {
                if (ImGui.Button("Disconnect"))
                    this.linkService.Unlink();
            }
        }
        else
        {
            ImGui.TextWrapped(
                "Connect to TarotGen.io to send and receive shared readings. Log in for account "
                + "features (locking, favorites, My Readings), or continue as a guest — no account needed.");
            ImGui.Spacing();
            using (ImRaii.Disabled(this.linkService.IsBusy))
            using (ImRaii.PushColor(ImGuiCol.Button, new Vector4(0.46f, 0.33f, 0.66f, 1f)))
            using (ImRaii.PushColor(ImGuiCol.ButtonHovered, new Vector4(0.54f, 0.40f, 0.74f, 1f)))
            using (ImRaii.PushColor(ImGuiCol.ButtonActive, new Vector4(0.40f, 0.28f, 0.58f, 1f)))
            {
                if (ImGui.Button("Connect to TarotGen.io", new Vector2(-1, 34 * ImGuiHelpers.GlobalScale)))
                    this.linkService.StartLink();
            }
        }

        if (this.linkService.IsBusy)
        {
            if (ImGui.Button("Cancel"))
                this.linkService.Cancel();
        }

        if (!string.IsNullOrEmpty(this.linkService.Status))
        {
            ImGui.Spacing();
            ImGui.TextWrapped(this.linkService.Status);
        }

        if (this.api.IsConnected)
            DrawSharingSettings();

        Section("TarotGen.io");
        float scale = ImGuiHelpers.GlobalScale;
        using (ImRaii.PushColor(ImGuiCol.Button, new Vector4(0.20f, 0.47f, 0.58f, 1f)))
        using (ImRaii.PushColor(ImGuiCol.ButtonHovered, new Vector4(0.26f, 0.57f, 0.68f, 1f)))
        using (ImRaii.PushColor(ImGuiCol.ButtonActive, new Vector4(0.16f, 0.40f, 0.50f, 1f)))
        {
            if (ImGui.Button("Open TarotGen.io in your browser", new Vector2(-1, 38 * scale)))
                Util.OpenLink(this.api.SiteBase);
        }
    }

    private void DrawSharingSettings()
    {
        Section("Sharing");

        bool incoming = this.config.IncomingSharesEnabled;
        if (ImGui.Checkbox("Receive shared readings", ref incoming))
        {
            this.config.IncomingSharesEnabled = incoming;
            this.config.Save(this.pluginInterface);
        }
        ImGui.TextDisabled("A popup appears in-game when someone shares a reading with you.");

        if (this.config.IncomingSharesEnabled)
        {
            ImGui.Spacing();
            ImGui.TextDisabled("Accept shares from:");
            DrawTierRadio("Party members", "party_or_friends");
            ImGui.SameLine();
            DrawTierRadio("Anyone", "anyone");
            ImGui.Spacing();
            ImGui.TextWrapped(
                "While this is on, your character name and home world are shared with the relay so "
                + "others can send you readings. Turn it off to stop receiving and hide your name.");
        }
    }

    private void DrawTierRadio(string label, string tier)
    {
        if (ImGui.RadioButton(label, this.config.AcceptTier == tier))
        {
            this.config.AcceptTier = tier;
            this.config.Save(this.pluginInterface);
        }
    }

    // ── Loading + actions ─────────────────────────────────────────────────────

    private static void Section(string label)
    {
        ImGui.Spacing();
        ImGui.TextColored(SectionColor, label);
        ImGui.Separator();
    }

    private int MaxCardsForDeck()
    {
        if (this.selectedDeck == null)
            return 78;
        int total = this.selectedDeck.EffectiveTotalCards;
        if (this.config.UseAdditionalCards)
            total += this.selectedDeck.AdditionalCards;
        return Math.Max(1, total);
    }

    private void StartCatalogLoad()
    {
        this.catalogRequested = true;
        this.catalogLoading = true;
        this.catalogError = null;
        _ = Task.Run(async () =>
        {
            try
            {
                var decksTask = this.api.GetDecksAsync(this.api.Token);
                var spreadsTask = this.api.GetSpreadsAsync(this.api.Token);
                await Task.WhenAll(decksTask, spreadsTask).ConfigureAwait(false);

                this.spreads = spreadsTask.Result.OrderBy(s => s.CardCount).ThenBy(s => s.Name).ToList();
                this.deckGroups = BuildDeckGroups(decksTask.Result);

                this.selectedDeck = null;
                var allDecks = this.deckGroups.SelectMany(g => g.Decks).ToList();
                if (this.config.DefaultDeckId is { } deckId)
                    this.selectedDeck = allDecks.FirstOrDefault(d => d.DeckId == deckId);
                this.selectedDeck ??= allDecks.FirstOrDefault();

                this.selectedSpread = this.config.DefaultSpreadId is { } spreadId
                    ? this.spreads.FirstOrDefault(s => s.SpreadId == spreadId)
                    : null;
            }
            catch (Exception ex)
            {
                this.catalogError = $"Couldn't load decks/spreads: {ex.Message}";
            }
            finally
            {
                this.catalogLoading = false;
            }
        });
    }

    private static List<DeckGroup> BuildDeckGroups(IReadOnlyList<Deck> decks)
    {
        return decks
            .GroupBy(d => string.IsNullOrWhiteSpace(d.SystemShortName) ? "Other" : d.SystemShortName)
            .OrderBy(g => g.Key, StringComparer.OrdinalIgnoreCase)
            .Select(g =>
            {
                var ordered = g.OrderBy(d => d.Name, StringComparer.OrdinalIgnoreCase).ToList();
                int total = ordered[0].EffectiveTotalCards;
                var header = $"{g.Key.ToUpperInvariant()}  ·  {total} cards";
                return new DeckGroup(header, ordered);
            })
            .ToList();
    }

    private void StartGenerate()
    {
        if (this.selectedDeck == null)
            return;

        bool linked = this.api.IsLinked;
        var request = new GenerateRequest
        {
            DeckId = this.selectedDeck.DeckId,
            NumberOfCards = this.selectedSpread?.CardCount ?? this.freeDrawCount,
            UseReversals = this.config.UseReversals,
            UseAdditionalCards = this.config.UseAdditionalCards,
            SpreadId = this.selectedSpread?.SpreadId,
            ReadingName = linked && !string.IsNullOrWhiteSpace(this.newReadingTitle) ? this.newReadingTitle.Trim() : null,
            HideUser = linked && this.hideUser,
            Password = linked && !string.IsNullOrEmpty(this.newReadingPassword) ? this.newReadingPassword : null,
        };

        this.generating = true;
        this.generateError = null;
        _ = Task.Run(async () =>
        {
            try
            {
                var reading = await this.api.GenerateAsync(request, this.api.Token).ConfigureAwait(false);
                if (reading != null)
                {
                    // The plugin can't draw more cards, so lock an owned reading right away.
                    if (reading.IsOwner && !reading.IsFinal)
                    {
                        var locked = await this.api.FinalizeReadingAsync(reading.ReadingId, this.api.Token).ConfigureAwait(false);
                        if (locked != null)
                            reading = locked;
                    }

                    this.readingPanel.SetReading(reading);
                    this.switchToReadingTab = true;
                    this.myReadingsRequested = false; // refresh the list next view
                }
            }
            catch (Exception ex)
            {
                this.generateError = ex.Message;
            }
            finally
            {
                this.generating = false;
            }
        });
    }

    private void StartLoadMyReadings()
    {
        this.myReadingsRequested = true;
        this.myReadingsLoading = true;
        this.myReadingsError = null;
        _ = Task.Run(async () =>
        {
            try
            {
                var result = await this.api.GetMyReadingsAsync(this.api.Token).ConfigureAwait(false);
                this.myReadings = result ?? new List<Reading>();
            }
            catch (Exception ex)
            {
                this.myReadingsError = ex.Message;
            }
            finally
            {
                this.myReadingsLoading = false;
            }
        });
    }

    public void Dispose()
    {
    }

    private sealed record DeckGroup(string Header, List<Deck> Decks);
}

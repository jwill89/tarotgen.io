using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Linq;
using System.Numerics;
using System.Threading.Tasks;
using Dalamud.Bindings.ImGui;
using Dalamud.Interface.Utility;
using Dalamud.Interface.Utility.Raii;
using Dalamud.Utility;
using TarotGen.Plugin.Models;
using TarotGen.Plugin.Services;

namespace TarotGen.Plugin.Windows;

/// <summary>
/// Renders a single reading inside the main window's "Reading" tab. For a spread
/// it lays the cards out on a square canvas at their spread positions (matching
/// the web layout) and lists each position's meaning; for a free draw it shows a
/// wrapped grid. Cards are always face-up; clicking one opens a full-res lightbox.
/// </summary>
public sealed class ReadingPanel
{
    private static readonly Vector4 ErrorColor = new(1f, 0.45f, 0.45f, 1f);
    private static readonly Vector4 AccentColor = new(0.85f, 0.75f, 0.5f, 1f);

    private readonly TarotApiClient api;
    private readonly CardTextureCache textures;
    private readonly ShareRelay shareRelay;
    private readonly GameSocial social;

    private Reading? reading;
    private string codeBuffer = "";
    private string passwordBuffer = "";
    private volatile bool loading;
    private string? error;

    private LightboxCard? lightbox;
    private bool lightboxShowReversed;
    private bool openLightboxRequest;

    // Card scan-error reporting (from the lightbox). Keyed by (deckId, cardId);
    // ConcurrentDictionary so the background report task and Draw don't race.
    private readonly ConcurrentDictionary<(int, int), byte> reportedCards = new();
    private volatile bool reportingCard;
    private string? reportCardStatus;

    private volatile bool sharing;
    private string? shareStatus;

    public ReadingPanel(TarotApiClient api, CardTextureCache textures, ShareRelay shareRelay, GameSocial social)
    {
        this.api = api;
        this.textures = textures;
        this.shareRelay = shareRelay;
        this.social = social;
    }

    public bool HasReading => this.reading != null;

    public void SetReading(Reading value)
    {
        this.reading = value;
        this.error = null;
        this.passwordBuffer = "";
    }

    public void OpenReading(string idOrUrl)
    {
        this.codeBuffer = idOrUrl;
        StartLoad(idOrUrl);
    }

    public void Draw()
    {
        DrawContent();

        // Process a card-click from this frame, then render the lightbox popup.
        if (this.openLightboxRequest)
        {
            ImGui.OpenPopup("##cardLightbox");
            this.openLightboxRequest = false;
        }

        DrawLightbox();
    }

    private void DrawContent()
    {
        ImGui.SetNextItemWidth(220 * ImGuiHelpers.GlobalScale);
        ImGui.InputTextWithHint("##code", "reading code or share URL", ref this.codeBuffer, 256);
        ImGui.SameLine();
        using (ImRaii.Disabled(this.loading || string.IsNullOrWhiteSpace(this.codeBuffer)))
        {
            if (ImGui.Button("Open"))
                StartLoad(this.codeBuffer);
        }

        ImGui.Separator();

        if (this.error != null)
            ImGui.TextColored(ErrorColor, this.error);

        if (this.loading)
        {
            ImGui.TextUnformatted("Loading…");
            return;
        }

        if (this.reading == null)
        {
            ImGui.TextDisabled("Generate a reading, or open one by its share code above.");
            return;
        }

        if (this.reading.Locked)
        {
            DrawLocked();
            return;
        }

        DrawReading(this.reading);
    }

    private void DrawLocked()
    {
        ImGui.TextUnformatted($"\"{this.reading!.ReadingName ?? "This reading"}\" is password protected.");
        ImGui.SetNextItemWidth(200 * ImGuiHelpers.GlobalScale);
        ImGui.InputText("##pw", ref this.passwordBuffer, 128, ImGuiInputTextFlags.Password);
        ImGui.SameLine();
        using (ImRaii.Disabled(this.loading || string.IsNullOrEmpty(this.passwordBuffer)))
        {
            if (ImGui.Button("Unlock"))
                StartUnlock(this.reading!.ReadingId, this.passwordBuffer);
        }
    }

    private void DrawReading(Reading r)
    {
        var info = r.ReadingInfo;

        ImGui.TextUnformatted(ReadingFormat.Title(r));
        var meta = $"by {r.Reader ?? "Guest"}";
        if (info?.Spread != null)
            meta += $"  ·  {info.Spread.Name}";
        if (!string.IsNullOrEmpty(r.ReadingTime))
            meta += $"  ·  {r.ReadingTime}";
        ImGui.TextDisabled(meta);

        var url = $"{this.api.SiteBase}/reading/{r.ReadingId}";

        // The share code is what people paste into a /tell or party chat.
        ImGui.Spacing();
        ImGui.TextUnformatted("Share code:");
        ImGui.SameLine();
        ImGui.TextColored(AccentColor, r.ReadingId);
        ImGui.SameLine();
        if (ImGui.SmallButton("Copy code"))
            ImGui.SetClipboardText(r.ReadingId);
        ImGui.SameLine();
        if (ImGui.SmallButton("Copy link"))
            ImGui.SetClipboardText(url);
        ImGui.SameLine();
        Ui.HelpMarker("Paste the code into a /tell or party chat — anyone can open it with /tarot <code> or on the website.");

        if (ImGui.Button("Open in browser"))
            Util.OpenLink(url);

        if (this.shareRelay.CanShare)
        {
            ImGui.SameLine();
            using (ImRaii.Disabled(this.sharing))
            {
                if (ImGui.Button("Share to a player…"))
                {
                    this.shareStatus = null;
                    ImGui.OpenPopup("##sharePicker");
                }
            }
        }

        // No lock control here: plugin-generated readings are always auto-locked on
        // creation, so there is nothing to lock and no state worth surfacing.
        DrawSharePicker(r);

        if (!string.IsNullOrEmpty(this.shareStatus))
        {
            ImGui.Spacing();
            ImGui.TextDisabled(this.shareStatus);
        }

        if (info?.Spread != null && !string.IsNullOrWhiteSpace(info.Spread.Description))
        {
            ImGui.Spacing();
            ImGui.TextWrapped(info.Spread.Description);
        }

        ImGui.Separator();
        ImGui.TextDisabled("Click a card to view it full size.");

        if (info == null || info.Draw.Count == 0)
        {
            ImGui.TextDisabled("This reading has no cards.");
            return;
        }

        if (info.Spread != null && info.Spread.Positions.Count > 0)
            DrawSpread(info);
        else
            DrawGrid(info);
    }

    // ── Spread reading: positioned canvas + per-position details ──────────────

    private void DrawSpread(ReadingInfo info)
    {
        var positions = info.Spread!.Positions.OrderBy(p => p.Order).ToList();
        var cards = info.Draw;

        // Two columns: the positioned canvas on the left, the per-position card
        // meanings on the right — mirroring the website and the New Reading preview.
        using var table = ImRaii.Table("##readingspread", 2,
            ImGuiTableFlags.BordersInnerV | ImGuiTableFlags.Resizable);
        if (!table)
        {
            DrawSpreadCanvas(info, positions, cards);
            ImGui.Spacing();
            ImGui.TextColored(AccentColor, "Card Details");
            ImGui.Separator();
            DrawDetails(positions, cards);
            return;
        }

        ImGui.TableSetupColumn("Spread", ImGuiTableColumnFlags.WidthStretch, 0.58f);
        ImGui.TableSetupColumn("Details", ImGuiTableColumnFlags.WidthStretch, 0.42f);
        ImGui.TableNextRow();

        ImGui.TableNextColumn();
        DrawSpreadCanvas(info, positions, cards);

        ImGui.TableNextColumn();
        ImGui.TextColored(AccentColor, "Card Details");
        ImGui.Separator();
        DrawDetails(positions, cards);
    }

    private void DrawSpreadCanvas(ReadingInfo info, List<SpreadPosition> positions, IReadOnlyList<DrawCard> cards)
    {
        var fit = SpreadLayout.ComputeFit(positions);
        var sizePct = SpreadLayout.CardSizePct(fit);

        float scale = ImGuiHelpers.GlobalScale;
        float avail = ImGui.GetContentRegionAvail().X;
        float canvas = Math.Clamp(avail, 180f, 460f * scale);

        var origin = ImGui.GetCursorScreenPos();
        ImGui.InvisibleButton("##spreadcanvas", new Vector2(canvas, canvas));
        bool canvasClicked = ImGui.IsItemClicked();

        var dl = ImGui.GetWindowDrawList();
        dl.AddRect(origin, origin + new Vector2(canvas, canvas), 0x22FFFFFFu, 6f);

        float cardWpx = sizePct.X / 100f * canvas;
        float cardHpx = sizePct.Y / 100f * canvas;

        Vector2 CenterOf(SpreadPosition p)
        {
            var proj = SpreadLayout.Project(fit, p.X, p.Y);
            return origin + new Vector2(proj.X / 100f * canvas, proj.Y / 100f * canvas);
        }

        for (int i = 0; i < positions.Count; i++)
        {
            var p = positions[i];
            var center = CenterOf(p);
            var card = i < cards.Count ? cards[i] : null;
            var tex = card != null ? this.textures.GetCard(info.DeckId, card.CardId) : null;

            if (tex != null)
                SpreadDraw.RotatedImage(dl, tex.Handle, center, cardWpx, cardHpx, p.Rotation, card!.Reversed);
            else
                SpreadDraw.RotatedRect(dl, center, cardWpx, cardHpx, p.Rotation, 0x55FFFFFFu); // loading placeholder
        }

        for (int i = 0; i < positions.Count; i++)
        {
            var center = CenterOf(positions[i]);
            var badge = center + new Vector2(-cardWpx / 2f + 9f * scale, -cardHpx / 2f + 9f * scale);
            SpreadDraw.OrderBadge(dl, badge, positions[i].Order, scale);
        }

        // Click → lightbox (topmost card wins).
        if (canvasClicked)
        {
            var mouse = ImGui.GetMousePos();
            for (int i = positions.Count - 1; i >= 0; i--)
            {
                if (SpreadDraw.HitTest(mouse, CenterOf(positions[i]), cardWpx, cardHpx, positions[i].Rotation)
                    && i < cards.Count)
                {
                    OpenLightbox(info.DeckId, cards[i], positions[i].Title);
                    break;
                }
            }
        }
    }

    private void DrawDetails(IReadOnlyList<SpreadPosition> positions, IReadOnlyList<DrawCard> cards)
    {
        for (int i = 0; i < positions.Count; i++)
        {
            var p = positions[i];
            var card = i < cards.Count ? cards[i] : null;

            ImGui.TextColored(AccentColor, $"{p.Order}.");
            ImGui.SameLine();
            if (!string.IsNullOrWhiteSpace(p.Title))
            {
                ImGui.TextUnformatted(p.Title);
                ImGui.SameLine();
                ImGui.TextDisabled("—");
                ImGui.SameLine();
            }

            if (card != null)
                ImGui.TextUnformatted(card.CardName + (card.Reversed ? "  (reversed)" : ""));
            else
                ImGui.TextDisabled("—");
        }
    }

    // ── Free draw: a wrapped grid of cards ────────────────────────────────────

    private void DrawGrid(ReadingInfo info)
    {
        var deck = this.api.DeckById(info.DeckId);
        double aw = deck?.CardAspectW ?? 5.0;
        double ah = deck?.CardAspectH ?? 8.6;
        float scale = ImGuiHelpers.GlobalScale;
        float cardW = 100f * scale;
        float cardH = (float)(cardW * (ah / aw));
        float spacing = ImGui.GetStyle().ItemSpacing.X;
        float avail = ImGui.GetContentRegionAvail().X;
        int perRow = Math.Max(1, (int)(avail / (cardW + spacing)));

        for (int i = 0; i < info.Draw.Count; i++)
        {
            if (i % perRow != 0)
                ImGui.SameLine();
            DrawGridCard(info.DeckId, i + 1, info.Draw[i], cardW, cardH);
        }
    }

    private void DrawGridCard(int deckId, int order, DrawCard card, float cardW, float cardH)
    {
        ImGui.BeginGroup();

        var size = new Vector2(cardW, cardH);
        var topLeft = ImGui.GetCursorScreenPos();
        ImGui.InvisibleButton($"##gcard{deckId}_{order}_{card.CardId}", size);
        bool clicked = ImGui.IsItemClicked();

        var dl = ImGui.GetWindowDrawList();
        var tex = this.textures.GetCard(deckId, card.CardId);
        if (tex != null)
        {
            var uvMin = card.Reversed ? new Vector2(1, 1) : Vector2.Zero;
            var uvMax = card.Reversed ? Vector2.Zero : Vector2.One;
            dl.AddImage(tex.Handle, topLeft, topLeft + size, uvMin, uvMax, 0xFFFFFFFFu);
        }
        else
        {
            dl.AddRectFilled(topLeft, topLeft + size, 0x40303030u, 4f);
            dl.AddRect(topLeft, topLeft + size, 0x33FFFFFFu, 4f);
        }

        var scale = ImGuiHelpers.GlobalScale;
        SpreadDraw.OrderBadge(dl, topLeft + new Vector2(9f * scale, 9f * scale), order, scale);

        ImGui.PushTextWrapPos(ImGui.GetCursorPosX() + cardW);
        ImGui.TextUnformatted(card.CardName + (card.Reversed ? "  (rev)" : ""));
        ImGui.PopTextWrapPos();

        ImGui.EndGroup();

        if (clicked)
            OpenLightbox(deckId, card);
    }

    // ── Lightbox ──────────────────────────────────────────────────────────────

    private void OpenLightbox(int deckId, DrawCard card, string? positionTitle = null)
    {
        this.lightbox = new LightboxCard(deckId, card.CardId, card.CardName, card.Reversed, positionTitle);
        this.lightboxShowReversed = card.Reversed;
        this.reportCardStatus = null;
        this.openLightboxRequest = true;
    }

    private void DrawLightbox()
    {
        var vp = ImGui.GetMainViewport();
        ImGui.SetNextWindowPos(vp.WorkPos + (vp.WorkSize / 2f), ImGuiCond.Appearing, new Vector2(0.5f, 0.5f));

        using var popup = ImRaii.Popup("##cardLightbox", ImGuiWindowFlags.NoMove | ImGuiWindowFlags.AlwaysAutoResize);
        if (!popup)
        {
            this.lightbox = null;
            return;
        }

        if (this.lightbox is not { } lb)
        {
            ImGui.CloseCurrentPopup();
            return;
        }

        var tex = this.textures.GetCard(lb.DeckId, lb.CardId);
        if (tex != null)
        {
            var nat = tex.Size;
            float maxH = vp.WorkSize.Y * 0.82f;
            float maxW = vp.WorkSize.X * 0.9f;
            float scale = Math.Min(maxH / Math.Max(1f, nat.Y), maxW / Math.Max(1f, nat.X));
            var display = new Vector2(nat.X * scale, nat.Y * scale);

            var uvMin = this.lightboxShowReversed ? new Vector2(1, 1) : Vector2.Zero;
            var uvMax = this.lightboxShowReversed ? Vector2.Zero : Vector2.One;
            ImGui.Image(tex.Handle, display, uvMin, uvMax);
        }
        else
        {
            ImGui.TextUnformatted("Loading…");
        }

        // Card name with its position meaning, plus orientation.
        var caption = lb.Name;
        if (!string.IsNullOrWhiteSpace(lb.PositionTitle))
            caption = $"{lb.PositionTitle}  ·  {caption}";
        if (lb.Reversed)
            caption += this.lightboxShowReversed ? "  (Reversed)" : "  (shown upright)";
        ImGui.TextWrapped(caption);

        if (lb.Reversed)
        {
            if (ImGui.Button(this.lightboxShowReversed ? "Flip upright" : "Show reversed"))
                this.lightboxShowReversed = !this.lightboxShowReversed;
            ImGui.SameLine();
        }

        if (ImGui.Button("Close"))
            ImGui.CloseCurrentPopup();

        ImGui.SameLine();
        bool reported = this.reportedCards.ContainsKey((lb.DeckId, lb.CardId));
        using (ImRaii.Disabled(this.reportingCard || reported))
        {
            if (ImGui.Button(reported ? "Reported" : "Report scan issue"))
                StartReportCard(lb.DeckId, lb.CardId, lb.Name);
        }

        if (!string.IsNullOrEmpty(this.reportCardStatus))
            ImGui.TextDisabled(this.reportCardStatus);
    }

    // ── Async actions ─────────────────────────────────────────────────────────

    private void StartLoad(string idOrUrl)
    {
        var id = TarotApiClient.ExtractReadingId(idOrUrl);
        if (id == null)
        {
            this.error = "That doesn't look like a reading code or URL.";
            return;
        }

        this.loading = true;
        this.error = null;
        _ = Task.Run(async () =>
        {
            try
            {
                this.reading = await this.api.GetReadingAsync(id, this.api.Token).ConfigureAwait(false);
                this.passwordBuffer = "";
            }
            catch (Exception ex)
            {
                this.error = ex.Message;
            }
            finally
            {
                this.loading = false;
            }
        });
    }

    private void StartUnlock(string readingId, string password)
    {
        this.loading = true;
        this.error = null;
        _ = Task.Run(async () =>
        {
            try
            {
                this.reading = await this.api.UnlockReadingAsync(readingId, password, this.api.Token).ConfigureAwait(false);
                this.passwordBuffer = "";
            }
            catch (Exception ex)
            {
                this.error = ex.Message;
            }
            finally
            {
                this.loading = false;
            }
        });
    }

    // ── Share picker ──────────────────────────────────────────────────────────

    private void DrawSharePicker(Reading r)
    {
        using var popup = ImRaii.Popup("##sharePicker");
        if (!popup)
            return;

        if (this.social.Self() is not { } me)
        {
            ImGui.TextDisabled("Log in to a character to share readings.");
            return;
        }

        ImGui.TextDisabled("Send this reading to:");
        ImGui.Separator();

        bool any = false;

        if (this.social.Target() is { } target)
        {
            any = true;
            if (ImGui.Selectable($"{target.Name} ({target.World})  — current target"))
            {
                StartShare(r.ReadingId, target, me);
                ImGui.CloseCurrentPopup();
            }
        }

        foreach (var member in this.social.AllPartyMembers())
        {
            any = true;
            if (ImGui.Selectable($"{member.Name} ({member.World})"))
            {
                StartShare(r.ReadingId, member, me);
                ImGui.CloseCurrentPopup();
            }
        }

        if (!any)
            ImGui.TextDisabled("No party members or player target found.\nTarget a player or join a party, then try again.");
    }

    private void StartReportCard(int deckId, int cardId, string cardName)
    {
        this.reportingCard = true;
        this.reportCardStatus = "Reporting…";
        _ = Task.Run(async () =>
        {
            try
            {
                var status = await this.api.ReportCardAsync(deckId, cardId, cardName, this.api.Token)
                    .ConfigureAwait(false);
                this.reportedCards[(deckId, cardId)] = 0;
                this.reportCardStatus = status == "already_reported"
                    ? "You've already reported this card recently. Thanks!"
                    : "Thanks! This card has been reported for re-scanning.";
            }
            catch (TarotApiException ex)
            {
                this.reportCardStatus = ex.Message;
            }
            catch (Exception)
            {
                this.reportCardStatus = "Couldn't send the report. Please try again.";
            }
            finally
            {
                this.reportingCard = false;
            }
        });
    }

    private void StartShare(string readingId, GameSocial.Recipient to, GameSocial.Recipient self)
    {
        this.sharing = true;
        this.shareStatus = $"Sending to {to.Name}…";
        _ = Task.Run(async () =>
        {
            var result = await this.shareRelay.SendAsync(readingId, to, self).ConfigureAwait(false);
            this.shareStatus = result;
            this.sharing = false;
        });
    }

    private readonly record struct LightboxCard(int DeckId, int CardId, string Name, bool Reversed, string? PositionTitle);
}

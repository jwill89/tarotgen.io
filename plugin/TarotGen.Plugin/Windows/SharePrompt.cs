using System;
using System.Collections.Concurrent;
using System.Collections.Generic;
using System.Numerics;
using Dalamud.Bindings.ImGui;
using Dalamud.Interface.Utility;
using Dalamud.Interface.Utility.Raii;
using Dalamud.Interface.Windowing;
using TarotGen.Plugin.Models;

namespace TarotGen.Plugin.Windows;

/// <summary>
/// The passive "‹A› wants to share a Tarot reading with you" popup. It only ever
/// displays — nothing is fetched until the user clicks <b>View</b> (which is what
/// keeps the networked share feature Dalamud-compliant). Messages arrive from the
/// background <see cref="Services.ShareRelay"/> poll and queue here.
/// </summary>
public sealed class SharePrompt : Window
{

    private readonly ConcurrentQueue<ShareMessage> pending = new();
    private readonly HashSet<int> seenIds = new();
    private readonly object gate = new();

    private readonly Action<string> onView;
    private readonly Action<int> onBlock;

    private ShareMessage? current;

    public SharePrompt(Action<string> onView, Action<int> onBlock)
        : base("Shared Tarot Reading###TarotGenShare",
            ImGuiWindowFlags.NoCollapse | ImGuiWindowFlags.AlwaysAutoResize | ImGuiWindowFlags.NoDocking)
    {
        this.onView = onView;
        this.onBlock = onBlock;
        this.IsOpen = false;
    }

    /// <summary>Queue an incoming share (thread-safe; called off the framework thread).</summary>
    public void Enqueue(ShareMessage message)
    {
        lock (this.gate)
        {
            if (!this.seenIds.Add(message.Id))
                return;
        }

        this.pending.Enqueue(message);
        this.IsOpen = true;
    }

    public override void Draw()
    {
        if (this.current is null && !this.pending.TryDequeue(out this.current))
        {
            this.IsOpen = false;
            return;
        }

        var msg = this.current!;
        float scale = ImGuiHelpers.GlobalScale;

        ImGui.PushTextWrapPos(320 * scale);
        ImGui.TextWrapped($"{msg.SenderLabel} wants to share a Tarot reading with you.");
        ImGui.PopTextWrapPos();

        ImGui.Spacing();

        if (Ui.PrimaryButton("View reading", new Vector2(150 * scale, 30 * scale)))
        {
            this.onView(msg.Payload);
            Advance();
            return;
        }

        ImGui.SameLine();
        if (Ui.Button("Dismiss", new Vector2(90 * scale, 30 * scale)))
        {
            Advance();
            return;
        }

        ImGui.Spacing();
        ImGui.TextDisabled("Not interested in shares from this person?");
        ImGui.SameLine();
        if (Ui.Button("Block sender"))
        {
            if (msg.SenderClientId > 0)
                this.onBlock(msg.SenderClientId);
            Advance();
            return;
        }

        var remaining = this.pending.Count;
        if (remaining > 0)
        {
            ImGui.Spacing();
            ImGui.TextDisabled($"+{remaining} more waiting");
        }
    }

    private void Advance()
    {
        // Drop the current message; the next Draw dequeues the next or closes.
        this.current = null;
    }
}

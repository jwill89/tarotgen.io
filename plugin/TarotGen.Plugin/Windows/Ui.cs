using System.Numerics;
using Dalamud.Bindings.ImGui;

namespace TarotGen.Plugin.Windows;

/// <summary>Small shared ImGui helpers for consistent sectioning + inline help.</summary>
internal static class Ui
{
    public static readonly Vector4 SectionColor = new(0.85f, 0.75f, 0.5f, 1f);
    public static readonly Vector4 AccentColor = new(0.72f, 0.62f, 0.9f, 1f);

    /// <summary>A coloured section header with a rule under it.</summary>
    public static void Section(string label)
    {
        ImGui.Spacing();
        ImGui.TextColored(SectionColor, label);
        ImGui.Separator();
    }

    /// <summary>A dimmed "(?)" that shows <paramref name="text"/> as a wrapped tooltip on hover.</summary>
    public static void HelpMarker(string text)
    {
        ImGui.TextDisabled("(?)");
        if (!ImGui.IsItemHovered())
            return;

        ImGui.BeginTooltip();
        ImGui.PushTextWrapPos(ImGui.GetFontSize() * 24f);
        ImGui.TextUnformatted(text);
        ImGui.PopTextWrapPos();
        ImGui.EndTooltip();
    }
}

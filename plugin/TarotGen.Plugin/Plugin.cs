using System;
using System.IO;
using System.Net.Http;
using Dalamud.Game.Command;
using Dalamud.Interface.Windowing;
using Dalamud.Plugin;
using Dalamud.Plugin.Services;
using TarotGen.Plugin.Services;
using TarotGen.Plugin.Windows;

namespace TarotGen.Plugin;

/// <summary>
/// Composition root. Wires services + windows, registers /tarot, and tears
/// everything down symmetrically on unload. P0 (anonymous) scope: generate and
/// view readings; account linking (P1) and the share relay (P2) are additive.
/// </summary>
public sealed class Plugin : IDalamudPlugin
{
    private const string CommandName = "/tarot";

    private readonly IDalamudPluginInterface pluginInterface;
    private readonly ICommandManager commandManager;
    private readonly IPluginLog log;

    private readonly HttpClient http;
    private readonly Configuration config;
    private readonly TokenStore tokenStore;
    private readonly TarotApiClient api;
    private readonly LinkService linkService;
    private readonly CardTextureCache textures;
    private readonly GameSocial social;
    private readonly ShareRelay shareRelay;

    private readonly WindowSystem windowSystem = new("TarotGen");
    private readonly MainWindow mainWindow;
    private readonly ReadingPanel readingPanel;
    private readonly SharePrompt sharePrompt;

    public Plugin(
        IDalamudPluginInterface pluginInterface,
        ICommandManager commandManager,
        ITextureProvider textureProvider,
        IFramework framework,
        IPlayerState playerState,
        IPartyList partyList,
        ITargetManager targetManager,
        IDataManager dataManager,
        INotificationManager notificationManager,
        IPluginLog log)
    {
        this.pluginInterface = pluginInterface;
        this.commandManager = commandManager;
        this.log = log;

        this.config = pluginInterface.GetPluginConfig() as Configuration ?? new Configuration();

        this.http = new HttpClient();
        var version = typeof(Plugin).Assembly.GetName().Version?.ToString() ?? "0.0.0";
        this.http.DefaultRequestHeaders.UserAgent.ParseAdd($"TarotGenPlugin/{version} (+https://tarotgen.io)");

        this.tokenStore = new TokenStore(this.config, pluginInterface, log);
        this.api = new TarotApiClient(this.http, this.tokenStore, log);
        this.linkService = new LinkService(this.http, this.tokenStore, log);

        var cacheDir = Path.Combine(pluginInterface.GetPluginConfigDirectory(), "cards");
        this.textures = new CardTextureCache(this.http, textureProvider, log, cacheDir);

        this.social = new GameSocial(playerState, partyList, targetManager, dataManager);

        // The share relay's popup opens a shared reading via the main window; blocking
        // a sender routes through the relay. Both fields are set below before any click.
        this.sharePrompt = new SharePrompt(
            onView: id => this.mainWindow!.ShowReading(id),
            onBlock: clientId => this.shareRelay!.Block(clientId));
        this.shareRelay = new ShareRelay(
            this.api, this.config, framework, this.social, notificationManager, log, this.sharePrompt.Enqueue);

        this.readingPanel = new ReadingPanel(this.api, this.textures, this.shareRelay, this.social);
        this.mainWindow = new MainWindow(
            this.api, this.readingPanel, this.linkService, this.shareRelay, this.social, this.config, this.pluginInterface);

        this.windowSystem.AddWindow(this.mainWindow);
        this.windowSystem.AddWindow(this.sharePrompt);

        this.commandManager.AddHandler(CommandName, new CommandInfo(OnCommand)
        {
            HelpMessage = "Open TarotGen. Also: /tarot <code|url> opens that reading.",
        });

        this.pluginInterface.UiBuilder.Draw += this.windowSystem.Draw;
        this.pluginInterface.UiBuilder.OpenMainUi += this.OpenMain;
        this.pluginInterface.UiBuilder.OpenConfigUi += this.OpenConfig;

        this.shareRelay.Start();
    }

    public void Dispose()
    {
        this.pluginInterface.UiBuilder.Draw -= this.windowSystem.Draw;
        this.pluginInterface.UiBuilder.OpenMainUi -= this.OpenMain;
        this.pluginInterface.UiBuilder.OpenConfigUi -= this.OpenConfig;

        this.commandManager.RemoveHandler(CommandName);
        this.windowSystem.RemoveAllWindows();

        this.mainWindow.Dispose();

        this.shareRelay.Dispose();
        this.textures.Dispose();
        this.api.Dispose();
        this.http.Dispose();
    }

    private void OnCommand(string command, string args)
    {
        args = args.Trim();

        if (args.Length == 0)
        {
            this.mainWindow.Toggle();
            return;
        }

        // Anything else: treat it as a reading code or share URL.
        var id = TarotApiClient.ExtractReadingId(args);
        if (id != null)
            this.mainWindow.ShowReading(id);
        else
            this.log.Warning($"Couldn't read a reading code from '{args}'.");
    }

    private void OpenMain() => this.mainWindow.Toggle();

    // The config/gear button opens the window on its Settings tab.
    private void OpenConfig() => this.mainWindow.ShowSettings();
}

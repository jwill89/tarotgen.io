using System;
using System.Net;
using System.Net.Http;
using System.Net.Http.Json;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json.Serialization;
using System.Threading;
using System.Threading.Tasks;
using Dalamud.Plugin.Services;
using Dalamud.Utility;

namespace TarotGen.Plugin.Services;

/// <summary>
/// Drives the browser account-link flow: OAuth-style authorization code + PKCE
/// over an RFC 8252 loopback redirect. Opens the site's /authorize page in the
/// user's browser, catches the redirect on a local listener, and exchanges the
/// code for a token via POST /api/plugin/token. See plugin/docs/auth.md.
/// </summary>
public sealed class LinkService
{
    private const int TimeoutMinutes = 3;

    private readonly HttpClient http;
    private readonly TokenStore tokens;
    private readonly IPluginLog log;

    private HttpListener? activeListener;
    private CancellationTokenSource? flowCts;

    public LinkService(HttpClient http, TokenStore tokens, IPluginLog log)
    {
        this.http = http;
        this.tokens = tokens;
        this.log = log;
    }

    /// <summary>True while a link attempt is in flight.</summary>
    public bool IsBusy { get; private set; }

    /// <summary>Last status/error message for the UI (may be null).</summary>
    public string? Status { get; private set; }

    private static string SiteBase => Configuration.SiteUrl;

    /// <summary>Begin linking (non-blocking; opens the browser). No-op if already busy.</summary>
    public void StartLink()
    {
        if (this.IsBusy)
            return;

        this.IsBusy = true;
        this.Status = "Waiting for approval in your browser…";
        this.flowCts = new CancellationTokenSource();
        _ = Task.Run(RunAsync);
    }

    /// <summary>Abort an in-flight link attempt.</summary>
    public void Cancel()
    {
        try { this.activeListener?.Stop(); }
        catch { /* stopping unblocks GetContextAsync */ }
        this.flowCts?.Cancel();
    }

    public void Unlink()
    {
        this.tokens.Clear();
        this.Status = null;
    }

    private async Task RunAsync()
    {
        var cts = this.flowCts!;
        HttpListener? listener = null;
        try
        {
            var verifier = RandomUrlToken(32);
            var challenge = Base64Url(SHA256.HashData(Encoding.ASCII.GetBytes(verifier)));
            var state = RandomUrlToken(16);

            var port = FreeLoopbackPort();
            var redirectUri = $"http://127.0.0.1:{port}/callback";

            listener = new HttpListener();
            listener.Prefixes.Add($"http://127.0.0.1:{port}/");
            listener.Start();
            this.activeListener = listener;

            var authorizeUrl =
                $"{SiteBase}/authorize?response_type=code&client_id=plugin"
                + $"&redirect_uri={Uri.EscapeDataString(redirectUri)}&scope=account"
                + $"&code_challenge={challenge}&code_challenge_method=S256"
                + $"&state={Uri.EscapeDataString(state)}";
            Util.OpenLink(authorizeUrl);

            // Wait for the browser to redirect back — or a cancel / timeout.
            using var deadline = CancellationTokenSource.CreateLinkedTokenSource(cts.Token);
            deadline.CancelAfter(TimeSpan.FromMinutes(TimeoutMinutes));

            var contextTask = listener.GetContextAsync();
            var finished = await Task.WhenAny(contextTask, Task.Delay(Timeout.Infinite, deadline.Token))
                .ConfigureAwait(false);
            if (finished != contextTask)
            {
                this.Status = cts.IsCancellationRequested ? "Link cancelled." : "Link timed out — try again.";
                return;
            }

            var context = await contextTask.ConfigureAwait(false);
            var code = context.Request.QueryString["code"];
            var returnedState = context.Request.QueryString["state"];
            var ok = !string.IsNullOrEmpty(code) && returnedState == state;

            RespondHtml(context, ok
                ? "TarotGen linked. You can close this tab and return to the game."
                : "TarotGen link cancelled. You can close this tab.");

            if (!ok)
                throw new InvalidOperationException("Authorization was cancelled or the state did not match.");

            using var response = await this.http
                .PostAsJsonAsync($"{SiteBase}/api/plugin/token", new { code, code_verifier = verifier })
                .ConfigureAwait(false);
            if (!response.IsSuccessStatusCode)
                throw new InvalidOperationException($"Token exchange failed (HTTP {(int)response.StatusCode}).");

            var payload = await response.Content.ReadFromJsonAsync<TokenResponse>().ConfigureAwait(false);
            if (payload?.Token is not { Length: > 0 })
                throw new InvalidOperationException("The server did not return a token.");

            this.tokens.Save(payload.Token, payload.DisplayName ?? string.Empty);
            this.Status = $"Linked as {this.tokens.LinkedName}";
        }
        catch (Exception ex)
        {
            // A Cancel() surfaces here (the listener was stopped) — report it as such.
            this.Status = cts.IsCancellationRequested ? "Link cancelled." : $"Link failed: {ex.Message}";
            if (!cts.IsCancellationRequested)
                this.log.Warning($"Account link failed: {ex.Message}");
        }
        finally
        {
            try { listener?.Stop(); }
            catch { /* best-effort */ }
            this.activeListener = null;
            cts.Dispose();
            this.flowCts = null;
            this.IsBusy = false;
        }
    }

    private static int FreeLoopbackPort()
    {
        var probe = new TcpListener(IPAddress.Loopback, 0);
        probe.Start();
        var port = ((IPEndPoint)probe.LocalEndpoint).Port;
        probe.Stop();
        return port;
    }

    private static string RandomUrlToken(int bytes)
    {
        var buffer = new byte[bytes];
        RandomNumberGenerator.Fill(buffer);
        return Base64Url(buffer);
    }

    private static string Base64Url(byte[] data) =>
        Convert.ToBase64String(data).TrimEnd('=').Replace('+', '-').Replace('/', '_');

    private static void RespondHtml(HttpListenerContext context, string message)
    {
        var html = Encoding.UTF8.GetBytes(
            "<!doctype html><html><head><meta charset='utf-8'><title>TarotGen</title></head>"
            + "<body style='font-family:sans-serif;text-align:center;padding-top:3rem'>"
            + $"<h2>{message}</h2></body></html>");
        context.Response.ContentType = "text/html; charset=utf-8";
        context.Response.ContentLength64 = html.Length;
        context.Response.OutputStream.Write(html, 0, html.Length);
        context.Response.Close();
    }

    private sealed class TokenResponse
    {
        [JsonPropertyName("token")]
        public string? Token { get; set; }

        [JsonPropertyName("display_name")]
        public string? DisplayName { get; set; }
    }
}

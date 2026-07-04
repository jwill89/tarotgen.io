using System;
using System.Collections.Generic;
using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.RegularExpressions;
using System.Threading;
using System.Threading.Tasks;
using Dalamud.Plugin.Services;
using TarotGen.Plugin.Models;

namespace TarotGen.Plugin.Services;

/// <summary>
/// Thin async wrapper over the TarotGen REST API. All calls run off the framework
/// thread; callers marshal results back before touching ImGui. A linked account's
/// Bearer token (from <see cref="TokenStore"/>) is attached to every request — the
/// server treats it as optional on public reads and required on account routes.
/// Decks/spreads are cached after first fetch.
/// </summary>
public sealed class TarotApiClient : IDisposable
{
    private static readonly JsonSerializerOptions JsonOpts = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
        PropertyNameCaseInsensitive = true,
        DefaultIgnoreCondition = System.Text.Json.Serialization.JsonIgnoreCondition.WhenWritingNull,
    };

    private readonly HttpClient http;
    private readonly TokenStore tokens;
    private readonly IPluginLog log;
    private readonly CancellationTokenSource cts = new();

    private IReadOnlyList<Deck>? deckCache;
    private IReadOnlyList<Spread>? spreadCache;
    private Dictionary<int, Deck> decksById = new();

    public TarotApiClient(HttpClient http, TokenStore tokens, IPluginLog log)
    {
        this.http = http;
        this.tokens = tokens;
        this.log = log;
    }

    /// <summary>The plugin's linked cancellation token (cancelled on dispose).</summary>
    public CancellationToken Token => this.cts.Token;

    public bool IsLinked => this.tokens.IsLinked;

    public string LinkedName => this.tokens.LinkedName;

    public bool IsConnected => this.tokens.IsConnected;

    public int ClientId => this.tokens.ClientId;

    private static string ApiBase => Configuration.SiteUrl + "/api";

    public string SiteBase => Configuration.SiteUrl;

    // ── Catalog (cached) ─────────────────────────────────────────────────────

    public async Task<IReadOnlyList<Deck>> GetDecksAsync(CancellationToken ct = default)
    {
        if (this.deckCache is not null)
            return this.deckCache;

        var decks = await GetJsonAsync<List<Deck>>("/decks", ct).ConfigureAwait(false) ?? new();
        this.deckCache = decks;
        this.decksById = new Dictionary<int, Deck>();
        foreach (var d in decks)
            this.decksById[d.DeckId] = d;
        return decks;
    }

    public async Task<IReadOnlyList<Spread>> GetSpreadsAsync(CancellationToken ct = default)
    {
        if (this.spreadCache is not null)
            return this.spreadCache;

        var spreads = await GetJsonAsync<List<Spread>>("/spreads", ct).ConfigureAwait(false) ?? new();
        this.spreadCache = spreads;
        return spreads;
    }

    /// <summary>A deck from the cache, or null if decks aren't loaded / unknown id.</summary>
    public Deck? DeckById(int id) => this.decksById.TryGetValue(id, out var d) ? d : null;

    // ── Readings (anonymous; token-aware) ────────────────────────────────────

    public Task<Reading?> GenerateAsync(GenerateRequest request, CancellationToken ct = default)
        => PostJsonAsync<GenerateRequest, Reading>("/readings/generate", request, ct);

    public Task<Reading?> GetReadingAsync(string readingId, CancellationToken ct = default)
        => GetJsonAsync<Reading>($"/readings/{Uri.EscapeDataString(readingId)}", ct);

    public Task<Reading?> UnlockReadingAsync(string readingId, string password, CancellationToken ct = default)
        => PostJsonAsync<object, Reading>(
            $"/readings/{Uri.EscapeDataString(readingId)}/unlock",
            new { password },
            ct);

    // ── Account (require a linked token) ─────────────────────────────────────

    public Task<List<Reading>?> GetMyReadingsAsync(CancellationToken ct = default)
        => GetJsonAsync<List<Reading>>("/account/readings", ct);

    public Task<Reading?> FinalizeReadingAsync(string readingId, CancellationToken ct = default)
        => PostJsonAsync<object, Reading>(
            $"/readings/{Uri.EscapeDataString(readingId)}/finalize",
            new { },
            ct);

    // ── Share relay (client-token auth) ──────────────────────────────────────

    public Task<ClientView?> RegisterClientAsync(RegisterClientRequest req, CancellationToken ct = default)
        => PostJsonClientAsync<RegisterClientRequest, ClientView>("/plugin/clients/register", req, ct);

    public Task<List<ShareMessage>?> GetInboxAsync(CancellationToken ct = default)
        => GetJsonClientAsync<List<ShareMessage>>("/plugin/inbox", ct);

    /// <summary>Push a reading share. Throws <see cref="TarotApiException"/> on refusal (403/404/429).</summary>
    public Task ShareAsync(ShareRequest req, CancellationToken ct = default)
        => PostJsonClientAsync<ShareRequest, ShareAck>("/plugin/share", req, ct);

    public Task BlockAsync(string action, int clientId, CancellationToken ct = default)
        => PostJsonClientAsync<object, ShareAck>(
            "/plugin/clients/block",
            new { action, client_id = clientId },
            ct);

    // ── HTTP plumbing ────────────────────────────────────────────────────────

    private async Task<T?> GetJsonAsync<T>(string path, CancellationToken ct)
    {
        using var linked = CancellationTokenSource.CreateLinkedTokenSource(ct, this.cts.Token);
        using var request = new HttpRequestMessage(HttpMethod.Get, ApiBase + path);
        AddAuth(request);
        using var resp = await this.http.SendAsync(request, linked.Token).ConfigureAwait(false);
        return await ReadOrThrow<T>(resp, linked.Token).ConfigureAwait(false);
    }

    private async Task<TOut?> PostJsonAsync<TIn, TOut>(string path, TIn body, CancellationToken ct)
    {
        using var linked = CancellationTokenSource.CreateLinkedTokenSource(ct, this.cts.Token);
        using var request = new HttpRequestMessage(HttpMethod.Post, ApiBase + path)
        {
            Content = JsonContent.Create(body, options: JsonOpts),
        };
        AddAuth(request);
        using var resp = await this.http.SendAsync(request, linked.Token).ConfigureAwait(false);
        return await ReadOrThrow<TOut>(resp, linked.Token).ConfigureAwait(false);
    }

    private async Task<T?> GetJsonClientAsync<T>(string path, CancellationToken ct)
    {
        using var linked = CancellationTokenSource.CreateLinkedTokenSource(ct, this.cts.Token);
        using var request = new HttpRequestMessage(HttpMethod.Get, ApiBase + path);
        AddClientAuth(request);
        using var resp = await this.http.SendAsync(request, linked.Token).ConfigureAwait(false);
        return await ReadOrThrow<T>(resp, linked.Token).ConfigureAwait(false);
    }

    private async Task<TOut?> PostJsonClientAsync<TIn, TOut>(string path, TIn body, CancellationToken ct)
    {
        using var linked = CancellationTokenSource.CreateLinkedTokenSource(ct, this.cts.Token);
        using var request = new HttpRequestMessage(HttpMethod.Post, ApiBase + path)
        {
            Content = JsonContent.Create(body, options: JsonOpts),
        };
        AddClientAuth(request);
        using var resp = await this.http.SendAsync(request, linked.Token).ConfigureAwait(false);
        return await ReadOrThrow<TOut>(resp, linked.Token).ConfigureAwait(false);
    }

    private void AddAuth(HttpRequestMessage request)
    {
        var token = this.tokens.Token;
        if (!string.IsNullOrEmpty(token))
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
    }

    private void AddClientAuth(HttpRequestMessage request)
    {
        var token = this.tokens.ClientToken;
        if (!string.IsNullOrEmpty(token))
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
    }

    private async Task<T?> ReadOrThrow<T>(HttpResponseMessage resp, CancellationToken ct)
    {
        if (resp.StatusCode == HttpStatusCode.NoContent)
            return default;

        if (resp.IsSuccessStatusCode)
            return await resp.Content.ReadFromJsonAsync<T>(JsonOpts, ct).ConfigureAwait(false);

        // The API returns { "error": "…" } (or { "errors": [...] }) on failure.
        string message = $"HTTP {(int)resp.StatusCode}";
        try
        {
            var body = await resp.Content.ReadFromJsonAsync<ApiError>(JsonOpts, ct).ConfigureAwait(false);
            if (!string.IsNullOrWhiteSpace(body?.Error))
                message = body!.Error!;
            else if (body?.Errors is { Count: > 0 })
                message = string.Join("; ", body.Errors);
        }
        catch
        {
            /* non-JSON error body — keep the status-code message */
        }

        this.log.Warning($"TarotGen API {(int)resp.StatusCode} for request: {message}");
        throw new TarotApiException((int)resp.StatusCode, message);
    }

    /// <summary>
    /// Pull a reading id out of a raw code or a share URL
    /// (e.g. https://tarotgen.io/reading/ab12cd34ef). reading_id is 10 hex chars.
    /// </summary>
    public static string? ExtractReadingId(string input)
    {
        if (string.IsNullOrWhiteSpace(input))
            return null;

        input = input.Trim();
        var idx = input.IndexOf("/reading/", StringComparison.OrdinalIgnoreCase);
        if (idx >= 0)
            input = input[(idx + "/reading/".Length)..];

        input = input.Split('?', '#')[0].Trim('/');
        var segment = input.Contains('/') ? input[(input.LastIndexOf('/') + 1)..] : input;

        return Regex.IsMatch(segment, "^[0-9a-fA-F]{6,}$") ? segment.ToLowerInvariant()
             : segment.Length > 0 ? segment
             : null;
    }

    public void Dispose()
    {
        this.cts.Cancel();
        this.cts.Dispose();
    }

    private sealed class ApiError
    {
        public string? Error { get; set; }
        public List<string>? Errors { get; set; }
    }
}

/// <summary>Thrown when the API returns a non-2xx status; carries the server message.</summary>
public sealed class TarotApiException : Exception
{
    public int StatusCode { get; }

    public TarotApiException(int statusCode, string message) : base(message)
        => this.StatusCode = statusCode;
}

using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;

namespace OsonPos.PrintAgent;

public static class AgentJson
{
    public static readonly JsonSerializerOptions Options = new(JsonSerializerDefaults.Web) { PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower };
}

public static class AgentVersion
{
    public static string Current => typeof(AgentVersion).Assembly.GetName().Version!.ToString(3);
}

public sealed class AgentSettings
{
    public string ServerUrl { get; set; } = "";
    public string AgentId { get; set; } = "";
    public string StoreId { get; set; } = "";
    public string ProtectedToken { get; set; } = "";
    public bool AutoStart { get; set; }
}

public sealed class PrintProfile
{
    public string Name { get; set; } = "";
    public string PrinterName { get; set; } = "";
    public string LocalKey { get; set; } = "";
    public string DocumentType { get; set; } = "";
    public double WidthMm { get; set; }
    public double? HeightMm { get; set; }
}

public sealed class AgentJob
{
    public string Id { get; set; } = "";
    public string ClaimToken { get; set; } = "";
    public string Sha256 { get; set; } = "";
    public int Copies { get; set; }
    public string Status { get; set; } = "";
    public DateTimeOffset AutoSubmitUntil { get; set; }
    public DateTimeOffset ServerTime { get; set; }
    [System.Text.Json.Serialization.JsonIgnore] public long ReceivedTick { get; set; }
    public PrintProfile Profile { get; set; } = new();
}

public sealed class JobEvent
{
    public string EventId { get; set; } = Guid.NewGuid().ToString();
    public string ClaimToken { get; set; } = "";
    public string Status { get; set; } = "";
    public int? WindowsJobId { get; set; }
    public string? ErrorCode { get; set; }
    public string? Message { get; set; }
}

public sealed class JournalEntry
{
    public int SchemaVersion { get; set; } = 1;
    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
    public AgentJob Job { get; set; } = new();
    public string Stage { get; set; } = "received";
    public int? WindowsJobId { get; set; }
    public JobEvent? PendingEvent { get; set; }
}

public sealed class AgentJournal(string root)
{
    private readonly object gate = new();
    private Dictionary<string, JournalEntry>? cached;
    public string Root { get; } = Path.GetFullPath(root);

    public void Save(JournalEntry entry)
    {
        if (!Guid.TryParse(entry.Job.Id, out _)) throw new InvalidDataException("Job ID yaroqsiz.");
        lock (gate)
        {
            Directory.CreateDirectory(Root);
            entry.UpdatedAt = DateTimeOffset.UtcNow;
            AtomicWrite(Path.Combine(Root, entry.Job.Id + ".json"), JsonSerializer.Serialize(entry, AgentJson.Options));
            if (cached is not null) cached[entry.Job.Id] = entry;
        }
    }

    public List<JournalEntry> ReadAll()
    {
        lock (gate)
        {
            if (cached is not null) return cached.Values.ToList();
            if (!Directory.Exists(Root)) return [];
            var entries = Directory.EnumerateFiles(Root, "*.json").Select(path =>
            {
                var entry = JsonSerializer.Deserialize<JournalEntry>(ReadStable(path), AgentJson.Options)
                    ?? throw new InvalidDataException("Jurnal o‘qilmadi.");
                if (entry.SchemaVersion != 1 || !Guid.TryParse(entry.Job.Id, out _) || Path.GetFileNameWithoutExtension(path) != entry.Job.Id)
                    throw new InvalidDataException("Jurnal versiyasi yoki identifikatori yaroqsiz. Avtomatik chop etish to‘xtatildi.");
                return entry;
            }).ToList();
            cached = entries.ToDictionary(e => e.Job.Id);
            return entries;
        }
    }

    private static string ReadStable(string path)
    {
        using var stream = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete);
        using var reader = new StreamReader(stream, Encoding.UTF8);
        return reader.ReadToEnd();
    }

    public static void AtomicWrite(string path, string content)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(path)!);
        string temp = path + ".tmp";
        using (var stream = new FileStream(temp, FileMode.Create, FileAccess.Write, FileShare.None, 4096, FileOptions.WriteThrough))
        {
            var bytes = Encoding.UTF8.GetBytes(content);
            stream.Write(bytes);
            stream.Flush(true);
        }
        if (File.Exists(path)) File.Replace(temp, path, null);
        else File.Move(temp, path);
    }
}

public interface IAgentApi
{
    Task<AgentJob?> Claim(CancellationToken ct);
    Task<byte[]> Document(AgentJob job, CancellationToken ct);
    Task<string> Event(AgentJob job, JobEvent evt, CancellationToken ct);
}

public sealed class AgentApi : IAgentApi, IDisposable
{
    private readonly HttpClient client;
    public static Uri ValidateServer(string value)
    {
        if (!Uri.TryCreate(value.Trim().TrimEnd('/') + "/", UriKind.Absolute, out var uri)
            || uri.UserInfo.Length != 0 || uri.Query.Length != 0 || uri.Fragment.Length != 0
            || (uri.Scheme != "https" && !(uri.Scheme == "http" && uri.IsLoopback)))
            throw new InvalidDataException("HTTPS server manzilini kiriting.");
        return uri;
    }

    public AgentApi(string server, string? token = null)
    {
        var handler = new HttpClientHandler { AllowAutoRedirect = false };
        client = new HttpClient(handler) { BaseAddress = new Uri(ValidateServer(server), "api/print-agent/v1/"), Timeout = TimeSpan.FromSeconds(20) };
        client.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        if (token is not null) client.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", token);
    }

    private async Task<JsonElement> Post(string path, object body, CancellationToken ct)
    {
        using var result = await client.PostAsJsonAsync(path, body, AgentJson.Options, ct);
        var content = await result.Content.ReadAsStringAsync(ct);
        if (!result.IsSuccessStatusCode) throw new AgentHttpException(result.StatusCode, content);
        using var doc = JsonDocument.Parse(content);
        return doc.RootElement.GetProperty("data").Clone();
    }

    public Task<JsonElement> Pair(string code, string name, CancellationToken ct) => Post("pair", new { code, name, version = AgentVersion.Current }, ct);
    public Task<JsonElement> Heartbeat(object printers, CancellationToken ct) => Post("heartbeat", new { version = AgentVersion.Current, printers }, ct);
    public async Task<AgentJob?> Claim(CancellationToken ct)
    {
        long started = Environment.TickCount64;
        var data = await Post("jobs/claim", new { }, ct);
        if (data.ValueKind == JsonValueKind.Null) return null;
        var job = data.Deserialize<AgentJob>(AgentJson.Options)!;
        if (job.ServerTime == default) throw new InvalidDataException("Server vaqti olinmadi.");
        job.ReceivedTick = started; return job;
    }
    public async Task<byte[]> Document(AgentJob job, CancellationToken ct)
    {
        if (!Guid.TryParse(job.Id, out _)) throw new InvalidDataException("Job ID yaroqsiz.");
        using var result = await client.GetAsync("jobs/" + job.Id + "/document", HttpCompletionOption.ResponseHeadersRead, ct);
        result.EnsureSuccessStatusCode();
        if (result.Content.Headers.ContentLength > 20 * 1024 * 1024) throw new InvalidDataException("PDF hajmi juda katta.");
        using var stream = await result.Content.ReadAsStreamAsync(ct);
        using var memory = new MemoryStream();
        byte[] buffer = new byte[16384];
        int count;
        while ((count = await stream.ReadAsync(buffer, ct)) > 0)
        {
            if (memory.Length + count > 20 * 1024 * 1024) throw new InvalidDataException("PDF hajmi juda katta.");
            memory.Write(buffer, 0, count);
        }
        var bytes = memory.ToArray();
        VerifyDocument(bytes, job.Sha256);
        return bytes;
    }
    public static void VerifyDocument(byte[] bytes, string expectedHash)
    {
        if (bytes.Length < 5 || Encoding.ASCII.GetString(bytes, 0, 5) != "%PDF-"
            || !string.Equals(Convert.ToHexString(SHA256.HashData(bytes)), expectedHash, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("Hujjat hashi mos emas.");
    }
    public async Task<string> Event(AgentJob job, JobEvent evt, CancellationToken ct)
    {
        var data = await Post("jobs/" + job.Id + "/events", evt, ct);
        return data.GetProperty("status").GetString()!;
    }
    public void Dispose() => client.Dispose();
}

public sealed class AgentHttpException(HttpStatusCode status, string body) : Exception(
    status == HttpStatusCode.Unauthorized ? "Agent bekor qilingan. Qayta ulang." : $"Server xatosi: {(int)status}")
{
    public HttpStatusCode Status { get; } = status;
    public string ResponseBody { get; } = body;
}

public interface IPreparedPrint : IDisposable
{
    int Submit(string title, int copies, Action<int> onJobCreated, Action beforePage);
}

public interface IPrintAdapter
{
    Task<IPreparedPrint> Prepare(AgentJob job, byte[] pdf, CancellationToken ct);
}

public sealed class JobProcessor(IAgentApi api, AgentJournal journal, IPrintAdapter printer, Func<DateTimeOffset>? clock = null)
{
    private DateTimeOffset Now => clock?.Invoke() ?? DateTimeOffset.UtcNow;
    public async Task Send(JournalEntry entry, string status, CancellationToken ct, string? error = null, string? message = null)
    {
        entry.PendingEvent ??= new JobEvent
        {
            ClaimToken = entry.Job.ClaimToken,
            Status = status,
            WindowsJobId = entry.WindowsJobId,
            ErrorCode = error,
            Message = message
        };
        journal.Save(entry);
        string actual = await api.Event(entry.Job, entry.PendingEvent, ct);
        if (actual != entry.PendingEvent.Status) throw new InvalidDataException("Job holati o‘zgargan. Qayta chop etishdan oldin tekshiring.");
        entry.Stage = actual;
        entry.PendingEvent = null;
        journal.Save(entry);
    }

    public async Task Recover(CancellationToken ct)
    {
        foreach (var entry in journal.ReadAll())
        {
            if (entry.PendingEvent is not null)
            {
                try { await Send(entry, entry.PendingEvent.Status, ct); }
                catch (Exception e) when (e is InvalidDataException || e is AgentHttpException { Status: HttpStatusCode.Conflict or HttpStatusCode.Forbidden or HttpStatusCode.NotFound or HttpStatusCode.UnprocessableEntity or HttpStatusCode.BadRequest }) { entry.PendingEvent = null; entry.Stage = "unknown"; journal.Save(entry); }
            }
            if (entry.Stage is "received" or "rendering" or "claimed" or "submitting" or "submission_started")
            {
                entry.PendingEvent = null;
                entry.Stage = "unknown";
                journal.Save(entry);
                await Send(entry, "unknown", ct, "submission_unknown");
            }
        }
    }

    public async Task Execute(AgentJob job, CancellationToken ct)
    {
        var prior = journal.ReadAll().FirstOrDefault(e => e.Job.Id == job.Id);
        if (prior is not null && prior.Stage != "failed_before_submission") return;
        var entry = new JournalEntry { Job = job };
        journal.Save(entry);
        bool started = false;
        DateTimeOffset JobNow() => clock is not null || job.ServerTime == default ? Now : job.ServerTime.AddMilliseconds(Environment.TickCount64 - job.ReceivedTick);
        try
        {
            await Send(entry, "rendering", ct);
            var bytes = await api.Document(job, ct);
            AgentApi.VerifyDocument(bytes, job.Sha256);
            using var prepared = await printer.Prepare(job, bytes, ct);
            if (JobNow() >= job.AutoSubmitUntil) throw new InvalidDataException("job_expired");
            await Send(entry, "submitting", ct);
            if (JobNow() >= job.AutoSubmitUntil) throw new InvalidDataException("job_expired");
            entry.Stage = "submission_started";
            journal.Save(entry);
            started = true;
            int spoolId = prepared.Submit("OsonPOS " + job.Id, job.Copies,
                id => { entry.WindowsJobId = id; journal.Save(entry); },
                () => { ct.ThrowIfCancellationRequested(); if (JobNow() >= job.AutoSubmitUntil) throw new InvalidDataException("job_expired"); });
            entry.WindowsJobId = spoolId;
            await Send(entry, "spooled", ct);
        }
        catch (Exception error) when (!ct.IsCancellationRequested)
        {
            if (entry.PendingEvent is not null) throw;
            entry.PendingEvent = null;
            entry.Stage = started || entry.Stage == "submitting" ? "unknown" : "failed_before_submission";
            journal.Save(entry);
            string code = started ? "submission_unknown" : error.Message.StartsWith("paper_size_unsupported") ? "paper_size_unsupported" : error.Message == "printer_not_found" ? "printer_not_found" : error.Message == "job_expired" ? "job_expired" : "render_failed";
            string message = code == "paper_size_unsupported" ? error.Message.Replace("paper_size_unsupported:", "").Trim() : code == "printer_not_found" ? "Tanlangan printer topilmadi. Admin uni qayta biriktirsin." : code == "submission_unknown" ? "Qisman chiqish ehtimoli bor. Qayta chiqarishdan oldin printerni tekshiring." : "Printer va qog‘oz sozlamalarini tekshiring.";
            await Send(entry, entry.Stage, ct, code, message);
        }
    }
}

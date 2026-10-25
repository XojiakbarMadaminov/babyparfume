using OsonPos.PrintAgent;
using System.Security.Cryptography;
using System.Text;

int passed = 0;
void Assert(bool condition, string message) { if (!condition) throw new Exception(message); }
async Task Check(string name, Func<Task> test) { await test(); passed++; Console.WriteLine("PASS " + name); }
string root = Path.Combine(Path.GetTempPath(), "OsonPosAgentTests", Guid.NewGuid().ToString());
AgentJob Job(DateTimeOffset? until = null) => new()
{
    Id = Guid.NewGuid().ToString(),
    ClaimToken = Guid.NewGuid().ToString(),
    Copies = 1,
    Sha256 = Convert.ToHexString(SHA256.HashData(Encoding.ASCII.GetBytes("%PDF-test"))),
    AutoSubmitUntil = until ?? DateTimeOffset.UtcNow.AddMinutes(10)
};
await Check("durable journal survives reopening", async () =>
{
    var journal = new AgentJournal(Path.Combine(root, "durable")); var entry = new JournalEntry { Job = Job(), Stage = "submission_started" }; journal.Save(entry);
    entry.WindowsJobId = 42; journal.Save(entry); var read = new AgentJournal(journal.Root).ReadAll().Single(); Assert(read.Stage == "submission_started" && read.WindowsJobId == 42, "journal lost"); await Task.CompletedTask;
});
await Check("normal execution and replay submits once", async () =>
{
    var api = new FakeApi(); var adapter = new FakePrinter(); var journal = new AgentJournal(Path.Combine(root, "normal")); var p = new JobProcessor(api, journal, adapter); var job = Job();
    await p.Execute(job, CancellationToken.None); await p.Execute(job, CancellationToken.None); Assert(adapter.Count == 1, "duplicate printing"); Assert(journal.ReadAll().Single().Stage == "spooled", "not recorded");
});
await Check("expired intent never reaches printer", async () =>
{
    var adapter = new FakePrinter(); var p = new JobProcessor(new FakeApi(), new AgentJournal(Path.Combine(root, "expiry")), adapter);
    await p.Execute(Job(DateTimeOffset.UtcNow.AddSeconds(-1)), CancellationToken.None); Assert(adapter.Count == 0, "printed after TTL");
});
await Check("ambiguous partial submission is not retried", async () =>
{
    var adapter = new FakePrinter { ThrowAfterStart = true }; var journal = new AgentJournal(Path.Combine(root, "partial")); var p = new JobProcessor(new FakeApi(), journal, adapter); var job = Job();
    await p.Execute(job, CancellationToken.None); await p.Execute(job, CancellationToken.None); Assert(adapter.Count == 1, "ambiguous replay"); Assert(journal.ReadAll().Single().Stage == "unknown", "false success");
});
await Check("lost spool acknowledgement recovers without another print", async () =>
{
    var api = new FakeApi { FailOnceAt = "spooled" }; var adapter = new FakePrinter(); var journal = new AgentJournal(Path.Combine(root, "ack")); var job = Job();
    try { await new JobProcessor(api, journal, adapter).Execute(job, CancellationToken.None); } catch (HttpRequestException) { }
    var recovered = new JobProcessor(api, new AgentJournal(journal.Root), adapter); await recovered.Recover(CancellationToken.None);
    Assert(adapter.Count == 1, "print repeated"); Assert(new AgentJournal(journal.Root).ReadAll().Single().Stage == "spooled", "ack lost");
});
await Check("lost submission grant remains conservative", async () =>
{
    var api = new FakeApi { FailOnceAt = "submitting" }; var adapter = new FakePrinter(); var journal = new AgentJournal(Path.Combine(root, "grant"));
    try { await new JobProcessor(api, journal, adapter).Execute(Job(), CancellationToken.None); } catch (HttpRequestException) { }
    await new JobProcessor(api, new AgentJournal(journal.Root), adapter).Recover(CancellationToken.None); Assert(adapter.Count == 0, "grant loss printed"); Assert(new AgentJournal(journal.Root).ReadAll().Single().Stage == "unknown", "grant loss not quarantined");
});
await Check("tampered document and unsafe URL are rejected", async () =>
{
    bool rejected = false; try { AgentApi.VerifyDocument(Encoding.ASCII.GetBytes("%PDF-bad"), new string('0', 64)); } catch (InvalidDataException) { rejected = true; }
    Assert(rejected, "hash ignored");
    rejected = false; try { AgentApi.ValidateServer("http://shop.example"); } catch (InvalidDataException) { rejected = true; }
    Assert(rejected, "HTTP accepted");
    Assert(AgentApi.ValidateServer("https://shop.example/").Scheme == "https", "HTTPS rejected"); await Task.CompletedTask;
});
await Check("revoked permission quarantines an unacknowledged grant", async () =>
{
    var api = new FakeApi { RejectAt = "submitting" }; var printer = new FakePrinter(); var journal = new AgentJournal(Path.Combine(root, "forbidden"));
    try { await new JobProcessor(api, journal, printer).Execute(Job(), CancellationToken.None); } catch (AgentHttpException) { }
    await new JobProcessor(api, new AgentJournal(journal.Root), printer).Recover(CancellationToken.None);
    Assert(printer.Count == 0, "permission denial printed"); Assert(new AgentJournal(journal.Root).ReadAll().Single().Stage == "unknown", "permission denial blocked journal recovery");
});
if (args.Length == 2 && args[0] == "--pdf")
{
    await Check("Windows native PDF renderer reads real barcode document", async () =>
    {
        var file = await Windows.Storage.StorageFile.GetFileFromPathAsync(Path.GetFullPath(args[1])); var pdf = await Windows.Data.Pdf.PdfDocument.LoadFromFileAsync(file);
        Assert(pdf.PageCount == 2, "expected 2 labels, got " + pdf.PageCount); using var page = pdf.GetPage(0);
        Assert(Math.Abs(page.Size.Width * 25.4 / 96 - 30) < .5, "wrong label width"); Assert(Math.Abs(page.Size.Height * 25.4 / 96 - 20) < .5, "wrong label height");
        using var stream = new Windows.Storage.Streams.InMemoryRandomAccessStream(); await page.RenderToStreamAsync(stream);
        using var input = stream.AsStreamForRead(); input.Position = 0; using var output = File.Create(Path.Combine(root, "barcode-preview.png")); await input.CopyToAsync(output);
        Console.WriteLine("Preview: " + Path.Combine(root, "barcode-preview.png"));
    });
}
Console.WriteLine(passed + " agent tests passed. No printer was used.");

sealed class FakeApi : IAgentApi
{
    public string? FailOnceAt;
    public string? RejectAt;
    public Task<AgentJob?> Claim(CancellationToken ct) => Task.FromResult<AgentJob?>(null);
    public Task<byte[]> Document(AgentJob job, CancellationToken ct) => Task.FromResult(Encoding.ASCII.GetBytes("%PDF-test"));
    public Task<string> Event(AgentJob job, JobEvent evt, CancellationToken ct) { if (RejectAt == evt.Status) throw new AgentHttpException(System.Net.HttpStatusCode.Forbidden, "denied"); if (FailOnceAt == evt.Status) { FailOnceAt = null; throw new HttpRequestException("lost response"); } return Task.FromResult(evt.Status); }
}
sealed class FakePrinter : IPrintAdapter
{
    public int Count; public bool ThrowAfterStart;
    public Task<IPreparedPrint> Prepare(AgentJob job, byte[] pdf, CancellationToken ct) => Task.FromResult<IPreparedPrint>(new Prepared(this));
    private sealed class Prepared(FakePrinter owner) : IPreparedPrint
    {
        public int Submit(string title, int copies, Action<int> created, Action beforePage) { beforePage(); owner.Count++; created(42); if (owner.ThrowAfterStart) throw new IOException("partial"); return 42; }
        public void Dispose() { }
    }
}

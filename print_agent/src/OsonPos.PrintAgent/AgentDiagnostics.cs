using System.Text.Json;
using Windows.Data.Pdf;
using Windows.Storage;
using Windows.Storage.Streams;

namespace OsonPos.PrintAgent;

public static class AgentDiagnostics
{
    public static async Task CheckPdf(string path, string report)
    {
        var file = await StorageFile.GetFileFromPathAsync(Path.GetFullPath(path));
        var pdf = await PdfDocument.LoadFromFileAsync(file);
        using var page = pdf.GetPage(0);
        using var memory = new InMemoryRandomAccessStream();
        await page.RenderToStreamAsync(memory);
        string image = Path.ChangeExtension(Path.GetFullPath(report), ".png");
        using (var input = memory.AsStreamForRead())
        using (var output = File.Create(image)) { input.Position = 0; await input.CopyToAsync(output); }
        AgentJournal.AtomicWrite(Path.GetFullPath(report), JsonSerializer.Serialize(new
        {
            pages = pdf.PageCount,
            width_mm = page.Size.Width * 25.4 / 96,
            height_mm = page.Size.Height * 25.4 / 96,
            preview_path = image,
            printer_used = false
        }, AgentJson.Options));
    }
}

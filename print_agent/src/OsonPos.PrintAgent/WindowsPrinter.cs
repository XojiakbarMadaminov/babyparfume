using System.ComponentModel;
using System.Drawing.Printing;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Text;
using Windows.Data.Pdf;
using Windows.Storage;
using Windows.Storage.Streams;

namespace OsonPos.PrintAgent;

public sealed record PrinterInfo(string LocalKey, string Name, string Driver, string Port, uint Status);

public static class WindowsPrinters
{
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct Info2
    {
        public IntPtr Server, Name, Share, Port, Driver, Comment, Location, DevMode, SepFile, Processor, DataType, Parameters, Security;
        public uint Attributes, Priority, DefaultPriority, StartTime, UntilTime, Status, Jobs, AveragePpm;
    }
    [DllImport("winspool.drv", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern bool EnumPrinters(uint flags, string? name, uint level, IntPtr buffer, uint size, out uint needed, out uint returned);

    public static List<PrinterInfo> List()
    {
        EnumPrinters(6, null, 2, IntPtr.Zero, 0, out uint needed, out _);
        if (needed == 0) return [];
        IntPtr buffer = Marshal.AllocHGlobal((int)needed);
        try
        {
            if (!EnumPrinters(6, null, 2, buffer, needed, out _, out uint count)) throw new Win32Exception();
            var result = new List<PrinterInfo>();
            for (int i = 0; i < count; i++)
            {
                var p = Marshal.PtrToStructure<Info2>(buffer + i * Marshal.SizeOf<Info2>());
                string name = Marshal.PtrToStringUni(p.Name) ?? "";
                string driver = Marshal.PtrToStringUni(p.Driver) ?? "";
                string port = Marshal.PtrToStringUni(p.Port) ?? "";
                string key = Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(name + "\n" + driver + "\n" + port))).ToLowerInvariant();
                result.Add(new(key, name, driver, port, p.Status));
            }
            return result;
        }
        finally { Marshal.FreeHGlobal(buffer); }
    }

    public static void Validate(PrintProfile profile)
    {
        var p = List().FirstOrDefault(p => p.LocalKey == profile.LocalKey && p.Name == profile.PrinterName)
            ?? throw new InvalidDataException("printer_not_found");
        if (p.Port.StartsWith("FILE:", StringComparison.OrdinalIgnoreCase)
            || p.Port.StartsWith("PORTPROMPT:", StringComparison.OrdinalIgnoreCase)
            || p.Driver.Contains("PDF", StringComparison.OrdinalIgnoreCase)
            || p.Name.Contains("PDF", StringComparison.OrdinalIgnoreCase)
            || p.Driver.Contains("XPS", StringComparison.OrdinalIgnoreCase)
            || p.Name.Contains("OneNote", StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("Virtual printer avtomatik chiqarish uchun ruxsat etilmagan.");
        if ((p.Status & (1u | 2u | 4u | 8u | 16u | 128u | 1048576u | 4194304u)) != 0)
            throw new InvalidDataException("Printer tayyor emas.");
    }
}

public sealed class WindowsPdfPrinter(string cacheRoot) : IPrintAdapter
{
    public async Task<IPreparedPrint> Prepare(AgentJob job, byte[] pdf, CancellationToken ct)
    {
        WindowsPrinters.Validate(job.Profile);
        string root = Path.Combine(Path.GetFullPath(cacheRoot), Guid.Parse(job.Id).ToString());
        Directory.CreateDirectory(root);
        string pdfPath = Path.Combine(root, "document.pdf");
        await File.WriteAllBytesAsync(pdfPath, pdf, ct);
        var file = await StorageFile.GetFileFromPathAsync(pdfPath);
        var document = await PdfDocument.LoadFromFileAsync(file);
        if (document.PageCount == 0 || document.PageCount > 2000) throw new InvalidDataException("PDF sahifalari chegaradan tashqarida.");
        using var first = document.GetPage(0);
        double width = first.Size.Width * 25.4 / 96;
        double height = first.Size.Height * 25.4 / 96;
        if (Math.Abs(width - job.Profile.WidthMm) > .6 || (job.Profile.HeightMm is double h && Math.Abs(height - h) > .6))
            throw new InvalidDataException("PDF va profil o‘lchami mos emas.");
        var device = new PrintDevice(job.Profile.PrinterName, width, height);
        try
        {
            var pages = new List<string>();
            int dpi = Math.Min(300, Math.Max(72, device.DpiX));
            long totalBytes = 0;
            for (uint i = 0; i < document.PageCount; i++)
            {
                ct.ThrowIfCancellationRequested();
                using var page = document.GetPage(i);
                if (Math.Abs(page.Size.Width * 25.4 / 96 - width) > .6 || Math.Abs(page.Size.Height * 25.4 / 96 - height) > .6)
                    throw new InvalidDataException("PDF sahifalari o‘lchami turlicha.");
                uint w = (uint)Math.Ceiling(page.Size.Width * dpi / 96);
                uint hPx = (uint)Math.Ceiling(page.Size.Height * dpi / 96);
                if ((long)w * hPx > 40_000_000) throw new InvalidDataException("Render o‘lchami juda katta.");
                using var memory = new InMemoryRandomAccessStream();
                await page.RenderToStreamAsync(memory, new PdfPageRenderOptions { DestinationWidth = w, DestinationHeight = hPx, BackgroundColor = Windows.UI.Color.FromArgb(255, 255, 255, 255) });
                string path = Path.Combine(root, i + ".png");
                using var input = memory.AsStreamForRead();
                input.Position = 0;
                using (var output = File.Create(path)) await input.CopyToAsync(output, ct);
                totalBytes += new FileInfo(path).Length;
                if (totalBytes > 150 * 1024 * 1024) throw new InvalidDataException("Render fayllari hajmi juda katta.");
                pages.Add(path);
            }
            return new Prepared(device, pages, job.Profile);
        }
        catch { device.Dispose(); throw; }
    }

    private sealed class Prepared(PrintDevice device, List<string> pages, PrintProfile profile) : IPreparedPrint
    {
        public int Submit(string title, int copies, Action<int> onJobCreated, Action beforePage)
        {
            WindowsPrinters.Validate(profile);
            if (copies < 1 || copies > 100) throw new InvalidDataException("Nusxalar soni yaroqsiz.");
            beforePage();
            int id = device.Start(title);
            try
            {
                onJobCreated(id);
                for (int copy = 0; copy < copies; copy++)
                    foreach (var page in pages)
                    {
                        beforePage();
                        device.Page(page);
                    }
                device.End();
                return id;
            }
            catch { device.Abort(); throw; }
        }
        public void Dispose() => device.Dispose();
    }
}

public sealed class PrintDevice : IDisposable
{
    private IntPtr dc;
    public int DpiX => GetDeviceCaps(dc, 88);
    private readonly double widthMm;
    private readonly double heightMm;
    public PrintDevice(string name, double width, double height)
    {
        widthMm = width; heightMm = height;
        var printer = new PrinterSettings { PrinterName = name };
        if (!printer.IsValid) throw new InvalidDataException("Printer drayveri topilmadi.");
        var page = new PageSettings(printer) { PaperSize = new PaperSize("OsonPOS", (int)Math.Round(width * 100 / 25.4), (int)Math.Round(height * 100 / 25.4)), Margins = new Margins(0, 0, 0, 0) };
        IntPtr mode = printer.GetHdevmode(page);
        try { var pointer = GlobalLock(mode); if (pointer == IntPtr.Zero) throw new Win32Exception(); try { dc = CreateDC("WINSPOOL", name, null, pointer); } finally { GlobalUnlock(mode); } }
        finally { GlobalFree(mode); }
        if (dc == IntPtr.Zero) throw new Win32Exception();
        double actual = GetDeviceCaps(dc, 110) * 25.4 / GetDeviceCaps(dc, 88);
        double actualHeight = GetDeviceCaps(dc, 111) * 25.4 / GetDeviceCaps(dc, 90);
        if (Math.Abs(actual - width) > 2 || Math.Abs(actualHeight - height) > 3)
        {
            Dispose();
            throw new InvalidDataException("paper_size_unsupported: Windows drayverida qog‘oz o‘lchamini tekshiring.");
        }
    }
    public int Start(string title)
    {
        var info = new DocInfo { Size = Marshal.SizeOf<DocInfo>(), Name = title };
        int id = StartDoc(dc, ref info);
        if (id <= 0) throw new Win32Exception();
        return id;
    }
    public void Page(string path)
    {
        if (StartPage(dc) <= 0) throw new Win32Exception();
        using (var image = Image.FromFile(path))
        using (var graphics = Graphics.FromHdc(dc))
        {
            graphics.PageUnit = GraphicsUnit.Pixel;
            graphics.Clear(Color.White);
            graphics.DrawImage(image, new Rectangle(-GetDeviceCaps(dc, 112), -GetDeviceCaps(dc, 113),
                (int)Math.Round(widthMm * GetDeviceCaps(dc, 88) / 25.4), (int)Math.Round(heightMm * GetDeviceCaps(dc, 90) / 25.4)));
        }
        if (EndPage(dc) <= 0) throw new Win32Exception();
    }
    public void End() { if (EndDoc(dc) <= 0) throw new Win32Exception(); }
    public void Abort() => AbortDoc(dc);
    public void Dispose() { if (dc != IntPtr.Zero) { DeleteDC(dc); dc = IntPtr.Zero; } }
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct DocInfo { public int Size; public string Name; public string? Output; public string? DataType; public int Type; }
    [DllImport("gdi32.dll", CharSet = CharSet.Unicode, SetLastError = true)] private static extern IntPtr CreateDC(string driver, string device, string? output, IntPtr mode);
    [DllImport("gdi32.dll", CharSet = CharSet.Unicode, SetLastError = true)] private static extern int StartDoc(IntPtr dc, ref DocInfo info);
    [DllImport("gdi32.dll")] private static extern int StartPage(IntPtr dc);
    [DllImport("gdi32.dll")] private static extern int EndPage(IntPtr dc);
    [DllImport("gdi32.dll")] private static extern int EndDoc(IntPtr dc);
    [DllImport("gdi32.dll")] private static extern int AbortDoc(IntPtr dc);
    [DllImport("gdi32.dll")] private static extern int GetDeviceCaps(IntPtr dc, int index);
    [DllImport("gdi32.dll")] private static extern bool DeleteDC(IntPtr dc);
    [DllImport("kernel32.dll")] private static extern IntPtr GlobalLock(IntPtr memory);
    [DllImport("kernel32.dll")] private static extern bool GlobalUnlock(IntPtr memory);
    [DllImport("kernel32.dll")] private static extern IntPtr GlobalFree(IntPtr memory);
}

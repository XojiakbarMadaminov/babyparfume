using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.Json;
using Microsoft.Win32;

namespace OsonPos.PrintAgent;

public static class AgentStorage
{
    public static string Root => Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "OsonPOS", "PrintAgent");
    public static string SettingsPath => Path.Combine(Root, "settings.json");
    public static AgentSettings Load() => File.Exists(SettingsPath)
        ? JsonSerializer.Deserialize<AgentSettings>(File.ReadAllText(SettingsPath), AgentJson.Options) ?? new() : new();
    public static void Save(AgentSettings settings) => AgentJournal.AtomicWrite(SettingsPath, JsonSerializer.Serialize(settings, AgentJson.Options));
}

public static class TokenProtection
{
    [StructLayout(LayoutKind.Sequential)] private struct Blob { public int Length; public IntPtr Data; }
    [DllImport("crypt32.dll", SetLastError = true, CharSet = CharSet.Unicode)] private static extern bool CryptProtectData(ref Blob input, string? description, IntPtr entropy, IntPtr reserved, IntPtr prompt, uint flags, out Blob output);
    [DllImport("crypt32.dll", SetLastError = true, CharSet = CharSet.Unicode)] private static extern bool CryptUnprotectData(ref Blob input, IntPtr description, IntPtr entropy, IntPtr reserved, IntPtr prompt, uint flags, out Blob output);
    [DllImport("kernel32.dll")] private static extern IntPtr LocalFree(IntPtr value);
    private static byte[] Transform(byte[] value, bool protect)
    {
        var input = new Blob { Length = value.Length, Data = Marshal.AllocHGlobal(value.Length) };
        Marshal.Copy(value, 0, input.Data, value.Length);
        Blob output;
        try
        {
            bool ok = protect ? CryptProtectData(ref input, "OsonPOS", IntPtr.Zero, IntPtr.Zero, IntPtr.Zero, 1, out output)
                : CryptUnprotectData(ref input, IntPtr.Zero, IntPtr.Zero, IntPtr.Zero, IntPtr.Zero, 1, out output);
            if (!ok) throw new System.ComponentModel.Win32Exception();
            try { var bytes = new byte[output.Length]; Marshal.Copy(output.Data, bytes, 0, bytes.Length); return bytes; }
            finally { LocalFree(output.Data); }
        }
        finally { Marshal.FreeHGlobal(input.Data); }
    }
    public static string Protect(string value) => Convert.ToBase64String(Transform(Encoding.UTF8.GetBytes(value), true));
    public static string Unprotect(string value) => Encoding.UTF8.GetString(Transform(Convert.FromBase64String(value), false));
}

public sealed class AgentRuntime(AgentSettings settings) : IDisposable
{
    private readonly CancellationTokenSource cancellation = new();
    private AgentApi? api;
    private Task? task;
    private DateTimeOffset lastPrune = DateTimeOffset.MinValue;
    public event Action<string>? StatusChanged;
    public void Start()
    {
        api = new AgentApi(settings.ServerUrl, TokenProtection.Unprotect(settings.ProtectedToken));
        var processor = new JobProcessor(api, new AgentJournal(Path.Combine(AgentStorage.Root, "journal", Guid.Parse(settings.AgentId).ToString())), new WindowsPdfPrinter(Path.Combine(AgentStorage.Root, "cache", Guid.Parse(settings.AgentId).ToString())));
        task = Task.Run(() => Task.WhenAll(Poll(processor, cancellation.Token), Heartbeat(cancellation.Token)), cancellation.Token);
    }
    private async Task Poll(JobProcessor processor, CancellationToken ct)
    {
        while (!ct.IsCancellationRequested)
        {
            try
            {
                await processor.Recover(ct);
                if (DateTimeOffset.UtcNow - lastPrune > TimeSpan.FromHours(1)) { PruneLocal(); lastPrune = DateTimeOffset.UtcNow; }
                var job = await api!.Claim(ct);
                if (job is not null)
                {
                    StatusChanged?.Invoke("Tayyorlanmoqda: " + job.Profile.Name);
                    await processor.Execute(job, ct);
                    StatusChanged?.Invoke("Topshiriq qayd etildi. Holatni OsonPOSda tekshiring.");
                }
                await Task.Delay(2000, ct);
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested) { return; }
            catch (AgentHttpException e) when (e.Status == System.Net.HttpStatusCode.Unauthorized)
            { StatusChanged?.Invoke(e.Message); cancellation.Cancel(); return; }
            catch (Exception e)
            {
                StatusChanged?.Invoke(e is HttpRequestException or TaskCanceledException ? "Server bilan aloqa yo‘q. Navbat saqlangan." : e.Message);
                try { await Task.Delay(10000, ct); } catch (OperationCanceledException) { return; }
            }
        }
    }
    private async Task Heartbeat(CancellationToken ct)
    {
        while (!ct.IsCancellationRequested)
        {
            try
            {
                var printers = WindowsPrinters.List().Select(p => new { local_key = p.LocalKey, name = p.Name, driver = p.Driver }).ToArray();
                await api!.Heartbeat(printers, ct);
                StatusChanged?.Invoke("Serverga ulangan · " + printers.Length + " printer");
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested) { return; }
            catch (Exception e) { StatusChanged?.Invoke(e is AgentHttpException ? e.Message : "Aloqa tekshirilmoqda..."); }
            try { await Task.Delay(15000, ct); } catch (OperationCanceledException) { return; }
        }
    }
    private void PruneLocal()
    {
        string id = Guid.Parse(settings.AgentId).ToString();
        var journal = new AgentJournal(Path.Combine(AgentStorage.Root, "journal", id));
        foreach (var entry in journal.ReadAll())
        {
            if (entry.PendingEvent is not null || entry.Stage is not ("spooled" or "completed_reported")) continue;
            if (entry.UpdatedAt > DateTimeOffset.UtcNow.AddDays(-7)) continue;
            string folder = Path.Combine(AgentStorage.Root, "cache", id, Guid.Parse(entry.Job.Id).ToString());
            if (!Directory.Exists(folder)) continue;
            foreach (string path in Directory.EnumerateFiles(folder))
            {
                string name = Path.GetFileName(path);
                if (name == "document.pdf" || System.Text.RegularExpressions.Regex.IsMatch(name, @"^\d+\.png$")) try { File.Delete(path); } catch (IOException) { } catch (UnauthorizedAccessException) { }
            }
        }
    }
    public void Dispose() { cancellation.Cancel(); api?.Dispose(); }
}

public static class AgentInstallation
{
    public static string InstallRoot => Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "Programs", "OsonPOSPrintAgent");
    public static string InstalledExe => Path.Combine(InstallRoot, "OsonPOSPrintAgent.exe");
    public static void AutoStart(bool enabled)
    {
        using var key = Registry.CurrentUser.CreateSubKey(@"Software\Microsoft\Windows\CurrentVersion\Run");
        if (enabled) key.SetValue("OsonPOSPrintAgent", "\"" + InstalledExe + "\" --background");
        else key.DeleteValue("OsonPOSPrintAgent", false);
    }
    public static void Install()
    {
        using var form = new Form { Text = "OsonPOS Print Agent — o‘rnatish", Width = 520, Height = 230, StartPosition = FormStartPosition.CenterScreen, FormBorderStyle = FormBorderStyle.FixedDialog, MaximizeBox = false };
        var label = new Label { Text = "Windows uchun chek va barcode yordamchisi.\nDastur joriy foydalanuvchi uchun o‘rnatiladi.\nPrinter drayveri o‘rnatilgan bo‘lishi kerak.", Left = 20, Top = 20, Width = 470, Height = 65 };
        var startup = new CheckBox { Text = "Windowsga kirilganda avtomatik ishga tushirish", Checked = true, Left = 20, Top = 95, Width = 470 };
        var button = new Button { Text = "O‘rnatish", Left = 335, Top = 140, Width = 145, Height = 32 };
        button.Click += (_, _) =>
        {
            try
            {
                Directory.CreateDirectory(InstallRoot);
                string source = Environment.ProcessPath!;
                if (!string.Equals(Path.GetFullPath(source), Path.GetFullPath(InstalledExe), StringComparison.OrdinalIgnoreCase)) File.Copy(source, InstalledExe, true);
                AutoStart(startup.Checked);
                using var uninstall = Registry.CurrentUser.CreateSubKey(@"Software\Microsoft\Windows\CurrentVersion\Uninstall\OsonPOSPrintAgent");
                uninstall.SetValue("DisplayName", "OsonPOS Print Agent"); uninstall.SetValue("DisplayVersion", "1.0.0");
                uninstall.SetValue("Publisher", "OsonPOS"); uninstall.SetValue("UninstallString", "\"" + InstalledExe + "\" --uninstall");
                uninstall.SetValue("InstallLocation", InstallRoot);
                var settings = AgentStorage.Load(); settings.AutoStart = startup.Checked; AgentStorage.Save(settings);
                Process.Start(new ProcessStartInfo(InstalledExe) { UseShellExecute = true });
                form.Close();
            }
            catch (Exception e) { MessageBox.Show(form, "O‘rnatib bo‘lmadi. Ishlab turgan agentni yoping va qayta urining.\n" + e.Message); }
        };
        form.Controls.AddRange([label, startup, button]);
        Application.Run(form);
    }
    public static void Uninstall(bool helper, int waitFor)
    {
        if (!helper)
        {
            if (MessageBox.Show("Agent o‘chirilsinmi? Topshiriqlar jurnali va sozlamalar saqlanadi.", "OsonPOS", MessageBoxButtons.YesNo) != DialogResult.Yes) return;
            string temp = Path.Combine(Path.GetTempPath(), "OsonPOSUninstall-" + Guid.NewGuid() + ".exe");
            File.Copy(Environment.ProcessPath!, temp);
            Process.Start(new ProcessStartInfo(temp, "--remove-install " + Environment.ProcessId) { UseShellExecute = false, CreateNoWindow = true });
            return;
        }
        try { using var process = Process.GetProcessById(waitFor); process.WaitForExit(15000); } catch (ArgumentException) { }
        AutoStart(false);
        try { File.Delete(InstalledExe); } catch (IOException) { MessageBox.Show("Agentni tray menyusidan yoping, keyin o‘chirishni qayta ishga tushiring."); return; }
        Registry.CurrentUser.DeleteSubKeyTree(@"Software\Microsoft\Windows\CurrentVersion\Uninstall\OsonPOSPrintAgent", false);
        MessageBox.Show("OsonPOS Print Agent o‘chirildi. Topshiriqlar jurnali saqlandi.");
    }
}

using System.Text.Json;

namespace OsonPos.PrintAgent;

public sealed class MainForm : Form
{
    private readonly TextBox server = new() { Width = 420, PlaceholderText = "https://sizning-serveringiz.uz" };
    private readonly TextBox name = new() { Width = 420, Text = Environment.MachineName };
    private readonly TextBox code = new() { Width = 250, MaxLength = 12, CharacterCasing = CharacterCasing.Upper };
    private readonly Label status = new() { AutoSize = false, Width = 490, Height = 65, Text = "Kompyuterni ulash kodini admin paneldan oling." };
    private readonly CheckBox startup = new() { Text = "Windowsga kirilganda ishga tushirish", AutoSize = true };
    private readonly ListBox printers = new() { Width = 490, Height = 105 };
    private readonly ListBox jobs = new() { Width = 490, Height = 90, HorizontalScrollbar = true };
    private readonly Button pair = new() { Text = "Kompyuterni ulash", Width = 180, Height = 34 };
    private readonly NotifyIcon tray;
    private AgentRuntime? runtime;
    private bool quitting;
    private readonly bool background;

    public MainForm(bool background)
    {
        this.background = background;
        Text = "OsonPOS Print Agent"; Width = 560; Height = 740; MinimumSize = new Size(540, 580);
        StartPosition = FormStartPosition.CenterScreen;
        var panel = new FlowLayoutPanel { Dock = DockStyle.Fill, FlowDirection = FlowDirection.TopDown, WrapContents = false, Padding = new Padding(20), AutoScroll = true };
        panel.Controls.Add(new Label { Text = "OsonPOS · chek va barcode", AutoSize = true, Font = new Font("Segoe UI", 15, FontStyle.Bold) });
        panel.Controls.Add(new Label { Text = "Server manzili", AutoSize = true, Margin = new Padding(3, 18, 3, 3) }); panel.Controls.Add(server);
        panel.Controls.Add(new Label { Text = "Kompyuter nomi", AutoSize = true, Margin = new Padding(3, 12, 3, 3) }); panel.Controls.Add(name);
        panel.Controls.Add(new Label { Text = "Bir martalik ulash kodi (12 belgi)", AutoSize = true, Margin = new Padding(3, 12, 3, 3) }); panel.Controls.Add(code);
        panel.Controls.Add(pair); panel.Controls.Add(startup); panel.Controls.Add(status);
        panel.Controls.Add(new Label { Text = "Windows printerlari (yo‘nalish admin panelda tanlanadi)", AutoSize = true }); panel.Controls.Add(printers);
        var refresh = new Button { Text = "Printerlarni yangilash", Width = 180, Height = 30 };
        refresh.Click += (_, _) => { RefreshPrinters(); RefreshJobs(); }; panel.Controls.Add(refresh);
        panel.Controls.Add(new Label { Text = "Oxirgi topshiriqlar · lokal jurnal", AutoSize = true }); panel.Controls.Add(jobs); Controls.Add(panel);
        tray = new NotifyIcon { Icon = SystemIcons.Application, Text = "OsonPOS Print Agent", Visible = true };
        var menu = new ContextMenuStrip(); menu.Items.Add("Ochish", null, (_, _) => OpenWindow()); menu.Items.Add("Chiqish", null, (_, _) => { quitting = true; Close(); });
        tray.ContextMenuStrip = menu; tray.DoubleClick += (_, _) => OpenWindow();
        menu.Items.Insert(1, new ToolStripMenuItem("Diagnostika", null, (_, _) => { OpenWindow(); RefreshPrinters(); RefreshJobs(); }));
        pair.Click += async (_, _) => await Pair();
        startup.CheckedChanged += (_, _) =>
        {
            if (!IsHandleCreated) return;
            try { AgentInstallation.AutoStart(startup.Checked); var s = AgentStorage.Load(); s.AutoStart = startup.Checked; AgentStorage.Save(s); }
            catch (Exception e) { status.Text = e.Message; }
        };
        FormClosing += (_, e) => { if (!quitting) { e.Cancel = true; Hide(); } else { runtime?.Dispose(); tray.Dispose(); } };
    }
    protected override void OnShown(EventArgs e)
    {
        base.OnShown(e);
        try
        {
            var settings = AgentStorage.Load(); server.Text = settings.ServerUrl; startup.Checked = settings.AutoStart;
            RefreshPrinters();
            if (settings.ProtectedToken.Length > 0) { Start(settings); if (background) Hide(); }
        }
        catch (Exception error) { status.Text = "Sozlamani tekshiring: " + error.Message; }
    }
    private void OpenWindow() { Show(); WindowState = FormWindowState.Normal; Activate(); RefreshJobs(); }
    private void RefreshJobs()
    {
        try
        {
            jobs.Items.Clear();
            var settings = AgentStorage.Load();
            if (!Guid.TryParse(settings.AgentId, out var id)) return;
            var journal = new AgentJournal(Path.Combine(AgentStorage.Root, "journal", id.ToString()));
            foreach (var entry in journal.ReadAll().OrderByDescending(e => e.UpdatedAt).Take(10))
                jobs.Items.Add(entry.Job.Id[..8] + " · " + entry.Stage + (entry.WindowsJobId is int spool ? " · Windows #" + spool : ""));
        }
        catch (Exception e) { status.Text = "Jurnalni tekshiring: " + e.Message; }
    }
    private void RefreshPrinters()
    {
        try { printers.Items.Clear(); foreach (var p in WindowsPrinters.List()) printers.Items.Add(p.Name + " · " + p.Driver); }
        catch (Exception e) { status.Text = e.Message; }
    }
    private async Task Pair()
    {
        pair.Enabled = false;
        try
        {
            string address = AgentApi.ValidateServer(server.Text).ToString();
            using var api = new AgentApi(address);
            var result = await api.Pair(code.Text.Trim(), name.Text.Trim(), CancellationToken.None);
            var settings = new AgentSettings
            {
                ServerUrl = address,
                AgentId = result.GetProperty("agent_id").GetString()!,
                StoreId = result.GetProperty("store_id").GetString()!,
                ProtectedToken = TokenProtection.Protect(result.GetProperty("token").GetString()!),
                AutoStart = startup.Checked
            };
            AgentStorage.Save(settings); code.Clear(); Start(settings);
        }
        catch (Exception e) { status.Text = e is AgentHttpException a ? a.Status == System.Net.HttpStatusCode.Conflict ? "Avval admin paneldan eski kompyuterni uzing." : "Ulash kodi, server va ruxsatlarni tekshiring." : e.Message; }
        finally { pair.Enabled = true; }
    }
    private void Start(AgentSettings settings)
    {
        runtime?.Dispose(); runtime = new AgentRuntime(settings);
        runtime.StatusChanged += message => { if (!IsDisposed && IsHandleCreated) BeginInvoke(() => status.Text = message); };
        runtime.Start(); status.Text = "Do‘kon #" + settings.StoreId + " · ulanish tekshirilmoqda...";
    }
}
